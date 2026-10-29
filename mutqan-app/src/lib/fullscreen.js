// Open as an app: hide browser bars automatically.
// Installed PWA -> manifest "fullscreen" does it. In a normal browser tab, the first tap/touch enters fullscreen
// (browsers only allow fullscreen after a user gesture). iOS Safari has no page fullscreen: use "Add to Home Screen".
const isStandalone = () => {
  try {
    return window.matchMedia('(display-mode: fullscreen)').matches ||
      window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true
  } catch { return false }
}
export function autoFullscreen() {
  try {
    const el = document.documentElement
    const req = el.requestFullscreen || el.webkitRequestFullscreen
    if (!req || isStandalone() || sessionStorage.getItem('mq_fs_off') === '1') return
    const go = () => {
      document.removeEventListener('pointerup', go, true)
      document.removeEventListener('keydown', go, true)
      if (document.fullscreenElement || document.webkitFullscreenElement) return
      try {
        const p = req.call(el, { navigationUI: 'hide' })
        if (p && p.catch) p.catch(() => {})
      } catch { /* ignore */ }
    }
    document.addEventListener('pointerup', go, true)
    document.addEventListener('keydown', go, true)
    // If the user leaves fullscreen on purpose (Esc / back), don't fight them in this tab.
    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement) { try { sessionStorage.setItem('mq_fs_off', '1') } catch { /* ignore */ } }
    })
  } catch { /* ignore */ }
}
