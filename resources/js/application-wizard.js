export function mountApplicationWizard(form) {
    if (form.dataset.wizardReady) return;
    const steps = [...form.querySelectorAll('[data-wizard-step]')];
    const controls = form.querySelector('[data-wizard-controls]');
    const back = form.querySelector('[data-wizard-back]');
    const next = form.querySelector('[data-wizard-next]');
    const progress = form.querySelector('[data-wizard-progress]');
    const feedback = form.querySelector('[data-wizard-feedback]');
    const review = form.querySelector('[data-wizard-review]');
    let current = 0;
    const conditions = () => {
        for (const group of form.querySelectorAll('[data-condition-field]')) {
            const control = form.elements.namedItem(group.dataset.conditionField);
            const relevant = control?.value === group.dataset.conditionValue;
            group.hidden = !relevant;
            group.querySelectorAll('input,select,textarea').forEach(input => {
                input.disabled = !relevant;
                input.required = relevant;
            });
        }
    };
    const renderReview = () => {
        review.replaceChildren();
        steps.slice(0, -1).forEach(step => {
            const heading = document.createElement('h3');
            heading.textContent = step.querySelector('legend').textContent;
            const list = document.createElement('dl');
            list.className = 'details-list';
            const collected = new Set();
            step.querySelectorAll('input,select,textarea').forEach(input => {
                if (input.disabled || input.type === 'hidden' || (input.type === 'checkbox' && !input.checked) || collected.has(input.name)) return;
                collected.add(input.name);
                const title = document.createElement('dt');
                const label = input.closest('.field')?.querySelector(input.type === 'checkbox' ? 'legend' : 'label');
                title.textContent = label?.textContent || input.name.replaceAll('_',' ');
                const value = document.createElement('dd');
                value.textContent = input.type === 'checkbox' ? [...step.querySelectorAll('input[type="checkbox"]')].filter(item => item.name === input.name && item.checked).map(item => item.value).join(', ') : input.value || 'Not supplied';
                list.append(title, value);
            });
            review.append(heading, list);
        });
    };
    const show = index => {
        current = index;
        steps.forEach((step, position) => { step.hidden = position !== current; });
        progress.textContent = 'Step ' + (current + 1) + ' of ' + steps.length + ' — ' + steps[current].querySelector('legend').textContent;
        back.disabled = current === 0;
        next.hidden = current === steps.length - 1;
        if (current === steps.length - 1) renderReview();
        feedback.textContent = '';
    };
    const valid = step => {
        for (const input of step.querySelectorAll('input,select,textarea')) {
            if (!input.disabled && !input.checkValidity()) { input.reportValidity(); return false; }
        }
        for (const choices of step.querySelectorAll('[data-required-choices]')) {
            if (!choices.querySelector('input:checked')) {
                feedback.textContent = 'Please choose at least one capability.';
                choices.querySelector('input').focus();
                return false;
            }
        }
        return true;
    };
    form.addEventListener('change', conditions);
    next.addEventListener('click', () => {
        conditions();
        if (valid(steps[current])) {
            show(current + 1);
            steps[current].scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        }
    });
    back.addEventListener('click', () => show(Math.max(0, current - 1)));
    form.addEventListener('submit', event => {
        conditions();
        for (let index = 0; index < steps.length; index++) {
            show(index);
            if (!valid(steps[index])) { event.preventDefault(); return; }
        }
        if (!navigator.onLine) {
            event.preventDefault();
            feedback.textContent = 'You are offline. Reconnect and submit yourself; applications are not queued.';
        }
    });
    form.noValidate = true;
    conditions();
    controls.hidden = false;
    show(0);
    form.dataset.wizardReady = 'true';
}
document.querySelectorAll('[data-application-wizard]').forEach(mountApplicationWizard);
