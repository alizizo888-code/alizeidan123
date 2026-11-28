// The Android apps' native side (see native/shared): background GPS, the WhatsApp-notification reader, opening Waze /
// Google Maps / Termux / WhatsApp, and keeping the app awake. In a normal browser every call quietly does nothing
// (or falls back to a web link), so screens can use these without checking where they run.
import { API_BASE, getToken, getAnonId, post, currentUser } from './_api'

export const isNative = () => typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
const plug = (name) => (isNative() ? window.Capacitor.registerPlugin(name) : null)
const absApi = () => (/^https?:/.test(API_BASE) ? API_BASE : location.origin + API_BASE)

/** {listener, battery, configured} or null outside the app / on an app built before the native layer. */
export async function nativeStatus() {
  const p = plug('MqNative'); if (!p) return null
  try { return await p.listenerEnabled() } catch { return null }
}

export async function openListenerSettings() { try { await plug('MqNative')?.openListenerSettings() } catch { /* old app */ } }
export async function openFullScreenSettings() { try { await plug('MqNative')?.openFullScreenSettings() } catch { /* old app */ } }
export async function keepAwake() { try { return await plug('MqNative')?.ignoreBattery() } catch { return null } }

/** Turn-by-turn in the driver's app of choice; a web link when the app (or the native layer) is not there. */
export async function openNav(lat, lng, app = 'google') {
  const web = app === 'waze' ? `https://waze.com/ul?ll=${lat},${lng}&navigate=yes` : `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`
  const p = plug('MqNative')
  if (p) {
    const url = app === 'waze' ? `waze://?ll=${lat},${lng}&navigate=yes` : `google.navigation:q=${lat},${lng}&mode=d`
    try { const r = await p.openUrl({ url }); if (r?.ok) return } catch { /* fall through */ }
  }
  window.open(web, '_blank', 'noopener')
}

/** Open another app by package (Termux, WhatsApp…); the store page when it is not installed. */
export async function openTool(pkg, web) {
  const p = plug('MqNative')
  if (p) { try { await p.openApp({ package: pkg }); return } catch { /* old app */ } }
  if (web) window.open(web, '_blank', 'noopener')
}


/**
 * The alert service (native/shared/java/MqPushService): keeps orders, messages, tones and hotline calls coming with the
 * screen off — Android freezes the page itself, so the page alone could not. Called for every signed-in user,
 * customers included. While it runs the page does not post its own system notifications (no doubles).
 */
export async function nativeAlerts() {
  const p = plug('MqNative'); if (!p) return false
  try {
    const role = currentUser()?.role || ''
    const st = await p.configure({ endpoint: absApi(), token: getToken(), anon: getAnonId(), alerts: true, role })
    window.__MQ_NATIVE_PUSH = true
    void st
    try { await plug('LocalNotifications')?.requestPermissions() } catch { /* ignore */ }
    return true
  } catch { window.__MQ_NATIVE_PUSH = false; return false }   // app built before the alert service: the page keeps notifying
}

export async function nativeSignedOut() { try { await plug('MqNative')?.reset() } catch { /* ignore */ } window.__MQ_NATIVE_PUSH = false; started = false }

/** A tapped notification opens its page (the order, the call, the inbox). */
export function nativeLaunchRoutes() {
  const p = plug('MqNative'); if (!p) return
  const go = (u) => { if (u && typeof u === 'string') { if (u.startsWith('#')) location.hash = u.slice(1); else if (u.startsWith('/')) location.hash = u } }
  const take = () => p.takeLaunchUrl().then((r) => go(r?.url)).catch(() => {})
  take()
  try { p.addListener('open', (d) => go(d?.url)) } catch { /* old app */ }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') take() })
}

let started = false
let watcher = null
/**
 * Called once a team member is signed in inside the app: hands the native layer the site address and the session
 * token (for the notification reader), and starts background GPS for field roles — a foreground-service position
 * every ~30 s (technician) that keeps flowing with the screen off, which is what lets the server tell the customer
 * «الفني قريب» on its own.
 */
export async function nativeStart() {
  if (!isNative() || started || !getToken()) return
  started = true
  let cfg = null
  try { cfg = await post('/native', { op: 'config' }) } catch { started = false; return }
  try { await plug('MqNative')?.configure({ endpoint: absApi(), token: getToken(), anon: getAnonId(), readWhatsApp: !!cfg.readWhatsApp, alerts: true }); window.__MQ_NATIVE_PUSH = true } catch { /* app built before the native layer */ }
  const g = cfg.gps
  const bg = plug('BackgroundGeolocation')
  if (!g || !bg) return
  let last = 0
  try {
    watcher = await bg.addWatcher({
      backgroundTitle: 'متقن — مشاركة موقعك أثناء الشغل',
      backgroundMessage: 'بيتبعت موقعك بس وإنت عندك طلب مفتوح، عشان العميل يعرف إمتى توصل.',
      requestPermissions: true, stale: false, distanceFilter: g.minMeters || 25,
    }, (loc, err) => {
      if (err || !loc) return
      const now = Date.now()
      if (now - last < (g.every || 30) * 1000) return
      last = now
      const body = { lat: loc.latitude, lng: loc.longitude, acc: loc.accuracy }   // the server drops vague readings
      ;(g.path === '/track' ? post('/track', body) : post('/ops', { op: 'pos', ...body })).catch(() => {})
    })
    window.__MQ_BG_GPS = true
  } catch { /* permission refused: the in-page pinger keeps working while the app is open */ }
}

export async function nativeStop() {
  try { if (watcher) await plug('BackgroundGeolocation')?.removeWatcher({ id: watcher }) } catch { /* ignore */ }
  watcher = null; started = false; window.__MQ_BG_GPS = false
}
