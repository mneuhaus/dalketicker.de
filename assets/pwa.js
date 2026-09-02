/*
 * Progressive-web-app glue: register the service worker, offer Chrome's
 * install prompt on demand and show a small iOS home-screen hint.
 */

import { local } from './storage.js';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // The worker's precached offline shell is static markup, so it can't
        // read the region colour from the page — hand it over on the script
        // URL instead (constant per host, so the registration stays stable).
        const color = document.querySelector('meta[name="theme-color"]')?.content || '';
        navigator.serviceWorker.register('/sw.js?c=' + encodeURIComponent(color)).catch(() => {});
    });
}

const DISMISS_KEY = 'dalketicker.installBadge.dismissedAt';
const DISMISS_DAYS = 30;

let deferredPrompt = null;
let installBadge = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // suppress the auto mini-infobar — we offer it on demand
    deferredPrompt = e;
    document.documentElement.classList.add('can-install');
    showInstallBadge('prompt');
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    document.documentElement.classList.remove('can-install');
    hideInstallBadge(false);
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

document.addEventListener('DOMContentLoaded', () => {
    installBadge = document.querySelector('[data-install-badge]');
    document.documentElement.classList.toggle('has-surprise-actions', Boolean(document.querySelector('[data-surprise-actions]')));

    document.querySelector('[data-install-badge-close]')?.addEventListener('click', () => {
        rememberDismissed();
        hideInstallBadge(true);
    });

    document.querySelector('[data-install-badge-action]')?.addEventListener('click', async (e) => {
        if (!deferredPrompt) {
            rememberDismissed();
            return;
        }

        e.preventDefault();
        await promptInstall();
    });

    if (deferredPrompt) {
        showInstallBadge('prompt');
        return;
    }

    if (isAppleTouchDevice() && !isStandalone() && !isRecentlyDismissed()) {
        window.setTimeout(() => showInstallBadge('ios'), 900);
    }
});

function showInstallBadge(mode) {
    if (!installBadge || isStandalone() || isRecentlyDismissed()) {
        return;
    }

    installBadge.dataset.installMode = mode;
    installBadge.querySelectorAll('[data-install-badge-mode]').forEach((el) => {
        el.hidden = el.dataset.installBadgeMode !== mode;
    });

    installBadge.hidden = false;
    document.documentElement.classList.add('has-install-badge');
    window.requestAnimationFrame(() => installBadge?.classList.add('is-visible'));
}

function hideInstallBadge(animate) {
    if (!installBadge) {
        return;
    }

    installBadge.classList.remove('is-visible');
    document.documentElement.classList.remove('has-install-badge');

    if (!animate) {
        installBadge.hidden = true;
        return;
    }

    window.setTimeout(() => {
        if (!installBadge?.classList.contains('is-visible')) {
            installBadge.hidden = true;
        }
    }, 200);
}

async function promptInstall() {
    if (!deferredPrompt) {
        return;
    }

    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    deferredPrompt = null;
    document.documentElement.classList.remove('can-install');
    rememberDismissed();
    hideInstallBadge(true);
}

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function isAppleTouchDevice() {
    const ua = window.navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (ua.includes('Macintosh') && window.navigator.maxTouchPoints > 1);
}

function isRecentlyDismissed() {
    const raw = local.get(DISMISS_KEY);
    if (!raw) {
        return false;
    }

    const dismissedAt = Number.parseInt(raw, 10);
    return Number.isFinite(dismissedAt) && Date.now() - dismissedAt < DISMISS_DAYS * 24 * 60 * 60 * 1000;
}

function rememberDismissed() {
    // Best effort: with storage blocked the hint simply shows again next time.
    local.set(DISMISS_KEY, String(Date.now()));
}
