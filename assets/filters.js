/*
 * Remember the chosen filters (search, categories, place, period) in
 * localStorage and re-apply them on a later visit to a filter view that
 * carries no filters of its own. "Meine Events" and pagination are excluded.
 */

import { local, session } from './storage.js';

const KEY = 'dalketicker:filters';

// Scalar keys worth remembering. The custom date range (von/bis) is
// deliberately NOT among them: absolute dates go stale, and replaying a June
// range in July would silently redirect to an empty list. The relative
// presets (zeitraum) stay meaningful across sessions.
const REMEMBERED_KEYS = ['q', 'ort', 'zeitraum', 'kurse'];
// Keys that mark a URL as "carries its own filters" (so memory never
// overrides it) — the remembered ones plus the date range.
const FILTER_KEYS = [...REMEMBERED_KEYS, 'von', 'bis'];
// Categories arrive as kategorie[] from the forms, as plain kategorie, or as
// indexed kategorie[0..n] from server-generated links (http_build_query).
const CATEGORY_KEY = /^kategorie(\[\d*\])?$/;

function filterSlice(sp) {
    const out = new URLSearchParams();
    for (const k of REMEMBERED_KEYS) {
        const v = sp.get(k);
        if (v) out.set(k, v);
    }
    for (const [k, v] of sp) {
        if (v && CATEGORY_KEY.test(k)) out.append('kategorie[]', v);
    }
    return out;
}

function hasFilterKeys(sp) {
    return [...sp.keys()].some((k) => FILTER_KEYS.includes(k) || CATEGORY_KEY.test(k));
}

/** Store the filter slice, or forget it when there is nothing left. */
function remember(slice) {
    if (slice) {
        local.set(KEY, slice);
    } else {
        local.remove(KEY);
    }
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
        local.set(KEY, current);
        session.set(APPLIED, '1');
    } else if (hasFilterKeys(params)) {
        // The form submitted explicitly empty filters (it always sends
        // q=&ort=&…) → clear the memory so deselected filters don't
        // resurrect on the next visit.
        local.remove(KEY);
        session.set(APPLIED, '1');
    } else if (!hasMeine && !session.get(APPLIED)) {
        // First arrival this session with no filter → re-apply the last
        // remembered one. After that we leave the user's choices alone, so
        // deselecting the last filter actually clears the view.
        session.set(APPLIED, '1');
        const saved = local.get(KEY);
        if (saved) {
            window.location.replace(window.location.pathname + '?' + saved);
            return;
        }
    }

    // Links that drop filters without going through the form ("Filter
    // zurücksetzen", the category "alle" link) sync the memory to whatever
    // their target URL still carries. Their target may have no filter keys
    // at all, in which case the arrival logic above would leave the old
    // memory untouched — and the dropped categories would come back on the
    // next visit.
    document.querySelectorAll('[data-reset-filters]').forEach((a) => {
        a.addEventListener('click', () => {
            remember(filterSlice(new URL(a.href, window.location.href).searchParams).toString());
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFilterMemory);
} else {
    initFilterMemory();
}
