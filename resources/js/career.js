const paperViewport = document.querySelector('[data-cv-paper-viewport]');
if (paperViewport) {
    const paper = paperViewport.querySelector('.cv-document');
    const sizeButton = document.querySelector('[data-cv-paper-size]');
    let actualSize = false;
    let lastWidth = -1;
    const fit = () => {
        lastWidth = paperViewport.clientWidth;
        paper.style.zoom = String(actualSize ? 1 : Math.min(1, lastWidth / 794));
        sizeButton.textContent = actualSize ? 'Fit page to screen' : 'Actual size (100%)';
        sizeButton.setAttribute('aria-pressed', String(actualSize));
    };
    if (CSS.supports('zoom', '1')) {
        sizeButton.addEventListener('click', () => { actualSize = !actualSize; fit(); });
        const observer = new ResizeObserver(() => {
            if (Math.abs(lastWidth - paperViewport.clientWidth) > .5) requestAnimationFrame(fit);
        });
        observer.observe(paperViewport);
        window.addEventListener('pagehide', () => observer.disconnect());
        window.addEventListener('pageshow', () => { observer.observe(paperViewport); fit(); });
        document.fonts.ready.then(fit);
        fit();
    } else {
        sizeButton.hidden = true;
    }
}

document.querySelectorAll('iframe[name="cv-preview-frame"]').forEach(frame => {
    frame.addEventListener('load', () => {
        const form = document.querySelector('#cv-editor');
        form?.removeAttribute('aria-busy');
        const button = form?.querySelector('[data-cv-preview]');
        button?.removeAttribute('aria-disabled');
        button?.classList.remove('is-loading');
    });
});

document.querySelectorAll('[data-career-theme]').forEach(button => {
    button.addEventListener('click', () => {
        const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try { localStorage.setItem('ideyra-theme', theme); } catch {}
        button.setAttribute('aria-label', `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`);
    });
});
