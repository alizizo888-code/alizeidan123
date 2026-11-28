// The visitor's heartbeat for «التوأم الرقمي» (owner, 2026-11-28): every customer page on every face of the network
// tells oxygen11.com «I'm here» every 40 s while it is visible. Random session id only — no phone, no name, no IP kept.
// Staff screens and the desk never send it. sendBeacon + text/plain = no CORS preflight from any domain.
const HQ = 'https://oxygen11.com/wp-json/mutqan/v1/presence'
let started = false
function sid() {
  try {
    let s = sessionStorage.getItem('mq_vsid')
    if (!s) { s = (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)).replace(/[^a-z0-9]/g, ''); sessionStorage.setItem('mq_vsid', s) }
    return s
  } catch { return '' }
}
function staffScreen() { const h = location.hash || ''; return !!window.__DESK_KIND__ || /^#\/(staff|notifications)/.test(h) || /^\/staff/.test(location.pathname) }
function beat() {
  if (document.visibilityState !== 'visible' || staffScreen()) return
  const id = sid(); if (!id) return
  let ordered = false; try { ordered = localStorage.getItem('mq_has_order') === '1' } catch { /* ignore */ }
  const body = JSON.stringify({ sid: id, url: location.href.split('#')[0] + (location.hash || '').slice(0, 60), title: document.title, ref: document.referrer, q: location.search, bot: !!window.__mqBotOpen, ordered })
  try { if (navigator.sendBeacon && navigator.sendBeacon(HQ, new Blob([body], { type: 'text/plain' }))) return } catch { /* fall back */ }
  try { fetch(HQ, { method: 'POST', body, mode: 'no-cors', keepalive: true, headers: { 'Content-Type': 'text/plain' } }) } catch { /* never matters */ }
}
export function startPresence() {
  if (started || typeof window === 'undefined') return
  started = true
  setTimeout(beat, 2500)
  setInterval(beat, 40000)
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') beat() })
  window.addEventListener('hashchange', () => setTimeout(beat, 500))
}
