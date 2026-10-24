const menuToggle = document.querySelector('[data-menu-toggle]');
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
