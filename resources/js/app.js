const menuToggle = document.querySelector('[data-menu-toggle]');
document.querySelectorAll('[data-remove-criterion]').forEach(button => button.addEventListener('click', () => button.closest('[data-criterion]').remove()));
document.querySelectorAll('[data-network-add-criterion]').forEach(button => {
    button.addEventListener('click', () => {
        const index = Number(button.dataset.next);
        if (button.parentElement.querySelectorAll('[data-criterion]').length >= 10) return;
        const row = document.createElement('div');
        row.className = 'network-criteria';
        row.dataset.criterion = '';
        for (const [key, text, type] of [['name', 'Criterion', 'text'], ['weight', 'Weight %', 'number']]) {
            const label = document.createElement('label');
            label.textContent = text;
            const input = document.createElement('input');
            input.type = type;
            input.name = 'criteria[' + index + '][' + key + ']';
            input.required = true;
            if (key === 'name') input.maxLength = 100;
            else { input.min = '0.01'; input.max = '100'; input.step = '0.01'; }
            label.append(input);
            row.append(label);
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button button-secondary';
        remove.textContent = 'Remove criterion';
        remove.addEventListener('click', () => row.remove());
        row.append(remove);
        button.parentElement.querySelector('[data-criteria-rows]').append(row);
        button.dataset.next = String(index + 1);
        row.querySelector('input').focus();
    });
});
document.querySelectorAll('[data-network-add-agenda]').forEach(button => {
    button.addEventListener('click', () => {
        const index = Number(button.dataset.next);
        if (index >= 20) return;
        const row = document.createElement('fieldset');
        const legend = document.createElement('legend');
        legend.textContent = 'Agenda session ' + (index + 1);
        row.append(legend);
        for (const [key, title, type] of [['title', 'Session title', 'text'], ['starts_at', 'Starts in event timezone', 'datetime-local'], ['ends_at', 'Ends in event timezone', 'datetime-local']]) {
            const label = document.createElement('label');
            label.textContent = title;
            const input = document.createElement('input');
            input.type = type;
            input.name = 'agenda[' + index + '][' + key + ']';
            input.required = true;
            if (key === 'title') input.maxLength = 180;
            label.append(input);
            row.append(label);
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button button-secondary';
        remove.textContent = 'Remove session';
        remove.addEventListener('click', () => row.remove());
        row.append(remove);
        button.parentElement.querySelector('[data-agenda-rows]').append(row);
        button.dataset.next = String(index + 1);
        row.querySelector('input').focus();
    });
});
if (document.querySelector('[data-career-theme], [data-cv-preview], [data-cv-paper-viewport]')) import('./career.js');
if (document.querySelector('[data-opportunity-editor], [data-group-application]')) import('./opportunities.js');
if (document.querySelector('[data-app-shell]')) import('./workspace.js');
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
        const input = button.parentElement.querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
        button.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
    });
});
if (document.querySelector('[data-analytics]')) {
    import('./analytics.js').catch(() => {
        document.querySelector('[data-analytics-status]').textContent = 'Interactive charts are unavailable. Summaries, data tables and CSV exports remain available.';
    });
}
if (document.querySelector('[data-application-wizard]')) {
    import('./application-wizard.js').catch(() => {});
}
if (document.querySelector('.marketing-header')) {
    import('./experience.js').then(module => module.startExperience()).catch(() => {
        // All content and links remain usable when enhancements are unavailable.
    });
}
const publicNavigation = document.querySelector('#public-navigation');
if (menuToggle && publicNavigation) {
    menuToggle.hidden = false;
    publicNavigation.classList.add('menu-enhanced');
    const closeMenu = () => {
        menuToggle.setAttribute('aria-expanded', 'false');
        publicNavigation.classList.remove('menu-open');
    };
    menuToggle.addEventListener('click', () => {
        const open = menuToggle.getAttribute('aria-expanded') !== 'true';
        menuToggle.setAttribute('aria-expanded', String(open));
        publicNavigation.classList.toggle('menu-open', open);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
            closeMenu();
            menuToggle.focus();
        }
    });
    publicNavigation.addEventListener('click', event => {
        if (event.target.closest('a')) closeMenu();
    });
}

document.querySelectorAll('[data-open-dialog]').forEach(button => {
    button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal());
});

let installPrompt;
window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    document.querySelectorAll('[data-install]').forEach(button => { button.hidden = false; });
});
document.querySelectorAll('[data-install]').forEach(button => {
    button.addEventListener('click', async () => {
        if (!installPrompt) return;
        await installPrompt.prompt();
        await installPrompt.userChoice;
        installPrompt = undefined;
        document.querySelectorAll('[data-install]').forEach(item => { item.hidden = true; });
    });
});
window.addEventListener('appinstalled', () => {
    installPrompt = undefined;
    document.querySelectorAll('[data-install]').forEach(button => { button.hidden = true; });
});

const connectionStatus = document.querySelector('[data-connection-status]');
const announceConnection = () => {
    if (connectionStatus) connectionStatus.textContent = navigator.onLine ? 'Connection restored.' : 'You are offline. Workspace content needs an internet connection.';
};
window.addEventListener('online', announceConnection);
window.addEventListener('offline', announceConnection);

if ('serviceWorker' in navigator && window.isSecureContext && !import.meta.env.DEV) {
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
            const notice = document.querySelector('[data-update-notice]');
            const showUpdate = () => { if (registration.waiting && navigator.serviceWorker.controller && notice) notice.hidden = false; };
            showUpdate();
            registration.addEventListener('updatefound', () => {
                registration.installing?.addEventListener('statechange', showUpdate);
            });
            let updateRequested = false;
            document.querySelector('[data-update]')?.addEventListener('click', () => {
                if (registration.waiting) {
                    updateRequested = true;
                    registration.waiting.postMessage({ type: 'ACTIVATE_UPDATE' });
                }
            });
            document.querySelector('[data-dismiss-update]')?.addEventListener('click', () => { notice.hidden = true; });
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (updateRequested) window.location.reload();
            });
        } catch {
            if (connectionStatus) connectionStatus.textContent = 'Offline support is unavailable. You can continue online.';
        }
    });
}
if (document.querySelector('[data-ai-coach]')) import('./ai.js');
