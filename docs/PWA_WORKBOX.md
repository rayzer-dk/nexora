# Nexora: optional Workbox storefront cache

The existing Appearance → Storefront → PWA option controls the installable web-app manifest. The separate **Workbox caching** checkbox is OFF for new and existing stores. Enabling PWA alone does not start caching.

## Production build

`npm run build:production` builds Vite and vendors the pinned Workbox 7.4.1 release into `public/assets/workbox/v7.4.1/`. This requires internet access on the build machine the first time; browsers do **not** load Workbox from a third-party CDN. The downloaded files and their SHA-256 hashes are shipped in the release. If the libraries are absent, the server responds with the disabled cleanup worker instead of a broken active cache.

Vendor files are official Workbox JavaScript libraries (MIT-licensed). Keep the vendor directory in deployment archives. The site's own worker logic is readable in `resources/pwa/sw.js`.

## What is cached

When both PWA and Workbox caching are enabled, the root-scoped service worker may cache same-origin public Vite build assets (/build/, immutable hashed JS/CSS/fonts/images) and public static images/fonts (/assets/). Cache entries are capped and time-limited. An anonymous, static `/offline-pwa.html` is precached for failed public navigations.

No HTML document, catalog/search/API response, product price, stock, account, cart, checkout, payment, admin response, signed URL, media download or personal data is cached. Online navigations always reach the server; protected routes are not intercepted. Browser HTTP caching and server-side caches are independent of Workbox.

## Updates, OFF and Web Push

The new worker waits for old browsing sessions to close rather than forcibly reloading checkout. It removes legacy nx-static/nx-images caches and obsolete nx-workbox caches when activated.

Turning Workbox OFF replaces the cache worker with a network-only passthrough worker so the separate PWA installation option stays functional, and deletes only Nexora-owned caches on the visitor's next online load. When PWA itself is OFF, the worker unregisters unless a Web Push subscription needs it. It does not clear browser data, IndexedDB, shopping carts, or unrelated service workers. Existing Web Push subscriptions are preserved: when Workbox is ON the worker imports the existing push event handlers; when OFF an active subscription continues through the standalone push worker. Push notifications remain opt-in.

Changing this setting is per-store; effective only after saving the Appearance form. Service-worker scope is the current origin (/), so multiple stores hosted under paths of the same origin should not use different cache policies. The supported multi-store configuration uses distinct hosts.

## Checks before enabling in production

Run `npm run qa` with a configured E2E environment and confirm that `/sw.js` contains Workbox imports only when enabled. Test update activation, offline fallback, checkout/cart mutations, private pages, Web Push opt-in/out, multiple tabs and PWA disablement on real browsers. The online/offline integration needs browser E2E; static checks alone do not certify it.
