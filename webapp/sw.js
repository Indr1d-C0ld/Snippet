/* snippet - service worker.
   Servito dalla root della webapp (scope = tutta l'app). Cache statica per
   gli asset, network-first per le navigazioni con fallback offline.html.
   Non tocca mai le richieste non-GET (POST verso ingest/compose). */

const CACHE = 'snippet-v1';
const PRECACHE = [
  'assets/style.css',
  'assets/pwa.js',
  'assets/map.js',
  'assets/icon-192.png',
  'assets/icon-512.png',
  'manifest.php',
  'offline.html',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(PRECACHE))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Navigazioni: rete, poi cache della stessa pagina, poi offline.html.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(() =>
        caches.match(req).then((hit) => hit || caches.match('offline.html'))
      )
    );
    return;
  }

  // Asset: cache-first, con aggiornamento in background per /assets/.
  e.respondWith(
    caches.match(req).then((hit) => {
      const net = fetch(req)
        .then((res) => {
          if (res && res.ok && url.pathname.indexOf('/assets/') !== -1) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() => hit);
      return hit || net;
    })
  );
});
