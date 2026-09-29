/* Nexora storefront service worker (version __VERSION__).
 * Static assets are cached; HTML is never cached, so prices, stock and carts are always live.
 * Without a connection navigations fall back to the pre-cached /offline page. */
const VERSION = '__VERSION__';
const STATIC_CACHE = `nx-static-${VERSION}`;
const IMAGE_CACHE = `nx-images-${VERSION}`;
const OFFLINE_URL = '/offline';
const IMAGE_LIMIT = 150;
const PRIVATE_PREFIXES = ['/admin', '/account', '/checkout', '/cart', '/api', '/graphql', '/webhooks', '/privacy/consent', '/setup.php', '/install'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' }))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = new Set([STATIC_CACHE, IMAGE_CACHE]);
    for (const key of await caches.keys()) {
      if (key.startsWith('nx-') && !keep.has(key)) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

async function trim(cacheName, limit) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (const key of keys.slice(0, Math.max(0, keys.length - limit))) await cache.delete(key);
}

async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const hit = await cache.match(request);
  if (hit) return hit;
  const response = await fetch(request);
  if (response.ok) cache.put(request, response.clone());
  return response;
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(IMAGE_CACHE);
  const hit = await cache.match(request);
  const network = fetch(request).then((response) => {
    if (response.ok) { cache.put(request, response.clone()); trim(IMAGE_CACHE, IMAGE_LIMIT); }
    return response;
  }).catch(() => hit);
  return hit || network;
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (PRIVATE_PREFIXES.some((prefix) => url.pathname === prefix || url.pathname.startsWith(prefix + '/'))) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()));
    return;
  }
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(request));
    return;
  }
  if (url.pathname.startsWith('/media/') || request.destination === 'image') {
    event.respondWith(staleWhileRevalidate(request));
  }
});
