// «بداية جديدة»: wipes what the app has kept on this device so it starts as if just installed, the newest version,
// with nothing old on screen — cached files and old updates, service workers, saved screens and chats, shown
// notifications — and (optionally) marks the notifications as read. The sign-in stays unless asked otherwise:
// sessions, the owner's PIN and this phone's device id (its one-device activation) are kept, so nobody has to
// ask the owner for a new link just because they cleaned up.
import { db } from './db'
import { auth } from './auth'

const KEEP = [/^desk_sess_/, /:mutqan_token$/, /:mutqan_user$/, /^mutqan_token$/, /^mutqan_user$/, /^desk_pin_/, /^desk_pin$/, /^mq_device_id$/, /^desk_door$/, /^desk_ops_name$/, /^mutqan_theme$/, /^mq_wake_on$/, /^mq_va_mute$/, /^mq_outbox$/]   // mq_outbox: edits made offline, not yet on the server — never thrown away
const KEEP_IDB = ['mutqan_session']

async function markNotificationsRead() {
  try {
    const me = auth.getCurrentUser()?.id
    const rows = await db.selectShared('notifications', { read: false }, { order: '-at', limit: 200 })
    const unread = (rows || []).filter((n) => !n.read && (n.audience === 'ops' || n.userId === me))
    await Promise.all(unread.slice(0, 200).map((n) => db.updateShared('notifications', n.id, { read: true }).catch(() => {})))
    return unread.length
  } catch { return 0 }
}

export async function freshStart({ readNotifications = true, signOut = false } = {}) {
  if (readNotifications && !signOut) await markNotificationsRead()
  // Notifications already shown in the phone's tray.
  try { const regs = (await navigator.serviceWorker?.getRegistrations?.()) || []; for (const r of regs) { try { (await r.getNotifications()).forEach((n) => n.close()) } catch { /* ignore */ } } } catch { /* ignore */ }
  // Old versions: service workers and every cache.
  try { const regs = (await navigator.serviceWorker?.getRegistrations?.()) || []; await Promise.all(regs.map((r) => r.unregister())) } catch { /* ignore */ }
  try { const keys = await caches.keys(); await Promise.all(keys.map((k) => caches.delete(k))) } catch { /* ignore */ }
  // Saved screens, chats, drafts, counters.
  try { sessionStorage.clear() } catch { /* ignore */ }
  try {
    const drop = []
    for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (signOut || !KEEP.some((re) => re.test(k))) drop.push(k) }
    drop.forEach((k) => localStorage.removeItem(k))
  } catch { /* ignore */ }
  try {
    if (indexedDB.databases) {
      const dbs = await indexedDB.databases()
      await Promise.all(dbs.filter((d) => d.name && (signOut || !KEEP_IDB.includes(d.name))).map((d) => new Promise((res) => { const r = indexedDB.deleteDatabase(d.name); r.onsuccess = r.onerror = r.onblocked = () => res() })))
    }
  } catch { /* ignore */ }
  if (signOut) { try { await auth.signOut?.() } catch { /* ignore */ } }
  const u = new URL(location.href); u.searchParams.set('fresh', String(Date.now()))
  if (signOut) u.hash = '#/'
  location.replace(u.toString())
}
