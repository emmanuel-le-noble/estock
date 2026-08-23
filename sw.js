const CACHE_NAME = 'estock-v1';
const STATIC_ASSETS = [
    './',
    './tableau_bord.php',
    './caisse.php',
    './articles.php',
    './stock.php',
    './mouvements.php',
    './assets/css/style.css',
    './assets/js/app.js',
    './assets/js/caisse.js',
    './assets/images/logo-eStock.png',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(STATIC_ASSETS);
        }).then(function () {
            return self.skipWaiting();
        })
    );
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

    if (url.pathname.indexOf('/api/') !== -1) {
        e.respondWith(
            fetch(e.request).catch(function () {
                return new Response(JSON.stringify({ error: 'Hors-ligne', found: false }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    e.respondWith(
        caches.match(e.request).then(function (cached) {
            var fetched = fetch(e.request).then(function (response) {
                if (response && response.status === 200) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(e.request, clone);
                    });
                }
                return response;
            }).catch(function () {
                return cached;
            });
            return cached || fetched;
        })
    );
});
