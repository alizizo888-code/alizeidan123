// One way to open a notification's link (owner, 2026-11-28: tapping a WhatsApp-centre alert left a white page —
// a full address was handed to the in-app router, which has no screen for it).
export function openUrl(navigate, url) {
  const u = String(url || ''); if (!u) return
  if (/^https?:\/\//i.test(u)) {
    try { const x = new URL(u); if (x.origin === location.origin && x.hash.startsWith('#/')) { navigate(x.hash.slice(1)); return } } catch { /* fall through */ }
    window.location.assign(u); return
  }
  navigate(u.replace(/^#/, '').replace(/^(?!\/)/, '/'))
}
