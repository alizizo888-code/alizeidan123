import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import '../../lib/leafletCss'
import { addBaseLayers } from '../../lib/baseLayers'
import { Navigation, Crosshair, Sparkles, Loader2, Phone, Route, RefreshCw, X, MapPin } from 'lucide-react'
import { post, get, errText } from '../../lib/_api'
import { openNav } from '../../lib/native'
import Ago from '../Ago'
import PinCard from '../PinCard'

// «الخريطة الحية»: every technician as a coded, coloured live dot with the road he drove today, operations staff, and
// customers coloured by the technician carrying their job. Refreshes every 8 s (Mutqan_Route::fleet on the server).
// Tap a technician to follow him, see his next job + ETA and the open jobs on his road, and give him one in one tap.
const LEAFLET_CSS = 'bundled' // leaflet.css ships inside the app (the desk CSP blocks unpkg)
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
const EVERY = 8000
const PIN_KINDS = { client: 'عميل', active_client: 'عميل نشط', old_client: 'عميل قديم', tech: 'فني', shop: 'محل', other: 'تاني' }
const PIN_EMOJI = { client: '👤', active_client: '🟢', old_client: '🔁', tech: '🧰', shop: '🏪', other: '📌' }
const ST = { new: 'جديد', forced: 'بانتظار فني', assigned: 'مسند', accepted: 'مقبول', en_route: 'في الطريق', nearby: 'قريب', arrived: 'وصل', in_progress: 'شغال' }

function techIcon(L, t, selected) {
  const dim = !t.live ? 'opacity:.55;filter:grayscale(.5);' : ''
  const arrow = t.motion?.moving && t.motion.heading != null
    ? `<div style="position:absolute;left:50%;top:-11px;width:0;height:0;margin-left:-6px;border-left:6px solid transparent;border-right:6px solid transparent;border-bottom:10px solid ${t.color};transform-origin:6px 28px;transform:rotate(${t.motion.heading}deg)"></div>` : ''
  const ring = selected ? `box-shadow:0 0 0 4px #fff,0 0 0 8px ${t.color}88;` : 'box-shadow:0 2px 8px rgba(0,0,0,.5);'
  const pulse = t.live ? `<div style="position:absolute;inset:-6px;border-radius:999px;border:2px solid ${t.color};animation:mqlive 1.8s infinite"></div>` : ''
  return L.divIcon({
    className: '', iconSize: [46, 46], iconAnchor: [23, 23],
    html: `<div style="position:relative;width:46px;height:46px;${dim}">${pulse}${arrow}<div style="width:46px;height:46px;border-radius:999px;background:${t.color};border:3px solid #fff;${ring}display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-weight:900;line-height:1"><span style="font-size:11px">${esc(t.code)}</span><span style="font-size:13px">🧰</span></div></div>`,
  })
}

export default function LiveFleet({ onOpenOrder, canAssign = true, focus = null }) {
  const el = useRef(null), map = useRef(null), layer = useRef(null), fitted = useRef(false)
  const [L, setL] = useState(null)
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [sel, setSel] = useState(null)          // technician id
  const [follow, setFollow] = useState(false)
  const [show, setShow] = useState({ trails: true, staff: true, customers: true, free: true, old: true, pins: true })
  const [ai, setAi] = useState(null)
  const [busy, setBusy] = useState('')
  const [pin, setPin] = useState(null)          // the customer card opened from a pin
  const [homePick, setHomePick] = useState('')   // tech id: the next tap on the map is his home
  const [place, setPlace] = useState({})         // tech id → address of his last position
  const homePickRef = useRef(''); homePickRef.current = homePick
  const [old, setOld] = useState({ clients: [], pins: [] })   // every customer ever served + the hand-dropped pins
  const [oldCard, setOldCard] = useState(null)
  const [pinMode, setPinMode] = useState(false)
  const [draft, setDraft] = useState(null)       // a new pin being filled in
  const pinModeRef = useRef(false); pinModeRef.current = pinMode
  const [ord, setOrd] = useState(null)           // the order picked from the list beside the map
  const [ordPlace, setOrdPlace] = useState('')

  const load = useCallback(async () => {
    try { setData(await post('/ops', { op: 'fleet' })); setErr('') } catch (e) { setErr(errText(e, 'تعذر تحميل الخريطة')) }
  }, [])
  useEffect(() => { load(); const t = setInterval(() => { if (document.visibilityState === 'visible') load() }, EVERY); return () => clearInterval(t) }, [load])
  const loadOld = useCallback(async () => { try { const r = await post('/ops', { op: 'old_clients' }); setOld({ clients: r?.clients || [], pins: r?.pins || [] }) } catch { /* the layer is extra */ } }, [])
  useEffect(() => { loadOld(); const t = setInterval(() => { if (document.visibilityState === 'visible') loadOld() }, 120000); return () => clearInterval(t) }, [loadOld])

  useEffect(() => {
    void LEAFLET_CSS
    import('leaflet').then((m) => setL(m.default || m))
  }, [])
  useEffect(() => {
    if (!L || !el.current || map.current) return
    const m = L.map(el.current, { zoomControl: false }).setView([21.4225, 39.8262], 11)
    addBaseLayers(L, m)
    L.control.zoom({ position: 'bottomleft' }).addTo(m)
    layer.current = L.layerGroup().addTo(m); map.current = m
    // a new pin: «📌 دبوس جديد» then a tap, or a long press / right click anywhere
    m.on('contextmenu', (e) => setDraft({ lat: e.latlng.lat, lng: e.latlng.lng, kind: 'client', name: '', phone: '', note: '' }))
    m.on('click', async (e) => {
      if (pinModeRef.current) { setPinMode(false); setDraft({ lat: e.latlng.lat, lng: e.latlng.lng, kind: 'client', name: '', phone: '', note: '' }); return }
      const tid = homePickRef.current; if (!tid) return
      setHomePick('')
      try { await post('/ops', { op: 'set_home', tech: tid, lat: e.latlng.lat, lng: e.latlng.lng }); await loadRef.current?.() } catch (er) { alert(errText(er, 'ما اتحفظش')) }
    })
    setTimeout(() => m.invalidateSize(), 200)
    return () => { m.remove(); map.current = null }
  }, [L])

  const loadRef = useRef(null); loadRef.current = load
  const techs = useMemo(() => data?.techs || [], [data])
  const selT = techs.find((t) => t.id === sel) || null

  // draw
  useEffect(() => {
    if (!L || !map.current || !data) return
    const g = layer.current; g.clearLayers(); const all = []
    if (show.trails) {
      for (const t of techs) if (t.trail?.length > 1 && (!sel || sel === t.id)) L.polyline(t.trail, { color: t.color, weight: sel === t.id ? 5 : 3, opacity: sel === t.id ? 0.95 : 0.6 }).addTo(g)
      for (const s of show.staff ? data.staff || [] : []) if (s.trail?.length > 1) L.polyline(s.trail, { color: '#e2e8f0', weight: 2, dashArray: '4 6', opacity: 0.6 }).addTo(g)
    }
    // tech → his next job
    for (const t of techs) if (t.lat != null && t.job && !t.home && (!sel || sel === t.id)) L.polyline([[t.lat, t.lng], [t.job.lat, t.job.lng]], { color: t.color, weight: 2, dashArray: '8 8' }).addTo(g)
    // on-route suggestions of the selected technician
    if (selT?.lat != null) for (const r of selT.onRoute || []) { const o = (data.orders || []).find((x) => x.id === r.id); if (o) L.polyline([[selT.lat, selT.lng], [o.lat, o.lng]], { color: '#facc15', weight: 2, dashArray: '2 6' }).addTo(g) }
    if (show.customers) {
      for (const o of data.orders || []) {
        const free = !o.tech
        if (free && !show.free) continue
        const c = o.color || '#ef4444'
        const mk = L.marker([o.lat, o.lng], { icon: L.divIcon({ className: '', iconSize: [28, 28], iconAnchor: [14, 28], html: `<div style="width:28px;height:28px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${c};border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);${free ? 'animation:mqpulse 1.4s infinite;' : ''}display:flex;align-items:center;justify-content:center"><span style="transform:rotate(45deg);font-size:12px">${free ? '🔔' : '🏠'}</span></div>` }) }).addTo(g)
        mk.bindTooltip(esc(`#${o.n} ${o.name} · ${ST[o.s] || o.s}`), { direction: 'top' })
        mk.on('click', () => setPin({ orderId: o.id, lat: o.lat, lng: o.lng, title: o.name, emoji: free ? '🔔' : '🏠' }))
        all.push([o.lat, o.lng])
      }
    }
    // every customer ever served (the permanent ledger): 🔁 due for a visit (> 150 days), 👤 otherwise, grey when his last order was deleted
    if (show.old) {
      const liveKeys = new Set((data.orders || []).map((o) => `${o.lat.toFixed(4)},${o.lng.toFixed(4)}`))
      for (const c of old.clients) {
        if (liveKeys.has(`${c.lat.toFixed(4)},${c.lng.toFixed(4)}`)) continue
        const due = (c.days ?? 0) > 150, col = c.lastDeleted ? '#94a3b8' : due ? '#f59e0b' : '#3b82f6'
        const mk = L.marker([c.lat, c.lng], { icon: L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10], html: `<div style="width:20px;height:20px;border-radius:999px;background:${col};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;font-size:10px">${due ? '🔁' : '👤'}</div>` }) }).addTo(g)
        mk.bindTooltip(esc(`${c.name || c.phone || 'عميل قديم'} · ${c.n} طلب${c.last ? ' · آخرها ' + c.last : ''}${c.days != null ? ' · من ' + c.days + ' يوم' : ''}`), { direction: 'top' })
        mk.on('click', () => openOld(c))
      }
    }
    if (show.pins) for (const pn of old.pins) {
      const mk = L.marker([pn.lat, pn.lng], { icon: L.divIcon({ className: '', iconSize: [28, 28], iconAnchor: [14, 26], html: `<div style="font-size:22px;filter:drop-shadow(0 1px 2px #000)">${esc(pn.emoji)}</div>` }) }).addTo(g)
      mk.bindTooltip(esc(`${PIN_KINDS[pn.kind] || ''} ${pn.name}${pn.note ? ' — ' + pn.note : ''}`), { direction: 'top' })
      mk.on('click', () => setDraft({ ...pn }))
    }
    if (show.staff) for (const s of data.staff || []) {
      L.marker([s.lat, s.lng], { icon: L.divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 15], html: '<div style="width:30px;height:30px;border-radius:8px;background:#0f172a;border:2px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:15px">🧭</div>' }) })
        .addTo(g).bindTooltip(esc(`${s.name} (${s.role === 'supervisor' ? 'مشرف' : 'عمليات'})`), { direction: 'top' })
      all.push([s.lat, s.lng])
    }
    for (const t of techs) {
      if (t.lat == null) continue
      const mk = L.marker([t.lat, t.lng], { icon: techIcon(L, t, sel === t.id), zIndexOffset: sel === t.id ? 1000 : t.live ? 500 : 0 }).addTo(g)
      mk.bindTooltip(esc(`${t.code} ${t.name}${t.live ? (t.motion?.moving ? ` · ${t.motion.kmh} كم/س` : ' · واقف') : t.home ? ' · السكن' : ' · آخر موقع'}`), { direction: 'top', offset: [0, -20] })
      mk.on('click', () => setSel(t.id))
      all.push([t.lat, t.lng])
    }
    // the trip of the selected technician: where he started 🚩 → where he is → the customer
    const tr = selT?.job?.trip
    if (tr && selT.lat != null) {
      L.marker([tr.startLat, tr.startLng], { icon: L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [4, 24], html: '<div style="font-size:22px;filter:drop-shadow(0 1px 2px #000)">🚩</div>' }) }).addTo(g).bindTooltip(esc('بداية المشوار'), { direction: 'top' })
      L.polyline([[selT.lat, selT.lng], [selT.job.lat, selT.job.lng]], { color: selT.color, weight: 4, dashArray: '10 8', opacity: 0.95 }).addTo(g)
        .bindTooltip(esc(`باقي ${tr.left} كم · ~${tr.etaMin} د`), { permanent: true, direction: 'center', className: 'mq-leg' })
    }
    // the order picked from the list: a big pulsing pin, and the line from its technician
    if (ord?.lat != null) {
      L.marker([ord.lat, ord.lng], { zIndexOffset: 2000, icon: L.divIcon({ className: '', iconSize: [44, 44], iconAnchor: [22, 44], html: `<div style="position:relative;width:44px;height:44px"><div style="position:absolute;left:8px;top:30px;width:28px;height:28px;border-radius:999px;border:3px solid #facc15;animation:mqlive 1.4s infinite"></div><div style="width:44px;height:44px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:#facc15;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center"><span style="transform:rotate(45deg);font-size:18px">📍</span></div></div>` }) })
        .addTo(g).bindTooltip(esc(`#${ord.n} ${ord.title}`), { direction: 'top', offset: [0, -40], permanent: true })
      const ot = techs.find((t) => t.id === ord.tech)
      if (ot?.lat != null && sel !== ot.id) L.polyline([[ot.lat, ot.lng], [ord.lat, ord.lng]], { color: ot.color, weight: 3, dashArray: '8 8' }).addTo(g)
    }
    if (selT?.homeLat != null) {
      L.marker([selT.homeLat, selT.homeLng], { icon: L.divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 28], html: `<div style="font-size:24px;filter:drop-shadow(0 1px 2px #000)">🏡</div>` }) }).addTo(g).bindTooltip(esc('سكن ' + selT.name), { direction: 'top' })
    }
    if (follow && selT?.lat != null) map.current.setView([selT.lat, selT.lng], Math.max(map.current.getZoom(), 14), { animate: true })
    else if (all.length && !fitted.current) { map.current.fitBounds(all, { padding: [40, 40], maxZoom: 14 }); fitted.current = true }
  }, [L, data, techs, sel, selT, show, follow, onOpenOrder, ord, old])

  useEffect(() => {
    const t = selT; if (!t || t.lastLat == null || place[t.id]) return
    get(`/geocode?lat=${t.lastLat}&lng=${t.lastLng}`).then((r) => setPlace((p) => ({ ...p, [t.id]: r?.label || r?.address || r?.display || '' }))).catch(() => {})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selT?.id, selT?.lastLat])
  // A technician: fit him, his customer and where the trip started; a lone technician: zoom on him.
  const showTech = useCallback((t) => {
    setSel(t.id); setOrd(null)
    if (t.lat == null || !map.current) return
    const pts = [[t.lat, t.lng]]
    if (t.job) pts.push([t.job.lat, t.job.lng])
    if (t.job?.trip) pts.push([t.job.trip.startLat, t.job.trip.startLng])
    if (pts.length > 1) map.current.flyToBounds(pts, { padding: [60, 60], maxZoom: 16, duration: 0.8 })
    else map.current.flyTo([t.lat, t.lng], 16, { duration: 0.8 })
  }, [])
  const focusT = showTech
  // From the list beside the map: an order flies to its customer, a technician to him and his trip.
  const pending = useRef(null)
  useEffect(() => { if (focus) pending.current = focus }, [focus])
  useEffect(() => {
    const f = pending.current; if (!f || !map.current) return
    if (f.kind === 'tech') {
      const t = techs.find((x) => x.id === f.id); if (!t) { if (data) { pending.current = null; setErr('الفني ده مش ظاهر على الخريطة (مفيش موقع)') } return }
      pending.current = null; setErr(''); showTech(t); return
    }
    pending.current = null; setErr('')
    const row = (data?.orders || []).find((x) => x.id === f.id)
    const lat = f.lat ?? row?.lat ?? null, lng = f.lng ?? row?.lng ?? null
    setOrd({ ...f, lat, lng, tech: f.tech || row?.tech || '' }); setOrdPlace(''); setSel(null); setFollow(false)
    if (lat == null) return
    map.current.flyTo([lat, lng], 17, { duration: 0.9 })
    get(`/geocode?lat=${lat}&lng=${lng}`).then((g) => setOrdPlace(g?.label || g?.address || g?.display || '')).catch(() => {})
  }, [focus, data, techs, L, showTech])
  const ordTech = ord ? techs.find((t) => t.id === ord.tech) : null
  async function openOld(c) {
    setOldCard({ ...c, loading: true })
    try { const r = await post('/ops', { op: 'client_card', key: c.key }); setOldCard({ ...c, rec: r }) } catch (e) { setOldCard({ ...c, err: errText(e, 'تعذر') }) }
  }
  const savePin = async () => {
    try { await post('/ops', { op: 'pin_save', ...draft }); setDraft(null); loadOld() } catch (e) { alert(errText(e, 'ما اتحفظش')) }
  }
  const delPin = async () => {
    if (!draft?.id || !confirm('تمسح الدبوس ده؟')) return
    try { await post('/ops', { op: 'pin_del', id: draft.id }); setDraft(null); loadOld() } catch (e) { alert(errText(e, 'ما اتمسحش')) }
  }
  const give = async (orderId, techId) => {
    setBusy(orderId)
    try { await post('/ops', { op: 'route_assign', order: orderId, tech: techId }); await load() } catch (e) { alert(errText(e, 'ما اتسندش')) } finally { setBusy('') }
  }
  const askAi = async () => { setAi({ loading: true }); try { setAi(await post('/ops', { op: 'fleet_ai' })) } catch (e) { setAi({ text: errText(e, 'تعذر') }) } }

  const live = techs.filter((t) => t.live)
  const list = [...techs].sort((a, b) => (b.live - a.live) || ((b.lat != null) - (a.lat != null)) || a.code.localeCompare(b.code))
  const Toggle = ({ k, label }) => <button onClick={() => setShow((s) => ({ ...s, [k]: !s[k] }))} className={`shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-bold ${show[k] ? 'bg-white/10 border-white/30 text-white' : 'border-white/10 text-slate-500'}`}>{label}</button>

  return (
    <div className="flex flex-col gap-2" dir="rtl">
      <style>{'@keyframes mqlive{0%{transform:scale(.9);opacity:.9}100%{transform:scale(1.5);opacity:0}}@keyframes mqpulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.7)}100%{box-shadow:0 0 0 14px rgba(239,68,68,0)}}.mq-leg{background:#0d1220;color:#fde68a;border:1px solid #facc15;font-weight:900;font-size:11px}'}</style>
      <div className="flex items-center gap-2 flex-wrap text-[12px]">
        <span className="rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-400/30 px-2.5 py-1 font-black">🟢 مباشر {live.length}</span>
        <span className="rounded-full bg-sky-500/15 text-sky-300 border border-sky-400/30 px-2.5 py-1 font-black">🚗 ماشي {data?.count?.moving ?? 0}</span>
        <span className="rounded-full bg-rose-500/15 text-rose-300 border border-rose-400/30 px-2.5 py-1 font-black">🔔 من غير فني {data?.count?.free ?? 0}</span>
        {data?.at && <span className="text-slate-500 flex items-center gap-1"><RefreshCw size={11} className="animate-spin [animation-duration:4s]" />تحديث <Ago at={data.at} /></span>}
        <span className="flex-1" />
        <button onClick={askAi} className="rounded-xl bg-violet-500/20 border border-violet-400/40 text-violet-200 px-3 py-1.5 font-black flex items-center gap-1"><Sparkles size={13} />وزّع بالذكاء</button>
      </div>
      <div className="flex gap-1.5 overflow-x-auto pb-1"><Toggle k="trails" label="〰️ خطوط السير" /><Toggle k="customers" label="🏠 العملاء" /><Toggle k="free" label="🔔 طلبات من غير فني" /><Toggle k="staff" label="🧭 العمليات والمشرفين" /><Toggle k="old" label={`🔁 كل العملاء القدامى (${old.clients.length})`} /><Toggle k="pins" label={`📌 الدبابيس (${old.pins.length})`} />
        {canAssign && <button onClick={() => setPinMode((v) => !v)} className={`shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-black ${pinMode ? 'bg-amber-400 text-black border-amber-400 animate-pulse' : 'border-amber-400/50 text-amber-200'}`}>{pinMode ? '👆 اضغط المكان على الخريطة…' : '📌 دبوس جديد'}</button>}</div>
      {err && <p className="text-rose-300 text-xs font-bold">{err}</p>}
      {ai && (
        <div className="rounded-2xl border border-violet-400/30 bg-violet-500/10 p-3 text-[13px] text-violet-50 relative">
          <button onClick={() => setAi(null)} className="absolute left-2 top-2 text-violet-200"><X size={15} /></button>
          {ai.loading ? <p className="flex items-center gap-2"><Loader2 size={14} className="animate-spin" />بيحلل الخريطة…</p> : <p className="whitespace-pre-wrap leading-relaxed pl-5">{ai.text}</p>}
        </div>
      )}

      <div className="grid lg:grid-cols-[1fr_320px] gap-2">
        <div className="relative rounded-2xl overflow-hidden border border-white/10">
          <div ref={el} style={{ height: 520 }} className="w-full bg-slate-900" />
          {ord && (
            <div className="absolute inset-x-2 top-2 z-[500] rounded-2xl bg-[#0d1220]/95 backdrop-blur border border-yellow-400/60 p-3 grid gap-1.5 text-[12px]">
              <button onClick={() => setOrd(null)} className="absolute left-2 top-2 text-slate-400"><X size={16} /></button>
              <p className="font-black text-white text-sm pl-5">📍 #{ord.n} {ord.title}</p>
              {ord.lat == null ? <p className="text-amber-300 font-bold">الطلب ده لسه من غير لوكيشن — اطلب من العميل يبعت اللوكيشن نفسه.</p> : <>
                <p className="text-slate-200">{ordPlace || ord.address || `${Number(ord.lat).toFixed(5)}, ${Number(ord.lng).toFixed(5)}`}</p>
                {ordPlace && ord.address && <p className="text-slate-400">🏠 {ord.address}</p>}
              </>}
              {ordTech ? (
                <button onClick={() => showTech(ordTech)} className="text-right rounded-lg bg-white/5 px-2 py-1.5">
                  <span className="rounded-md px-1.5 py-0.5 text-[10px] font-black ml-1" style={{ background: ordTech.color }}>{ordTech.code}</span>
                  <b>{ordTech.name}</b>
                  {ordTech.job?.id === ord.id && ordTech.job.trip ? <> · قطع {ordTech.job.trip.done} من {ordTech.job.trip.total} كم · باقي {ordTech.job.trip.left} كم (~{ordTech.job.trip.etaMin} د)</>
                    : ordTech.job?.id === ord.id && ordTech.job.km != null ? <> · على بعد {ordTech.job.km} كم (~{ordTech.job.etaMin} د)</>
                    : ordTech.lat == null ? ' · مفيش موقع للفني' : ordTech.job ? ` · دلوقتي رايح طلب #${ordTech.job.n}` : ''}
                  <span className="text-sky-300"> · خط سيره ›</span>
                </button>
              ) : ord.tech ? <p className="text-slate-400">👷 الفني مش ظاهر على الخريطة دلوقتي</p> : <p className="text-rose-300 font-bold">🔔 لسه من غير فني</p>}
              <div className="flex gap-1.5">
                <button onClick={() => onOpenOrder?.({ id: ord.id })} className="flex-1 rounded-xl bg-primary text-black font-black py-1.5">افتح الطلب</button>
                {ord.lat != null && <button onClick={() => map.current?.flyTo([ord.lat, ord.lng], 18, { duration: 0.6 })} className="rounded-xl bg-white/10 px-3 font-bold">قرّب أكتر</button>}
                {ord.lat != null && <button onClick={() => openNav(ord.lat, ord.lng, 'google')} className="rounded-xl bg-white/10 px-3 font-bold flex items-center gap-1"><Navigation size={12} />روح</button>}
              </div>
            </div>
          )}
          {draft && (
            <div className="absolute inset-x-2 top-2 z-[600] rounded-2xl bg-[#0d1220]/95 backdrop-blur border border-amber-400/60 p-3 grid gap-1.5 text-[12px]">
              <button onClick={() => setDraft(null)} className="absolute left-2 top-2 text-slate-400"><X size={16} /></button>
              <p className="font-black text-white">{draft.id ? '📌 دبوس' : '📌 دبوس جديد'} <span className="text-slate-400 font-normal" dir="ltr">{Number(draft.lat).toFixed(5)}, {Number(draft.lng).toFixed(5)}</span></p>
              <div className="flex flex-wrap gap-1">{Object.keys(PIN_KINDS).map((k) => <button key={k} onClick={() => setDraft((d) => ({ ...d, kind: k }))} className={`rounded-full px-2.5 py-1 text-[11px] font-bold border ${draft.kind === k ? 'bg-amber-400 text-black border-amber-400' : 'border-white/15'}`}>{PIN_EMOJI[k]} {PIN_KINDS[k]}</button>)}</div>
              <input value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} placeholder="الاسم" className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5" />
              <input value={draft.phone} onChange={(e) => setDraft((d) => ({ ...d, phone: e.target.value }))} placeholder="الجوال (اختياري)" dir="ltr" className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5" />
              <input value={draft.note} onChange={(e) => setDraft((d) => ({ ...d, note: e.target.value }))} placeholder="ملاحظة" className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5" />
              <div className="flex gap-1.5">
                {canAssign && <button onClick={savePin} className="flex-1 rounded-xl bg-amber-400 text-black font-black py-1.5">حفظ</button>}
                {draft.phone && <a href={`tel:${draft.phone}`} className="rounded-xl bg-emerald-600 px-3 flex items-center"><Phone size={13} /></a>}
                {draft.id && <button onClick={delPin} className="rounded-xl bg-rose-500/20 text-rose-200 px-3 font-bold">مسح</button>}
              </div>
            </div>
          )}
          {oldCard && !draft && (
            <div className="absolute inset-x-2 top-2 z-[550] max-h-[70%] overflow-y-auto rounded-2xl bg-[#0d1220]/95 backdrop-blur border border-sky-400/50 p-3 grid gap-1.5 text-[12px]">
              <button onClick={() => setOldCard(null)} className="absolute left-2 top-2 text-slate-400"><X size={16} /></button>
              <p className="font-black text-white text-sm pl-5">{(oldCard.days ?? 0) > 150 ? '🔁' : '👤'} {oldCard.name || 'عميل قديم'} {oldCard.phone && <span className="text-slate-400 font-normal" dir="ltr">{oldCard.phone}</span>}</p>
              <p className="text-slate-300">✅ اتعمل عنده قبل كده · {oldCard.n} طلب ({oldCard.done} اتقفل){oldCard.days != null ? ` · آخر مرة من ${oldCard.days} يوم` : ''}</p>
              {oldCard.address && <p className="text-slate-400">🏠 {oldCard.address}</p>}
              {(oldCard.via || []).length > 0 && <p className="text-slate-400">جه عن طريق: {oldCard.via.join('، ')}{(oldCard.techs || []).length ? ` · الفنيين: ${oldCard.techs.join('، ')}` : ''}</p>}
              {oldCard.loading && <p className="text-slate-500 flex items-center gap-1"><Loader2 size={12} className="animate-spin" />السجل…</p>}
              {oldCard.err && <p className="text-rose-300">{oldCard.err}</p>}
              {(oldCard.rec?.orders || []).map((x) => (
                <button key={x.id} onClick={() => !x.deleted && onOpenOrder?.({ id: x.id })} className={`text-right rounded-lg px-2 py-1.5 ${x.deleted ? 'bg-white/[0.02] text-slate-500' : 'bg-white/5'}`}>
                  <b>#{x.number || String(x.id).slice(-5)}</b> {x.service || ''} · {ST[x.status] || (x.status === 'completed' ? 'اتقفل' : x.status === 'cancelled' ? 'ملغي' : x.status)}
                  {x.technicianName ? ` · ${x.technicianName}` : ''}{x.agreedPrice ? ` · ${x.agreedPrice} ر.س` : ''} · {String(x.createdAt || '').slice(0, 10)}{x.deleted ? ' · (الطلب اتمسح)' : ''}
                </button>
              ))}
              <div className="flex gap-1.5">
                {oldCard.phone && <a href={`tel:${oldCard.phone}`} className="rounded-xl bg-emerald-600 px-3 py-1.5 font-bold flex items-center gap-1"><Phone size={13} />اتصال</a>}
                <button onClick={() => openNav(oldCard.lat, oldCard.lng, 'google')} className="rounded-xl bg-white/10 px-3 py-1.5 font-bold flex items-center gap-1"><Navigation size={12} />روح</button>
              </div>
            </div>
          )}
          {selT && (
            <div className="absolute inset-x-2 bottom-2 z-[500] max-h-[60%] overflow-y-auto rounded-2xl bg-[#0d1220]/95 backdrop-blur border p-3 grid gap-1.5" style={{ borderColor: selT.color }}>
              <button onClick={() => { setSel(null); setFollow(false) }} className="absolute left-2 top-2 text-slate-400"><X size={16} /></button>
              <p className="font-black text-white text-sm flex items-center gap-2"><span className="rounded-md px-1.5 py-0.5 text-[11px]" style={{ background: selT.color }}>{selT.code}</span>{selT.name}</p>
              <p className="text-xs text-slate-300">
                {selT.lat == null ? 'مفيش موقع — التطبيق مقفول أو مش «متصل»' : selT.live ? (selT.motion.moving ? `🚗 ماشي ${selT.motion.kmh} كم/س` : `🅿️ واقف من ${selT.motion.parkedMin} دقيقة`) : selT.home ? '🏠 موقع السكن' : <>آخر موقع <Ago at={Date.now() - (selT.ago || 0) * 1000} /></>}
                {selT.motion?.km > 0 && ` · قطع ${selT.motion.km} كم النهارده`}
              </p>
              <div className="grid grid-cols-2 gap-1 text-[11px]">
                <span className="rounded-lg bg-white/5 px-2 py-1">{selT.battery != null ? <span className={selT.battery <= 20 && !selT.charging ? 'text-rose-300 font-black' : 'text-slate-200'}>🔋 {selT.battery}%{selT.charging ? ' ⚡ بيشحن' : ''}{selT.net ? ` · ${selT.net}` : ''}</span> : <span className="text-slate-500">🔋 البطارية مش معروفة</span>}</span>
                <span className="rounded-lg bg-white/5 px-2 py-1 text-slate-200">{selT.activeSince && selT.live ? <>🟢 نشط من <Ago at={selT.activeSince} /></> : selT.shift ? '🟡 «متصل» من غير موقع' : '⚪ مش متصل'}</span>
                <span className="col-span-2 rounded-lg bg-white/5 px-2 py-1 text-slate-300">👁️ آخر ظهور {selT.lastSeenAt ? <Ago at={selT.lastSeenAt} /> : '—'}{place[selT.id] ? ` — ${place[selT.id]}` : selT.lastLat != null ? ` — ${Number(selT.lastLat).toFixed(4)}, ${Number(selT.lastLng).toFixed(4)}` : ''}</span>
                <span className="col-span-2 rounded-lg bg-white/5 px-2 py-1 text-slate-300">🏡 السكن: {selT.homeLat != null ? `${Number(selT.homeLat).toFixed(5)}, ${Number(selT.homeLng).toFixed(5)}` : 'مش محدد'}</span>
              </div>
              {canAssign && (
                <div className="flex gap-1.5">
                  <button onClick={() => setHomePick(homePick === selT.id ? '' : selT.id)} className={`flex-1 rounded-xl py-1.5 text-[11px] font-black ${homePick === selT.id ? 'bg-amber-400 text-black animate-pulse' : 'bg-white/10'}`}>{homePick === selT.id ? '👆 اضغط مكان السكن على الخريطة…' : '🏡 حدد السكن على الخريطة'}</button>
                  {selT.lastLat != null && <button onClick={() => map.current?.setView([selT.lastLat, selT.lastLng], 18)} className="rounded-xl bg-white/10 px-2.5 text-[11px] font-bold">آخر مكان</button>}
                  {selT.homeLat != null && <button onClick={() => map.current?.setView([selT.homeLat, selT.homeLng], 18)} className="rounded-xl bg-white/10 px-2.5 text-[11px] font-bold">السكن</button>}
                </div>
              )}
              {homePick === selT.id && <p className="text-[10px] text-amber-200">💡 حوّل لـ«قمر صناعي» (الزرار فوق الخريطة) وقرّب لحد ما تشوف البيت بالظبط، وبعدين اضغط عليه.</p>}
              {selT.job && (
                <div className="rounded-lg bg-white/5 px-2 py-1.5 text-[12px] grid gap-1">
                  <button onClick={() => onOpenOrder?.({ id: selT.job.id })} className="text-right font-bold text-white">📍 تبع طلب #{selT.job.n} {selT.job.client} — {ST[selT.job.s] || selT.job.s} ›</button>
                  {selT.job.trip ? (() => { const tr = selT.job.trip; return (<>
                    <div className="h-2 rounded-full bg-white/10 overflow-hidden"><div className="h-full rounded-full" style={{ width: `${Math.min(100, tr.pct)}%`, background: selT.color }} /></div>
                    <div className="grid grid-cols-4 gap-1 text-center text-[10px]">
                      <span className="rounded bg-white/5 py-1"><b className="block text-[12px] text-white">{tr.total}</b>كم المشوار كله</span>
                      <span className="rounded bg-white/5 py-1"><b className="block text-[12px] text-emerald-300">{tr.done}</b>كم قطعها</span>
                      <span className="rounded bg-white/5 py-1"><b className="block text-[12px] text-amber-300">{tr.left}</b>كم باقي</span>
                      <span className="rounded bg-white/5 py-1"><b className="block text-[12px] text-sky-300">{tr.arrived ? 'وصل' : `~${tr.etaMin}`}</b>{tr.arrived ? '✅' : 'د للوصول'}</span>
                    </div>
                    <p className="text-[10px] text-slate-400">🚩 بدأ المشوار من {tr.min} دقيقة · قطع {tr.pct}%</p>
                  </>) })() : selT.job.etaMin ? <p className="text-[11px] text-slate-300">على بعد {selT.job.km} كم · يوصل خلال ~{selT.job.etaMin} د (المشوار بيتحسب أول ما يبقى موقعه مباشر)</p> : <p className="text-[11px] text-slate-500">مفيش موقع مباشر للفني عشان نحسب المسافة.</p>}
                </div>
              )}
              {(selT.onRoute || []).length > 0 && <p className="text-[11px] text-yellow-300 font-black mt-1 flex items-center gap-1"><Route size={12} />مهام على طريقه</p>}
              {(selT.onRoute || []).map((r) => (
                <div key={r.id} className="flex items-center gap-2 rounded-lg bg-yellow-400/10 border border-yellow-400/25 px-2 py-1.5">
                  <button onClick={() => onOpenOrder?.({ id: r.id })} className="flex-1 text-right text-[12px]"><b>#{r.n}</b> {r.client} {r.svc && `· ${r.svc}`}<br /><span className="text-[11px] text-yellow-200/80">{r.why}</span></button>
                  {canAssign && <button disabled={busy === r.id} onClick={() => give(r.id, selT.id)} className="shrink-0 rounded-lg bg-yellow-400 text-black font-black text-[11px] px-2.5 py-1.5 disabled:opacity-50">{busy === r.id ? '…' : 'اسندها له'}</button>}
                </div>
              ))}
              <div className="flex gap-2 mt-1">
                {selT.lat != null && <button onClick={() => setFollow((f) => !f)} className={`flex-1 rounded-xl py-2 text-xs font-bold flex items-center justify-center gap-1 ${follow ? 'bg-primary text-black' : 'bg-white/10'}`}><Crosshair size={13} />{follow ? 'بتتابعه' : 'تابعه'}</button>}
                {selT.lat != null && <button onClick={() => openNav(selT.lat, selT.lng, 'google')} className="flex-1 rounded-xl bg-white/10 py-2 text-xs font-bold flex items-center justify-center gap-1"><Navigation size={13} />روح له</button>}
                {selT.phone && <a href={`tel:${selT.phone}`} className="rounded-xl bg-emerald-600 px-3 flex items-center"><Phone size={14} /></a>}
              </div>
            </div>
          )}
        </div>

        <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-2 grid content-start gap-1.5 max-h-[520px] overflow-y-auto">
          {!data && <p className="text-slate-500 text-xs p-3 flex items-center gap-2"><Loader2 size={13} className="animate-spin" />بيحمّل…</p>}
          {list.map((t) => (
            <button key={t.id} onClick={() => focusT(t)} className={`text-right rounded-xl px-2.5 py-2 border flex items-center gap-2 ${sel === t.id ? 'bg-white/10' : 'bg-white/[0.03] border-white/10'}`} style={sel === t.id ? { borderColor: t.color } : undefined}>
              <span className="w-9 h-9 shrink-0 rounded-full flex items-center justify-center text-[10px] font-black text-white border-2 border-white" style={{ background: t.color, opacity: t.live ? 1 : 0.5 }}>{t.code}</span>
              <span className="flex-1 min-w-0">
                <span className="block text-[13px] font-bold truncate">{t.name}{t.off && <span className="text-[10px] text-slate-500"> · مقفول</span>}</span>
                <span className="block text-[11px] text-slate-400 truncate">
                  {t.lat == null ? 'مفيش موقع' : t.live ? (t.motion.moving ? `🚗 ${t.motion.kmh} كم/س` : '🅿️ واقف') : t.home ? '🏠 السكن' : 'آخر موقع قديم'}
                  {t.job ? ` · #${t.job.n}${t.job.etaMin ? ` ~${t.job.etaMin}د` : ''}` : t.lat != null ? ' · فاضي' : ''}
                  {t.battery != null ? ` · 🔋${t.battery}%${t.charging ? '⚡' : ''}` : ''}
                </span>
              </span>
              {(t.onRoute || []).length > 0 && <span className="shrink-0 rounded-full bg-yellow-400 text-black text-[10px] font-black px-1.5 py-0.5" title="مهام على طريقه">🧭{t.onRoute.length}</span>}
            </button>
          ))}
          {data && !techs.length && <p className="text-slate-500 text-xs p-3">مفيش فنيين.</p>}
        </div>
      </div>
      {pin && <PinCard pin={pin} onClose={() => setPin(null)} />}
      <p className="text-[10px] text-slate-500 flex items-start gap-1"><MapPin size={11} className="shrink-0 mt-0.5" />كل فني بكود ولون ثابت. 🔁/👤 كل عميل اتعمل عنده قبل كده بيفضل على الخريطة حتى لو الطلب اتمسح. 📌 دبوس جديد: الزرار وبعدين اضغط المكان، أو ضغطة طويلة على الخريطة. النقطة بتنبض لما الموقع مباشر (آخر 10 دقايق)، والسهم اتجاهه وهو ماشي. خط السير بيتسجل بس وهو «متصل» أو عنده شغل، وبيتمسح لوحده بعد 12 ساعة أو لما يقفل.</p>
    </div>
  )
}
