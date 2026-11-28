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
    const page = self.registration.scope.split('?')[0]
    if (wins.some((w) => w.visibilityState === 'visible' && w.focused && w.url.split('#')[0].split('?')[0] === page)) return
    const hi = d.priority === 'high'
    const icon = new URL('icon-oxygen-192.png', self.registration.scope).href
    const ev = String(d.event || '')
    const kind = d.kind || (ev.includes('call') ? 'call' : /msg|message|chat|phone_wa/.test(ev) ? 'msg_in' : /new_order|order_bot|wa_handoff|warranty_claim/.test(ev) ? 'order'
      : /assigned|accepted|rejected|en_route|nearby|arrived|started|progress|completed|contact|distance|order_status/.test(ev) ? 'tech' : 'ops')
    const VIBE = { order: [500, 150, 500, 150, 900], msg_in: [180, 100, 180], tech: [120, 80, 120, 80, 300], ops: [220, 90, 220, 90, 320], customer: [180, 100, 180], call: [1000, 500, 1000, 500, 1000, 500, 1000] }
    const MARK = { order: '🆕 ', msg_in: '💬 ', tech: '🧰 ', ops: '🛰️ ', call: '📞 ', customer: '' }
    const title = d.title || 'تنبيه جديد'
    await self.registration.showNotification(/^\p{Extended_Pictographic}/u.test(title) ? title : (MARK[kind] || '') + title, {
      body: d.body || '', tag: d.id || undefined, renotify: true, requireInteraction: hi || kind === 'call', silent: false,
      vibrate: VIBE[kind] || (hi ? VIBE.order : [200, 100, 200]), icon, badge: icon, dir: 'rtl', lang: 'ar', timestamp: Date.now(), data: { url: d.url || '' },
    })
  })())
})
self.addEventListener('notificationclick', (e) => {
  e.notification.close()
  const url = e.notification.data && e.notification.data.url ? String(e.notification.data.url).replace(/^#/, '') : ''
  // Open the alert inside THIS app only (its scope is its own page: desk.html / desk-ops.html / desk-tech.html / desk-wa.html).
  const page = self.registration.scope
  e.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true })
    const w = all.find((c) => c.url.split('#')[0].split('?')[0] === page.split('?')[0])
    if (w) {
      await w.focus()
      if (url) { try { await w.navigate(page + '#' + url) } catch { w.postMessage({ type: 'mq-open', url }) } }
      return
    }
    await clients.openWindow(page + (url ? '#' + url : ''))
  })())
})
