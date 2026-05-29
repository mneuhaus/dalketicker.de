/*
 * "Events merken" — client-side bookmarks.
 *
 * Saved event IDs live only in this browser's localStorage; nothing is sent to
 * the server, so there's no personal data processing and no consent needed
 * (the feature is user-requested functionality under § 25 TDDDG).
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

function isSaved(id) {
    return read().includes(String(id));
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
    return ids;
}

/** Reflect saved state on all bookmark buttons + count badges currently in DOM. */
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
}

/** On the "Gemerkt" page: load the saved events as rendered cards. */
async function renderSavedList() {
    const list = document.querySelector('[data-saved-list]');
    const empty = document.querySelector('[data-saved-empty]');
    if (!list) return;

    const ids = read();
    if (ids.length === 0) {
        if (empty) empty.classList.remove('hidden');
        list.innerHTML = '';
        return;
    }

    try {
        const res = await fetch('/gemerkt/liste?ids=' + encodeURIComponent(ids.join(',')), {
            headers: { 'X-Requested-With': 'fetch' },
        });
        const html = (await res.text()).trim();
        if (html === '' || !html.includes('data-save-btn')) {
            if (empty) empty.classList.remove('hidden');
            list.innerHTML = '';
        } else {
            if (empty) empty.classList.add('hidden');
            list.innerHTML = html;
            refresh();
        }
    } catch (e) {
        list.innerHTML = '<p class="text-ink/50">Konnte die gemerkten Veranstaltungen nicht laden.</p>';
    }
}

function init() {
    refresh();
    renderSavedList();

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-save-btn]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        toggle(btn.dataset.eventId);
        refresh();
        // If we're on the bookmarks page, removing the last one should re-render.
        if (document.querySelector('[data-saved-list]')) {
            renderSavedList();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
