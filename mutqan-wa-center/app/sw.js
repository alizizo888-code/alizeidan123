/* مركز الواتساب — Service Worker: إشعارات حتى والتطبيق مقفول */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = {body: e.data ? e.data.text() : ''}; }
  const title = d.title || 'مركز الواتساب';
  const opts = {
    body: d.body || 'رسالة جديدة', tag: d.tag || 'mwc', renotify: true, dir: 'rtl', lang: 'ar',
    icon: d.icon || 'icon-192.png', badge: d.badge || undefined, vibrate: [220, 90, 220, 90, 220],
    requireInteraction: false, timestamp: Date.now(), data: {url: d.url || self.registration.scope},
  };
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
    // لو التطبيق مفتوح وقدّام المستخدم، هو بيرن بنفسه — منكررش
    const visible = wins.some(w => w.visibilityState === 'visible' && w.focused);
    wins.forEach(w => w.postMessage({type: 'push', data: d}));
    if (!visible) await self.registration.showNotification(title, opts);
  })());
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
    for (const w of wins) {
      if (w.url.startsWith(self.registration.scope)) { await w.focus(); w.postMessage({type: 'open', url}); return; }
    }
    await self.clients.openWindow(url);
  })());
});

/* ضروري عشان المتصفح يعتبره تطبيق قابل للتثبيت — من غير كاش عشان البيانات تفضل لحظية */
self.addEventListener('fetch', () => {});
