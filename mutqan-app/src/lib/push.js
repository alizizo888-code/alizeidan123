import { nativeNotify, nativeAskPermission } from './nativeNotify'
import { post, currentUser, onUser } from './_api'
import { BN } from './netBrand'
import { isNative, nativeAlerts, nativeLaunchRoutes } from './native'
import { vibePattern, effectiveSound } from './alert'

// In-app notifications without a push server: every signed-in device polls /push inbox every few
// seconds while the app is open, then shows a system notification (if allowed) and fires an event.
// (True background push needs a VAPID service worker — a later phase.)
const SEEN_KEY = 'mutqan_push_since'
let timer = null
let since = 0
try { since = Number(localStorage.getItem(SEEN_KEY)) || 0 } catch { /* private mode */ }
const timers = new Map()
const seen = new Set()   // ids already shown (the server may repeat the boundary second)
const STALE_MS = 10 * 60 * 1000
export const staleAt = (at, now = Date.now()) => { const t = Date.parse(at || ''); return Number.isFinite(t) && now - t > STALE_MS }

const supported = () => typeof Notification !== 'undefined'
export const notificationState = () => (supported() ? Notification.permission : 'unsupported')

const iconUrl = () => (window.__DESK_BRAND__ ? `./icon-${window.__DESK_BRAND__}-192.png` : `${window.__MUTQAN__?.app || '/app/'}icon-192.png`)
async function showSystem(m) {
  const hi = m.priority === 'high'
  const opts = { body: m.body || '', tag: m.id, icon: iconUrl(), badge: iconUrl(), dir: 'rtl', lang: 'ar', vibrate: m.silent ? [] : vibePattern(soundCfg || {}, hi), silent: !!m.silent, requireInteraction: hi, renotify: true, data: { url: m.url || '' } }
  try {
    const reg = await navigator.serviceWorker?.getRegistration?.()
    if (reg?.showNotification) { await reg.showNotification(m.title || BN, opts); return }
  } catch { /* fall back */ }
  try {
    const n = new Notification(m.title || BN, opts)
    n.onclick = () => { window.focus(); if (m.url) location.hash = String(m.url).replace(/^#/, '') }
  } catch { /* unsupported */ }
}

let soundCfg = null
export const setSoundConfig = (c) => { soundCfg = c }
const inQuiet = (q) => {
  if (!q?.on) return false
  const d = new Date(); const cur = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  return q.from <= q.to ? cur >= q.from && cur < q.to : cur >= q.from || cur < q.to
}
// A ringing call has its own banner with answer / decline (IncomingCalls); while the app is open its push must not
// stack a second alarm card on top of it. With the app closed the system notification still wakes the phone.
export const CALL_EVENTS = new Set(['incoming_call', 'hotline_call'])
// Owner controls: which events are really "high", and quiet hours (no sound/vibration, banner still shows).
function resolve(m) {
  const c = soundCfg || {}   // not loaded yet: this device's own mute still applies
  let priority = m.priority === 'high' ? 'high' : 'normal'
  if (m.event && c.highEvents && m.event in c.highEvents) priority = c.highEvents[m.event] ? 'high' : 'normal'
  const silent = effectiveSound(c).enabled === false || inQuiet(c.quiet)
  return { ...m, priority, silent }
}

async function poll() {
  if (!currentUser()) return   // keeps running while the app is in the background (the browser throttles it, but a ringing order still gets through)
  try {
    const r = await post('/push', { op: 'inbox', since: since || undefined })
    const now = Date.parse(r.now) || Date.now()
    const items = r.items || []
    if (!since) { for (const m of items) seen.add(m.id) }  // first poll ever: remember, don't replay old messages
    else {
      for (const m of items) {
        if (seen.has(m.id)) continue
        seen.add(m.id); if (seen.size > 300) seen.delete(seen.values().next().value)
        // Catch-up after the page was closed a while: alerts older than 10 minutes are already in «الإشعارات» and on
        // the orders board — ringing them all again on every reload is what made «إغلاق الكل» look broken.
        if (staleAt(m.at, now)) continue
        const rm = resolve(m)
        window.dispatchEvent(new CustomEvent('mutqan:push', { detail: rm }))
        if (supported() && Notification.permission === 'granted') {
          showSystem(rm)
        }
        // Inside the app the alert service already posted it (with the screen off too); posting again would double it.
        if (!window.__MQ_NATIVE_PUSH) nativeNotify(rm)
      }
    }
    since = now
    try { localStorage.setItem(SEEN_KEY, String(since)) } catch { /* ignore */ }
  } catch { /* offline / signed out */ }
}

// Background notifications: once the user has allowed notifications, this device subscribes with the site's own
// push key (Web Push, no third-party service) and tells the server who it belongs to. Re-run on sign-in/out so
// the server always targets the right person. Safe to call often.
const b64u = (s) => { const p = '='.repeat((4 - (s.length % 4)) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(r, (c) => c.charCodeAt(0)) }
const sameKey = (a, b) => { if (!a || !b) return false; const x = new Uint8Array(a); return x.length === b.length && x.every((v, i) => v === b[i]) }
let bgBusy = null
export function enableBackgroundPush() {
  if (bgBusy) return bgBusy
  bgBusy = (async () => {
    try {
      if (!supported() || Notification.permission !== 'granted' || !('serviceWorker' in navigator) || !('PushManager' in window)) return false
      const reg = await Promise.race([navigator.serviceWorker.ready, new Promise((ok) => setTimeout(() => ok(null), 10000))])
      if (!reg || !reg.pushManager) return false
      const { key } = await post('/webpush', { op: 'key' })
      const want = b64u(key)
      let sub = await reg.pushManager.getSubscription()
      if (sub && !sameKey(sub.options && sub.options.applicationServerKey, want)) { try { await sub.unsubscribe() } catch { /* ignore */ } sub = null }
      if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: want })
      await post('/webpush', { op: 'subscribe', sub: sub.toJSON(), app: (typeof window !== 'undefined' && window.__DESK_KIND__) || 'web' })
      return true
    } catch { return false } finally { setTimeout(() => { bgBusy = null }, 1000) }
  })()
  return bgBusy
}

export function startInbox() {
  if (timer) return
  nativeAskPermission()
  enableBackgroundPush()
  onUser(() => { enableBackgroundPush() })
  if (isNative()) {
    nativeLaunchRoutes()
    // Customers who ordered without an account get their alerts by their anonymous id, so the service runs for them too;
    // signing in or out just hands it the new identity.
    nativeAlerts()
    onUser(() => { nativeAlerts() })
  }
  timer = setInterval(poll, 4000)
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll() })
  onUser(() => { since = 0; poll() })
  poll()
}

const send = (target, message) => post('/push', { op: 'send', target, message })

export const push = {
  send: (userId, message) => send({ user: userId }, message),
  sendToRole: (_gid, role, message) => send({ role }, message),
  sendToAdmins: (_gid, message) => send({ admins: true }, message),
  async requestPermission() { const r = supported() ? await Notification.requestPermission() : 'unsupported'; if (r === 'granted') enableBackgroundPush(); return r },
  async subscribe() { startInbox(); return { ok: true } },
  diagnose() {
    if (!supported()) return { ok: false, reason: 'unsupported', hint: 'هذا المتصفح لا يدعم الإشعارات. أضف التطبيق للشاشة الرئيسية أو استخدم متصفحًا حديثًا.' }
    return { ok: true, permission: Notification.permission }
  },
  // Follow-up reminders fire only while the app stays open (no server scheduler on shared hosting).
  async scheduleIn(ms, message) {
    const id = 'sch-' + Math.random().toString(36).slice(2, 9)
    const t = setTimeout(() => {
      timers.delete(id)
      window.dispatchEvent(new CustomEvent('mutqan:push', { detail: { id, ...message } }))
      if (supported() && Notification.permission === 'granted') { try { new Notification(message.title || BN, { body: message.body || '' }) } catch { /* ignore */ } }
    }, Math.max(1000, ms))
    timers.set(id, t)
    return { id }
  },
  async cancelSchedule(id) { clearTimeout(timers.get(id)); timers.delete(id); return { ok: true } },
}
