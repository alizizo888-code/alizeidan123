import { deviceInfo } from '../lib/deviceHealth'
import TechIdCard from './TechIdCard'
import { isSalary, payroll } from '../lib/salary'
import NativeSetup from './NativeSetup'
import { isNative, openNav } from '../lib/native'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Phone, Navigation, Power, MapPin, Star, CheckCircle2, Clock, RefreshCw, Wallet } from 'lucide-react'
import { maps } from '../lib/maps'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { ACTIVE_STATUSES, SPECIALTIES, STATUS_META, specialtyLabel } from '../constants'
import { remainingCapacity, TECH_LEVELS } from '../assignment'
import { orderAmount } from '../payment'
import { custody, starsOf } from '../lib/custody'
import TechTerms from './TechTerms'
import WalletCard from './WalletCard'
import { orderNo, placeText } from '../lib/orderNo'

const isToday = (iso) => { if (!iso) return false; return new Date(iso).toDateString() === new Date().toDateString() }
const km = (m) => (m < 1000 ? `${Math.round(m)} م` : `${(m / 1000).toFixed(1)} كم`)
const navUrl = (lat, lng) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
// Inside the app the link opens Google Maps' own navigation directly (lib/native); in a browser it stays a web link.
const navClick = (lat, lng, app = 'google') => (e) => { if (isNative()) { e.preventDefault(); openNav(lat, lng, app) } }
const DECL = 'mq_declined_orders'
const loadDeclined = () => { try { return JSON.parse(localStorage.getItem(DECL) || '[]') } catch { return [] } }

// Technician home (reference: design-reference/02): online switch, today's numbers, the job in hand, jobs open right now.
export default function TechHome({ acting = false, myId, myProfile, displayName, orders, onSaveProfile, onAccept, onOpen, busy }) {
  const [filter, setFilter] = useState('all')
  const [declined, setDeclined] = useState(loadDeclined)
  const [here, setHere] = useState(null)
  const [msg, setMsg] = useState('')
  const profRef = useRef(myProfile); profRef.current = myProfile
  const lastW = useRef({ t: 0, lat: null, lng: null })
  const online = myProfile ? myProfile.active !== false : false

  // Real device position -> published to my profile (operations sees me live on the field map). Only while I am online.
  useEffect(() => {
    if (!online || acting) return undefined
    const w = maps.watchLocation((p) => {
      setHere({ lat: p.lat, lng: p.lng })
      const L = lastW.current; const now = Date.now()
      let moved = L.lat == null ? Infinity : 0
      try { if (L.lat != null) moved = maps.distance(L.lat, L.lng, p.lat, p.lng) } catch { /* ignore */ }
      if (profRef.current && myId && (now - L.t > 60000 || moved > 150)) {
        lastW.current = { t: now, lat: p.lat, lng: p.lng }
        deviceInfo().then((d) => onSaveProfile({ ...profRef.current, userId: myId, lat: p.lat, lng: p.lng, lastSeenAt: new Date().toISOString(), ...d })).catch(() => {})
      }
    })
    return () => w.stop()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online, myId])

  const { data: payRows } = useLiveShared('tech_pay', { limit: 5 })
  const sharePct = useMemo(() => { const r = (payRows || [])[0]; const v = Number(r?.sharePct); return v > 0 && v <= 100 ? v : 0 }, [payRows])

  const mine = useMemo(() => (orders || []).filter((o) => o.technicianId === myId), [orders, myId])
  const doneToday = mine.filter((o) => ['completed', 'closed'].includes(o.status) && !o.noCommission && isToday(o.completedAt || o.updatedAt))
  const active = mine.filter((o) => ACTIVE_STATUSES.includes(o.status))
  const current = active.find((o) => ['en_route', 'nearby', 'arrived', 'in_progress', 'accepted'].includes(o.status)) || active[0] || null
  const pendingAccept = active.filter((o) => o.status === 'assigned')
  const rated = mine.filter((o) => starsOf(o))
  const avg = rated.length ? rated.reduce((s, o) => s + starsOf(o), 0) / rated.length : 0
  const earn = sharePct ? doneToday.reduce((s, o) => s + orderAmount(o), 0) * sharePct / 100 : 0
  // What he holds and what he owes, from the same sum the owner's accounts page uses.
  const { data: mySetl } = useLiveShared('settlements', { filters: { techId: myId || '__none__' }, order: '-at', limit: 50 })
  const { data: myW } = useLiveShared('tech_wallet', { filters: { techId: myId || '__none__' }, limit: 1 })
  const myWallet = (myW || [])[0] || null
  const wallet = useMemo(() => custody(myId, orders, mySetl, sharePct, myWallet, Number((payRows || [])[0]?.cashSharePct) || 0, Number((payRows || [])[0]?.cashCap) || 0), [myId, orders, mySetl, sharePct, myWallet, payRows])
  const lastSetl = (mySetl || [])[0]
  const pr = useMemo(() => (isSalary(myWallet) ? payroll(myId, orders, mySetl, myWallet) : null), [myId, orders, mySetl, myWallet])
  const rem = myProfile ? remainingCapacity(myProfile, orders || []) : 0
  const lvl = TECH_LEVELS.find((l) => l.id === myProfile?.level)

  const open = (orders || []).filter((o) => o.status === 'forced' && !o.technicianId && !declined.includes(o.id))
  const filtered = open.filter((o) => filter === 'all' || o.specialtyId === filter)
  const cats = SPECIALTIES.filter((s) => open.some((o) => o.specialtyId === s.id))
  const dist = (o) => { const p = here || (myProfile?.lat != null ? { lat: myProfile.lat, lng: myProfile.lng } : null); if (!p || o.lat == null) return null; try { return maps.distance(p.lat, p.lng, o.lat, o.lng) } catch { return null } }

  const toggle = async () => {
    if (!myProfile) { setMsg('احفظ ملفك أولًا من تبويب «ملفي اليومي»'); return }
    try { await onSaveProfile({ ...myProfile, active: !online }) } catch { setMsg('تعذر التحديث') }
  }
  const decline = (o) => { const n = [...declined, o.id]; setDeclined(n); try { localStorage.setItem(DECL, JSON.stringify(n.slice(-200))) } catch { /* ignore */ } }
  const arrived = async (o) => {
    try { await db.updateShared('orders', o.id, { status: 'arrived', statusHistory: [...(o.statusHistory || []), { status: 'arrived', at: new Date().toISOString(), by: 'technician' }] }) } catch { setMsg('تعذر تأكيد الوصول') }
  }
  const st = current && (STATUS_META[current.status] || {})

  return (
    <div className="flex flex-col gap-4">
      {msg && <p className="bg-amber-500/15 border border-amber-400/40 text-amber-200 text-xs font-bold rounded-xl p-3">{msg}</p>}
      <NativeSetup compact />
      <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 flex items-center gap-3">
        <button onClick={toggle} className={`flex items-center gap-2 rounded-full px-4 py-2.5 text-sm font-black border ${online ? 'bg-emerald-500/20 border-emerald-400/50 text-emerald-300' : 'bg-slate-700/40 border-white/15 text-slate-300'}`}>
          <Power size={15} />{online ? 'متصل' : 'غير متصل'}<span className={`w-2.5 h-2.5 rounded-full ${online ? 'bg-emerald-400 animate-pulse' : 'bg-slate-500'}`} />
        </button>
        <div className="flex-1 min-w-0 text-left">
          <p className="font-black text-white text-sm truncate" dir="rtl">{displayName}</p>
          <p className="text-[11px] text-slate-400 truncate" dir="rtl">{[lvl?.label, (myProfile?.specialties || []).slice(0, 2).map(specialtyLabel).join(' / ')].filter(Boolean).join(' • ') || 'فني'}</p>
        </div>
      </section>
      <div className="flex items-center gap-2 rounded-xl bg-white/[0.04] border border-white/10 px-3 py-2 text-[11px] text-slate-300">
        <MapPin size={14} className="text-primary shrink-0" />
        <span className="flex-1 truncate">{here ? 'موقعك مباشر ويصل للعمليات' : online ? 'جارٍ تحديد موقعك… فعّل صلاحية الموقع' : 'اتصل لتبدأ مشاركة موقعك مع العمليات'}</span>
        <button onClick={() => maps.getCurrentLocation().then((p) => setHere({ lat: p.lat, lng: p.lng })).catch(() => setMsg('فعّل صلاحية الموقع'))} className="flex items-center gap-1 text-primary font-bold"><RefreshCw size={12} />تحديث</button>
      </div>

      <TechTerms myId={myId} wallet={myWallet} acc={wallet} lines={mySetl} displayName={displayName} acting={acting} />
      <a href="#/pay" className="rounded-2xl bg-sky-500/10 border border-sky-400/20 px-3 py-2.5 flex items-center gap-2 text-xs font-black text-sky-200">🏦 حسابات التحويل — اعرضها للعميل أو انسخها له<span className="mr-auto text-sky-300">←</span></a>

      {/* «محفظتي» is always on screen now; the old summary stays folded under it for the last settlement line */}
      <WalletCard acc={wallet} lines={mySetl} />
      {false && (
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3" aria-label="حسابي مع الشركة">
          <p className="text-xs font-black text-white flex items-center gap-1.5 mb-2"><Wallet size={13} />حسابي مع الشركة</p>
          <div className="grid grid-cols-3 gap-1.5 text-center">
            <div className="rounded-lg bg-white/[0.04] p-2"><p className="text-[10px] text-slate-400 font-bold">نقد معك</p><p className="font-black text-white text-sm">{Math.round(wallet.cash)}</p></div>
            <div className="rounded-lg bg-white/[0.04] p-2"><p className="text-[10px] text-slate-400 font-bold">نصيبك</p><p className="font-black text-amber-300 text-sm">{Math.round(wallet.commission)}</p></div>
            <div className="rounded-lg bg-white/[0.04] p-2"><p className="text-[10px] text-slate-400 font-bold">سلّمت</p><p className="font-black text-white text-sm">{Math.round(wallet.settledIn - wallet.settledOut)}</p></div>
          </div>
          <p className={`mt-2 text-xs font-black text-center rounded-lg py-2 ${wallet.balance > 0.5 ? 'bg-rose-500/15 text-rose-200' : wallet.balance < -0.5 ? 'bg-emerald-500/15 text-emerald-200' : 'bg-white/[0.04] text-slate-300'}`}>
            {wallet.balance > 0.5 ? `سلّم للشركة ${Math.round(wallet.balance)} ر.س` : wallet.balance < -0.5 ? `لك عند الشركة ${Math.round(-wallet.balance)} ر.س` : 'حسابك متزن ✓'}
          </p>
          {lastSetl && <p className="mt-1.5 text-[10px] text-slate-400 text-center">آخر تسوية: {lastSetl.dir === 'out' ? 'استلمت من الشركة' : 'سلّمت للشركة'} {Math.round(Number(lastSetl.amount) || 0)} ر.س · {new Date(lastSetl.at).toLocaleDateString('ar-SA')}</p>}
        </section>
      )}

      <section className="grid grid-cols-3 gap-2">
        {pr ? (
          <div className="rounded-xl bg-white/[0.04] border border-emerald-400/30 p-3 text-center" data-salary-bonus><p className="text-[10px] text-slate-400 font-bold flex items-center justify-center gap-1"><Wallet size={11} />مكافآت الشهر</p><p className="font-display font-black text-lg text-emerald-300 mt-1">{Math.round(pr.autoBonus + pr.bonus)}</p><p className="text-[9px] text-slate-500">{pr.next ? `فاضل ${pr.toNext} طلب ← +${pr.next.amt}` : 'ر.س'}</p></div>
        ) : (
        <div className="rounded-xl bg-white/[0.04] border border-white/10 p-3 text-center"><p className="text-[10px] text-slate-400 font-bold flex items-center justify-center gap-1"><Wallet size={11} />أرباح اليوم</p><p className="font-display font-black text-lg text-white mt-1">{sharePct ? Math.round(earn) : '—'}</p><p className="text-[9px] text-slate-500">{sharePct ? `ر.س (${sharePct}%)` : 'تحددها الإدارة'}</p></div>
        )}
        <div className="rounded-xl bg-white/[0.04] border border-white/10 p-3 text-center"><p className="text-[10px] text-slate-400 font-bold flex items-center justify-center gap-1"><CheckCircle2 size={11} />المنجزة اليوم</p><p className="font-display font-black text-lg text-white mt-1">{doneToday.length}</p><p className="text-[9px] text-slate-500">المتبقي {rem} مهمة</p></div>
        <div className="rounded-xl bg-white/[0.04] border border-white/10 p-3 text-center"><p className="text-[10px] text-slate-400 font-bold flex items-center justify-center gap-1"><Star size={11} />تقييمي</p><p className="font-display font-black text-lg text-amber-300 mt-1">{avg ? avg.toFixed(2) : '—'}</p><p className="text-[9px] text-slate-500">{rated.length ? `${rated.length} تقييم` : 'لا تقييمات بعد'}</p></div>
      </section>

      <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3"><TechIdCard home /></section>

      {pr && (
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-1 text-xs" data-payroll>
          <p className="font-black text-white text-sm mb-1">💼 راتبك الشهر ده</p>
          <p className="flex justify-between text-slate-300"><span>الراتب</span><b className="text-white">{Math.round(pr.salary)} ر.س</b></p>
          <p className="flex justify-between text-slate-300"><span>مكافأة الطلبات ({pr.good} طلب تمام × {pr.per})</span><b className="text-emerald-300">+{Math.round(pr.orderBonus)}</b></p>
          {pr.tier && <p className="flex justify-between text-slate-300"><span>مكافأة {pr.tier.n} طلب في الشهر</span><b className="text-emerald-300">+{pr.tier.amt}</b></p>}
          {pr.bonus > 0 && <p className="flex justify-between text-slate-300"><span>مكافآت من الإدارة</span><b className="text-emerald-300">+{Math.round(pr.bonus)}</b></p>}
          {pr.penalty > 0 && <p className="flex justify-between text-slate-300"><span>خصومات / عقوبات</span><b className="text-rose-300">−{Math.round(pr.penalty)}</b></p>}
          <p className="flex justify-between border-t border-white/10 pt-1 mt-1 text-slate-200"><span>المستحق</span><b className="text-white">{Math.round(pr.due)} ر.س</b></p>
          {pr.paid > 0 && <p className="flex justify-between text-slate-400"><span>اتصرف لك</span><b>{Math.round(pr.paid)}</b></p>}
          {pr.next && <p className="text-[10px] text-amber-300 mt-1">🎯 فاضلك {pr.toNext} طلب وتاخد مكافأة {pr.next.amt} ر.س</p>}
        </section>
      )}

      {pendingAccept.length > 0 && (
        <button onClick={() => onOpen(pendingAccept[0])} className="rounded-xl bg-sky-500/15 border border-sky-400/40 text-sky-200 text-xs font-black py-2.5">لديك {pendingAccept.length} مهمة مُسندة بانتظار قبولك — عرض</button>
      )}

      {current && (
        <section className="rounded-2xl bg-white/[0.05] border border-emerald-400/30 p-4 flex flex-col gap-3">
          <div className="flex items-center gap-2"><span className="text-[10px] font-black text-emerald-300">المهمة الجارية حاليًا</span><span className="mr-auto text-[10px] text-slate-500" dir="ltr">#{orderNo(current)}</span></div>
          <button onClick={() => onOpen(current)} className="text-right">
            <p className="font-black text-white text-sm">{specialtyLabel(current.specialtyId)} · {current.clientName || 'عميل'}</p>
            <p className="text-[11px] text-slate-400 truncate">{placeText(current)}</p>
            {st?.label && <span className="inline-block mt-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-black" style={{ background: (st.color || '#64748b') + '33', color: st.color }}>{st.label}</span>}
          </button>
          <div className="grid grid-cols-3 gap-2">
            {current.clientPhone ? <a href={`tel:${current.clientPhone}`} className="flex items-center justify-center gap-1.5 bg-white/10 rounded-xl py-2.5 text-xs font-bold"><Phone size={14} />اتصال</a> : <span />}
            {current.lat != null ? <a href={navUrl(current.lat, current.lng)} onClick={navClick(current.lat, current.lng)} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-1.5 bg-white/10 rounded-xl py-2.5 text-xs font-bold"><Navigation size={14} />الملاحة</a> : <span />}
            <button onClick={() => onOpen(current)} className="flex items-center justify-center gap-1.5 bg-white/10 rounded-xl py-2.5 text-xs font-bold"><Clock size={14} />التفاصيل</button>
          </div>
          {['en_route', 'nearby'].includes(current.status) && <button onClick={() => arrived(current)} className="w-full bg-emerald-500 text-black font-black rounded-xl py-3 text-sm">تأكيد: وصلت لموقع العميل ✓</button>}
          {['accepted', 'arrived', 'in_progress'].includes(current.status) && <button onClick={() => onOpen(current)} className="w-full bg-primary text-black font-black rounded-xl py-3 text-sm">متابعة المهمة (مراحل التنفيذ والفاتورة)</button>}
        </section>
      )}

      <section>
        <div className="flex items-end mb-2"><div className="flex-1"><h2 className="font-display font-black text-white text-base">الطلبات المتاحة فورًا</h2><p className="text-[11px] text-slate-400">اختر الطلب للبدء في التنفيذ المباشر</p></div>{open.length > 0 && <span className="rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-black px-2.5 py-1">{open.length} مهمة</span>}</div>
        <div className="flex gap-1.5 overflow-x-auto pb-2">
          <button onClick={() => setFilter('all')} className={`shrink-0 rounded-full px-3.5 py-1.5 text-xs font-bold border ${filter === 'all' ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>الكل ({open.length})</button>
          {cats.map((s) => <button key={s.id} onClick={() => setFilter(s.id)} className={`shrink-0 rounded-full px-3.5 py-1.5 text-xs font-bold border ${filter === s.id ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{s.icon} {s.label} ({open.filter((o) => o.specialtyId === s.id).length})</button>)}
        </div>
        {filtered.length === 0 && <p className="text-slate-500 text-xs text-center py-8">لا توجد طلبات متاحة الآن</p>}
        <div className="flex flex-col gap-2.5">
          {filtered.map((o) => {
            const d = dist(o)
            return (
              <div key={o.id} className={`rounded-2xl border p-3.5 flex flex-col gap-2 ${o.priority === 'urgent' ? 'bg-rose-500/10 border-rose-400/40' : 'bg-white/[0.04] border-white/10'}`}>
                <div className="flex items-center gap-2 text-[10px] font-black">
                  {o.priority === 'urgent' && <span className="rounded-full bg-rose-500/25 text-rose-200 px-2 py-0.5">⚡ طلب عاجل</span>}
                  {o.preferredTime && <span className="rounded-full bg-sky-500/20 text-sky-200 px-2 py-0.5">⏰ {o.preferredTime}</span>}
                  {d != null && <span className="mr-auto text-slate-400 flex items-center gap-1"><MapPin size={11} />{km(d)} عنك</span>}
                </div>
                <button onClick={() => onOpen(o)} className="text-right">
                  <p className="font-black text-white text-sm">{specialtyLabel(o.specialtyId)}</p>
                  <p className="text-[11px] text-slate-400 truncate">{placeText(o)}</p>
                </button>
                {orderAmount(o) > 0 && <p className="font-display font-black text-white">{orderAmount(o)} <span className="text-[11px] text-slate-400">ر.س{sharePct ? ` · نصيبك ≈ ${Math.round(orderAmount(o) * sharePct / 100)}` : ''}</span></p>}
                <div className="flex gap-2">
                  <button onClick={() => onAccept(o)} disabled={busy || rem <= 0 || !online} className="flex-[2] bg-emerald-500 text-black font-black rounded-xl py-2.5 text-sm disabled:opacity-40">قبول المهمة فورًا ✓</button>
                  <button onClick={() => decline(o)} className="flex-1 bg-white/10 rounded-xl py-2.5 text-xs font-bold text-slate-200">اعتذار</button>
                </div>
                {(!online || rem <= 0) && <p className="text-[10px] text-amber-300 text-center">{!online ? 'اضغط «متصل» لتتمكن من القبول' : 'وصلت للحد اليومي'}</p>}
              </div>
            )
          })}
        </div>
      </section>
    </div>
  )
}
