// «الأرقام والحملات» (owner, 2026-11-21): every company WhatsApp number and its job (manager / sales / outreach /
// reserve), the services WhatsApp offers, and the invitation campaigns — their hours, ceilings and texts. The same data
// as wp-admin ← «حملات الاستقطاب والأرقام» (Mutqan_Lines / Mutqan_Outreach); the owner changes it, operations see it.
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2, Save, RefreshCw, Phone, Megaphone, Plus } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { useTeamRole } from '../hooks/useTeamRole'
import AccessDenied from '../components/AccessDenied'

const ROLE_LABEL = { manager: 'المدير', sales: 'مبيعات', outreach: 'استقطاب', reserve: 'احتياطي' }
const STATUS = [['active', 'مفعّل'], ['pending', 'مستني الربط'], ['paused', 'موقوف']]
const CHANNELS = [['sites', 'المواقع'], ['articles', 'المقالات'], ['blogger', 'بلوجر'], ['ads', 'إعلانات'], ['maps', 'خرائط'], ['haraj', 'حراج'], ['emergency', 'طوارئ']]
const SRC = { maps: 'خرائط جوجل', system: 'عملاء سابقين', whatsapp: 'واتساب' }
const ST = { new: 'لسه ما اتبعتلهمش', sent: 'اتبعتلهم', optout: 'لا ترسل', ours: 'أرقامنا' }

export default function NumbersCampaigns() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const [tab, setTab] = useState('numbers')
  if (loading) return <p className="p-10 text-center text-slate-400">جارِ التحقق…</p>
  if (!isOwner && !['operations', 'supervisor'].includes(role)) return <AccessDenied refresh={refresh} />
  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-5xl mx-auto w-full px-4 py-4 flex items-center gap-3">
          <button onClick={() => navigate('/staff')} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1"><p className="text-[11px] text-emerald-400 font-bold tracking-widest">WHATSAPP NUMBERS</p><h1 className="text-lg font-black text-white">الأرقام والحملات</h1></div>
        </div>
        <div className="max-w-5xl mx-auto w-full px-4 pb-3 flex gap-1.5">
          {[['numbers', 'الأرقام والخدمات', Phone], ['campaigns', 'حملات الاستقطاب', Megaphone]].map(([id, l, I]) => (
            <button key={id} onClick={() => setTab(id)} className={`rounded-xl px-3 py-1.5 text-xs font-bold flex items-center gap-1 ${tab === id ? 'bg-emerald-500 text-black' : 'bg-white/5 text-slate-300'}`}><I size={13} />{l}</button>
          ))}
        </div>
      </header>
      <main className="max-w-5xl mx-auto w-full px-4 py-5">
        {tab === 'numbers' ? <Numbers canEdit={isOwner} /> : <Campaigns canEdit={isOwner} />}
      </main>
    </div>
  )
}

function useLoad(path) {
  const [data, setData] = useState(null); const [err, setErr] = useState('')
  const load = () => post(path, { op: 'view' }).then(setData).catch((e) => setErr(errText(e)))
  useEffect(() => { load() }, [])
  return [data, setData, err, load]
}

function Numbers({ canEdit }) {
  const [v, setV, err] = useLoad('/lines')
  const [busy, setBusy] = useState(false); const [msg, setMsg] = useState('')
  const [add, setAdd] = useState({ phone: '', label: '', role: 'sales' })
  if (err) return <p className="text-rose-400 text-sm">{err}</p>
  if (!v) return <Loader2 className="animate-spin text-slate-400 mx-auto" />
  const set = (raw, f) => setV({ ...v, numbers: v.numbers.map((n) => (n.raw === raw ? { ...n, ...f } : n)) })
  const save = async () => {
    setBusy(true); setMsg('')
    try {
      const r = await post('/lines', { op: 'save', patch: v.numbers.filter((n) => n.status !== 'invalid').map((n) => ({ phone: n.raw, role: n.role, status: n.status, label: n.label, channels: n.channels || [] })),
        add: add.phone ? [add] : [], rotate: v.rotate, off: v.sections.filter((s) => !s.on).map((s) => s.n) })
      setV(r); setAdd({ phone: '', label: '', role: 'sales' }); setMsg('اتحفظ ✓ — مركز الواتساب بياخد التغيير خلال 10 دقايق')
    } catch (e) { setMsg(errText(e)) }
    setBusy(false)
  }
  return (
    <div className="flex flex-col gap-3">
      <p className="text-[11px] text-slate-400">«المدير» بيبقى غرفة العمليات أول ما تخليه «مفعّل» بعد ربطه. «مبيعات» بتظهر في المواقع والمقالات وبتتبدل كل {v.rotateDays} أيام. «استقطاب» بس هي اللي بتبعت الحملات. «موقوف» يوقف الرقم من غير ما يمسحه. كل رقم بيتجهز للربط لوحده في مركز الواتساب (امسح الكود من «ربط الواتسابات»).</p>
      {v.numbers.map((n) => (
        <div key={n.raw} className="bg-white/[0.04] border border-white/10 rounded-2xl p-3 flex flex-col gap-2">
          <div className="flex items-center gap-2 flex-wrap">
            <b className="text-white text-sm" dir="ltr">{n.local}</b>
            <input disabled={!canEdit} value={n.label} onChange={(e) => set(n.raw, { label: e.target.value })} className="flex-1 min-w-[120px] bg-white/5 rounded-lg px-2 py-1 text-xs text-white" />
            {n.status === 'invalid' ? <span className="text-[11px] text-rose-400 font-bold">رقم غلط</span> : (<>
              <select disabled={!canEdit} value={n.role} onChange={(e) => set(n.raw, { role: e.target.value })} className="bg-white/5 rounded-lg px-2 py-1 text-xs text-white">{Object.entries(ROLE_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
              <select disabled={!canEdit} value={n.status} onChange={(e) => set(n.raw, { status: e.target.value })} className="bg-white/5 rounded-lg px-2 py-1 text-xs text-white">{STATUS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
            </>)}
          </div>
          {n.role === 'sales' && <div className="flex flex-wrap gap-2">{CHANNELS.map(([k, l]) => (
            <label key={k} className="text-[11px] text-slate-300 flex items-center gap-1"><input type="checkbox" disabled={!canEdit} checked={(n.channels || []).includes(k)} onChange={(e) => set(n.raw, { channels: e.target.checked ? [...(n.channels || []), k] : (n.channels || []).filter((x) => x !== k) })} />{l}</label>
          ))}</div>}
          {n.note && <p className="text-[10px] text-slate-500">{n.note}</p>}
        </div>
      ))}
      {canEdit && (
        <div className="bg-white/[0.03] border border-dashed border-white/15 rounded-2xl p-3 flex flex-wrap gap-2 items-center">
          <Plus size={14} className="text-slate-400" />
          <input placeholder="رقم جديد 05…" dir="ltr" value={add.phone} onChange={(e) => setAdd({ ...add, phone: e.target.value })} className="bg-white/5 rounded-lg px-2 py-1 text-xs text-white w-36" />
          <input placeholder="الاسم" value={add.label} onChange={(e) => setAdd({ ...add, label: e.target.value })} className="bg-white/5 rounded-lg px-2 py-1 text-xs text-white w-28" />
          <select value={add.role} onChange={(e) => setAdd({ ...add, role: e.target.value })} className="bg-white/5 rounded-lg px-2 py-1 text-xs text-white">{Object.entries(ROLE_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </div>
      )}
      <label className="text-xs text-slate-300 flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={!!v.rotate} onChange={(e) => setV({ ...v, rotate: e.target.checked })} />بدّل أرقام المبيعات على الصفحات كل {v.rotateDays} أيام</label>
      <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-3">
        <p className="text-sm font-black text-white mb-1">الخدمات اللي بيعرضها الواتساب</p>
        <p className="text-[11px] text-slate-400 mb-2">شيل العلامة لإيقاف خدمة مؤقتًا: تختفي من قائمة الواتساب، واللي يطلبها يتحول لموظف. الخدمات الموقوفة من «تحكمات المالك ← الخدمات» بتقف هنا كمان.</p>
        <div className="flex flex-wrap gap-3">{v.sections.map((s) => (
          <label key={s.n} className="text-xs text-slate-200 flex items-center gap-1"><input type="checkbox" disabled={!canEdit} checked={s.on} onChange={(e) => setV({ ...v, sections: v.sections.map((x) => (x.n === s.n ? { ...x, on: e.target.checked } : x)) })} />{s.label}</label>
        ))}</div>
      </div>
      {canEdit && <button disabled={busy} onClick={save} className="self-start bg-emerald-500 text-black rounded-xl px-4 py-2 text-sm font-black flex items-center gap-1">{busy ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />}حفظ</button>}
      {msg && <p className="text-xs text-emerald-300">{msg}</p>}
    </div>
  )
}

function Campaigns({ canEdit }) {
  const [v, setV, err, load] = useLoad('/outreach')
  const [busy, setBusy] = useState(false); const [msg, setMsg] = useState(''); const [stop, setStop] = useState('')
  if (err) return <p className="text-rose-400 text-sm">{err}</p>
  if (!v) return <Loader2 className="animate-spin text-slate-400 mx-auto" />
  const s = v.settings
  const put = (f) => setV({ ...v, settings: { ...s, ...f } })
  const num = (k, w = 'w-16') => <input type="number" disabled={!canEdit} value={s[k]} onChange={(e) => put({ [k]: Number(e.target.value) })} className={`bg-white/5 rounded-lg px-2 py-1 text-xs text-white ${w}`} />
  const run = async (body, ok) => { setBusy(true); setMsg(''); try { const r = await post('/outreach', body); setMsg(ok(r)); await load() } catch (e) { setMsg(errText(e)) } setBusy(false) }
  return (
    <div className="flex flex-col gap-3">
      <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-3 flex flex-col gap-2 text-xs text-slate-200">
        <label className="flex items-center gap-2 font-bold"><input type="checkbox" disabled={!canEdit} checked={!!s.on} onChange={(e) => put({ on: e.target.checked ? 1 : 0 })} />الحملات شغالة (بتبعت بس من أرقام الاستقطاب المربوطة)</label>
        <p>ساعات الإرسال (توقيت السعودية): من {num('from')} لـ {num('to')}</p>
        <p>حد كل رقم في اليوم: أول يوم {num('start')} + {num('ramp')} كل يوم، وبحد أقصى {num('max')} — التدرّج بيحمي الرقم من الحظر.</p>
        <p>نفس العميل: مرة كل {num('cooldown')} يوم، و{num('maxTouches', 'w-12')} مرات بالكتير.</p>
        <div className="flex gap-3">{Object.entries(SRC).map(([k, l]) => (
          <label key={k} className="flex items-center gap-1"><input type="checkbox" disabled={!canEdit} checked={!!s.sources?.[k]} onChange={(e) => put({ sources: { ...s.sources, [k]: e.target.checked ? 1 : 0 } })} />{l}</label>
        ))}</div>
        <p className="font-bold mt-1">رسايل العملاء الجدد <span className="text-slate-500 font-normal">(كل رسالة لوحدها، بينهم سطر فيه --- · المتغيرات: {'{brand} {city} {field}'})</span></p>
        <textarea disabled={!canEdit} rows={9} value={s.cold} onChange={(e) => put({ cold: e.target.value })} className="bg-white/5 rounded-xl p-2 text-xs text-white" />
        <p className="font-bold">رسايل العملاء السابقين <span className="text-slate-500 font-normal">(المتغيرات: {'{brand} {name} {last}'})</span></p>
        <textarea disabled={!canEdit} rows={6} value={s.customer} onChange={(e) => put({ customer: e.target.value })} className="bg-white/5 rounded-xl p-2 text-xs text-white" />
        {canEdit && <button disabled={busy} onClick={() => run({ op: 'save', settings: s }, () => 'اتحفظت الخطة ✓')} className="self-start bg-emerald-500 text-black rounded-xl px-4 py-2 text-sm font-black flex items-center gap-1"><Save size={15} />حفظ الخطة</button>}
      </div>
      <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-3">
        <div className="flex items-center mb-2"><p className="flex-1 text-sm font-black text-white">قاعدة العملاء المحتملين</p>
          {canEdit && <button disabled={busy} onClick={() => run({ op: 'sync' }, (r) => `اتضاف ${r.system || 0} عميل سابق و${r.whatsapp || 0} من الواتساب`)} className="bg-white/10 rounded-lg px-2 py-1 text-[11px] text-white flex items-center gap-1"><RefreshCw size={12} />جمّع دلوقتي</button>}</div>
        <table className="w-full text-xs text-slate-300"><tbody>
          {(v.stats?.by || []).map((r, i) => <tr key={i} className="border-t border-white/5"><td className="py-1">{SRC[r.source] || r.source}</td><td>{ST[r.status] || r.status}</td><td>{r.n}</td><td>ردّوا: {r.r || 0}</td></tr>)}
          {!(v.stats?.by || []).length && <tr><td className="text-slate-500 py-2">القاعدة لسه فاضية</td></tr>}
        </tbody></table>
        <p className="text-sm font-black text-white mt-3 mb-1">أرقام الاستقطاب النهارده</p>
        {Object.entries(v.stats?.lines || {}).map(([k, l]) => <p key={k} className="text-xs text-slate-300" dir="rtl">0{k}: اتبعت {l.today} من {l.cap} (من {l.first})</p>)}
        {!Object.keys(v.stats?.lines || {}).length && <p className="text-xs text-slate-500">لسه محدش بعت — اربط أرقام الاستقطاب في مركز الواتساب.</p>}
      </div>
      {canEdit && (
        <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-3 flex gap-2 items-center">
          <input placeholder="رقم طلب إننا ما نبعتلوش" dir="ltr" value={stop} onChange={(e) => setStop(e.target.value)} className="flex-1 bg-white/5 rounded-lg px-2 py-1.5 text-xs text-white" />
          <button disabled={busy || !stop} onClick={() => run({ op: 'optout', phone: stop }, (r) => (r.ok ? 'اتشال من الحملات للأبد ✓' : 'الرقم مش صحيح'))} className="bg-rose-600/80 rounded-lg px-3 py-1.5 text-xs font-bold text-white">لا ترسل</button>
        </div>
      )}
      {msg && <p className="text-xs text-emerald-300">{msg}</p>}
    </div>
  )
}
