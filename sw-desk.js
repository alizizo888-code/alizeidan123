// Desk apps: installable shell; data always comes live from the brand servers (no caching of API calls).
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))
self.addEventListener('fetch', () => {})

// Background notifications (Web Push from the site's own server): a new order, a message or a hotline call
// rings with the app closed and the screen off. When the app is open and in front, it shows the alert itself.
self.addEventListener('push', (e) => {
  let d = {}
  try { d = e.data ? e.data.json() : {} } catch { d = { body: e.data ? e.data.text() : '' } }
  e.waitUntil((async () => {
    const wins = await clients.matchAll({ type: 'window', includeUncontrolled: true })
    if (wins.some((w) => w.visibilityState === 'visible' && w.focused)) return
    const hi = d.priority === 'high'
    const icon = new URL('icon-oxygen-192.png', self.registration.scope).href
    await self.registration.showNotification(d.title || 'تنبيه جديد', {
      body: d.body || '', tag: d.id || undefined, renotify: true, requireInteraction: hi,
      vibrate: hi ? [500, 150, 500, 150, 900] : [200, 100, 200], icon, badge: icon, dir: 'rtl', lang: 'ar', data: { url: d.url || '' },
    })
  })())
})
self.addEventListener('notificationclick', (e) => {
  e.notification.close()
  const url = e.notification.data && e.notification.data.url ? String(e.notification.data.url).replace(/^#/, '') : ''
  e.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true })
    const w = all[0]
    if (w) { await w.focus(); if (url) w.navigate(w.url.split('#')[0] + '#' + url).catch(() => {}); return }
    await clients.openWindow(self.registration.scope + 'desk.html' + (url ? '#' + url : ''))
  })())
})
