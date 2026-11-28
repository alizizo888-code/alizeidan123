import { deviceInfo } from '../lib/deviceHealth'
import { isNative, openNav } from '../lib/native'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Navigation, Phone } from 'lucide-react'
import { etaText, kmText } from '../lib/eta'
import { orderFacts } from '../lib/orderFacts'
import FieldMapCanvas from './FieldMapCanvas'
import { maps } from '../lib/maps'
import { isTechAvailable } from '../assignment'
import { ACTIVE_STATUSES, specialtyLabel } from '../constants'
import { seenAgo } from '../lib/crm'
import { post } from '../lib/_api'
import PinCard from './PinCard'

const navUrl = (lat, lng) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
// Inside the app the link opens Google Maps' own navigation directly (lib/native); in a browser it stays a web link.
const navClick = (lat, lng, app = 'google') => (e) => { if (isNative()) { e.preventDefault(); openNav(lat, lng, app) } }

// Technician field map: my live position, the nearest colleague, the customer I'm heading to and the nearest open repair shops.
export default function TechMap({ myId, myProfile, profiles, orders, shops, upsertProfile }) {
  const [me, setMe] = useState(null)
  const [err, setErr] = useState('')
  const lastWrite = useRef(0)
  const profRef = useRef(myProfile)
  profRef.current = myProfile

  useEffect(() => {
    const w = maps.watchLocation((p) => {
      setMe({ lat: p.lat, lng: p.lng })
      const now = Date.now()
      // publish my position (at most every 30 s) so operations and colleagues see "last seen" and distance
      if (myId && profRef.current && now - lastWrite.current > 30000) {
        lastWrite.current = now
        deviceInfo().then((d) => upsertProfile({ ...profRef.current, userId: myId, lat: p.lat, lng: p.lng, lastSeenAt: new Date().toISOString(), ...d })).catch(() => {})
      }
    })
    maps.getCurrentLocation().then((p) => setMe({ lat: p.lat, lng: p.lng })).catch(() => setErr('فعّل خدمة الموقع ليظهر موقعك على الخريطة'))
    return () => w.stop()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [myId])

  const here = me || (myProfile?.lat != null ? { lat: myProfile.lat, lng: myProfile.lng } : myProfile?.homeLat != null ? { lat: myProfile.homeLat, lng: myProfile.homeLng } : null)
  const d = (a, b) => { try { return maps.distance(a.lat, a.lng, b.lat, b.lng) } catch { return Infinity } }

  const mine = useMemo(() => (orders || []).filter((o) => o.technicianId === myId && ACTIVE_STATUSES.includes(o.status) && o.lat != null), [orders, myId])
  const nearShops = useMemo(() => here ? (shops || []).filter((s) => s.lat != null && s.available !== false).map((s) => ({ s, dist: d(here, s) })).sort((a, b) => a.dist - b.dist).slice(0, 6) : [], [here, shops])

  const nav = useNavigate()
  const [view, setView] = useState('map')
  const [pin, setPin] = useState(null)
  // Old customers around me (any technician served them): fetched again after I move ~1.5 km or every 10 minutes.
  const [near, setNear] = useState([])
  const [showNear, setShowNear] = useState(true)
  const nearAt = useRef({ lat: null, lng: null, t: 0 })
  useEffect(() => {
    if (!here) return
    const last = nearAt.current
    const moved = last.lat == null ? Infinity : d(last, here)
    if (moved < 1500 && Date.now() - last.t < 600000) return
    nearAt.current = { lat: here.lat, lng: here.lng, t: Date.now() }
    post('/field', { op: 'near', lat: here.lat, lng: here.lng, km: 6 }).then((r) => setNear(Array.isArray(r) ? r : [])).catch(() => {})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [here?.lat, here?.lng])

  // Schedule: my live jobs in the order they will be visited (appointment first, then nearest), each leg with its distance and arrival estimate.
  const route = useMemo(() => {
    const list = [...mine].sort((a, b) => (a.appointmentAt ? new Date(a.appointmentAt).getTime() : Infinity) - (b.appointmentAt ? new Date(b.appointmentAt).getTime() : Infinity))
    const timed = list.filter((o) => o.appointmentAt), loose = list.filter((o) => !o.appointmentAt)
    let cur = here, left = [...loose]; const order = [...timed]
    while (left.length) { // nearest-first for the jobs without a fixed time
      const base = order.length ? order[order.length - 1] : cur
      let bi = 0, bd = Infinity
      left.forEach((o, i) => { const dd = base && base.lat != null ? d(base, o) : 0; if (dd < bd) { bd = dd; bi = i } })
      order.push(left.splice(bi, 1)[0])
    }
    let prev = here
    return order.map((o) => { const leg = prev ? d(prev, o) : null; prev = o; return { o, leg } })
  }, [mine, here])
  const open = useMemo(() => (orders || []).filter((o) => !o.technicianId && ['new', 'forced'].includes(o.status) && o.lat != null).map((o) => ({ o, dist: here ? d(here, o) : null }))
    .sort((a, b) => (b.o.priority === 'urgent') - (a.o.priority === 'urgent') || (a.dist ?? 0) - (b.dist ?? 0)).slice(0, 12), [orders, here])
  const past = useMemo(() => {
    const m = new Map()
    for (const o of orders || []) {
      if (o.technicianId !== myId || !['completed', 'closed'].includes(o.status) || o.lat == null) continue
      const k = o.clientPhone || o.clientName || o.id
      const at = new Date(o.completedAt || o.updatedAt || o.createdAt).getTime()
      const cur = m.get(k)
      if (!cur) m.set(k, { k, name: o.clientName, phone: o.clientPhone, lat: o.lat, lng: o.lng, address: o.address, last: at, n: 1, lastService: o.specialtyId })
      else { cur.n++; if (at > cur.last) { cur.last = at; cur.lastService = o.specialtyId; cur.lat = o.lat; cur.lng = o.lng } }
    }
    return [...m.values()].map((c) => ({ ...c, days: Math.floor((Date.now() - c.last) / 86400000), dist: here ? d(here, c) : null })).sort((a, b) => b.days - a.days)
  }, [orders, myId, here])
  const mates = useMemo(() => {
    if (!here) return []
    return (profiles || []).filter((t) => t.userId !== myId && !t.isSite && (t.lat ?? t.homeLat) != null)
      .map((t) => { const lat = t.lat ?? t.homeLat, lng = t.lng ?? t.homeLng; return { t, lat, lng, dist: d(here, { lat, lng }), free: isTechAvailable(t, orders || []) } })
      .sort((a, b) => a.dist - b.dist).slice(0, 6)
  }, [here, profiles, myId, orders])

  const points = view === 'customers' ? [
    ...open.map(({ o }) => ({ id: 'j' + o.id, lat: o.lat, lng: o.lng, emoji: o.priority === 'urgent' ? '🚨' : '🔔', color: '#ef4444', pulse: o.priority === 'urgent', label: `${o.clientName || 'عميل'} — ${specialtyLabel(o.specialtyId)}` })),
    ...past.map((c) => ({ id: 'p' + c.k, lat: c.lat, lng: c.lng, emoji: c.days > 150 ? '🔁' : '👤', color: c.days > 150 ? '#f59e0b' : '#3b82f6', label: `${c.name || 'عميل'} — منذ ${c.days} يومًا` })),
  ] : [
    ...mine.map((o) => ({ id: 'o' + o.id, lat: o.lat, lng: o.lng, emoji: '🏠', color: '#ef4444', label: `${o.clientName || 'عميل'} — وجهتي` })),
    ...mates.map(({ t, lat, lng, free }, i) => ({ id: 'nt' + t.userId, lat, lng, emoji: '🧰', color: free ? '#10b981' : '#64748b', label: `${t.displayName || 'فني'}${i === 0 ? ' (الأقرب)' : ''} · ${free ? 'متاح' : 'مشغول'}` })),
    ...nearShops.map(({ s }) => ({ id: 's' + s.id, lat: s.lat, lng: s.lng, emoji: '🏪', color: '#8b5cf6', label: s.name })),
  ]
  const nearPts = (view === 'customers' || showNear) ? near.filter((c) => !past.some((p) => Math.abs(p.lat - c.lat) < 1e-4 && Math.abs(p.lng - c.lng) < 1e-4))
    .map((c) => ({ id: 'n' + c.key, lat: c.lat, lng: c.lng, emoji: (c.days ?? 0) > 150 ? '🔁' : '👤', color: (c.days ?? 0) > 150 ? '#f59e0b' : '#60a5fa', label: `${c.name || 'عميل قديم'} — ${c.lastService || ''}${c.days != null ? ` · من ${c.days} يوم` : ''}` })) : []
  const allPoints = view === 'plan' ? points : [...points, ...nearPts]
  // Tapping a pin opens its full card (who, rating, job, place, history + share / GPS / drive / open order).
  const onPin = (p) => {
    const id = String(p.id || ''); const k = id.slice(0, 1)
    if (k === 'o' || k === 'j') { const o = (orders || []).find((x) => 'o' + x.id === id || 'j' + x.id === id); if (o) setPin({ orderId: o.id, lat: o.lat, lng: o.lng, title: o.clientName, emoji: k === 'o' ? '🏠' : '🔔', phoneDirect: o.technicianId === myId ? o.clientPhone : '' }) }
    else if (k === 'p') { const c = past.find((x) => 'p' + x.k === id); if (c) setPin({ kind: 'customer', phone: c.phone || '', key: c.phone ? '' : c.k, lat: c.lat, lng: c.lng, title: c.name, emoji: '👤', phoneDirect: c.phone }) }
    else if (k === 'n') { const c = near.find((x) => 'n' + x.key === id); if (c) setPin({ kind: 'customer', orderId: c.orderId, lat: c.lat, lng: c.lng, title: c.name, emoji: '🔁' }) }
    else if (id.startsWith('nt')) { const m = mates.find((x) => 'nt' + x.t.userId === id); if (m) setPin({ kind: 'tech', lat: m.lat, lng: m.lng, title: m.t.displayName || 'فني', emoji: '🧰', phoneDirect: m.t.phone || '', extra: <p className="text-[11px] text-slate-400 mt-2">{m.free ? 'متاح' : 'مشغول'} · آخر ظهور {seenAgo(m.t.lastSeenAt)}</p> }) }
    else if (k === 's') { const sh = nearShops.find((x) => 's' + x.s.id === id); if (sh) setPin({ kind: 'shop', lat: sh.s.lat, lng: sh.s.lng, title: sh.s.name, emoji: '🏪', phoneDirect: sh.s.phone || '' }) }
  }
  // The straight line from me to my customer carries the distance + arrival estimate right on the map.
  const lines = here && view === 'map' ? mine.map((o) => { const m = d(here, o); return { from: [here.lat, here.lng], to: [o.lat, o.lng], color: '#ef4444', label: `${kmText(m)} · ${etaText(m)}` } }) : []
  const tab = (id, label) => <button key={id} role="tab" aria-selected={view === id} onClick={() => setView(id)} className={`flex-1 rounded-xl py-2 text-xs font-black border ${view === id ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300 bg-white/5'}`}>{label}</button>
  const card = 'bg-white/5 border border-white/10 rounded-2xl p-3 flex items-center gap-2'
  const goBtn = (lat, lng) => <a href={navUrl(lat, lng)} onClick={navClick(lat, lng)} target="_blank" rel="noreferrer" className="shrink-0 bg-primary text-black rounded-xl px-3 py-2 text-xs font-black flex items-center gap-1"><Navigation size={14} />توجيه</a>

  return (
    <div className="grid gap-3">
      {err && <p className="text-xs text-amber-300 bg-amber-500/10 rounded-xl p-2">{err}</p>}
      <div className="flex gap-1.5" role="tablist">{tab('map', '🗺️ الخريطة')}{tab('plan', '📅 جدول اليوم')}{tab('customers', '👥 العملاء')}</div>
      {view !== 'plan' && <FieldMapCanvas points={allPoints} lines={lines} me={here} height={330} onSelect={onPin} />}
      {view === 'map' && <button onClick={() => setShowNear((v) => !v)} className={`self-start rounded-full border px-3 py-1 text-[11px] font-bold ${showNear ? 'border-amber-400/50 bg-amber-500/15 text-amber-200' : 'border-white/10 text-slate-400'}`}>🔁 العملاء القدامى حواليا ({near.length})</button>}
      <p className="text-[10px] text-slate-500 -mt-1">اضغط على أي علامة في الخريطة يفتح لك كارتها: العميل وتقييمه وطلبه وسجله، ومشاركة الموقع والـGPS والتوجّه.</p>
      {pin && <PinCard pin={pin} me={here} onClose={() => setPin(null)} />}

      {view === 'map' && (
        <div className="grid gap-2">
          {mine.map((o) => { const m = here ? d(here, o) : null; return (
            <div key={o.id} className={card}>
              <div className="flex-1 min-w-0"><p className="text-sm font-black text-white truncate">🏠 {o.clientName || 'عميل'} — {specialtyLabel(o.specialtyId)}</p><p className="text-[11px] text-slate-400 truncate">{o.address || ''}</p>
                {m != null && <p className="text-[11px] font-black text-sky-300 mt-0.5">{kmText(m)} · وصول {etaText(m)}</p>}</div>
              {goBtn(o.lat, o.lng)}
            </div>) })}
          {mine.length === 0 && <p className="text-xs text-slate-500">لا توجد مهمة نشطة — وجهة العميل وخطها المباشر ووقت الوصول يظهر هنا فور إسناد طلب لك.</p>}
          <p className="text-xs font-black text-slate-200 mt-1">🧰 الزملاء القريبون</p>
          {mates.map(({ t, dist, free }) => (
            <div key={t.userId} className={card}>
              <div className="flex-1 min-w-0"><p className="text-sm font-black text-white truncate">{t.displayName || 'فني'} <span className={`text-[10px] font-bold ${free ? 'text-emerald-300' : 'text-slate-400'}`}>· {free ? 'متاح' : 'مشغول'}</span></p><p className="text-[11px] text-slate-400">{kmText(dist)} · {etaText(dist)} · آخر ظهور {seenAgo(t.lastSeenAt)}</p></div>
              {t.phone && <a href={`tel:${t.phone}`} className="shrink-0 bg-emerald-600 rounded-xl px-3 py-2 text-xs font-black text-white flex items-center gap-1"><Phone size={14} />اتصال</a>}
            </div>))}
          {mates.length === 0 && <p className="text-xs text-slate-500">لا يوجد زملاء بموقع معروف حاليًا.</p>}
          <p className="text-xs font-black text-slate-200 mt-1">🏪 محلات قطع الغيار المتاحة (الأقرب أولًا)</p>
          {nearShops.map(({ s, dist }) => (
            <div key={s.id} className={card}>
              <div className="flex-1 min-w-0"><p className="text-sm font-bold text-white truncate">{s.name}</p><p className="text-[11px] text-slate-400">{kmText(dist)} · {etaText(dist)}{s.specialties ? ` · ${s.specialties}` : ''}</p></div>
              {s.phone && <a href={`tel:${s.phone}`} className="shrink-0 bg-emerald-600 rounded-xl px-3 py-2 text-xs font-black text-white flex items-center gap-1"><Phone size={14} />اتصال</a>}
              {goBtn(s.lat, s.lng)}
            </div>))}
          {nearShops.length === 0 && <p className="text-xs text-slate-500">لم تُضف محلات قريبة بعد (تضيفها العمليات من الخريطة الميدانية).</p>}
        </div>
      )}

      {view === 'plan' && (
        <div className="grid gap-2" data-testid="tech-plan">
          <p className="text-xs text-slate-400">ترتيب المرور: الطلبات ذات الموعد أولًا ثم الأقرب فالأقرب — كل محطة بمسافتها ووقت وصولها من التي قبلها.</p>
          {route.length === 0 && <p className="text-xs text-slate-500 py-4 text-center">لا طلبات في جدولك الآن.</p>}
          {route.map(({ o, leg }, i) => (
            <div key={o.id} className={card}>
              <span className="w-7 h-7 rounded-full bg-primary text-black text-xs font-black flex items-center justify-center shrink-0">{i + 1}</span>
              <button onClick={() => nav(`/staff/order/${o.id}`)} className="flex-1 min-w-0 text-right">
                <p className="text-sm font-black text-white truncate">{o.clientName || 'عميل'} — {specialtyLabel(o.specialtyId)}{o.priority === 'urgent' && <span className="mr-1 text-[10px] text-rose-300">عاجل</span>}</p>
                <p className="text-[11px] text-sky-300 font-bold truncate">📅 {orderFacts(o).when}</p>
                <p className="text-[11px] text-slate-400 truncate">{o.address || ''}{leg != null ? ` · ${kmText(leg)} (${etaText(leg)}) ${i === 0 ? 'من موقعك' : 'من المحطة السابقة'}` : ''}</p>
              </button>
              {goBtn(o.lat, o.lng)}
            </div>))}
        </div>
      )}

      {view === 'customers' && (
        <div className="grid gap-2" data-testid="tech-customers">
          <p className="text-xs font-black text-slate-200">🔔 طلبات وعملاء جدد متاحون (العاجل ثم الأقرب)</p>
          {open.map(({ o, dist }) => (
            <div key={o.id} className={card}>
              <button onClick={() => nav(`/staff/order/${o.id}`)} className="flex-1 min-w-0 text-right">
                <p className="text-sm font-black text-white truncate">{o.priority === 'urgent' ? '🚨 ' : '🆕 '}{o.clientName || 'عميل'} — {specialtyLabel(o.specialtyId)}</p>
                <p className="text-[11px] text-slate-400 truncate">{o.address || ''}{dist != null ? ` · ${kmText(dist)} (${etaText(dist)})` : ''}</p>
              </button>
              {goBtn(o.lat, o.lng)}
            </div>))}
          {open.length === 0 && <p className="text-xs text-slate-500">لا طلبات مفتوحة قريبة الآن.</p>}
          <p className="text-xs font-black text-slate-200 mt-1">👤 عملائي السابقون (الأقدم أولًا — 🔁 حان وقت الصيانة الدورية)</p>
          {past.map((c) => (
            <div key={c.k} className={card}>
              <div className="flex-1 min-w-0"><p className="text-sm font-black text-white truncate">{c.days > 150 ? '🔁 ' : ''}{c.name || 'عميل'} <span className="text-[10px] text-slate-400 font-bold">· {c.n} {c.n > 1 ? 'خدمات' : 'خدمة'} · آخرها منذ {c.days} يومًا</span></p>
                <p className="text-[11px] text-slate-400 truncate">{specialtyLabel(c.lastService)} · {c.address || ''}{c.dist != null ? ` · ${kmText(c.dist)}` : ''}</p></div>
              {c.phone && <a href={`tel:${c.phone}`} className="shrink-0 bg-emerald-600 rounded-xl px-3 py-2 text-xs font-black text-white flex items-center gap-1"><Phone size={14} />اتصال</a>}
              {goBtn(c.lat, c.lng)}
            </div>))}
          {past.length === 0 && <p className="text-xs text-slate-500">ستظهر هنا عملاؤك بعد إتمام أول خدمة.</p>}
          <p className="text-xs font-black text-slate-200 mt-1">🔁 عملاء قدامى حواليك (من كل الفنيين — الأقرب أولًا)</p>
          {near.slice(0, 30).map((c) => (
            <button key={c.key} onClick={() => setPin({ kind: 'customer', orderId: c.orderId, lat: c.lat, lng: c.lng, title: c.name, emoji: '🔁' })} className={card + ' text-right'}>
              <div className="flex-1 min-w-0"><p className="text-sm font-black text-white truncate">{c.name || 'عميل قديم'} <span className="text-[10px] text-slate-400 font-bold">· {c.n} {c.n > 1 ? 'خدمات' : 'خدمة'}{c.days != null ? ` · آخرها من ${c.days} يوم` : ''}{c.mine ? ' · عميلك' : ''}</span></p>
                <p className="text-[11px] text-slate-400 truncate">{c.lastService}{c.lastTech ? ` — ${c.lastTech}` : ''} · {kmText(c.m)}{c.address ? ` · ${c.address}` : ''}</p></div>
              <span className="shrink-0 text-[11px] font-black text-sky-300">الكارت ›</span>
            </button>))}
          {near.length === 0 && <p className="text-xs text-slate-500">مفيش عملاء قدامى في حدود 6 كم من موقعك (أو الموقع مش متفعل).</p>}
        </div>
      )}
    </div>
  )
}
