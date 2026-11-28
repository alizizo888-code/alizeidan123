import { useMemo, useState, useRef, useEffect } from 'react'
import { post } from '../lib/_api'
import { isNative, openNav } from '../lib/native'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Phone, X, Plus, Trash2, UserPlus, Navigation } from 'lucide-react'
import FieldMapCanvas from '../components/FieldMapCanvas'
import PlacePickerModal from '../components/PlacePickerModal'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { maps } from '../lib/maps'
import { useTeamRole } from '../hooks/useTeamRole'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { isTechAvailable } from '../assignment'
import { ACTIVE_STATUSES, STATUS_META, specialtyLabel } from '../constants'
import { CRM_STATUS, CRM_FINANCE, CRM_STYLE, crmLabel, buildCustomers, seenAgo, isOnline } from '../lib/crm'
import AccessDenied from '../components/AccessDenied'
import { useWarrantyPolicy } from '../hooks/useWarranty'
import { orderWarranty, warrantyText } from '../warranty'
import { orderAmount } from '../payment'
import OriginCard from '../components/OriginCard'
import PinCard, { openGps, shareLocation } from '../components/PinCard'
import { orderNo } from '../lib/orderNo'

const LAYERS = [
  { id: 'regular', label: 'عملاء قدامى', emoji: '👤', color: '#3b82f6' },
  { id: 'new', label: 'عملاء جدد', emoji: '🆕', color: '#22c55e' },
  { id: 'vip', label: 'مميزون / ولاء', emoji: '⭐', color: '#f59e0b' },
  { id: 'blocked', label: 'محظورون', emoji: '⛔', color: '#ef4444' },
  { id: 'orders', label: 'طلبات جديدة', emoji: '🔔', color: '#ef4444' },
  // Every finished job still under warranty, where it was done — so a call about «the AC you fixed
  // last month» is a pin on the map with its dates, not a search through the registry.
  { id: 'warranty', label: 'ضمانات سارية', emoji: '🛡️', color: '#14b8a6' },
  { id: 'techOn', label: 'فنيون متاحون', emoji: '🧰', color: '#10b981' },
  { id: 'techOff', label: 'فنيون مغلقون', emoji: '🧰', color: '#64748b' },
  { id: 'shops', label: 'محلات الصيانة', emoji: '🏪', color: '#8b5cf6' },
  { id: 'clientPins', label: 'مواقع عملاء جدد', emoji: '📍', color: '#06b6d4' },
]
const inputCls = 'w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-sm text-white outline-none focus:border-primary'

export default function FieldMap() {
  const navigate = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 500 })
  const { data: crm } = useLiveShared('customer_profiles', { limit: 800 })
  const { data: shops } = useLiveShared('shops', { limit: 300 })
  const { data: clientPins } = useLiveShared('client_pins', { limit: 300 })
  const { profiles } = useTechProfiles()
  const [on, setOn] = useState(() => Object.fromEntries(LAYERS.map((l) => [l.id, true])))
  const [sel, setSel] = useState(null)
  const [picker, setPicker] = useState(null) // null | 'shop' | 'client'
  const mapRef = useRef(null)
  const allowed = isOwner || ['owner', 'operations', 'supervisor'].includes(role)
  // Live positions (2026-11-21): the same /ops fleet feed the operations room uses — each technician's last GPS point,
  // whether it is live (< a few minutes), his next job and ETA, and the supervisors in the field. Every 8 s while
  // the page is visible, at once when it becomes visible again, so the map moves instead of sitting still.
  const [fleet, setFleet] = useState(null)
  const [fleetAt, setFleetAt] = useState(0)
  useEffect(() => {
    if (!allowed) return undefined
    let alive = true, busy = false
    const load = async () => {
      if (busy || document.visibilityState === 'hidden') return
      busy = true
      try { const r = await post('/ops', { op: 'fleet' }); if (alive && r) { setFleet(r); setFleetAt(Date.now()) } } catch { /* keep the last good picture */ }
      busy = false
    }
    load()
    const t = setInterval(load, 8000)
    const vis = () => { if (document.visibilityState === 'visible') load() }
    document.addEventListener('visibilitychange', vis)
    return () => { alive = false; clearInterval(t); document.removeEventListener('visibilitychange', vis) }
  }, [allowed])
  const { data: fposts } = useLiveShared('field_posts', { order: '-createdAt', limit: 80 })
  const liveById = useMemo(() => Object.fromEntries(((fleet && fleet.techs) || []).map((r) => [String(r.id), r])), [fleet])

  const customers = useMemo(() => buildCustomers(orders || [], crm || []), [orders, crm])
  const newOrders = useMemo(() => (orders || []).filter((o) => o.status === 'new' && o.lat != null), [orders])
  const { policy } = useWarrantyPolicy()
  const warranties = useMemo(() => (orders || [])
    .filter((o) => o.lat != null && ['completed', 'closed'].includes(o.status))
    .map((o) => ({ order: o, w: orderWarranty(o, policy) }))
    .filter((x) => x.w.known && x.w.active), [orders, policy])
  const points = useMemo(() => {
    const out = []
    for (const c of customers) {
      const g = c.status === 'vip' || c.status === 'loyal' ? 'vip' : c.status === 'blocked' ? 'blocked' : c.status === 'new' ? 'new' : 'regular'
      if (!on[g]) continue
      const L = LAYERS.find((x) => x.id === g)
      out.push({ id: 'c' + c.key, kind: 'customer', data: c, lat: c.lat, lng: c.lng, emoji: L.emoji, color: L.color, label: c.name || c.phone || 'عميل' })
    }
    if (on.warranty) for (const w of warranties) out.push({ id: 'w' + w.order.id, kind: 'warranty', data: w, lat: w.order.lat, lng: w.order.lng, emoji: '🛡️', color: '#14b8a6', label: `ضمان · ${w.order.clientName || ''} · ${w.w.remainingDays} يوم` })
    if (on.orders) for (const o of newOrders) out.push({ id: 'o' + o.id, kind: 'order', data: o, lat: o.lat, lng: o.lng, emoji: '🔔', color: '#ef4444', pulse: true, label: `طلب جديد · ${o.clientName || ''}` })
    for (const t of profiles || []) {
      const fr = liveById[String(t.userId)]
      const lat = fr?.lat ?? t.lat ?? t.homeLat, lng = fr?.lng ?? t.lng ?? t.homeLng
      if (lat == null) continue
      const av = isTechAvailable(t, orders || [])
      if (!on[av ? 'techOn' : 'techOff']) continue
      const live = !!fr?.live
      const where = live ? `مباشر · منذ ${Math.max(0, Math.round((fr.ago || 0) / 60))} د` : fr?.home ? 'من البيت' : 'آخر موقع'
      const job = fr?.job ? ` · ← ${fr.job.client || 'طلب'}${fr.job.etaMin ? ` (${fr.job.etaMin} د)` : ''}` : ''
      out.push({ id: 't' + t.userId, kind: 'tech', data: { ...t, _live: fr }, lat, lng, emoji: '🧰', pulse: live, color: live ? '#22c55e' : av ? '#10b981' : '#64748b', label: `${fr?.code ? fr.code + ' · ' : ''}${t.displayName || 'فني'} · ${av ? 'متاح' : 'مغلق'} · ${where}${job}` })
    }
    // from the field: road warnings, pins and jobs technicians dropped (last 12 hours)
    for (const fp of fposts || []) if (fp.lat != null && fp.status !== 'done' && Date.now() - Date.parse(fp.createdAt) < 12 * 3600000) out.push({ id: 'fp' + fp.id, kind: 'fieldpost', data: fp, lat: fp.lat, lng: fp.lng, emoji: fp.kind === 'warn' ? '⚠️' : fp.kind === 'job' ? '🆕' : '📍', color: fp.kind === 'warn' ? '#f59e0b' : '#a855f7', pulse: fp.kind === 'warn' || fp.kind === 'job', label: `${fp.byName}: ${String(fp.text || '').slice(0, 60)}` })
    if (on.techOn) for (const st of (fleet && fleet.staff) || []) if (st.lat != null) out.push({ id: 'st' + st.id, kind: 'staff', data: st, lat: st.lat, lng: st.lng, emoji: '🧑‍💼', color: '#f59e0b', label: `${st.name} · منذ ${Math.round((st.ago || 0) / 60)} د` })
    if (on.shops) for (const s of shops || []) if (s.lat != null) out.push({ id: 's' + s.id, kind: 'shop', data: s, lat: s.lat, lng: s.lng, emoji: '🏪', color: s.available === false ? '#64748b' : '#8b5cf6', label: s.name })
  if (on.clientPins) for (const p of clientPins || []) if (p.lat != null) out.push({ id: 'p' + p.id, kind: 'clientPin', data: p, lat: p.lat, lng: p.lng, emoji: '📍', color: '#06b6d4', label: p.name || 'عميل جديد' })
    return out
    }, [customers, newOrders, warranties, profiles, shops, clientPins, orders, on, liveById, fleet, fposts])
  // a dashed line from each moving technician to the job he is heading to
  const lines = useMemo(() => Object.values(liveById).filter((r) => r.lat != null && !r.home && r.job && r.job.lat != null).map((r) => ({ from: [r.lat, r.lng], to: [r.job.lat, r.job.lng], color: r.color || '#38bdf8', label: r.job.etaMin ? `${r.job.etaMin} د` : '' })), [liveById])
  const liveCount = Object.values(liveById).filter((r) => r.live).length

  if (loading) return <div className="h-full flex items-center justify-center text-slate-500 text-sm bg-[#090c14]" dir="rtl">جارِ التحقق…</div>
  if (!allowed) return <AccessDenied need="المالك ومشرف العمليات" />

  async function onPick(place) {
  const kind = picker
  setPicker(null)
  if (!place) return
  const gid = await team.groupId()
  if (kind === 'shop') {
    await db.insertShared('shops', { name: place.name, phone: '', lat: place.lat, lng: place.lng, available: true, specialties: '', lastSeenAt: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
  } else if (kind === 'client') {
    await db.insertShared('client_pins', { name: place.name, address: place.address || '', lat: place.lat, lng: place.lng, createdAt: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
  }
}
  const counts = { customers: customers.length, orders: newOrders.length, warranties: warranties.length, techs: (profiles || []).length, shops: (shops || []).length }

  return (
    <div className="h-full flex flex-col bg-[#090c14] text-white" dir="rtl">
      <header className="shrink-0 px-4 pt-[calc(var(--page-sat,env(safe-area-inset-top,0px))+0.8rem)] pb-2">
                <div className="flex items-center gap-2"><button onClick={() => navigate('/staff')} className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button><h1 className="flex-1 font-black">🗺️ الخريطة الميدانية</h1><button onClick={() => setPicker('client')} className="flex items-center gap-1 text-xs font-bold bg-cyan-600 rounded-full px-3 py-1.5"><UserPlus size={14} />عميل</button><button onClick={() => setPicker('shop')} className="flex items-center gap-1 text-xs font-bold bg-violet-600 rounded-full px-3 py-1.5"><Plus size={14} />محل</button></div>
        <p className="text-[10px] text-slate-400 mt-1">{counts.customers} عميل • {counts.orders} طلب جديد • {counts.warranties} ضمان ساري • {counts.techs} فني • {counts.shops} محل — «محل» يُثبَّت في منتصف الخريطة</p>
        <p className="text-[10px] mt-0.5 flex items-center gap-1"><span className={`inline-block w-2 h-2 rounded-full ${fleetAt && Date.now() - fleetAt < 30000 ? 'bg-emerald-400 animate-pulse' : 'bg-slate-500'}`} /><span className="text-slate-400">{fleetAt ? `بث مباشر · ${liveCount} فني متحرك الآن · يتحدّث كل 8 ثوانٍ` : 'جارِ الاتصال بالبث المباشر…'}</span></p>
        <div className="flex gap-1.5 overflow-x-auto pt-2 pb-1">
          {LAYERS.map((l) => <button key={l.id} onClick={() => setOn((p) => ({ ...p, [l.id]: !p[l.id] }))} className={`shrink-0 rounded-full border px-3 py-1 text-[11px] font-bold ${on[l.id] ? 'text-white' : 'opacity-40 text-slate-300'}`} style={{ borderColor: l.color, background: on[l.id] ? l.color + '33' : 'transparent' }}>{l.emoji} {l.label}</button>)}
        </div>
      </header>
      <div className="flex-1 min-h-0 px-3 pb-3"><FieldMapCanvas points={points} lines={on.techOn ? lines : []} height="100%" onSelect={(p) => { setSel(p); try { if (p?.lat != null && mapRef.current?.flyTo) mapRef.current.flyTo([p.lat, p.lng], Math.max(mapRef.current.getZoom?.() || 0, 16), { duration: 0.6 }) } catch { /* ignore */ } }} onReady={(m) => { mapRef.current = m }} className="h-full" /></div>
      {sel && (sel.kind === 'order' || sel.kind === 'warranty')
        ? <PinCard pin={{ orderId: sel.kind === 'order' ? sel.data.id : sel.data.order.id, lat: sel.lat, lng: sel.lng, title: (sel.kind === 'order' ? sel.data : sel.data.order).clientName, emoji: sel.emoji }} onClose={() => setSel(null)} />
        : sel && <Sheet sel={sel} onClose={() => setSel(null)} navigate={navigate} orders={orders || []} policy={policy} />}
      {picker && <PlacePickerModal title={picker === 'shop' ? 'إضافة محل صيانة' : 'إضافة موقع عميل'} center={mapRef.current?.getCenter?.()} onClose={() => setPicker(null)} onPick={onPick} />}
    </div>
  )
}

function Row({ k, v }) { return v ? <p className="text-xs text-slate-300"><span className="text-slate-500">{k}: </span>{v}</p> : null }

function Sheet({ sel, onClose, navigate, orders, policy }) {
  const d = sel.data
  return (
    <div className="fixed inset-x-0 md:right-64 bottom-0 z-40 max-h-[72vh] overflow-y-auto bg-[#0d1220] border-t border-white/10 rounded-t-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
      <button onClick={onClose} className="absolute left-3 top-3 text-slate-400"><X size={18} /></button>
      {sel.lat != null && (
        <div className="flex gap-2 mb-2">
          <a href={maps.navigationUrl(sel.lat, sel.lng, 'google')} onClick={(e) => { if (isNative()) { e.preventDefault(); openNav(sel.lat, sel.lng, 'google') } }} target="_blank" rel="noopener noreferrer" className="flex-1 flex items-center justify-center gap-1 bg-blue-600 rounded-xl py-2 text-xs font-bold"><Navigation size={14} />خرائط جوجل</a>
          <button onClick={() => shareLocation(sel.lat, sel.lng, sel.label || '')} className="flex-1 flex items-center justify-center gap-1 bg-white/10 rounded-xl py-2 text-xs font-bold">مشاركة الموقع</button>
          <button onClick={() => openGps(sel.lat, sel.lng, sel.label || '')} className="flex-1 flex items-center justify-center gap-1 bg-white/10 rounded-xl py-2 text-xs font-bold">فتح بالـGPS</button>
          <a href={maps.navigationUrl(sel.lat, sel.lng, 'waze')} onClick={(e) => { if (isNative()) { e.preventDefault(); openNav(sel.lat, sel.lng, 'waze') } }} target="_blank" rel="noopener noreferrer" className="flex-1 flex items-center justify-center gap-1 bg-sky-500 rounded-xl py-2 text-xs font-bold"><Navigation size={14} />Waze</a>
        </div>
      )}
      {sel.lat != null && <p className="text-[10px] text-slate-500 mb-2" dir="ltr">📍 {Number(sel.lat).toFixed(5)}, {Number(sel.lng).toFixed(5)}</p>}
      {sel.kind === 'customer' && <CustomerCard c={d} policy={policy} navigate={navigate} />}
      {sel.kind === 'warranty' && (
        <div className="grid gap-1.5"><p className="font-black">🛡️ ضمان ساري — {specialtyLabel(d.order.specialtyId)}</p>
          <Row k="العميل" v={`${d.order.clientName || ''} ${d.order.clientPhone || ''}`} /><Row k="العنوان" v={d.order.address} />
          <Row k="الضمان" v={warrantyText(d.w)} /><Row k="نُفّذ بواسطة" v={d.order.technicianName} /><Row k="التقرير" v={d.order.report} /><OriginCard order={d.order} compact />
          <button onClick={() => navigate(`/staff/order/${d.order.id}`)} className="mt-2 bg-primary text-black font-black rounded-xl py-2 text-sm">فتح الطلب</button></div>
      )}
      {sel.kind === 'order' && (
        <div className="grid gap-1.5"><p className="font-black">🔔 طلب جديد — {specialtyLabel(d.specialtyId)}</p><Row k="العميل" v={`${d.clientName || ''} ${d.clientPhone || ''}`} /><Row k="العنوان" v={d.address} /><Row k="الوصف" v={d.description} /><Row k="وصل منذ" v={seenAgo(d.createdAt)} /><OriginCard order={d} compact />
          <button onClick={() => navigate(`/staff/order/${d.id}`)} className="mt-2 bg-primary text-black font-black rounded-xl py-2 text-sm">فتح الطلب والإسناد</button></div>
      )}
      {sel.kind === 'tech' && (
        <div className="grid gap-1.5"><p className="font-black">🧰 {d.displayName || 'فني'}</p><Row k="الحالة" v={d.active === false ? 'مغلق' : isTechAvailable(d, orders) ? 'متاح' : 'مشغول/اكتملت السعة'} /><Row k="آخر ظهور" v={seenAgo(d.lastSeenAt || d.updatedAt)} /><Row k="مهام نشطة" v={String(orders.filter((o) => o.technicianId === d.userId && ACTIVE_STATUSES.includes(o.status)).length)} /><Row k="التخصصات" v={(d.specialties || []).map(specialtyLabel).join('، ')} />
          {d.phone && <a href={`tel:${d.phone}`} className="mt-2 flex items-center justify-center gap-2 bg-emerald-600 rounded-xl py-2 text-sm font-bold"><Phone size={15} />اتصال</a>}</div>
      )}
      {sel.kind === 'shop' && <ShopCard s={d} onClose={onClose} />}
      {sel.kind === 'clientPin' && (
        <div className="grid gap-1.5"><p className="font-black">📍 {d.name || 'موقع عميل'}</p><Row k="العنوان" v={d.address} />
          <button onClick={async () => { if (confirm('حذف الموقع؟')) { await db.deleteShared('client_pins', d.id); onClose() } }} className="mt-2 bg-rose-600/80 rounded-xl py-2 text-sm font-bold flex items-center justify-center gap-1"><Trash2 size={15} />حذف</button>
        </div>
      )}
    </div>
  )
}

function ShopCard({ s, onClose }) {
  const upd = (patch) => db.updateShared('shops', s.id, { ...patch, lastSeenAt: new Date().toISOString() })
  return (
    <div className="grid gap-1.5"><p className="font-black">🏪 {s.name}</p><Row k="آخر ظهور" v={seenAgo(s.lastSeenAt || s.updatedAt)} /><Row k="الحالة" v={s.available === false ? 'مغلق' : 'متاح'} />
      <div className="flex gap-2 mt-2"><button onClick={() => { upd({ available: s.available === false }); onClose() }} className="flex-1 bg-white/10 rounded-xl py-2 text-sm font-bold">{s.available === false ? 'تفعيل كمتاح' : 'إغلاق مؤقت'}</button>
        {s.phone && <a href={`tel:${s.phone}`} className="flex-1 bg-emerald-600 rounded-xl py-2 text-sm font-bold text-center">اتصال</a>}
        <button onClick={async () => { if (confirm('حذف المحل؟')) { await db.deleteShared('shops', s.id); onClose() } }} className="px-3 bg-rose-600/80 rounded-xl"><Trash2 size={15} /></button></div></div>
  )
}

function CustomerCard({ c, policy, navigate }) {
  const p = c.profile || {}
  const [f, setF] = useState({ status: p.status || c.status, finance: p.finance || 'unknown', style: p.style || 'calm', special: p.special || '', note: p.note || '', lastDealing: p.lastDealing || '' })
  const [saved, setSaved] = useState('')
  const lo = c.lastOrder
  const done = c.orders.filter((o) => o.status === 'completed').length
  const learned = useMemo(() => {
    const fin = c.orders.filter((o) => ['completed', 'closed'].includes(o.status))
    const paid = fin.filter((o) => o.paymentStatus === 'paid').reduce((s, o) => s + orderAmount(o), 0)
    const units = {}
    for (const o of fin) { const q = o.acQuote; if (q?.type) units[q.type] = Math.max(units[q.type] || 0, Number(q.qty) || 1) }
    const views = c.orders.map((o) => o.techOnClient).filter(Boolean)
    const rated = views.filter((v) => v.stars)
    const tagN = {}; for (const v of views) for (const t of v.tags || []) tagN[t] = (tagN[t] || 0) + 1
    return {
      paid: Math.round(paid),
      units: Object.entries(units).map(([t, q]) => `${t} × ${q}`).join('، '),
      address: c.address || '',   // buildCustomers keeps the newest order's address
      views, stars: rated.length ? rated.reduce((s, v) => s + v.stars, 0) / rated.length : 0,
      tags: Object.entries(tagN).sort((a, b) => b[1] - a[1]).slice(0, 6),
    }
  }, [c.orders])
  async function save() {
    try { const gid = await team.groupId(); await db.upsertShared('customer_profiles', { ...p, ...f, name: c.name, phone: c.phone, updatedAt: new Date().toISOString() }, c.key, { groupId: gid, visibleTo: 'group', writableBy: 'admins' }); setSaved('تم الحفظ ✓') } catch { setSaved('تعذر الحفظ') }
    setTimeout(() => setSaved(''), 2000)
  }
  return (
    <div className="grid gap-2">
      <p className="font-black">{c.name || 'عميل'} <span className="text-[11px] font-bold" style={{ color: CRM_STATUS.find((s) => s.id === f.status)?.color }}>• {crmLabel(CRM_STATUS, f.status)}</span></p>
      {!c.name && !c.phone && <p className="text-[11px] text-amber-300">العميل ده لسه مالوش اسم ولا رقم مسجل — الرقم بيتسجل أول ما يبعت رسالة الطلب من واتساب.</p>}
      <Row k="الجوال" v={c.phone} /><Row k="آخر ظهور" v={seenAgo(c.lastAt)} /><Row k="الطلبات" v={`${c.orders.length} (مكتمل ${done})`} />
      {lo && <OriginCard order={lo} compact />}
      {lo && <Row k="آخر تعامل" v={`${specialtyLabel(lo.specialtyId)} — ${STATUS_META[lo.status]?.label || lo.status}${orderAmount(lo) ? ` — ${orderAmount(lo)} ر.س` : ''}`} />}
      {/* What the jobs taught us, collected without anybody typing it in. */}
      <Row k="إجمالي المدفوع" v={learned.paid ? `${learned.paid} ر.س` : ''} />
      <Row k="أجهزته" v={learned.units} />
      <Row k="العنوان" v={learned.address} />
      {learned.views.length > 0 && (
        <div className="rounded-xl bg-white/[0.04] border border-white/10 p-2.5">
          <p className="text-[11px] font-black text-white mb-1">رأي الفنيين {learned.stars ? <span className="text-amber-400">{'★'.repeat(Math.round(learned.stars))}</span> : null} <span className="text-slate-500 font-bold">({learned.views.length})</span></p>
          {learned.tags.length > 0 && <div className="flex flex-wrap gap-1 mb-1">{learned.tags.map(([t, n]) => <span key={t} className="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-slate-300">{t}{n > 1 ? ` ×${n}` : ''}</span>)}</div>}
          {learned.views.filter((v) => v.note).slice(0, 3).map((v, i) => <p key={i} className="text-[11px] text-slate-300">«{v.note}» <span className="text-slate-500">— {v.by || 'فني'}</span></p>)}
        </div>
      )}
      {/* His whole record: every visit, what was done, for how much, by whom, the fault, the warranty and where he came from. */}
      <div className="rounded-xl bg-white/[0.04] border border-white/10 p-2.5 grid gap-2">
        <p className="text-[11px] font-black text-white">سجل العميل ({c.orders.length})</p>
        {[...c.orders].sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || ''))).map((o) => {
          const w = orderWarranty(o, policy)
          const amount = orderAmount(o)
          const units = o.acQuote?.type ? `${o.acQuote.type} × ${o.acQuote.qty || 1}` : ''
          return (
            <div key={o.id} className="rounded-lg bg-black/20 p-2 grid gap-0.5">
              <div className="flex items-center gap-1.5 text-[11px]"><b className="text-white">#{o.number || orderNo(o)}</b><span className="text-slate-300">{specialtyLabel(o.specialtyId)}</span><span className="text-slate-500">{o.createdAt ? new Date(o.createdAt).toLocaleDateString('ar-SA-u-nu-latn', { day: 'numeric', month: 'short', year: 'numeric' }) : ''}</span><span className="mr-auto text-slate-400">{STATUS_META[o.status]?.label || o.status}</span></div>
              <Row k="المطلوب" v={[o.serviceDetails, units].filter(Boolean).join(' — ')} />
              <Row k="العطل / التفاصيل" v={o.description || o.problem || o.note} />
              <Row k="السعر" v={amount ? `${amount} ر.س${o.paymentStatus === 'paid' ? ' (مدفوع)' : o.paymentStatus ? ' (غير مدفوع)' : ''}` : ''} />
              <Row k="الفني" v={o.technicianName} />
              <Row k="التقرير" v={o.report} />
              <Row k="الضمان" v={o.warrantyVoid ? 'ملغي من المالك' : warrantyText(w)} />
              <OriginCard order={o} compact />
              <button onClick={() => navigate(`/staff/order/${o.id}`)} className="mt-1 self-start rounded-lg bg-white/10 px-3 py-1 text-[11px] font-bold">فتح الطلب</button>
            </div>
          )
        })}
      </div>
      <div className="grid grid-cols-2 gap-2 mt-1">
        <label className="text-[10px] text-slate-400">حالة العميل<select value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} className={inputCls}>{CRM_STATUS.map((s) => <option key={s.id} value={s.id} className="text-black">{s.label}</option>)}</select></label>
        <label className="text-[10px] text-slate-400">الحالة المادية<select value={f.finance} onChange={(e) => setF({ ...f, finance: e.target.value })} className={inputCls}>{CRM_FINANCE.map((s) => <option key={s.id} value={s.id} className="text-black">{s.label}</option>)}</select></label>
        <label className="text-[10px] text-slate-400">أسلوب التعامل<select value={f.style} onChange={(e) => setF({ ...f, style: e.target.value })} className={inputCls}>{CRM_STYLE.map((s) => <option key={s.id} value={s.id} className="text-black">{s.label}</option>)}</select></label>
        <label className="text-[10px] text-slate-400">تسعيرة خاصة<input value={f.special} onChange={(e) => setF({ ...f, special: e.target.value })} placeholder="مثال: غسيل 90 / خصم 10%" className={inputCls} /></label>
      </div>
      <label className="text-[10px] text-slate-400">آخر تعامل (ملاحظتك)<input value={f.lastDealing} onChange={(e) => setF({ ...f, lastDealing: e.target.value })} className={inputCls} /></label>
      <label className="text-[10px] text-slate-400">ملاحظات<textarea rows={2} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} className={inputCls} /></label>
      <div className="flex gap-2"><button onClick={save} className="flex-1 bg-primary text-black font-black rounded-xl py-2 text-sm">حفظ بيانات العميل {saved}</button>{c.phone && <a href={`tel:${c.phone}`} className="px-4 bg-emerald-600 rounded-xl flex items-center"><Phone size={16} /></a>}</div>
    </div>
  )
}
