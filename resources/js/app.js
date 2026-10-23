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
