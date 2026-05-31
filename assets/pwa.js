/*
 * Progressive-web-app glue: register the service worker and capture the
 * install prompt WITHOUT showing Chrome's automatic banner. The install is
 * only offered via the discreet "/app" page (a [data-install-app] button).
 */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // suppress the auto mini-infobar — we offer it on demand
    deferredPrompt = e;
    document.documentElement.classList.add('can-install');
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    document.documentElement.classList.remove('can-install');
});

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-install-app]');
    if (!btn || !deferredPrompt) return;
    e.preventDefault();
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    document.documentElement.classList.remove('can-install');
});
