/*
 * Minimal service worker — just enough to make dalketicker installable as a
 * home-screen app. Network-first with a cache fallback for basic offline
 * resilience; no aggressive caching, no tracking.
 */
const CACHE = 'dalke-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (e) => {
    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request)
            .then((res) => {
                // Keep a copy of successful same-origin GETs for offline fallback.
                if (res.ok && new URL(e.request.url).origin === self.location.origin) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(e.request, copy)).catch(() => {});
                }
                return res;
            })
            .catch(() => caches.match(e.request)),
    );
});
