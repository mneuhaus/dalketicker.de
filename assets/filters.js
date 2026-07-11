/*
 * Remember the chosen filters (search, categories, place, period) in
 * localStorage and re-apply them on a later visit to a filter view that
 * carries no filters of its own. "Meine Events" and pagination are excluded.
 */

const KEY = 'dalketicker:filters';

const SCALAR_KEYS = ['q', 'ort', 'zeitraum', 'von', 'bis', 'kurse'];
// Categories arrive as kategorie[] from the forms, as plain kategorie, or as
// indexed kategorie[0..n] from server-generated links (http_build_query).
const CATEGORY_KEY = /^kategorie(\[\d*\])?$/;

function filterSlice(sp) {
    const out = new URLSearchParams();
    for (const k of SCALAR_KEYS) {
        const v = sp.get(k);
        if (v) out.set(k, v);
    }
    for (const [k, v] of sp) {
        if (v && CATEGORY_KEY.test(k)) out.append('kategorie[]', v);
    }
    return out;
}

function hasFilterKeys(sp) {
    return [...sp.keys()].some((k) => SCALAR_KEYS.includes(k) || CATEGORY_KEY.test(k));
}

function initFilterMemory() {
    // Only act on the filter views (they render the sidebar form).
    if (!document.querySelector('form[data-autosubmit]')) return;

    const params = new URLSearchParams(window.location.search);
    const hasMeine = params.get('meine') === '1';
    const current = filterSlice(params).toString();

    const APPLIED = 'dalketicker:filtersApplied';

    if (current) {
        // A filter is active in the URL → remember it (and mark this session
        // as "already filtering" so we don't fight later changes).
        localStorage.setItem(KEY, current);
        sessionStorage.setItem(APPLIED, '1');
    } else if (hasFilterKeys(params)) {
        // The form submitted explicitly empty filters (it always sends
        // q=&ort=&…) → clear the memory so deselected filters don't
        // resurrect on the next visit.
        localStorage.removeItem(KEY);
        sessionStorage.setItem(APPLIED, '1');
    } else if (!hasMeine && !sessionStorage.getItem(APPLIED)) {
        // First arrival this session with no filter → re-apply the last
        // remembered one. After that we leave the user's choices alone, so
        // deselecting the last filter actually clears the view.
        sessionStorage.setItem(APPLIED, '1');
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
