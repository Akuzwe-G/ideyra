const shell = document.querySelector('[data-app-shell]');
const html = document.documentElement;
const sidebar = shell.querySelector('#app-sidebar');
const toggle = shell.querySelector('[data-sidebar-toggle]');
const backdrop = shell.querySelector('.drawer-backdrop');
const mobile = matchMedia('(max-width: 959px)');
const themeToggle = shell.querySelector('[data-theme-toggle]');
html.dataset.shellReady = 'true';
toggle.hidden = false;
themeToggle.hidden = false;
let drawerOpen = false;
const save = (key, value) => { try { localStorage.setItem(key, value); } catch { /* Preferences are optional. */ } };
function syncTheme() {
    const dark = html.dataset.theme === 'dark';
    shell.dataset.dashboardTheme = dark ? 'dark' : 'light';
    themeToggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    themeToggle.setAttribute('aria-pressed', String(dark));
    document.dispatchEvent(new CustomEvent('ideyra:themechange'));
}
themeToggle.addEventListener('click', () => {
    html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    save('ideyra-theme', html.dataset.theme);
    syncTheme();
});
function navigation() {
    html.dataset.drawer = mobile.matches && drawerOpen ? 'open' : 'closed';
    document.body.classList.toggle('drawer-open', mobile.matches && drawerOpen);
    sidebar.inert = mobile.matches && !drawerOpen;
    backdrop.hidden = !mobile.matches || !drawerOpen;
    shell.querySelector('.drawer-close').hidden = !mobile.matches;
    toggle.setAttribute('aria-expanded', String(mobile.matches ? drawerOpen : html.dataset.sidebar !== 'collapsed'));
    if (mobile.matches && drawerOpen) {
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        sidebar.setAttribute('aria-label', 'Workspace navigation');
    } else {
        sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal');
    }
}
toggle.addEventListener('click', () => {
    if (mobile.matches) {
        drawerOpen = !drawerOpen;
        navigation();
        if (drawerOpen) sidebar.querySelector('a').focus();
    } else {
        html.dataset.sidebar = html.dataset.sidebar === 'collapsed' ? 'expanded' : 'collapsed';
        save('ideyra-sidebar', html.dataset.sidebar);
        navigation();
    }
});
const closeDrawer = () => { drawerOpen = false; navigation(); toggle.focus(); };
shell.querySelectorAll('[data-drawer-close]').forEach(button => button.addEventListener('click', closeDrawer));
mobile.addEventListener('change', () => { drawerOpen = false; navigation(); });
const profile = shell.querySelector('[data-profile-menu]');
document.addEventListener('click', event => { if (!profile.contains(event.target)) profile.open = false; });
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        if (drawerOpen) closeDrawer();
        if (profile.open) { profile.open = false; profile.querySelector('summary').focus(); }
    }
    if (event.key === 'Tab' && mobile.matches && drawerOpen) {
        const controls = [...sidebar.querySelectorAll('a,button:not([hidden])')].filter(node => node.getClientRects().length);
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
const dialog = shell.querySelector('[data-confirm-dialog]');
let pendingForm = null;
let pendingSubmitter = null;
shell.querySelectorAll('form[method=POST]').forEach(form => {
    const path = new URL(form.action).pathname;
    if (/\/members\/\d+(\/permissions|\/invitation)?$/.test(path) || /\/system\/(roles(\/\d+)?|settings)$/.test(path)) {
        form.dataset.confirm = 'Confirm this access or configuration change? Review the selected values before continuing.';
    }
    if (form.querySelector('[name=current_password]')) {
        form.dataset.confirm = 'Change your password? Your other account sessions will be signed out.';
        form.dataset.confirmIf = 'password';
    }
});
shell.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.confirmIf && !form.querySelector('[name=' + form.dataset.confirmIf + ']')?.value) return;
        if (form.dataset.confirmed === 'true') { delete form.dataset.confirmed; return; }
        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter;
        const status = form.querySelector('[name=status]')?.selectedOptions?.[0]?.textContent;
        dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirm + (status ? ' Selected status: ' + status + '.' : '');
        dialog.returnValue = '';
        dialog.showModal();
        dialog.querySelector('[value=cancel]').focus();
    });
});
dialog.addEventListener('close', () => {
    if (dialog.returnValue === 'confirm' && pendingForm) {
        pendingForm.dataset.confirmed = 'true';
        if (pendingSubmitter) pendingForm.requestSubmit(pendingSubmitter);
        else pendingForm.requestSubmit();
    } else pendingSubmitter?.focus();
    pendingForm = null; pendingSubmitter = null;
});
shell.querySelectorAll('form:not([method=dialog])').forEach(form => {
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        form.setAttribute('aria-busy', 'true');
        const button = event.submitter;
        if (button) { button.setAttribute('aria-disabled', 'true'); button.classList.add('is-loading'); }
    });
});
navigation();
syncTheme();
window.addEventListener('pageshow', () => {
    shell.querySelectorAll('[aria-busy=true]').forEach(node => node.removeAttribute('aria-busy'));
    shell.querySelectorAll('.is-loading').forEach(node => { node.classList.remove('is-loading'); node.removeAttribute('aria-disabled'); });
    navigation();
});
