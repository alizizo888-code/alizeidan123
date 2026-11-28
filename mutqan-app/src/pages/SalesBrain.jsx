import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2, Save, RefreshCw, Brain, CalendarClock, SlidersHorizontal, BookOpen, FlaskConical, MessagesSquare, Bell, Plus, Trash2, Send, CheckCircle2, XCircle, TrendingUp, MapPin, Sparkles } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { post, errText } from '../lib/_api'
import AccessDenied from '../components/AccessDenied'

// «العقل التجاري»: the owner's control room for how the assistant sells — who the customer is, what price to land on,
// which slots to offer in Makkah and Jeddah so the day does not clutter, and the 700-scenario playbook behind it.
// Everything here reads and writes POST /sales; the training chat talks to the same control assistant (/agent) that
// now has the sales tools, so the owner can simply explain prices and rules in his own words.

const inp = 'w-full bg-white/10 border-2 border-white/20 rounded-lg px-3 py-2 text-sm font-bold text-white focus:outline-none focus:border-sky-400'
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
const MODES = { cluster: 'مواعيد متجاورة', windows: 'داخل فترات محددة', next_day: 'قفل اليوم — من بكرة' }
const STAGES = { open: 'افتتاح واكتشاف', nego: 'فصال وتفاوض', close: 'اتفاق وإغلاق', time: 'مواعيد', place: 'مناطق' }
const TABS = [
  ['today', 'اليوم', CalendarClock], ['policy', 'السياسة', SlidersHorizontal], ['playbook', 'السيناريوهات', BookOpen],
  ['sim', 'جرّب البوت', FlaskConical], ['train', 'درّب البوت', MessagesSquare],
]
const Box = ({ title, hint, icon: Icon, children, className = '' }) => (
  <section className={`bg-white/5 border-2 border-white/15 rounded-2xl p-4 flex flex-col gap-3 ${className}`}>
    <div className="flex items-start gap-2">{Icon && <Icon size={16} className="text-sky-300 mt-0.5 shrink-0" />}<div><h2 className="font-display font-black text-sm text-sky-300">{title}</h2>{hint && <p className="text-[11px] text-white/60 mt-0.5 leading-relaxed">{hint}</p>}</div></div>
    {children}
  </section>
)
const Row = ({ label, children }) => <label className="flex flex-col gap-1 text-[11px] font-black text-white/80">{label}{children}</label>
const Chip = ({ on, children, onClick }) => <button type="button" onClick={onClick} className={`px-2.5 py-1 rounded-full text-[11px] font-black border-2 ${on ? 'bg-emerald-600/80 border-emerald-400' : 'bg-white/5 border-white/20 text-white/60'}`}>{children}</button>
const TONE = { sky: 'border-sky-400/40 bg-sky-500/10', emerald: 'border-emerald-400/40 bg-emerald-500/10', amber: 'border-amber-400/40 bg-amber-500/10', rose: 'border-rose-400/40 bg-rose-500/10' }
const Stat = ({ label, value, tone = 'sky' }) => <div className={`rounded-xl border-2 p-2.5 text-center ${TONE[tone] || TONE.sky}`}><p className="text-[10px] font-black text-white/60">{label}</p><p className="text-lg font-black">{value}</p></div>

export default function SalesBrain() {
  const nav = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const allowed = isOwner || role === 'operations'
  const [tab, setTab] = useState(() => { try { return sessionStorage.getItem('mq_sales_tab') || 'today' } catch { return 'today' } })
  const [ov, setOv] = useState(null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [f, setF] = useState(null)

  const load = async () => {
    setBusy(true); setMsg('')
    try { const r = await post('/sales', { op: 'overview' }); setOv(r); setF(r.settings) } catch (e) { setMsg(errText(e, 'تعذر تحميل العقل التجاري')) }
    setBusy(false)
  }
  useEffect(() => { if (allowed) load() }, [allowed]) // eslint-disable-line
  useEffect(() => { try { sessionStorage.setItem('mq_sales_tab', tab) } catch { /* private mode */ } }, [tab])

  const save = async (patch, ok = 'تم الحفظ ✅ — البوت يستخدم السياسة الجديدة من الرسالة الجاية') => {
    setBusy(true); setMsg('')
    try { const r = await post('/sales', { op: 'save', patch }); setF(r.settings); setOv((o) => ({ ...(o || {}), settings: r.settings })); setMsg(ok) } catch (e) { setMsg(errText(e, 'تعذر الحفظ')) }
    setBusy(false)
  }

  if (loading) return <div className="h-full flex items-center justify-center text-slate-400">جارِ التحميل…</div>
  if (!allowed) return <AccessDenied need="المالك ومشرف العمليات" />

  return (
    <div className="h-full flex flex-col text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base flex items-center gap-1.5"><Brain size={17} className="text-fuchsia-300" />العقل التجاري</h1><p className="text-[11px] text-white/60 truncate">الفصال، الأسعار المناسبة لكل عميل، ومواعيد مكة وجدة بدون لخبطة</p></div>
        <button onClick={load} disabled={busy} aria-label="تحديث" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center disabled:opacity-50">{busy ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />}</button>
      </header>
      <nav className="flex gap-1.5 overflow-x-auto px-3 py-2 border-b border-white/10 bg-slate-950/60" aria-label="أقسام العقل التجاري">
        {TABS.map(([id, label, Icon]) => <button key={id} onClick={() => setTab(id)} className={`shrink-0 flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-black border-2 ${tab === id ? 'bg-sky-600 border-sky-300' : 'bg-white/5 border-white/15 text-white/70'}`}><Icon size={14} />{label}</button>)}
      </nav>
      {msg && <p className="mx-4 mt-3 bg-sky-600/90 text-white text-[13px] font-black rounded-xl p-3">{msg}</p>}
      <div className="flex-1 overflow-y-auto">
        {!ov ? <p className="p-6 text-sm text-white/60">{busy ? 'جارِ التحميل…' : 'لا توجد بيانات بعد.'}</p> : (
          <div className="p-4 max-w-5xl mx-auto pb-24">
            {tab === 'today' && <Today ov={ov} setMsg={setMsg} />}
            {tab === 'policy' && f && <Policy f={f} setF={setF} save={save} busy={busy} isOwner={isOwner} />}
            {tab === 'playbook' && <Playbook ov={ov} />}
            {tab === 'sim' && <Simulator />}
            {tab === 'train' && <Train f={f} save={save} isOwner={isOwner} />}
          </div>
        )}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ today */
function CityCard({ c, slots }) {
  const pct = c.cap ? Math.min(1, c.count / c.cap) : 0
  return (
    <div className="rounded-2xl border-2 border-white/15 bg-white/5 p-3 flex flex-col gap-2">
      <div className="flex items-center justify-between"><p className="font-black flex items-center gap-1.5"><MapPin size={14} className="text-emerald-300" />{c.label}</p><span className={`text-[11px] font-black rounded-full px-2 py-0.5 ${c.serviceDay ? 'bg-emerald-600/70' : 'bg-rose-600/70'}`}>{c.serviceDay ? 'يوم خدمة' : 'مش يوم خدمة'}</span></div>
      <div className="h-2 rounded-full bg-white/10 overflow-hidden"><div className={`h-full ${pct >= 1 ? 'bg-rose-500' : pct >= 0.6 ? 'bg-amber-400' : 'bg-emerald-500'}`} style={{ width: `${pct * 100}%` }} /></div>
      <p className="text-[12px] text-white/80">{c.count} من {c.cap} طلب{c.count ? ` — من ${c.first} لـ ${c.last} · ${c.peak} في الذروة` : ''}</p>
      {!!c.hours?.length && <div className="flex flex-wrap gap-1">{c.hours.map((h, i) => <span key={i} className="text-[10px] font-black bg-white/10 rounded px-1.5 py-0.5">{h}</span>)}</div>}
      <p className="text-[11px] text-sky-200"><b>القاعدة الشغالة:</b> {c.ruleText}</p>
      {c.hints.map((h, i) => <p key={i} className="text-[11px] text-amber-200">↳ {h}</p>)}
      {slots && <p className="text-[11px] text-white/70"><b>أول مواعيد يعرضها البوت:</b> {slots.slots.length ? slots.slots.map((x) => x.label + (x.peak ? ' (ذروة)' : '')).join(' · ') : 'لا توجد خانات قريبة'}</p>}
    </div>
  )
}

function Today({ ov, setMsg }) {
  const [sending, setSending] = useState('')
  const ins = ov.insights || {}
  const digest = async (evening) => {
    setSending(evening ? 'e' : 'm')
    try { const r = await post('/sales', { op: 'digest', evening }); setMsg('اتبعت للفريق ✅\n' + r.text) } catch (e) { setMsg(errText(e, 'ما اتبعتش')) }
    setSending('')
  }
  return (
    <div className="grid gap-3">
      <Box title={`خطة ${ov.plan.weekday} ${ov.plan.date}`} hint="عدد الطلبات وأوقاتها في كل مدينة، والقاعدة اللي البوت ماشي بيها دلوقتي" icon={CalendarClock}>
        <div className="grid md:grid-cols-2 gap-3">{Object.entries(ov.plan.cities).map(([id, c]) => <CityCard key={id} c={c} slots={ov.slots?.[id]} />)}</div>
        {!!ov.plan.outOfZone && <p className="text-[12px] text-amber-200">خارج التغطية النهارده: {ov.plan.outOfZone} طلب (فرع شريك)</p>}
        <div className="flex flex-wrap gap-2">
          <button onClick={() => digest(false)} disabled={!!sending} className="flex items-center gap-1.5 bg-white/10 rounded-lg px-3 py-2 text-xs font-black disabled:opacity-50">{sending === 'm' ? <Loader2 size={14} className="animate-spin" /> : <Bell size={14} />}ابعت خطة اليوم للفريق</button>
          <button onClick={() => digest(true)} disabled={!!sending} className="flex items-center gap-1.5 bg-white/10 rounded-lg px-3 py-2 text-xs font-black disabled:opacity-50">{sending === 'e' ? <Loader2 size={14} className="animate-spin" /> : <Bell size={14} />}ابعت ملخص اليوم</button>
        </div>
      </Box>
      <Box title={`بكرة — ${ov.tomorrow.weekday}`} icon={CalendarClock}>
        <div className="grid sm:grid-cols-2 gap-2">{Object.entries(ov.tomorrow.cities).map(([id, c]) => <p key={id} className="text-[12px] bg-white/5 rounded-xl p-2.5"><b>{c.label}:</b> {c.count} طلب {c.serviceDay ? '' : '— مش يوم خدمة'}</p>)}</div>
      </Box>
      <Box title="الأسعار بتعلمنا إيه (آخر 30 يوم)" hint="كام في المية من السعر الرسمي بنحصّله فعلًا — مقياس إن البوت ما بيدّيش أسعار وحشة" icon={TrendingUp}>
        <div className="grid grid-cols-3 gap-2"><Stat label="طلبات بأسعار" value={ins.orders || 0} /><Stat label="التحصيل من الرسمي" value={ins.realizationPct == null ? '—' : `${ins.realizationPct}%`} tone="emerald" /><Stat label="متوسط الخصم" value={ins.avgDiscountPct == null ? '—' : `${ins.avgDiscountPct}%`} tone="amber" /></div>
        {ins.byBand && <div className="grid grid-cols-3 gap-2">{Object.entries(ins.byBand).map(([k, v]) => <p key={k} className="text-[11px] bg-white/5 rounded-lg p-2 text-center"><b>{k}</b><br />{v.orders} طلب{v.realizationPct == null ? '' : ` · ${v.realizationPct}%`}</p>)}</div>}
        {(ins.tips || []).map((t, i) => <p key={i} className="text-[12px] text-amber-100 bg-amber-500/10 border border-amber-400/30 rounded-lg p-2">💡 {t}</p>)}
      </Box>
      <Box title="آخر التنبيهات" hint="اللي العقل التجاري بعته للفريق (وتيليجرام لو مربوط)" icon={Bell}>
        {(ov.log || []).length ? (ov.log || []).slice(0, 15).map((e, i) => <p key={i} className="text-[12px] bg-white/5 rounded-lg p-2 whitespace-pre-wrap"><span className="text-white/50 text-[10px]">{String(e.at).slice(0, 16).replace('T', ' ')}</span> — {e.text}</p>) : <p className="text-[12px] text-white/60">لسه ما فيش تنبيهات.</p>}
      </Box>
    </div>
  )
}

/* ------------------------------------------------------------------ policy */
function Policy({ f, setF, save, busy, isOwner }) {
  const P = f.pricing, N = f.notify
  const setP = (k, v) => setF((s) => ({ ...s, pricing: { ...s.pricing, [k]: v } }))
  const setN = (k, v) => setF((s) => ({ ...s, notify: { ...s.notify, [k]: v } }))
  const setC = (id, k, v) => setF((s) => ({ ...s, cities: { ...s.cities, [id]: { ...s.cities[id], [k]: v } } }))
  const setR = (i, k, v) => setF((s) => ({ ...s, batching: s.batching.map((r, j) => (j === i ? { ...r, [k]: v } : r)) }))
  const num = (v) => (v === '' ? '' : Number(v))
  return (
    <div className="grid gap-3">
      {!isOwner && <p className="text-[12px] bg-amber-500/15 border border-amber-400/30 rounded-xl p-3 text-amber-100">تقدر تشوف السياسة، لكن تعديل الأسعار والقواعد للمالك بس.</p>}
      <div className="grid lg:grid-cols-2 gap-3">
        <Box title="حدود الأسعار" hint="البوت عمره ما ينزل تحت الحد الأدنى من محرك الأسعار ولا تحت أقصى خصم هنا — مهما فاصل العميل" icon={SlidersHorizontal}>
          <label className="flex items-center gap-2 text-[12px] font-black"><input type="checkbox" checked={!!f.enabled} onChange={(e) => setF((s) => ({ ...s, enabled: e.target.checked ? 1 : 0 }))} />العقل التجاري شغال</label>
          <div className="grid grid-cols-2 gap-2">
            <Row label="أقصى خصم كلي %"><input type="number" min="0" max="60" className={inp} value={P.maxDiscountPct} onChange={(e) => setP('maxDiscountPct', num(e.target.value))} /></Row>
            <Row label="خصم الموعد الهادي (صباحي) %"><input type="number" min="0" max="30" className={inp} value={P.offPeakPct} onChange={(e) => setP('offPeakPct', num(e.target.value))} /></Row>
            <Row label="خصم الولاء %"><input type="number" min="0" max="30" className={inp} value={P.loyalPct} onChange={(e) => setP('loyalPct', num(e.target.value))} /></Row>
            <Row label="العميل دائم بعد كام طلب"><input type="number" min="1" className={inp} value={P.loyalAfter} onChange={(e) => setP('loyalAfter', num(e.target.value))} /></Row>
            <Row label="متوسط فاتورة العميل المميز (ر.س)"><input type="number" min="50" className={inp} value={P.vipTicket} onChange={(e) => setP('vipTicket', num(e.target.value))} /></Row>
            <Row label="التقريب لأقرب"><input type="number" min="1" className={inp} value={P.roundTo} onChange={(e) => setP('roundTo', num(e.target.value))} /></Row>
          </div>
          <label className="flex items-center gap-2 text-[12px] font-black"><input type="checkbox" checked={!!P.noPeakDiscount} onChange={(e) => setP('noPeakDiscount', e.target.checked ? 1 : 0)} />ممنوع الخصم على مواعيد الذروة (يعرض موعد صباحي أرخص بدلها)</label>
        </Box>
        <Box title="الإشعارات" hint="خطة الصبح وملخص الليل وتنبيهات الأحداث — للفريق في التطبيق وتيليجرام" icon={Bell}>
          <div className="grid grid-cols-2 gap-2">
            <Row label="ساعة خطة الصبح"><input type="number" min="0" max="23" className={inp} value={N.morningHour} onChange={(e) => setN('morningHour', num(e.target.value))} /></Row>
            <Row label="ساعة ملخص الليل"><input type="number" min="0" max="23" className={inp} value={N.eveningHour} onChange={(e) => setN('eveningHour', num(e.target.value))} /></Row>
            <Row label="صفقة كبيرة من (ر.س)"><input type="number" min="100" className={inp} value={N.bigDealSar} onChange={(e) => setN('bigDealSar', num(e.target.value))} /></Row>
          </div>
          <div className="flex flex-wrap gap-1.5">
            <Chip on={!!N.thresholds} onClick={() => setN('thresholds', N.thresholds ? 0 : 1)}>عدد الطلبات وصل لقاعدة</Chip>
            <Chip on={!!N.bigDeal} onClick={() => setN('bigDeal', N.bigDeal ? 0 : 1)}>صفقة كبيرة</Chip>
            <Chip on={!!N.outOfZone} onClick={() => setN('outOfZone', N.outOfZone ? 0 : 1)}>طلب خارج التغطية</Chip>
            <Chip on={!!N.telegram} onClick={() => setN('telegram', N.telegram ? 0 : 1)}>تيليجرام</Chip>
          </div>
        </Box>
      </div>
      <div className="grid lg:grid-cols-2 gap-3">
        {Object.entries(f.cities).map(([id, c]) => (
          <Box key={id} title={c.label} hint={id === 'jeddah' ? 'جدة أبعد: أيام وساعات مخصصة وتجميع الطلبات في مشوار واحد' : 'النطاق الأساسي'} icon={MapPin}>
            <label className="flex items-center gap-2 text-[12px] font-black"><input type="checkbox" checked={!!c.enabled} onChange={(e) => setC(id, 'enabled', e.target.checked ? 1 : 0)} />استقبال طلبات {c.label}</label>
            <div className="flex flex-wrap gap-1.5">{DAYS.map((d, i) => <Chip key={d} on={c.days.includes(i)} onClick={() => setC(id, 'days', c.days.includes(i) ? c.days.filter((x) => x !== i) : [...c.days, i].sort())}>{d}</Chip>)}</div>
            <div className="grid grid-cols-2 gap-2">
              <Row label="من"><input type="time" className={inp} value={c.from} onChange={(e) => setC(id, 'from', e.target.value)} /></Row>
              <Row label="إلى"><input type="time" className={inp} value={c.to} onChange={(e) => setC(id, 'to', e.target.value)} /></Row>
              <Row label="أقصى طلبات في اليوم"><input type="number" min="1" className={inp} value={c.dailyCap} onChange={(e) => setC(id, 'dailyCap', num(e.target.value))} /></Row>
              {id === 'jeddah' && <Row label="أقل تجميع للمشوار"><input type="number" min="1" className={inp} value={c.minBatch || 1} onChange={(e) => setC(id, 'minBatch', num(e.target.value))} /></Row>}
            </div>
          </Box>
        ))}
      </div>
      <Box title="تنظيم الشغل بعد عدد معين من الطلبات" hint="لما مدينة توصل للعدد في اليوم، البوت يغيّر المواعيد اللي يعرضها عشان الشغل ما يتكركبش — ويبعتلك تنبيه" icon={CalendarClock}>
        {f.batching.map((r, i) => (
          <div key={i} className="grid grid-cols-2 sm:grid-cols-4 gap-2 items-end bg-white/5 rounded-xl p-2.5">
            <Row label="بعد كام طلب"><input type="number" min="1" className={inp} value={r.after} onChange={(e) => setR(i, 'after', num(e.target.value))} /></Row>
            <Row label="يعمل إيه"><select className={inp} value={r.mode} onChange={(e) => setR(i, 'mode', e.target.value)}>{Object.entries(MODES).map(([k, v]) => <option key={k} value={k} className="text-slate-900">{v}</option>)}</select></Row>
            {r.mode === 'cluster' && <Row label="± ساعات من الزيارة"><input type="number" min="1" max="6" className={inp} value={r.clusterHours} onChange={(e) => setR(i, 'clusterHours', num(e.target.value))} /></Row>}
            {r.mode === 'windows' && <Row label="الفترة"><div className="flex gap-1"><input type="time" className={inp} value={r.windows?.[0]?.from || '16:00'} onChange={(e) => setR(i, 'windows', [{ from: e.target.value, to: r.windows?.[0]?.to || '21:00' }])} /><input type="time" className={inp} value={r.windows?.[0]?.to || '21:00'} onChange={(e) => setR(i, 'windows', [{ from: r.windows?.[0]?.from || '16:00', to: e.target.value }])} /></div></Row>}
            <button onClick={() => setF((s) => ({ ...s, batching: s.batching.filter((_, j) => j !== i) }))} aria-label="حذف القاعدة" className="w-10 h-10 rounded-lg bg-rose-600/80 flex items-center justify-center justify-self-end"><Trash2 size={15} /></button>
          </div>
        ))}
        {f.batching.length < 6 && <button onClick={() => setF((s) => ({ ...s, batching: [...s.batching, { after: (s.batching.at(-1)?.after || 2) + 2, mode: 'cluster', clusterHours: 2, windows: [] }] }))} className="self-start flex items-center gap-1.5 bg-white/10 rounded-lg px-3 py-2 text-xs font-black"><Plus size={14} />قاعدة جديدة</button>}
      </Box>
      {isOwner && <button onClick={() => save({ enabled: f.enabled, pricing: f.pricing, notify: f.notify, cities: f.cities, batching: f.batching })} disabled={busy} className="sticky bottom-3 flex items-center justify-center gap-1.5 bg-emerald-600 text-white font-black text-sm rounded-xl px-4 py-3 shadow-lg disabled:opacity-60">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}حفظ السياسة</button>}
    </div>
  )
}

/* ------------------------------------------------------------------ playbook */
function Playbook({ ov }) {
  const [q, setQ] = useState('')
  const [stage, setStage] = useState('')
  const [arch, setArch] = useState('')
  const [items, setItems] = useState([])
  const [busy, setBusy] = useState(false)
  const st = ov.playbook || {}
  const run = async () => {
    setBusy(true)
    try { const r = await post('/sales', { op: 'scenarios', q, limit: 60, filters: { stage: stage || undefined, archetype: arch || undefined } }); setItems(r.items || []) } catch { setItems([]) }
    setBusy(false)
  }
  useEffect(() => { run() }, [stage, arch]) // eslint-disable-line
  return (
    <div className="grid gap-3">
      <Box title={`${st.total || 0} سيناريو فصال واتفاق`} hint={`${st.situations} موقف × ${st.archetypes} أنواع عملاء × ${st.contexts} (ذروة/هدوء). كل سيناريو له خطة، أسلوب مع العميل، حجم التنازل، والخطوة التالية — والأرقام دايمًا من محرك الأسعار`} icon={BookOpen}>
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">{Object.entries(st.byStage || {}).map(([k, v]) => <Stat key={k} label={STAGES[k] || k} value={v * (st.archetypes || 1) * (st.contexts || 1)} />)}</div>
        <form onSubmit={(e) => { e.preventDefault(); run() }} className="flex gap-2">
          <input className={inp} placeholder="ابحث: غالي، جدة، آخر سعر، شركة…" value={q} onChange={(e) => setQ(e.target.value)} />
          <button className="rounded-lg bg-sky-600 px-4 font-black text-sm">{busy ? <Loader2 size={16} className="animate-spin" /> : 'بحث'}</button>
        </form>
        <div className="flex flex-wrap gap-1.5"><Chip on={!stage} onClick={() => setStage('')}>كل المراحل</Chip>{Object.entries(STAGES).map(([k, v]) => <Chip key={k} on={stage === k} onClick={() => setStage(k)}>{v}</Chip>)}</div>
        <div className="flex flex-wrap gap-1.5"><Chip on={!arch} onClick={() => setArch('')}>كل العملاء</Chip>{Object.entries(ov.archetypes || {}).map(([k, v]) => <Chip key={k} on={arch === k} onClick={() => setArch(k)}>{v}</Chip>)}</div>
      </Box>
      <div className="grid md:grid-cols-2 gap-2">
        {items.map((s) => (
          <article key={s.id} className="rounded-xl border border-white/15 bg-white/5 p-3 flex flex-col gap-1.5">
            <p className="font-black text-[13px]">{s.title}</p>
            <p className="text-[10px] text-white/50" dir="ltr">{s.id} · تنازل ×{s.give}</p>
            <p className="text-[12px] text-white/85"><b className="text-sky-200">الخطة:</b> {s.tactic}</p>
            <p className="text-[11px] text-white/65"><b>مع العميل:</b> {s.persona}</p>
            {s.timing && <p className="text-[11px] text-amber-200">{s.timing}</p>}
            <p className="text-[12px] bg-emerald-500/10 border border-emerald-400/25 rounded-lg p-2">«{s.sample}»</p>
          </article>
        ))}
        {!items.length && !busy && <p className="text-[12px] text-white/60">ما فيش نتايج.</p>}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ simulator */
function Simulator() {
  const [x, setX] = useState({ text: 'والله غالي، غيركم أرخص', history: 'كم غسيل 3 مكيفات سبليت؟', list: 300, floor: 250, phone: '', city: '', ai: false })
  const [r, setR] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k, v) => setX((s) => ({ ...s, [k]: v }))
  const go = async () => {
    setBusy(true); setErr('')
    try { setR(await post('/sales', { op: 'simulate', ...x, history: x.history.split('\n').filter(Boolean) })) } catch (e) { setErr(errText(e, 'التجربة ما نجحتش')) }
    setBusy(false)
  }
  const pr = r?.price
  return (
    <div className="grid gap-3">
      <Box title="جرّب البوت قبل ما العميل يجرّبه" hint="اكتب رسالة عميل والسعر الرسمي والحد الأدنى — وشوف البوت هيختار أنهي سيناريو وهيقول كام وهيعرض أنهي مواعيد" icon={FlaskConical}>
        <Row label="رسائل العميل السابقة (كل سطر رسالة)"><textarea rows={2} className={inp} value={x.history} onChange={(e) => set('history', e.target.value)} /></Row>
        <Row label="آخر رسالة من العميل"><input className={inp} value={x.text} onChange={(e) => set('text', e.target.value)} /></Row>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <Row label="السعر الرسمي"><input type="number" className={inp} value={x.list} onChange={(e) => set('list', e.target.value)} /></Row>
          <Row label="الحد الأدنى"><input type="number" className={inp} value={x.floor} onChange={(e) => set('floor', e.target.value)} /></Row>
          <Row label="جوال عميل (اختياري)"><input className={inp} dir="ltr" value={x.phone} onChange={(e) => set('phone', e.target.value)} /></Row>
          <Row label="المدينة"><select className={inp} value={x.city} onChange={(e) => set('city', e.target.value)}><option value="" className="text-slate-900">من الكلام</option><option value="makkah" className="text-slate-900">مكة</option><option value="jeddah" className="text-slate-900">جدة</option><option value="out" className="text-slate-900">خارج التغطية</option></select></Row>
        </div>
        <label className="flex items-center gap-2 text-[12px] font-black"><input type="checkbox" checked={x.ai} onChange={(e) => set('ai', e.target.checked)} />اطلب رد فعلي من محرك الذكاء (يستهلك من الحصة)</label>
        <button onClick={go} disabled={busy || !x.text.trim()} className="self-start flex items-center gap-1.5 bg-fuchsia-600 rounded-lg px-4 py-2.5 text-sm font-black disabled:opacity-50">{busy ? <Loader2 size={16} className="animate-spin" /> : <Sparkles size={16} />}جرّب</button>
        {err && <p className="text-[12px] text-rose-200">{err}</p>}
      </Box>
      {r && (
        <div className="grid lg:grid-cols-2 gap-3">
          <Box title={r.scenario?.title || 'بدون سيناريو'} hint={r.scenario?.id} icon={BookOpen}>
            <div className="grid grid-cols-3 gap-2">
              <Stat label="نوع العميل" value={r.profile?.orders ? r.profile.archetypeLabel : ({ new: 'جديد', urgent: 'مستعجل', hesitant: 'متردد', bargainer: 'فصّال' }[r.archetype] || r.archetype)} />
              <Stat label="حساسية السعر" value={`${Math.round((r.sensitivity || 0) * 100)}%`} tone="amber" />
              <Stat label="المدينة" value={{ makkah: 'مكة', jeddah: 'جدة', out: 'خارج' }[r.city] || r.city} tone="emerald" />
            </div>
            {pr && <div className="grid grid-cols-4 gap-2"><Stat label="الرسمي" value={pr.list} /><Stat label="يقوله الآن" value={pr.now} tone="emerald" /><Stat label="الهدف" value={pr.target} tone="amber" /><Stat label="الأدنى (سري)" value={pr.floor} tone="rose" /></div>}
            {pr && <p className="text-[12px] text-white/80">{pr.why}{pr.ladder ? ` — سلم التنازل: ${pr.ladder.join(' ← ')}` : ''}</p>}
            <p className="text-[12px]"><b className="text-sky-200">الخطة:</b> {r.scenario?.tactic}</p>
            <p className="text-[12px] text-white/70"><b>المواعيد:</b> {r.slots?.length ? r.slots.map((s) => s.label).join(' · ') : 'لا يوجد'} {r.rule && <span className="text-amber-200">({r.rule})</span>}</p>
            {r.sample && <p className="text-[13px] bg-emerald-500/10 border border-emerald-400/25 rounded-lg p-2">«{r.sample}»</p>}
            {r.aiReply && <p className="text-[13px] bg-fuchsia-500/10 border border-fuchsia-400/30 rounded-lg p-2"><b>رد المحرك ({r.provider}):</b> {r.aiReply}</p>}
            {r.aiError && <p className="text-[12px] text-rose-200">المحرك: {r.aiError}</p>}
          </Box>
          <Box title="اللي بيوصل للذكاء الاصطناعي" hint="التعليمات الداخلية اللي بتتحط قبل المحادثة — العميل ما بيشوفهاش" icon={Brain}>
            <pre className="text-[11px] leading-relaxed whitespace-pre-wrap bg-black/30 rounded-lg p-3 max-h-96 overflow-y-auto">{r.prompt}</pre>
          </Box>
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ training chat */
const IDEAS = [
  'جدة خليها أيام الأحد والثلاثاء والخميس من 2 الضهر لـ 9 بالليل',
  'بعد 3 طلبات في اليوم قرّب المواعيد من بعض، وبعد 6 خليها بالليل بس',
  'أقصى خصم 10% وممنوع خصم في الذروة',
  'لو العميل شركة أو عمارة حوّله لي على طول',
  'جرّب: عميل قال «آخر سعر 150» والسعر الرسمي 220',
  'إيه خطة بكرة في مكة وجدة؟',
]
function Train({ f, save, isOwner }) {
  const [msgs, setMsgs] = useState(() => { try { return JSON.parse(sessionStorage.getItem('mq_sales_chat') || '[]') } catch { return [] } })
  const [text, setText] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const end = useRef(null)
  useEffect(() => { try { sessionStorage.setItem('mq_sales_chat', JSON.stringify(msgs.slice(-30))) } catch { /* private */ } end.current?.scrollIntoView({ block: 'end' }) }, [msgs])
  const ask = async (q) => {
    const t = (q ?? text).trim(); if (!t || busy) return
    const next = [...msgs, { role: 'user', text: t }]
    setMsgs(next); setText(''); setBusy(true)
    try {
      const r = await post('/agent', { messages: next.filter((m) => !m.note).map(({ role, text: tx }) => ({ role, text: tx })) })
      setMsgs((m) => [...m, { role: 'assistant', text: r.text, pending: r.pending || null }])
    } catch (e) { setMsgs((m) => [...m, { role: 'assistant', text: errText(e, 'المساعد ما ردّش') }]) }
    setBusy(false)
  }
  const decide = async (i, ok) => {
    const p = msgs[i].pending; if (!p || busy) return
    setBusy(true)
    try { const r = await post('/agent', ok ? { confirm: p.id } : { cancel: p.id }); setMsgs((m) => m.map((x, j) => (j === i ? { ...x, pending: { ...p, done: ok ? 'done' : 'cancelled' } } : x)).concat([{ role: 'user', text: r.text, note: true }])) } catch (e) { setMsgs((m) => m.concat([{ role: 'user', text: errText(e, 'ما اتنفذش'), note: true }])) }
    setBusy(false)
  }
  const notes = f?.notes || []
  return (
    <div className="grid lg:grid-cols-[1fr_320px] gap-3">
      <Box title="كلّم البوت وفهّمه الأسعار والدنيا" hint="اشرح بكلامك: الأيام، الأسعار، القواعد، أسلوب الفصال. التعديلات اللي بتمس الفلوس بتستنى ضغطة «تنفيذ» منك" icon={MessagesSquare}>
        <div className="grid gap-2 max-h-[55vh] overflow-y-auto pr-1">
          {!msgs.length && <div className="grid sm:grid-cols-2 gap-2">{IDEAS.map((q) => <button key={q} onClick={() => ask(q)} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-[12px] flex gap-2 items-center"><Sparkles size={13} className="text-fuchsia-300 shrink-0" />{q}</button>)}</div>}
          {msgs.map((m, i) => m.note ? <p key={i} className="text-center text-[11px] text-white/70">{m.text}</p> : (
            <div key={i} className={m.role === 'user' ? 'flex justify-start' : 'flex justify-end'}>
              <div className={`max-w-[90%] rounded-2xl px-3 py-2 text-[13px] leading-relaxed whitespace-pre-wrap ${m.role === 'user' ? 'bg-primary/20 border border-primary/30' : 'bg-white/5 border border-white/10'}`}>
                {m.text}
                {m.pending && (
                  <div className="mt-2 rounded-xl bg-amber-400/10 border border-amber-400/30 p-2 grid gap-2">
                    <p className="text-[12px] text-amber-100 font-bold">{m.pending.summary}</p>
                    {m.pending.done ? <p className="text-[11px] text-white/70">{m.pending.done === 'done' ? 'اتنفذ' : 'اتلغى'}</p> : (
                      <div className="flex gap-2">
                        <button disabled={busy} onClick={() => decide(i, true)} className="flex-1 rounded-lg bg-emerald-500/90 font-bold text-[12px] py-1.5 flex items-center justify-center gap-1"><CheckCircle2 size={14} />تنفيذ</button>
                        <button disabled={busy} onClick={() => decide(i, false)} className="flex-1 rounded-lg bg-white/10 font-bold text-[12px] py-1.5 flex items-center justify-center gap-1"><XCircle size={14} />إلغاء</button>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>
          ))}
          {busy && <p className="text-[12px] text-white/60 flex items-center gap-1.5 justify-end"><Loader2 size={13} className="animate-spin" />بيفكر…</p>}
          <div ref={end} />
        </div>
        <form onSubmit={(e) => { e.preventDefault(); ask() }} className="flex gap-2 items-end">
          <label htmlFor="sales-chat" className="sr-only">اكتب للبوت</label>
          <textarea id="sales-chat" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask() } }} placeholder="مثلاً: يوم الجمعة ما فيش جدة" className="flex-1 resize-none rounded-xl bg-white/5 border border-white/15 px-3 py-2 text-[14px] max-h-32" />
          <button type="submit" disabled={busy || !text.trim()} aria-label="إرسال" className="w-11 h-11 rounded-xl bg-primary flex items-center justify-center disabled:opacity-50"><Send size={17} /></button>
          {!!msgs.length && <button type="button" onClick={() => setMsgs([])} aria-label="محادثة جديدة" className="w-11 h-11 rounded-xl bg-white/10 flex items-center justify-center"><Trash2 size={16} /></button>}
        </form>
      </Box>
      <Box title={`تعليماتك الدائمة (${notes.length})`} hint="بتتحط في كل محادثة فصال. ما بتكسرش الحد الأدنى للسعر" icon={Brain}>
        {notes.map((n, i) => <div key={i} className="flex gap-2 items-start bg-white/5 rounded-lg p-2 text-[12px]"><p className="flex-1">{n.text}</p>{isOwner && <button onClick={() => save({ removeNote: i }, 'اتشالت التعليمة')} aria-label="حذف" className="text-rose-300"><Trash2 size={14} /></button>}</div>)}
        {isOwner && <form onSubmit={(e) => { e.preventDefault(); if (note.trim()) { save({ note }, 'اتضافت التعليمة ✅'); setNote('') } }} className="flex gap-2"><input className={inp} value={note} onChange={(e) => setNote(e.target.value)} placeholder="مثلاً: اذكر الضمان قبل أي خصم" /><button className="rounded-lg bg-emerald-600 px-3"><Plus size={16} /></button></form>}
      </Box>
    </div>
  )
}
