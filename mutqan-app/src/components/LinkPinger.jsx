import { useEffect } from 'react'
import { hotlink, isLinkedHere } from '../lib/link'

// A device paired with the hotline link code tells the site «I am here» every 45 s while the app is open and visible.
// The customer's hotline button reads that: someone online → it rings; nobody → it records a call-back request instead of ringing into the void.
export default function LinkPinger() {
  useEffect(() => {
    let alive = true
    // Every operations device asks, linked or not: with automatic joining on, the server pairs it on the spot.
    // Once the server has said «not linked and not joining», an unlinked device asks again only every 10 minutes.
    let lastNo = 0
    const ping = () => {
      if (!alive || document.visibilityState === 'hidden') return
      if (!isLinkedHere() && Date.now() - lastNo < 600000) return
      hotlink.ping().then((r) => { if (r && r.linked === false) lastNo = Date.now() }).catch(() => {})
    }
    ping()
    const t = setInterval(ping, 45000)
    document.addEventListener('visibilitychange', ping)
    window.addEventListener('focus', ping)
    return () => { alive = false; clearInterval(t); document.removeEventListener('visibilitychange', ping); window.removeEventListener('focus', ping) }
  }, [])
  return null
}
