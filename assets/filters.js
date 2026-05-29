/*
 * Remember the chosen filters (search, categories, place, period) in
 * localStorage and re-apply them on a later visit to a filter view that
 * carries no filters of its own. "Meine Events" and pagination are excluded.
 */

const KEY = 'dalketicker:filters';

function filterSlice(sp) {
    const out = new URLSearchParams();
    for (const k of ['q', 'ort', 'zeitraum', 'von', 'bis']) {
        const v = sp.get(k);
        if (v) out.set(k, v);
    }
    // categories arrive as kategorie[] (tolerate plain kategorie too)
    for (const v of sp.getAll('kategorie[]')) out.append('kategorie[]', v);
    for (const v of sp.getAll('kategorie')) out.append('kategorie[]', v);
    return out;
}

function initFilterMemory() {
    // Only act on the filter views (they render the sidebar form).
    if (!document.querySelector('form[data-autosubmit]')) return;

    const params = new URLSearchParams(window.location.search);
    const hasMeine = params.get('meine') === '1';
    const current = filterSlice(params).toString();

    if (current) {
        // A filter is active in the URL → remember it.
        localStorage.setItem(KEY, current);
    } else if (!hasMeine) {
        // No filter in the URL → re-apply the last remembered one.
        const saved = localStorage.getItem(KEY);
        if (saved) {
            window.location.replace(window.location.pathname + '?' + saved);
            return;
        }
    }

    // The reset link clears the memory.
    document.querySelectorAll('[data-reset-filters]').forEach((a) => {
        a.addEventListener('click', () => localStorage.removeItem(KEY));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFilterMemory);
} else {
    initFilterMemory();
}
