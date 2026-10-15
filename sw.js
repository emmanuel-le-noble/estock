const CACHE_NAME = 'estock-v4';

self.addEventListener('install', function (e) {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (e) {
    var url = new URL(e.request.url);

    if (e.request.method !== 'GET') return;

    // API : network uniquement, pas de cache
    if (url.pathname.indexOf('/api/') !== -1) {
        e.respondWith(
            fetch(e.request).catch(function () {
                return new Response(JSON.stringify({ error: 'Hors-ligne', found: false }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }
                });
            })
        );
        return;
    }

    // Pages dynamiques et réponses avec paramètres : jamais mises en cache,
    // car elles peuvent contenir des données propres à une session.
    if (url.pathname.endsWith('.php') || url.pathname === '/' || url.search) {
        e.respondWith(
            fetch(e.request).catch(function () {
                return new Response('Hors-ligne', { status: 503, headers: { 'Content-Type': 'text/plain' } });
            })
        );
        return;
    }

    // Assets statiques (CSS, JS, images, fonts, CDN) : cache-first
    e.respondWith(
        caches.match(e.request).then(function (cached) {
            if (cached) return cached;
            return fetch(e.request).then(function (response) {
                if (response && response.status === 200 && response.type === 'basic') {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(e.request, clone);
                    });
                }
                return response;
            });
        })
    );
});
