/*
 * Minimal service worker — just enough to make dalketicker installable as a
 * home-screen app. Network-first with a cache fallback for basic offline
 * resilience; no aggressive caching, no tracking. Symfony HTML responses are
 * "no-cache, private" and therefore never cached, so a small offline shell is
 * precached at install time and served for navigations that fail offline.
 *
 * IMPORTANT: never cache authenticated/private areas (admin, login, logout) or
 * responses marked private/no-store — otherwise admin pages and contact
 * personal data could linger in the browser cache after logout.
 */
const CACHE = 'dalke-v4';

// Authenticated / private paths that must never be written to the cache.
const NO_CACHE = /^\/(admin|login|logout)(\/|$)/;

// Synthetic cache key for the precached offline shell (no real route).
const OFFLINE_URL = '/offline.html';
const OFFLINE_HTML = `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline</title>
<style>
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: ui-sans-serif, system-ui, sans-serif; background: #faf9f6; color: #1b1d1e; }
  main { text-align: center; padding: 2rem; max-width: 26rem; }
  h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
  p { margin: 0 0 1.25rem; color: rgba(27, 29, 30, 0.7); }
  button { border: 0; border-radius: 9999px; padding: 0.6rem 1.4rem; font: inherit; font-weight: 600; color: #fff; background: #0a8da3; cursor: pointer; }
</style>
</head>
<body>
<main>
<h1>Gerade offline</h1>
<p>Diese Seite ist ohne Internetverbindung nicht verfügbar. Sobald du wieder online bist, geht es hier weiter.</p>
<button onclick="location.reload()">Erneut versuchen</button>
</main>
</body>
</html>`;

// Cap the cache so it can't grow unbounded; entries come back in insertion
// order, so dropping from the front evicts the oldest ones first. The offline
// shell (inserted first, at install) is exempt from eviction.
const MAX_ENTRIES = 120;
const trim = async (cache) => {
    const keys = (await cache.keys()).filter((req) => new URL(req.url).pathname !== OFFLINE_URL);
    if (keys.length > MAX_ENTRIES) {
        await Promise.all(keys.slice(0, keys.length - MAX_ENTRIES).map((k) => cache.delete(k)));
    }
};

self.addEventListener('install', (e) => e.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await cache.put(OFFLINE_URL, new Response(OFFLINE_HTML, {
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
    }));
    await self.skipWaiting();
})()));

self.addEventListener('activate', (e) => e.waitUntil((async () => {
    // Drop older caches (v1 may still hold admin/contact responses from before
    // this fix) so they're evicted on every client.
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
    await self.clients.claim();
})()));

self.addEventListener('fetch', (e) => {
    if (e.request.method !== 'GET') return;
    const url = new URL(e.request.url);
    e.respondWith(
        fetch(e.request)
            .then((res) => {
                const cc = res.headers.get('Cache-Control') || '';
                const cacheable = res.ok
                    && url.origin === self.location.origin
                    && !NO_CACHE.test(url.pathname)
                    && !/no-store|private/i.test(cc);
                if (cacheable) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(e.request, copy).then(() => trim(c))).catch(() => {});
                }
                return res;
            })
            .catch(async () => {
                const cached = await caches.match(e.request);
                if (cached) return cached;
                // Pages are never cached (private), so failed navigations get
                // the precached offline shell instead of a browser error page.
                if (e.request.mode === 'navigate') {
                    const offline = await caches.match(OFFLINE_URL);
                    if (offline) return offline;
                }
                return Response.error();
            }),
    );
});
