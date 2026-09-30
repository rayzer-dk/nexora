/* Nexora Commerce Web Push service worker. Shows the notification and opens its target on click. */
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }
  const title = typeof data.title === 'string' && data.title ? data.title : 'Nexora';
  const options = { body: typeof data.body === 'string' ? data.body : '', data: { url: typeof data.url === 'string' ? data.url : '/' }, tag: 'nexora-' + (data.url || 'default') };
  event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
  if (target.origin !== self.location.origin) { return; }
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const client of list) { if (client.url === target.href && 'focus' in client) { return client.focus(); } }
    return self.clients.openWindow(target.href);
  }));
});
