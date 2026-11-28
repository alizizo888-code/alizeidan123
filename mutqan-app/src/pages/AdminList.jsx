import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ChevronRight, Search, ArrowLeft, Phone, Globe, UserPlus, HardHat, ClipboardList } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { post } from '../lib/_api'
import { STATUS_META } from '../constants'
import { DONE, RUNNING, isToday, isUnassigned } from '../components/ops/helpers'
import Ago from '../components/Ago'
import { orderNo } from '../lib/orderNo'

// What sits behind each number on «الإدارة الشاملة»: tap «طلبات النهارده», «مواقع جالها طلبات», «طلبات انضمام»…
// and the actual records open here — each with its time («من 5 دقايق») — and each opens its own screen.
const doneAt = (o) => o.completedAt || o.updatedAt
const thisMonth = (iso) => { const d = new Date(iso), n = new Date(); return d.getMonth() === n.getMonth() && d.getFullYear() === n.getFullYear() }
const starsOf = (o) => Number(o.rating?.stars ?? o.rating ?? o.stars ?? 0) || 0
const price = (o) => Number(o.price) || 0
// Every number tile in the system opens its own list here (owner, 2026-11-22: «ما تبقاش صورة بس»).
const KINDS = {
  today: { title: 'طلبات النهارده', icon: ClipboardList, orders: (o) => isToday(o.createdAt) },
  unassigned: { title: 'طلبات مستنية توزيع', icon: ClipboardList, orders: isUnassigned, action: ['/staff/operations?tab=board', 'وزّعها من لوحة التوجيه'] },
  running: { title: 'طلبات شغالة دلوقتي', icon: ClipboardList, orders: (o) => RUNNING.includes(o.status), action: ['/staff/operations?tab=map', 'تابعها على الخريطة'] },
  done: { title: 'طلبات اتنفذت', icon: ClipboardList, orders: (o) => DONE.includes(o.status) },
  techs: { title: 'الفنيين', icon: HardHat, action: ['/staff/operations?tab=techs', 'جدول الفنيين الكامل'] },
  join: { title: 'طلبات الانضمام', icon: UserPlus, action: ['/staff/tech-requests', 'قبول ورفض الطلبات'] },
  sites: { title: 'مواقع جالها طلبات', icon: Globe, action: ['/staff/network', 'كل مواقع الشبكة'] },
  site: { title: 'طلبات موقع', icon: Globe, orders: () => true },
  assigned: { title: 'طلبات مُسندة لسه مابدأتش', icon: ClipboardList, orders: (o) => !o.inhouse && ['assigned', 'forced'].includes(o.status) && !!o.technicianId, action: ['/staff/operations?tab=board', 'لوحة التوجيه'] },
  doneToday: { title: 'اتنفذت النهارده', icon: ClipboardList, orders: (o) => DONE.includes(o.status) && isToday(doneAt(o)), sum: true },
  failed: { title: 'لم تتم / متعثرة النهارده', icon: ClipboardList, orders: (o) => (o.status === 'cancelled' && isToday(o.updatedAt)) || (o.escalation && !DONE.includes(o.status) && o.status !== 'cancelled') },
  revToday: { title: 'إيراد النهارده (المكتمل)', icon: ClipboardList, orders: (o) => DONE.includes(o.status) && isToday(doneAt(o)), sum: true, action: ['/staff/accounts', 'الحسابات'] },
  revMonth: { title: 'إيراد الشهر', icon: ClipboardList, orders: (o) => DONE.includes(o.status) && thisMonth(doneAt(o)), sum: true, action: ['/staff/accounts', 'الحسابات'] },
  rated: { title: 'التقييمات', icon: ClipboardList, orders: (o) => starsOf(o) > 0, stars: true, action: ['/staff/reports?tab=techs', 'تقرير الفنيين'] },
  cancelled: { title: 'الطلبات الملغية', icon: ClipboardList, orders: (o) => o.status === 'cancelled' },
  unpaid: { title: 'مكتملة ومش مدفوعة', icon: ClipboardList, orders: (o) => DONE.includes(o.status) && o.paymentStatus !== 'paid', sum: true, action: ['/staff/pay-links', 'روابط الدفع'] },
  paid: { title: 'مكتملة ومدفوعة', icon: ClipboardList, orders: (o) => DONE.includes(o.status) && o.paymentStatus === 'paid', sum: true },
  active: { title: 'الطلبات النشطة', icon: ClipboardList, orders: (o) => !DONE.includes(o.status) && o.status !== 'cancelled' },
  techsOn: { title: 'الفنيين المتاحين', icon: HardHat, action: ['/staff/operations?tab=techs', 'جدول الفنيين الكامل'] },
}

const Row = ({ onClick, children, orderId }) => (
  <button onClick={onClick} data-order-id={orderId} className="w-full text-right rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-3 flex items-center gap-3">
    <div className="flex-1 min-w-0">{children}</div>
    <ArrowLeft size={15} className="text-white/40 shrink-0" />
  </button>
)

export default function AdminList() {
  const nav = useNavigate()
  const { kind = 'today' } = useParams()
  const [sp] = useSearchParams()
  const brand = sp.get('brand') || ''
  const K = KINDS[kind] || KINDS.today
  const { isOwner, role, loading } = useTeamRole()
  const [q, setQ] = useState('')
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 500 })
  const { data: techs } = useLiveShared('tech_profiles', { limit: 300 })
  const { data: apps } = useLiveShared('tech_applications', { filters: { status: 'pending' }, order: '-createdAt', limit: 200 })
  const [sites, setSites] = useState(null)
  useEffect(() => { if (kind === 'sites' || kind === 'site') post('/network', { op: 'list' }).then((r) => setSites(r.sites || [])).catch(() => setSites([])) }, [kind])

  const siteName = (id) => (sites || []).find((s) => s.id === id)?.name || id || 'متقن'
  const has = (...xs) => { const t = q.trim(); return !t || xs.join(' ').includes(t) }

  const list = useMemo(() => {
    if (K.orders) {
      return (orders || []).filter((o) => K.orders(o) && (kind !== 'site' || (o.brand || 'oxygen') === brand))
    }
    return []
  }, [orders, K, kind, brand])

  const bySite = useMemo(() => {
    const m = {}
    for (const o of orders || []) {
      const k = o.brand || 'oxygen'
      const r = m[k] || (m[k] = { id: k, total: 0, today: 0, open: 0, last: '' })
      r.total++; if (isToday(o.createdAt)) r.today++; if (!DONE.includes(o.status) && o.status !== 'cancelled') r.open++
      if (!r.last || o.createdAt > r.last) r.last = o.createdAt
    }
    return Object.values(m).sort((a, b) => (b.last || '').localeCompare(a.last || ''))
  }, [orders])

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!(isOwner || role === 'operations' || role === 'supervisor')) return <p className="p-6 text-white text-sm font-bold" dir="rtl">الصفحة دي للمالك والعمليات.</p>

  const Icon = K.icon
  const title = kind === 'site' ? `طلبات ${siteName(brand)}` : K.title
  let body
  let total = null
  if (K.orders) {
    const rows = list.filter((o) => has(o.number, o.clientName, o.clientPhone, o.address, o.serviceLabel, siteName(o.brand)))
    if (K.sum) total = `${rows.length} طلب · الإجمالي ${rows.reduce((a, o) => a + price(o), 0).toLocaleString('ar-EG')} ر.س`
    else if (K.stars) { const r = rows.filter((o) => starsOf(o)); total = `${r.length} تقييم · المتوسط ${r.length ? (r.reduce((a, o) => a + starsOf(o), 0) / r.length).toFixed(2) : '—'} ⭐` }
    else total = `${rows.length} طلب`
    body = rows.length ? rows.map((o) => {
      const st = STATUS_META[o.status] || { label: o.status, bg: 'bg-white/10', text: 'text-white' }
      return (
        <Row key={o.id} orderId={o.id} onClick={() => nav(`/staff/order/${o.id}`)}>
          <div className="flex items-center gap-2">
            <p className="font-black text-[14px] truncate">#{o.number || orderNo(o)} · {o.clientName || 'عميل'}</p>
            <span className={`text-[10px] font-bold rounded-full px-2 py-0.5 shrink-0 ${st.bg} ${st.text}`}>{st.label}</span>
          </div>
          <p className="text-[12px] text-white/60 truncate">{o.serviceLabel || o.specialtyId || ''}{o.address ? ` · ${o.address}` : ''}</p>
          {(K.sum || K.stars) && <p className="text-[11px] text-emerald-300 font-bold">{K.sum ? `${price(o).toLocaleString('ar-EG')} ر.س` : ''}{K.stars ? ` ⭐ ${starsOf(o)}` : ''}{o.technicianName ? ` · ${o.technicianName}` : ''}</p>}
          <p className="text-[11px] text-white/45 flex flex-wrap gap-x-3 mt-0.5">
            <Ago at={o.createdAt} icon prefix="اتسجل " />
            {o.brand && <span>{siteName(o.brand)}</span>}
            {o.appointmentAt && <Ago at={o.appointmentAt} prefix="الموعد " />}
          </p>
        </Row>
      )
    }) : <p className="text-sm text-white/60 py-6 text-center">مفيش طلبات هنا دلوقتي ✓</p>
  } else if (kind === 'techs' || kind === 'techsOn') {
    const busy = {}; for (const o of orders || []) if (o.technicianId && RUNNING.concat(['assigned']).includes(o.status)) busy[o.technicianId] = (busy[o.technicianId] || 0) + 1
    const rows = (techs || []).filter((t) => (kind !== 'techsOn' || (t.available !== false && t.active !== false && !t.isSite)) && has(t.displayName, t.name, t.phone))
    body = rows.map((t) => {
      const id = t.userId || t.id
      return (
        <Row key={t.id} onClick={() => nav(`/staff/operations?tab=techs&tech=${encodeURIComponent(id)}`)}>
          <div className="flex items-center gap-2">
            <p className="font-black text-[14px] truncate">{t.displayName || t.name || 'فني'}</p>
            <span className={`text-[10px] font-bold rounded-full px-2 py-0.5 ${t.available === false ? 'bg-rose-500/20 text-rose-200' : 'bg-emerald-500/20 text-emerald-200'}`}>{t.available === false ? 'مش متاح' : 'متاح'}</span>
          </div>
          <p className="text-[11px] text-white/50 flex flex-wrap gap-x-3 mt-0.5">
            {t.phone && <span dir="ltr" className="flex items-center gap-1"><Phone size={10} />{t.phone}</span>}
            <span>شغل مفتوح: {busy[id] || 0}</span>
            {t.lastSeenAt && <Ago at={t.lastSeenAt} prefix="آخر ظهور " />}
          </p>
        </Row>
      )
    })
  } else if (kind === 'join') {
    const rows = (apps || []).filter((a) => has(a.name, a.phone, a.city))
    body = rows.length ? rows.map((a) => (
      <Row key={a.id} onClick={() => nav(`/staff/tech-requests?id=${encodeURIComponent(a.id)}`)}>
        <p className="font-black text-[14px] truncate">{a.name || 'متقدم'}</p>
        <p className="text-[11px] text-white/50 flex flex-wrap gap-x-3 mt-0.5">
          {a.phone && <span dir="ltr">{a.phone}</span>}{a.city && <span>{a.city}</span>}
          <Ago at={a.createdAt} prefix="قدّم " />
        </p>
      </Row>
    )) : <p className="text-sm text-white/60 py-6 text-center">مفيش طلبات انضمام مستنية ✓</p>
  } else if (kind === 'sites') {
    const rows = bySite.filter((s) => has(s.id, siteName(s.id)))
    body = rows.map((s) => (
      <Row key={s.id} onClick={() => nav(`/staff/admin/list/site?brand=${encodeURIComponent(s.id)}`)}>
        <p className="font-black text-[14px] truncate">{siteName(s.id)}</p>
        <p className="text-[11px] text-white/50 flex flex-wrap gap-x-3 mt-0.5">
          <span>{s.total} طلب</span><span>النهارده {s.today}</span><span>مفتوح {s.open}</span>{s.last && <Ago at={s.last} prefix="آخر طلب " />}
        </p>
      </Row>
    ))
  }

  return (
    <div className="min-h-full text-white pb-10" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3">
        <div className="max-w-3xl mx-auto flex items-center gap-3">
          <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
          <Icon size={18} className="text-primary shrink-0" />
          <h1 className="flex-1 min-w-0 font-display font-black text-base truncate">{title}</h1>
        </div>
        <div className="max-w-3xl mx-auto mt-3 relative">
          <Search size={16} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث بالاسم أو الرقم أو الجوال…" aria-label="بحث" className="w-full rounded-xl bg-white/5 border border-white/15 pr-9 pl-3 py-2.5 text-[14px] placeholder:text-white/40" />
        </div>
      </header>
      <div className="max-w-3xl mx-auto px-4 mt-4 grid gap-2">
        {total && <p className="text-[12px] text-white/70 font-bold">{total}</p>}
        {K.action && <button onClick={() => nav(K.action[0])} className="justify-self-start rounded-full bg-primary/15 border border-primary/40 text-primary px-3 py-1.5 text-[12px] font-black">{K.action[1]} ←</button>}
        {body}
      </div>
    </div>
  )
}
