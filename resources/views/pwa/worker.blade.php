const CACHE = 'ideyra-static-{{ $revision }}';
const ASSETS = @json($assets);
const OFFLINE = '/offline.html';
self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll([OFFLINE, ...ASSETS])));
});
self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('ideyra-static-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('message', event => {
    if (event.data?.type === 'ACTIVATE_UPDATE') self.skipWaiting();
});
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.open(CACHE).then(cache => cache.match(OFFLINE))));
        return;
    }
    if (!url.search && ASSETS.includes(url.pathname)) {
        event.respondWith(caches.open(CACHE).then(async cache => (await cache.match(request)) || fetch(request)));
    }
});
