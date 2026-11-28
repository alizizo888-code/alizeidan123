// The customer watches his technician come (owner order 2026-11-20): from «في الطريق» a live GPS map shows where the
// technician is, the road he will take to the customer (free OSRM routing), the way he has come so far, and in the
// middle of the map how many minutes and kilometres are left. It refreshes on its own every few seconds.
import { useEffect, useMemo, useRef, useState } from 'react'
import '../lib/leafletCss'
import { Navigation2, LocateFixed } from 'lucide-react'
import { addBaseLayers } from '../lib/baseLayers'

const LEAFLET_CSS = 'bundled' // leaflet.css ships inside the app (the desk CSP blocks unpkg)
const OSRM = 'https://router.project-osrm.org/route/v1/driving/'
const meters = (a, b) => { const R = 6371000, r = Math.PI / 180, dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r; const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) ** 2; return 2 * R * Math.asin(Math.min(1, Math.sqrt(h))) }
const kmText = (m) => (m < 1000 ? `${Math.round(m / 10) * 10} م` : `${(m / 1000).toFixed(m < 10000 ? 1 : 0)} كم`)
const bearing = (a, b) => { const r = Math.PI / 180; const y = Math.sin((b.lng - a.lng) * r) * Math.cos(b.lat * r); const x = Math.cos(a.lat * r) * Math.sin(b.lat * r) - Math.sin(a.lat * r) * Math.cos(b.lat * r) * Math.cos((b.lng - a.lng) * r); return (Math.atan2(y, x) / r + 360) % 360 }

/** order: needs lat/lng (customer), technicianLocation {lat,lng,updatedAt}, technicianName, status. refresh(): pulls the order again. */
export default function LiveTrack({ order, refresh, light = true }) {
  const el = useRef(null), map = useRef(null), layers = useRef(null)
  const [L, setL] = useState(null)
  const [route, setRoute] = useState(null)      // { coords:[[lat,lng]], sec, m, from:{lat,lng}, at }
  const [follow, setFollow] = useState(true)
  const fitted = useRef(false)
  const home = useMemo(() => (order?.lat != null ? { lat: Number(order.lat), lng: Number(order.lng) } : null), [order?.lat, order?.lng])
  const tl = order?.technicianLocation
  const tech = tl && tl.lat != null ? { lat: Number(tl.lat), lng: Number(tl.lng) } : null
  const trailKey = 'mq_trail_' + order?.id
  const [trail, setTrail] = useState(() => { try { return JSON.parse(sessionStorage.getItem(trailKey) || '[]') } catch { return [] } })

  // Refresh the order every 8 s while he is on the way (the rest of the page polls slower).
  useEffect(() => { if (!refresh) return; const t = setInterval(() => { if (document.visibilityState === 'visible') refresh() }, 8000); return () => clearInterval(t) }, [refresh])

  // The road he has come: every new position, kept for this order on this device.
  useEffect(() => {
    if (!tech) return
    setTrail((t) => {
      const last = t[t.length - 1]
      if (last && meters({ lat: last[0], lng: last[1] }, tech) < 15) return t
      const next = [...t, [tech.lat, tech.lng]].slice(-300)
      try { sessionStorage.setItem(trailKey, JSON.stringify(next)) } catch { /* ignore */ }
      return next
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tech?.lat, tech?.lng])

  // The road ahead and its time: asked again when he has moved 120 m or a minute passed.
  useEffect(() => {
    if (!tech || !home) return
    if (route && route.from && meters(route.from, tech) < 120 && Date.now() - route.at < 60000) return
    let live = true
    fetch(`${OSRM}${tech.lng},${tech.lat};${home.lng},${home.lat}?overview=full&geometries=geojson`)
      .then((r) => r.json()).then((j) => {
        const rt = j?.routes?.[0]; if (!live || !rt) return
        setRoute({ coords: rt.geometry.coordinates.map(([x, y]) => [y, x]), sec: rt.duration, m: rt.distance, from: tech, at: Date.now() })
      }).catch(() => { /* straight line + city speed below */ })
    return () => { live = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tech?.lat, tech?.lng, home?.lat, home?.lng])

  useEffect(() => {
    void LEAFLET_CSS
    import('leaflet').then((m) => setL(m.default || m))
  }, [])
  useEffect(() => {
    if (!L || !el.current || map.current) return
    const m = L.map(el.current, { zoomControl: false }).setView(home ? [home.lat, home.lng] : [21.4225, 39.8262], 14)
    addBaseLayers(L, m)
    L.control.zoom({ position: 'bottomleft' }).addTo(m)
    m.on('dragstart', () => setFollow(false))
    layers.current = L.layerGroup().addTo(m); map.current = m
    setTimeout(() => m.invalidateSize(), 200)
    return () => { m.remove(); map.current = null }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [L])

  // Straight-line fallback when the routing service does not answer: 30 km/h in the city.
  const left = route && tech && route.from && meters(route.from, tech) < 400 ? { m: route.m, sec: route.sec } : tech && home ? { m: meters(tech, home) * 1.3, sec: (meters(tech, home) * 1.3) / (30 / 3.6) } : null
  const mins = left ? Math.max(1, Math.round(left.sec / 60)) : null
  const head = trail.length >= 2 ? bearing({ lat: trail[trail.length - 2][0], lng: trail[trail.length - 2][1] }, { lat: trail[trail.length - 1][0], lng: trail[trail.length - 1][1] }) : 0

  useEffect(() => {
    const m = map.current, g = layers.current
    if (!L || !m || !g) return
    g.clearLayers()
    if (trail.length > 1) L.polyline(trail, { color: '#64748b', weight: 4, opacity: 0.7, dashArray: '1 8', lineCap: 'round' }).addTo(g)
    if (route?.coords?.length) { L.polyline(route.coords, { color: '#ffffff', weight: 9, opacity: 0.9 }).addTo(g); L.polyline(route.coords, { color: '#2563eb', weight: 5.5, opacity: 0.95 }).addTo(g) }
    else if (tech && home) L.polyline([[tech.lat, tech.lng], [home.lat, home.lng]], { color: '#2563eb', weight: 4, dashArray: '6 8' }).addTo(g)
    if (home) L.marker([home.lat, home.lng], { icon: L.divIcon({ className: '', iconSize: [34, 42], iconAnchor: [17, 40], html: '<div style="font-size:30px;line-height:34px;text-align:center;filter:drop-shadow(0 2px 3px rgba(0,0,0,.4))">🏠</div>' }) }).addTo(g).bindTooltip('موقعك')
    if (tech) {
      L.marker([tech.lat, tech.lng], { zIndexOffset: 1000, icon: L.divIcon({ className: '', iconSize: [46, 46], iconAnchor: [23, 23],
        html: `<div style="position:relative;width:46px;height:46px"><div style="position:absolute;inset:0;border-radius:50%;background:rgba(37,99,235,.25);animation:mqpulse 1.6s infinite"></div><div style="position:absolute;inset:8px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;transform:rotate(${head}deg)"><svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M12 2l7 19-7-4-7 4z"/></svg></div></div>` }) }).addTo(g).bindTooltip(order?.technicianName || 'الفني')
    }
    if (tech && home && (follow || !fitted.current)) {
      m.fitBounds(L.latLngBounds([[tech.lat, tech.lng], [home.lat, home.lng], ...(route?.coords || []).filter((_, i) => i % 10 === 0)]), { padding: [40, 40], maxZoom: 17, animate: fitted.current })
      fitted.current = true
    } else if (home && !fitted.current) { m.setView([home.lat, home.lng], 15); fitted.current = true }
  }, [L, trail, route, tech?.lat, tech?.lng, home?.lat, home?.lng, follow, head, order?.technicianName])

  const ago = tl?.updatedAt ? Math.max(0, Math.round((Date.now() - new Date(tl.updatedAt).getTime()) / 1000)) : null
  const arrived = ['arrived', 'in_progress'].includes(order?.status)
  return (
    <div className="relative rounded-2xl overflow-hidden border border-black/10" dir="rtl">
      <style>{'@keyframes mqpulse{0%{transform:scale(.6);opacity:.9}100%{transform:scale(1.6);opacity:0}}'}</style>
      <div ref={el} style={{ height: 320 }} className="w-full" />
      <div className="absolute top-2 inset-x-0 z-[500] flex justify-center pointer-events-none">
        <div className={`pointer-events-auto rounded-2xl px-4 py-2 shadow-xl border text-center ${light ? 'bg-white/95 border-black/10' : 'bg-[#0d1220]/95 border-white/10 text-white'}`}>
          {arrived ? <p className="font-black text-emerald-600 text-lg">✅ الفني وصل</p>
            : mins != null ? <>
              <p className="font-display font-black text-2xl text-blue-600 leading-7">{mins} <span className="text-sm">دقيقة</span></p>
              <p className="text-[11px] font-bold text-slate-500">{kmText(left.m)} · {order?.status === 'nearby' ? 'قريب منك جدًا' : 'في الطريق ليك'}</p>
            </> : <p className="text-[12px] font-bold text-slate-500">بانتظار موقع الفني…</p>}
        </div>
      </div>
      <div className="absolute bottom-2 right-2 z-[500] flex flex-col gap-1.5 items-end">
        {!follow && <button onClick={() => setFollow(true)} className="rounded-xl bg-blue-600 text-white text-[11px] font-black px-3 py-1.5 shadow flex items-center gap-1"><LocateFixed size={13} />تتبّع الفني</button>}
        {ago != null && <span className="rounded-lg bg-black/60 text-white text-[10px] px-2 py-0.5 flex items-center gap-1"><Navigation2 size={10} />تحديث {ago < 60 ? `من ${ago} ث` : `من ${Math.round(ago / 60)} د`}</span>}
      </div>
    </div>
  )
}
