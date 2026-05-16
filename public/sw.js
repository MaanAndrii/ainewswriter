const CACHE = 'newswriter-v1';
const STATIC = [
  '/',
  '/public/assets/newswriter.css',
  '/public/assets/newswriter.js',
  '/manifest.json'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // Only cache GET requests for static assets
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  // Don't cache API calls
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin/')) return;

  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request))
  );
});
