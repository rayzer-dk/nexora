/* Nexora public storefront: optional Workbox service worker.
 * No HTML, customer responses, checkout, API results or personal media are cached.
 * Web Push shares this registration without overwriting the caching worker. */
const NX_VERSION = '__VERSION__';
const WORKBOX_LIB = '/assets/workbox/v7.4.1/';
importScripts(WORKBOX_LIB + 'workbox-sw.js');
importScripts('/nexora-push-sw.js');

workbox.setConfig({ modulePathPrefix: WORKBOX_LIB, debug: false });
workbox.core.setCacheNameDetails({ prefix: 'nx-workbox', suffix: NX_VERSION });

const PUBLIC_ASSET_TYPES = new Set(['script', 'style', 'font', 'image']);
const STATIC_ASSET_TYPES = new Set(['font', 'image']);
const PRIVATE_PREFIXES = [
  '/admin', '/account', '/customer', '/cart', '/checkout', '/payment',
  '/login', '/logout', '/register', '/orders', '/api', '/graphql',
  '/webhooks', '/push', '/privacy', '/setup.php', '/upgrade.php', '/install'
];
const isPrivate = (path) => PRIVATE_PREFIXES.some((prefix) => path === prefix || path.startsWith(prefix + '/'));
const sameOrigin = ({ url, request }) =>
  request.method === 'GET' && url.origin === self.location.origin && !url.search && !isPrivate(url.pathname);
const cacheable = () => new workbox.cacheableResponse.CacheableResponsePlugin({ statuses: [200] });
const expiry = (maxEntries, maxAgeSeconds) =>
  new workbox.expiration.ExpirationPlugin({ maxEntries, maxAgeSeconds, purgeOnQuotaError: true });

// This is a static, anonymous fallback; SSR responses can contain store/customer data.
workbox.precaching.precacheAndRoute([{ url: '/offline-pwa.html', revision: NX_VERSION }]);

workbox.routing.registerRoute(
  (context) => sameOrigin(context) && context.url.pathname.startsWith('/build/') && PUBLIC_ASSET_TYPES.has(context.request.destination),
  new workbox.strategies.CacheFirst({
    cacheName: 'nx-workbox-build-' + NX_VERSION,
    plugins: [cacheable(), expiry(150, 30 * 24 * 3600)]
  })
);

// Public brand images and fonts only. Unhashed JS/CSS always go through the network.
workbox.routing.registerRoute(
  (context) => sameOrigin(context) && context.url.pathname.startsWith('/assets/') && STATIC_ASSET_TYPES.has(context.request.destination),
  new workbox.strategies.StaleWhileRevalidate({
    cacheName: 'nx-workbox-assets-' + NX_VERSION,
    plugins: [cacheable(), expiry(120, 14 * 24 * 3600)]
  })
);

// A network-only document with a generic offline fallback. No personalized HTML in Cache Storage.
workbox.routing.registerRoute(
  (context) => context.request.mode === 'navigate' && sameOrigin(context),
  async ({ event }) => {
    try {
      return await fetch(event.request);
    } catch {
      return (await workbox.precaching.matchPrecache('/offline-pwa.html')) || Response.error();
    }
  }
);

// Replace the legacy caches and remove superseded Nexora cache generations.
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = new Set([
      workbox.core.cacheNames.precache,
      'nx-workbox-build-' + NX_VERSION,
      'nx-workbox-assets-' + NX_VERSION
    ]);
    for (const name of await caches.keys()) {
      if (name.startsWith('nx-static-') || name.startsWith('nx-images-') ||
          (name.startsWith('nx-workbox-') && !keep.has(name))) {
        await caches.delete(name);
      }
    }
  })());
});
