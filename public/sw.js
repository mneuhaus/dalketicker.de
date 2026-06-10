/*
 * Minimal service worker — just enough to make dalketicker installable as a
 * home-screen app. Network-first with a cache fallback for basic offline
 * resilience; no aggressive caching, no tracking.
 *
 * IMPORTANT: never cache authenticated/private areas (admin, login, logout) or
 * responses marked private/no-store — otherwise admin pages and contact
 * personal data could linger in the browser cache after logout.
 */
const CACHE = 'dalke-v3';

// Authenticated / private paths that must never be written to the cache.
const NO_CACHE = /^\/(admin|login|logout)(\/|$)/;

// Cap the cache so it can't grow unbounded; entries come back in insertion
// order, so dropping from the front evicts the oldest ones first.
const MAX_ENTRIES = 120;
const trim = async (cache) => {
    const keys = await cache.keys();
    if (keys.length > MAX_ENTRIES) {
        await Promise.all(keys.slice(0, keys.length - MAX_ENTRIES).map((k) => cache.delete(k)));
    }
};

self.addEventListener('install', () => self.skipWaiting());

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
            .catch(() => caches.match(e.request)),
    );
});
