// Opening WhatsApp the fast way (owner report 2026-11-22: on the phone a wa.me link opened a «WhatsApp web» page
// first, and the emojis of the order message came out as «�»).
//  - wa.me redirects to api.whatsapp.com and that redirect breaks 4-byte emojis → we link api.whatsapp.com directly;
//  - on a phone we go straight into the WhatsApp app (whatsapp://send), falling back to the web link only if the app
//    did not open; on a computer the web/desktop link stays.
export const isPhone = () => /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '')

export function parseWa(href) {
  try {
    const u = new URL(href)
    if (u.hostname === 'wa.me') return { phone: u.pathname.replace(/\D/g, ''), text: u.searchParams.get('text') || '' }
    if (/(^|\.)whatsapp\.com$/.test(u.hostname) && /\/send/.test(u.pathname)) return { phone: (u.searchParams.get('phone') || '').replace(/\D/g, ''), text: u.searchParams.get('text') || '' }
  } catch { /* not a URL */ }
  return null
}

export const waWeb = (phone, text) => `https://api.whatsapp.com/send?${phone ? `phone=${String(phone).replace(/\D/g, '')}&` : ''}text=${encodeURIComponent(text || '')}`

export function openWa(phone, text) {
  const web = waWeb(phone, text)
  if (!isPhone()) { window.open(web, '_blank', 'noopener'); return }
  const app = `whatsapp://send?${phone ? `phone=${String(phone).replace(/\D/g, '')}&` : ''}text=${encodeURIComponent(text || '')}`
  let left = false
  const mark = () => { left = true }
  document.addEventListener('visibilitychange', mark, { once: true })
  window.addEventListener('pagehide', mark, { once: true })
  window.location.href = app
  setTimeout(() => { if (!left && document.visibilityState === 'visible') window.open(web, '_blank', 'noopener') }, 1400)
}

/** Mounted once: every WhatsApp link in the app goes through openWa(). */
export function installWaLinks() {
  if (window.__mqWa) return
  window.__mqWa = true
  document.addEventListener('click', (e) => {
    const a = e.target && e.target.closest && e.target.closest('a[href]')
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey) return
    const w = parseWa(a.href)
    if (!w) return
    e.preventDefault()
    openWa(w.phone, w.text)
  }, true)
  // window.open('https://wa.me/…') calls get the same treatment
  const orig = window.open.bind(window)
  window.open = (url, ...rest) => {
    const w = typeof url === 'string' ? parseWa(url) : null
    if (w && !/^https:\/\/api\.whatsapp\.com\/send\?/.test(url)) { openWa(w.phone, w.text); return null }
    return orig(url, ...rest)
  }
}
