// Native (Android app) local notifications: the Notification API does not exist inside the app shell, so alerts from the live inbox
// are shown through the Capacitor LocalNotifications plugin. Does nothing in a normal browser.
const native = () => typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
const plugin = () => (native() ? window.Capacitor.registerPlugin('LocalNotifications') : null)
let asked = false
let n = Math.floor(Date.now() / 1000) % 1000000
// A channel's sound can never be changed after Android created it. The first channel («alerts») pointed at a sound file that does not exist, so its
// notifications were silent. «orders2» is a NEW channel with the system's own notification sound (no custom file) — the old one is removed.
const CHANNEL = 'orders2'

export async function nativeAskPermission() {
  const p = plugin(); if (!p || asked) return
  asked = true
  try { const s = await p.checkPermissions(); if (s.display !== 'granted') await p.requestPermissions() } catch { /* ignore */ }
  try { await p.deleteChannel({ id: 'alerts' }) } catch { /* not there */ }
  try { await p.createChannel({ id: CHANNEL, name: 'تنبيهات الطلبات والمكالمات', description: 'طلب جديد · مكالمة واردة · تنبيه متابعة', importance: 5, visibility: 1, vibration: true, lights: true }) } catch { /* ignore */ }
}

export async function nativeNotify(m) {
  const p = plugin(); if (!p || !m || m.silent) return
  try {
    await p.schedule({ notifications: [{ id: ++n % 2147483000, title: String(m.title || 'تنبيه'), body: String(m.body || '').split('\n')[0].slice(0, 120), largeBody: String(m.body || ''), summaryText: String(m.title || ''), channelId: CHANNEL, schedule: { at: new Date(Date.now() + 300) }, extra: { url: m.url || '' } }] })
  } catch { /* ignore */ }
}
