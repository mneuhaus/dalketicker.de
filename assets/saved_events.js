/*
 * "Meine Events" — client-side bookmarks.
 *
 * Saved event IDs live only in this browser's localStorage; nothing is sent to
 * the server except, on demand, the ID list used by the "Nur meine Events"
 * filter. No personal data is processed and no consent is needed (user-
 * requested functionality under § 25 TDDDG).
 */

const KEY = 'dalketicker:saved';

function read() {
    try {
        const raw = JSON.parse(localStorage.getItem(KEY) || '[]');
        return Array.isArray(raw) ? raw.map(String) : [];
    } catch (e) {
        return [];
    }
}

function write(ids) {
    localStorage.setItem(KEY, JSON.stringify(ids));
}

function toggle(id) {
    id = String(id);
    const ids = read();
    const i = ids.indexOf(id);
    if (i === -1) {
        ids.push(id);
    } else {
        ids.splice(i, 1);
    }
    write(ids);
    return i === -1; // true if now saved
}

function onlySavedActive() {
    return new URLSearchParams(location.search).get('meine') === '1';
}

/** Reflect saved state on bookmark buttons, count badges and the hidden ID field. */
function refresh() {
    const ids = read();

    document.querySelectorAll('[data-save-btn]').forEach((btn) => {
        const saved = ids.includes(String(btn.dataset.eventId));
        btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
        btn.classList.toggle('is-saved', saved);
        btn.title = saved ? 'Gemerkt – zum Entfernen tippen' : 'Merken';
    });

    document.querySelectorAll('[data-save-count]').forEach((el) => {
        el.textContent = ids.length;
        el.classList.toggle('hidden', ids.length === 0);
    });

    // Keep the hidden IDs field (used by the "meine Events" filter) in sync; it
    // only submits while the "meine" view is active.
    const idsInput = document.querySelector('[data-saved-ids-input]');
    if (idsInput) {
        idsInput.value = ids.join(',');
        idsInput.disabled = !onlySavedActive();
    }
}

function init() {
    refresh();

    // Navbar "Meine Events": toggle the saved-only view, injecting the stored IDs.
    const meineNav = document.querySelector('[data-meine-nav]');
    if (meineNav) {
        meineNav.addEventListener('click', (e) => {
            e.preventDefault();
            if (onlySavedActive()) {
                window.location.href = '/';
                return;
            }
            const ids = read();
            window.location.href = '/?meine=1' + (ids.length ? '&ids=' + encodeURIComponent(ids.join(',')) : '');
        });
    }

    // Bookmark buttons (delegated; works for dynamically present cards too).
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-save-btn]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const nowSaved = toggle(btn.dataset.eventId);
        refresh();

        // When viewing the filtered "meine Events" list, an unsaved card should
        // disappear right away.
        if (!nowSaved && onlySavedActive()) {
            const card = btn.closest('[data-event-card]');
            if (card) card.remove();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
