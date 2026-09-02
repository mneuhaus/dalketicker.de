/*
 * Best-effort wrappers around localStorage / sessionStorage.
 *
 * With site data blocked (private mode on some browsers, "block all cookies",
 * strict tracking protection) merely touching window.localStorage throws a
 * SecurityError. Everything we keep there (filters, bookmarks, the dismissed
 * install hint) is a convenience the site works fine without, so a failing
 * read behaves like "nothing stored" and a failing write is simply dropped
 * instead of aborting the whole module.
 */

function wrap(name) {
    return {
        get(key) {
            try { return window[name].getItem(key); } catch { return null; }
        },
        set(key, value) {
            try { window[name].setItem(key, value); } catch { /* blocked or quota exceeded */ }
        },
        remove(key) {
            try { window[name].removeItem(key); } catch { /* blocked */ }
        },
    };
}

export const local = wrap('localStorage');
export const session = wrap('sessionStorage');
