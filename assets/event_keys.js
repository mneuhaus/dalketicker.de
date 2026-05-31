/*
 * Keyboard navigation on the event detail page.
 *
 *   ←  previous event in the current (filtered) list
 *   →  next event
 *   ↑  remember (bookmark) this event
 *   ↓  un-remember it
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

        const saveBtn = document.querySelector('[data-save-btn]');
        const isSaved = saveBtn ? saveBtn.getAttribute('aria-pressed') === 'true' : false;

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
            case 'ArrowUp':
                // Remember — only toggle if not already saved.
                if (saveBtn && !isSaved) { e.preventDefault(); saveBtn.click(); }
                break;
            case 'ArrowDown':
                // Un-remember — only toggle if currently saved.
                if (saveBtn && isSaved) { e.preventDefault(); saveBtn.click(); }
                break;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
