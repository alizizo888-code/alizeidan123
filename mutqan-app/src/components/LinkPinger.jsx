import { useEffect } from 'react'
import { hotlink, isLinkedHere } from '../lib/link'

// A device paired with the hotline link code tells the site «I am here» every 45 s while the app is open and visible.
// The customer's hotline button reads that: someone online → it rings; nobody → it records a call-back request instead of ringing into the void.
export default function LinkPinger() {
  useEffect(() => {
    let alive = true
    const ping = () => { if (!alive || document.visibilityState === 'hidden' || !isLinkedHere()) return; hotlink.ping().catch(() => {}) }
    ping()
    const t = setInterval(ping, 45000)
    document.addEventListener('visibilitychange', ping)
    window.addEventListener('focus', ping)
    return () => { alive = false; clearInterval(t); document.removeEventListener('visibilitychange', ping); window.removeEventListener('focus', ping) }
  }, [])
  return null
}
