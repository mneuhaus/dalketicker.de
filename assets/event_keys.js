/*
 * Keyboard navigation on the event detail page.
 *
 *   ←  previous event in the current (filtered) list
 *   →  next event
 *   m  merken / nicht mehr merken (toggle the bookmark)
 *
 * Up/down are deliberately NOT bound: they are the primary keyboard scroll
 * keys, and hijacking them makes a long detail page unscrollable without a
 * mouse.
 *
 * Reads the neighbour URLs from the [data-event-nav] container and reuses the
 * existing bookmark button (saved_events.js) so there is a single source of
 * truth for the saved state.
 */

function init() {
    const nav = document.querySelector('[data-event-nav]');
    if (!nav) return; // only on the detail page

    document.addEventListener('keydown', (e) => {
        // Don't hijack typing or browser shortcuts.
        if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
        const t = e.target;
        if (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;

        switch (e.key) {
            case 'ArrowLeft': {
                const url = nav.dataset.prevUrl;
                if (url) { e.preventDefault(); window.location.href = url; }
                break;
            }
            case 'ArrowRight': {
                const url = nav.dataset.nextUrl;
                if (url) { e.preventDefault(); window.location.href = url; }
                break;
            }
            case 'm':
            case 'M': {
                const saveBtn = document.querySelector('[data-save-btn]');
                if (saveBtn) { e.preventDefault(); saveBtn.click(); }
                break;
            }
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
