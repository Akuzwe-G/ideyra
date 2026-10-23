/**
 * Public-page enhancements. Reading order and visibility never depend on motion.
 */
export function mountExperience() {
    if (!document.querySelector('.marketing-header')) return () => {};
    const controller = new AbortController();
    const { signal } = controller;
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const animations = new Set();
    const observers = [];
    const restorers = [];
    const observed = new WeakSet();
    const seen = new WeakSet();

    const play = (element, direction = 'rise', delay = 0) => {
        if (reduced.matches || !element.animate) return;
        const origins = {
            rise: 'translateY(24px)',
            left: 'translateX(-16px) rotate(-.5deg)',
            right: 'translateX(16px) rotate(.5deg)',
            scale: 'scale(.95)',
        };
        const animation = element.animate([
            { opacity: .15, transform: origins[direction] || origins.rise },
            { opacity: 1, transform: 'translate(0) scale(1) rotate(0)' },
        ], { duration: 620, delay, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' });
        animations.add(animation);
        animation.finished.then(() => animations.delete(animation), () => animations.delete(animation));
    };

    const revealObserver = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            revealObserver.unobserve(entry.target);
            if (!seen.has(entry.target)) {
                seen.add(entry.target);
                play(entry.target, entry.target.dataset.motion, Number(entry.target.dataset.motionDelay || 0));
            }
        }
    }, { threshold: .12 }) : null;
    if (revealObserver) observers.push(revealObserver);

    const refresh = () => {
        const selectors = [
            '.section-heading', '.page-intro>h1', '.page-intro>p:last-child',
            '.feature-grid>.feature-card', '.dual-use>div', '.steps-grid>li',
            '.student-journey>li', '.story-chapter', '.opportunity-stack>span', '.marketing-cta',
            '.contact-grid>aside', '[data-motion]',
        ];
        for (const element of document.querySelectorAll(selectors.join(','))) {
            if (observed.has(element)) continue;
            observed.add(element);
            if (!element.dataset.motion) {
                element.dataset.motion = element.matches('.feature-card,.student-journey>li') ? 'scale' : 'rise';
            }
            if (element.parentElement.matches('.feature-grid,.steps-grid,.opportunity-stack,.student-journey')) {
                const index = [...element.parentElement.children].indexOf(element);
                element.dataset.motionDelay = String(Math.min(index * 70, 210));
            }
            revealObserver?.observe(element);
        }
    };
    refresh();

    document.querySelectorAll('.hero-copy>h1,.journey-chip,.journey-brand-icon').forEach((element, index) => {
        seen.add(element);
        play(element, element.matches('.journey-brand-icon') ? 'scale' : 'rise', index * 90);
    });

    document.querySelectorAll('[data-showcase]').forEach(showcase => {
        const tabs = [...showcase.querySelectorAll('[data-feature-tab]')];
        const panels = [...showcase.querySelectorAll('[data-feature-panel]')];
        const picker = showcase.querySelector('.feature-picker');
        const select = (index, focus = false) => {
            tabs.forEach((tab, tabIndex) => {
                const selected = tabIndex === index;
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
                panels[tabIndex].hidden = !selected;
                if (selected && focus) tab.focus({ preventScroll: true });
            });
            play(panels[index], 'scale');
        };
        picker.setAttribute('role', 'tablist');
        picker.setAttribute('aria-orientation', 'vertical');
        tabs.forEach((tab, index) => {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panels[index].id);
            panels[index].setAttribute('role', 'tabpanel');
            panels[index].setAttribute('aria-labelledby', tab.id);
            panels[index].tabIndex = 0;
            tab.addEventListener('click', event => { event.preventDefault(); select(index); }, { signal });
            tab.addEventListener('keydown', event => {
                let next;
                if (['ArrowDown', 'ArrowRight'].includes(event.key)) next = (index + 1) % tabs.length;
                if (['ArrowUp', 'ArrowLeft'].includes(event.key)) next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next !== undefined) {
                    event.preventDefault();
                    select(next, true);
                }
            }, { signal });
        });
        select(0);
        showcase.dataset.enhanced = 'true';
        restorers.push(() => {
            picker.removeAttribute('role');
            picker.removeAttribute('aria-orientation');
            tabs.forEach(tab => {
                ['role', 'aria-controls', 'aria-selected', 'tabindex'].forEach(name => tab.removeAttribute(name));
            });
            panels.forEach(panel => {
                panel.hidden = false;
                ['role', 'aria-labelledby', 'tabindex'].forEach(name => panel.removeAttribute(name));
            });
            delete showcase.dataset.enhanced;
        });
    });

    document.querySelectorAll('[data-story]').forEach(story => {
        const scene = story.querySelector('.story-scene');
        const links = [...story.querySelectorAll('[data-story-link]')];
        const steps = [...story.querySelectorAll('[data-story-step]')];
        let current = -1;
        const setStep = index => {
            if (index === current) return;
            current = index;
            story.dataset.step = String(index);
            scene.style.setProperty('--story-progress', String((index + 1) / steps.length));
            scene.querySelectorAll('[data-phase]').forEach(node => {
                node.classList.toggle('phase-visible', Number(node.dataset.phase) <= index);
                node.classList.toggle('phase-current', Number(node.dataset.phase) === index);
            });
            links.forEach((link, position) => {
                if (position === index) link.setAttribute('aria-current', 'step');
                else link.removeAttribute('aria-current');
            });
            steps.forEach((step, position) => step.classList.toggle('chapter-current', index === position));
        };
        const sceneMotion = () => {
            scene.classList.toggle('scene-enhanced', !reduced.matches && 'IntersectionObserver' in window);
        };
        sceneMotion();
        reduced.addEventListener('change', sceneMotion, { signal });
        setStep(0);
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                for (const entry of entries) {
                    if (entry.isIntersecting) setStep(Number(entry.target.dataset.storyStep));
                }
            }, { rootMargin: '-35% 0px -45% 0px', threshold: 0 });
            steps.forEach(step => observer.observe(step));
            observers.push(observer);
        }
        restorers.push(() => {
            scene.classList.remove('scene-enhanced');
            scene.style.removeProperty('--story-progress');
            scene.querySelectorAll('[data-phase]').forEach(node => node.classList.remove('phase-visible', 'phase-current'));
            steps.forEach(step => step.classList.remove('chapter-current'));
            links.forEach(link => link.removeAttribute('aria-current'));
            delete story.dataset.step;
        });
    });

    const contactForm = document.querySelector('form[data-contact-form]');
    if (contactForm) {
        const submit = contactForm.querySelector('[type="submit"]');
        const status = contactForm.querySelector('[data-submit-feedback]');
        contactForm.addEventListener('submit', event => {
            if (!navigator.onLine) {
                event.preventDefault();
                status.textContent = 'You’re offline. Your message has not been sent. Reconnect and submit it yourself; it will not be queued.';
                return;
            }
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            status.textContent = 'Storing your enquiry and preparing the email notification…';
        }, { signal });
        window.addEventListener('pageshow', () => {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            status.textContent = '';
        }, { signal });
        restorers.push(() => {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            status.textContent = '';
        });
    }

    const stopMotion = () => {
        if (reduced.matches) animations.forEach(animation => animation.cancel());
    };
    reduced.addEventListener('change', stopMotion, { signal });
    let refreshQueued = false;
    const mutationObserver = new MutationObserver(records => {
        if (refreshQueued || !records.some(record => record.addedNodes.length)) return;
        refreshQueued = true;
        queueMicrotask(() => {
            refreshQueued = false;
            if (signal.aborted) return;
            const replacedWidget = records.some(record =>
                record.target.closest?.('[data-showcase],[data-story]') ||
                [...record.addedNodes].some(node => node.nodeType === 1 && (node.matches('[data-showcase],[data-story]') || node.querySelector('[data-showcase],[data-story]')))
            );
            if (replacedWidget) startExperience();
            else refresh();
        });
    });
    const main = document.querySelector('main');
    if (main) mutationObserver.observe(main, { childList: true, subtree: true });

    return () => {
        controller.abort();
        mutationObserver.disconnect();
        observers.forEach(observer => observer.disconnect());
        animations.forEach(animation => animation.cancel());
        restorers.forEach(restore => restore());
    };
}

let dispose = () => {};
export function startExperience() {
    dispose();
    dispose = mountExperience();
}
document.addEventListener('livewire:navigating', () => dispose());
document.addEventListener('livewire:navigated', startExperience);
window.addEventListener('pagehide', () => dispose());
window.addEventListener('pageshow', event => { if (event.persisted) startExperience(); });
