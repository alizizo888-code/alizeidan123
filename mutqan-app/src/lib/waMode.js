// Opened from our WhatsApp welcome link (the «متجر الواتساب» page): a calm page inside WhatsApp's browser — no
// floating call/chat/AI buttons, no «حمّل التطبيق» banners, the home button goes back to the WhatsApp store page.
const K = 'mq_wa'
export function setWaMode() { try { sessionStorage.setItem(K, '1') } catch {} }
export function isWaMode() {
  try { if (sessionStorage.getItem(K) === '1') return true } catch {}
  return typeof location !== 'undefined' && /[?&]src=wa\b/.test(location.hash || '')
}
