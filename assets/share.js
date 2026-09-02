/*
 * "Teilen" — uses the native share sheet (navigator.share) on mobile; falls
 * back to copying the link to the clipboard on desktop browsers without it.
 * Delegated, so it works for any element with [data-share].
 */
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-share]');
    if (!btn) return;
    e.preventDefault();

    const url = btn.dataset.shareUrl || window.location.href;
    const title = btn.dataset.shareTitle || document.title;

    if (navigator.share) {
        navigator.share({ title, url }).catch(() => {});
        return;
    }

    const label = btn.querySelector('[data-share-label]');
    const feedback = (ok) => {
        if (!label) return;
        // Keep the original label from the first click only: a second click
        // within the 1.6 s would otherwise capture "Link kopiert" as the text
        // to restore, and the feedback would stick.
        if (label.dataset.orig === undefined) label.dataset.orig = label.textContent;
        label.textContent = ok ? 'Link kopiert' : 'Kopieren fehlgeschlagen';
        window.setTimeout(() => { label.textContent = label.dataset.orig; }, 1600);
    };
    // Legacy copy for browsers without the async Clipboard API (or when it
    // rejects): a temporary textarea + execCommand('copy').
    const legacyCopy = () => {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { ok = false; }
        ta.remove();
        feedback(ok);
    };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => feedback(true)).catch(legacyCopy);
    } else {
        legacyCopy();
    }
});
