import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2 } from 'lucide-react'
import { post } from '../lib/_api'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'

// «جلب العملاء» (owner, 2026-11-23 — ideas 16–21): one switch per part, the Google review link, and the yearly
// maintenance contracts whose visits become orders by themselves (server: Mutqan_Growth).
const Switch = ({ on, onChange, label, testid }) => (
  <button type="button" role="switch" aria-checked={!!on} onClick={() => onChange(!on)} data-switch={testid}
    className={`shrink-0 w-12 h-7 rounded-full p-0.5 transition-colors ${on ? 'bg-emerald-500' : 'bg-white/15'}`} aria-label={label}>
    <span className={`block w-6 h-6 rounded-full bg-white transition-transform ${on ? '-translate-x-5' : ''}`} />
  </button>
)

const Row = ({ n, title, desc, children }) => (
  <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 grid gap-2.5">
    <div className="flex items-start gap-3">
      <span className="shrink-0 w-7 h-7 rounded-full bg-primary/20 text-primary text-xs font-black flex items-center justify-center">{n}</span>
      <div className="flex-1 min-w-0"><p className="text-sm font-black text-white">{title}</p><p className="text-[11px] text-slate-400 leading-relaxed">{desc}</p></div>
    </div>
    {children}
  </section>
)

export default function GrowthSettings() {
  const navigate = useNavigate()
  const [cfg, setCfg] = useState(null)
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [url, setUrl] = useState('')
  useEffect(() => { post('/growth', { op: 'get' }).then((c) => { setCfg(c); setUrl(c?.reviewUrl || '') }).catch((e) => setMsg(e?.message || 'تعذر التحميل')) }, [])
  async function save(patch) {
    setBusy(true); setMsg('')
    try { const c = await post('/growth', { op: 'set', cfg: patch }); setCfg(c); setMsg('اتحفظ ✅') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') }
    setBusy(false)
  }
  const st = cfg?.status || {}
  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-[#0b1020] pb-16" dir="rtl">
      <header className="sticky top-0 z-10 bg-[#0b1020]/95 backdrop-blur border-b border-white/10">
        <div className="max-w-3xl mx-auto px-4 py-3 flex items-center gap-2">
          <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1"><p className="text-[11px] text-primary font-bold tracking-widest">الإدارة</p><h1 className="font-black text-lg text-white">🚀 جلب العملاء</h1></div>
          {busy && <Loader2 size={16} className="animate-spin text-slate-400" />}
        </div>
      </header>
      <main className="max-w-3xl mx-auto px-4 py-4 grid gap-3">
        {msg && <p className="text-center text-xs font-bold text-emerald-300">{msg}</p>}
        {!cfg ? <p className="text-center text-slate-500 text-sm py-10">جارِ التحميل…</p> : (
          <>
            <Row n="16" title="طلب تقييم على خرائط جوجل" desc="لما العميل يقيّم ٥ نجوم على واتساب، بيوصله رابط تقييم جوجل بتاعك. مقفول لحد ما تفعّله وتحط الرابط.">
              <div className="flex items-center gap-3"><Switch on={cfg.reviewOn} onChange={(v) => save({ reviewOn: v })} label="تفعيل تقييم جوجل" testid="review" /><span className="text-xs text-slate-300">{cfg.reviewOn ? 'مفعّل' : 'مقفول'}{cfg.reviewOn && !cfg.reviewUrl ? ' — حط الرابط عشان يشتغل' : ''}</span></div>
              <div className="flex gap-2">
                <input value={url} onChange={(e) => setUrl(e.target.value)} dir="ltr" placeholder="https://g.page/r/…/review" className="flex-1 rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-xs text-white outline-none" data-review-url />
                <button onClick={() => save({ reviewUrl: url })} className="rounded-lg bg-primary text-black text-xs font-black px-3" data-review-save>حفظ الرابط</button>
              </div>
              <p className="text-[10px] text-slate-500">من «ملف النشاط التجاري» في جوجل → «اطلب تقييمات» → انسخ الرابط.</p>
            </Row>
            <Row n="17" title="تذكير صيانة موسمي" desc="بعد عدد شهور من آخر خدمة، العميل اللي ما طلبش تاني بيوصله تذكير ودّي بالغسيل الدوري. حد أقصى في اليوم عشان الرقم ما يتبلّغش.">
              <div className="flex items-center gap-3"><Switch on={cfg.seasonalOn} onChange={(v) => save({ seasonalOn: v })} label="تفعيل التذكير" testid="seasonal" /><span className="text-xs text-slate-300">{cfg.seasonalOn ? `مفعّل — اتبعت النهارده ${st.seasonalSentToday || 0}` : 'مقفول'}</span></div>
              <div className="grid grid-cols-2 gap-2">
                <label className="grid gap-1 text-[10px] text-slate-400">بعد كام شهر<input type="number" min="1" max="24" defaultValue={cfg.seasonalMonths} onBlur={(e) => save({ seasonalMonths: Number(e.target.value) })} className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white" /></label>
                <label className="grid gap-1 text-[10px] text-slate-400">أقصى عدد في اليوم<input type="number" min="1" max="60" defaultValue={cfg.seasonalPerDay} onBlur={(e) => save({ seasonalPerDay: Number(e.target.value) })} className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white" /></label>
              </div>
              <label className="grid gap-1 text-[10px] text-slate-400">عرض يتضاف للرسالة (اختياري)<input defaultValue={cfg.seasonalOffer} onBlur={(e) => save({ seasonalOffer: e.target.value })} placeholder="مثال: خصم ١٠٪ على الغسيل الأسبوع ده" className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white" /></label>
            </Row>
            <Row n="18" title="استرجاع العميل اللي لغى" desc="لما عميل يلغي، البوت بيسأله عن السبب وبيعرض يرجّع الطلب (وبخصم لو السبب السعر).">
              <p className={`text-xs font-bold ${st.winback ? 'text-emerald-300' : 'text-amber-300'}`}>{st.winback ? '✓ شغال' : 'متوقف من إعدادات رحلة واتساب'}</p>
            </Row>
            <Contracts on={cfg.contractsOn} onToggle={(v) => save({ contractsOn: v })} />
            <Row n="20" title="ترشيح بمكافأة" desc="بعد التقييم الكويس العميل بياخد لينك واتساب يبعته لصاحبه بكود خاص بيه. لما صاحبه يطلب، الطلب بيتعلّم بمين رشّحه والعمليات بتتبلّغ بالخصم للاتنين.">
              <div className="flex items-center gap-3"><Switch on={cfg.referralOn} onChange={(v) => save({ referralOn: v })} label="تفعيل الترشيح" testid="referral" /><span className="text-xs text-slate-300">{cfg.referralOn ? 'مفعّل' : 'مقفول'}</span></div>
              <label className="flex items-center gap-2 text-[11px] text-slate-300">نسبة الخصم لكل واحد<input type="number" min="1" max="50" defaultValue={cfg.referralPct} onBlur={(e) => save({ referralPct: Number(e.target.value) })} className="w-20 rounded-lg bg-white/5 border border-white/10 px-2 py-1 text-xs text-white" />%</label>
            </Row>
            <Row n="21" title="ترحيب بالعميل الراجع" desc="أول ما عميل قديم يكلّمنا، البوت بيرحّب بيه باسمه ويفكّره بآخر خدمة وحالة ضمانها، والعمليات بتتبلّغ إنه رجع.">
              <p className={`text-xs font-bold ${st.returning ? 'text-emerald-300' : 'text-amber-300'}`}>{st.returning ? '✓ شغال' : 'مركز الواتساب مش متوصل'}</p>
            </Row>
          </>
        )}
      </main>
    </div>
  )
}

function Contracts({ on, onToggle }) {
  const { data } = useLiveShared('contracts', { order: '-createdAt', limit: 200 })
  const [f, setF] = useState({ company: '', phone: '', address: '', units: '', visitsPerYear: '4', startDate: new Date().toISOString().slice(0, 10), price: '', notes: '' })
  const [msg, setMsg] = useState('')
  async function add() {
    if (!f.company.trim() || !f.phone.trim()) { setMsg('اكتب اسم الجهة ورقمها'); return }
    try {
      await db.insertShared('contracts', { company: f.company.trim(), phone: f.phone.trim(), address: f.address.trim(), units: Number(f.units) || 0, visitsPerYear: Number(f.visitsPerYear) || 4, startDate: new Date(f.startDate + 'T09:00:00+03:00').toISOString(), price: Number(f.price) || 0, notes: f.notes.trim(), specialtyId: 'ac', active: true, visitsMade: 0, createdAt: new Date().toISOString() }, undefined, { groupId: await team.groupId(), visibleTo: 'group', writableBy: 'admins' })
      setF({ ...f, company: '', phone: '', address: '', units: '', price: '', notes: '' }); setMsg('اتضاف العقد ✅ — أول زيارة هتتسجل طلب لوحدها')
    } catch (e) { setMsg(e?.message || 'تعذر الحفظ') }
  }
  const toggle = (k) => db.updateShared('contracts', k.id, { active: k.active === false }).catch(() => {})
  const inp = 'rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white outline-none'
  return (
    <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 grid gap-2.5" data-contracts>
      <div className="flex items-start gap-3">
        <span className="shrink-0 w-7 h-7 rounded-full bg-primary/20 text-primary text-xs font-black flex items-center justify-center">19</span>
        <div className="flex-1"><p className="text-sm font-black text-white">عقود الصيانة السنوية</p><p className="text-[11px] text-slate-400">للشركات والمطاعم والعماير (من سحب الخرائط مثلًا): كل زيارة في موعدها بتتسجل طلب لوحدها وتتوزع على الفنيين.</p></div>
        <button type="button" role="switch" aria-checked={!!on} onClick={() => onToggle(!on)} className={`shrink-0 w-12 h-7 rounded-full p-0.5 ${on ? 'bg-emerald-500' : 'bg-white/15'}`} data-switch="contracts"><span className={`block w-6 h-6 rounded-full bg-white transition-transform ${on ? '-translate-x-5' : ''}`} /></button>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <input value={f.company} onChange={(e) => setF({ ...f, company: e.target.value })} placeholder="اسم الجهة" className={inp} data-contract-company />
        <input value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} placeholder="رقم التواصل" dir="ltr" className={inp} />
        <input value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} placeholder="العنوان / الحي" className={`${inp} col-span-2`} />
        <input value={f.units} onChange={(e) => setF({ ...f, units: e.target.value })} type="number" placeholder="عدد المكيفات" className={inp} />
        <select value={f.visitsPerYear} onChange={(e) => setF({ ...f, visitsPerYear: e.target.value })} className={inp}>{[2, 3, 4, 6, 12].map((n) => <option key={n} value={n} className="text-black">{n} زيارات في السنة</option>)}</select>
        <label className="grid gap-0.5 text-[10px] text-slate-400">أول زيارة<input type="date" value={f.startDate} onChange={(e) => setF({ ...f, startDate: e.target.value })} className={inp} /></label>
        <input value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} type="number" placeholder="قيمة العقد السنوية (ر.س)" className={inp} />
        <input value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} placeholder="ملاحظات (اختياري)" className={`${inp} col-span-2`} />
      </div>
      <button onClick={add} className="rounded-xl bg-primary text-black text-xs font-black py-2.5" data-contract-add>➕ إضافة عقد</button>
      {msg && <p className="text-[11px] font-bold text-emerald-300">{msg}</p>}
      {(data || []).map((k) => (
        <div key={k.id} className="rounded-xl bg-black/20 p-2.5 flex items-center gap-2 text-[11px]">
          <div className="flex-1 min-w-0">
            <p className="font-black text-white truncate">{k.company} {k.active === false && <span className="text-rose-300">(موقوف)</span>}</p>
            <p className="text-slate-400">{k.visitsPerYear} زيارات/سنة · اتعمل {k.visitsMade || 0} · الجاية: {k.nextVisitAt ? new Date(k.nextVisitAt).toLocaleDateString('ar-SA-u-nu-latn') : '—'}{k.price ? ` · ${k.price} ر.س` : ''}</p>
          </div>
          <button onClick={() => toggle(k)} className="rounded-lg bg-white/10 text-slate-200 px-2 py-1 font-bold">{k.active === false ? 'تشغيل' : 'إيقاف'}</button>
        </div>
      ))}
    </section>
  )
}
