// Оболочка приложения кэшируется, чтобы иконка открывалась мгновенно даже при плохой связи.
// Данные (/api) всегда идут напрямую на сервер.
const CACHE = 'ref-v31';
const SHELL = ['/', '/app.js?v=37', '/style.css?v=35', '/icon-192.png', '/favicon.png', '/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/')) return;
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request)
      .then(r => { caches.open(CACHE).then(c => c.put('/', r.clone())); return r; })
      .catch(() => caches.match('/')));
    return;
  }
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request)));
});

// Push приходит пустым — текст забираем с сервера, чтобы показать самое свежее
self.addEventListener('push', e => {
  e.waitUntil((async () => {
    let items = [];
    try {
      const r = await fetch('/api/push/pending', { credentials: 'include' });
      if (r.ok) items = (await r.json()).items || [];
    } catch (err) {}
    if (!items.length) {
      items = [{ title: 'Автошкола', body: 'Есть новости по вашим друзьям', url: '/' }];
    }
    for (const n of items) {
      await self.registration.showNotification(n.title, {
        body: n.body || '',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        data: { url: n.url || '/' },
        tag: 'drive-' + (n.id || Date.now()),
      });
    }
  })());
});

// Браузер время от времени сам пересоздаёт подписку (обновление, чистка, срок жизни).
// Без этого обработчика push тихо переставал приходить — именно так подписки и «слетали».
self.addEventListener('pushsubscriptionchange', e => {
  e.waitUntil((async () => {
    try {
      let key = e.oldSubscription && e.oldSubscription.options && e.oldSubscription.options.applicationServerKey;
      if (!key) {
        const r = await fetch('/api/push/key');
        const b64 = (await r.json()).key;
        key = Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
      }
      const sub = e.newSubscription
        || (await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }));
      await fetch('/api/push/subscribe', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-App': '1' },
        body: JSON.stringify({ subscription: sub.toJSON() }),
      });
    } catch (err) {}
  })());
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.includes(location.origin)) { c.focus(); c.navigate(url); return; }
    }
    await clients.openWindow(url);
  })());
});
