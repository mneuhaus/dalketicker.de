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
    const feedback = () => {
        if (!label) return;
        const prev = label.textContent;
        label.textContent = 'Link kopiert';
        window.setTimeout(() => { label.textContent = prev; }, 1600);
    };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(feedback).catch(() => {});
    } else {
        feedback();
    }
});
