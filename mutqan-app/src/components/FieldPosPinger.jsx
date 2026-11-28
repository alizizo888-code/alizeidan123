import { useEffect } from 'react'
import { post } from '../lib/_api'

// Tells the site where a field person is, so «فين الفني / فين المشرف دلوقتي» has an answer.
//   technician → /track  (the server keeps it only while they have an open job, one point, gone after 3 h)
//   supervisor → /ops    (one point while the app is open, gone after 3 h)
// Only while the app is open and visible, and only if location permission was already given or is granted
// on the first ask; a refusal is respected and never asked again in this session.
// A technician every 20 s: the customer's live map (LiveTrack) follows him from these points.
const EVERY = { technician: 20000, supervisor: 120000 }

export default function FieldPosPinger({ role }) {
  useEffect(() => {
    if (!EVERY[role] || typeof navigator === 'undefined' || !navigator.geolocation) return undefined
    let alive = true
    let denied = false
    let idleUntil = 0            // the server said «no open job»: ask again in five minutes, not every minute
    const send = () => {
      if (!alive || denied || window.__MQ_BG_GPS || document.visibilityState === 'hidden' || Date.now() < idleUntil) return
      navigator.geolocation.getCurrentPosition(
        (p) => {
          const body = { lat: p.coords.latitude, lng: p.coords.longitude, acc: p.coords.accuracy }
          const req = role === 'technician' ? post('/track', body) : post('/ops', { op: 'pos', ...body })
          req.then((r) => { if (r && r.ok === false && r.reason === 'no_open_job') idleUntil = Date.now() + 60000 }).catch(() => {})
        },
        (e) => { if (e && e.code === 1) denied = true },
        { enableHighAccuracy: true, maximumAge: 30000, timeout: 20000 },
      )
    }
    send()
    const t = setInterval(send, EVERY[role])
    document.addEventListener('visibilitychange', send)
    return () => { alive = false; clearInterval(t); document.removeEventListener('visibilitychange', send) }
  }, [role])
  return null
}
