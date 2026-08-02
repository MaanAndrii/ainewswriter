const VERSION = '{{APP_VERSION}}';
const CACHE   = 'newswriter-v' + VERSION;
const STATIC  = [
  '/public/assets/newswriter.css?v=' + VERSION,
  '/public/assets/newswriter.js?v='  + VERSION,
  '/public/assets/fonts/fonts.css',
  '/public/assets/fonts/roboto-400.ttf',
  '/public/assets/fonts/roboto-500.ttf',
  '/public/assets/fonts/roboto-700.ttf',
  '/public/assets/fonts/roboto-mono-400.ttf',
  '/public/assets/fonts/roboto-mono-500.ttf',
  '/manifest.json'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c =>
      Promise.allSettled(STATIC.map(url => c.add(url)))
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin/')) return;

  // Bypass SW entirely for HTML and JS — always fetch fresh from network.
  // Cache only fonts / css / manifest as offline fallback.
  const isHtml = e.request.destination === 'document' || url.pathname === '/' || url.pathname.endsWith('.html');
  const isJs   = url.pathname.endsWith('.js');
  if (isHtml || isJs) return; // don't intercept — browser handles directly

  e.respondWith(
    fetch(e.request)
      .then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => { try { c.put(e.request, clone); } catch(_) {} });
        }
        return res;
      })
      .catch(() => caches.match(e.request))
  );
});

// Повідомляємо відкриті вкладки про активацію нового SW
self.addEventListener('activate', () => {
  self.clients.matchAll({ type: 'window' }).then(clients => {
    clients.forEach(client => client.postMessage({ type: 'SW_UPDATED' }));
  });
});
