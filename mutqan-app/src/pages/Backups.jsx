// «النسخ الاحتياطية» (owner 2026-11-28): a weekly copy of all the system data, the last 4 kept (a month to go back),
// plus «نسخة الآن», restore (a safety copy is taken first), download (export) and upload (import).
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, DatabaseBackup, Download, Upload, RotateCcw, Trash2, Loader2, Plus, ShieldCheck } from 'lucide-react'
import { post } from '../lib/_api'
import { useTeamRole } from '../hooks/useTeamRole'
import AccessDenied from '../components/AccessDenied'

const KIND = { auto: ['أسبوعية تلقائية', 'bg-emerald-500/20 text-emerald-200'], manual: ['يدوية', 'bg-sky-500/20 text-sky-200'], safety: ['أمان قبل استرجاع', 'bg-amber-500/20 text-amber-200'], import: ['مستوردة', 'bg-violet-500/20 text-violet-200'] }
const when = (iso) => { try { return new Date(iso).toLocaleString('ar-SA', { dateStyle: 'medium', timeStyle: 'short' }) } catch { return iso } }
const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' ميجا' : Math.max(1, Math.round(b / 1024)) + ' كيلو')

export default function Backups() {
  const navigate = useNavigate()
  const { isOwner, loading } = useTeamRole()
  const [data, setData] = useState(null)
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState(''); const [err, setErr] = useState('')
  const file = useRef(null)

  const load = () => post('/backup', { op: 'list' }).then(setData).catch((e) => setErr(e?.message || 'تعذر تحميل النسخ'))
  useEffect(() => { if (isOwner) load() }, [isOwner]) // eslint-disable-line
  const run = async (key, body, done) => {
    setBusy(key); setErr(''); setMsg('')
    try { const r = await post('/backup', body); if (r.items) setData((d) => ({ ...(d || {}), items: r.items })); setMsg(done(r)) }
    catch (e) { setErr(e?.message || 'تعذر التنفيذ') } finally { setBusy('') }
  }
  const restore = (it) => {
    const w = window.prompt(`استرجاع نسخة ${when(it.at)} هيرجّع كل البيانات (الطلبات، العملاء، الفنيين، الحسابات، الإعدادات) لحالتها وقت النسخة.\nهناخد نسخة أمان من البيانات الحالية قبلها.\n\nاكتب «استرجاع» للتأكيد:`)
    if (w !== 'استرجاع') return
    run('r' + it.name, { op: 'restore', name: it.name, confirm: 'استرجاع' }, (r) => `تم الاسترجاع ✅ — ونسخة الأمان محفوظة باسم ${r.safety}. حدّث التطبيقات المفتوحة.`)
  }
  const download = async (it) => {
    setBusy('d' + it.name); setErr('')
    try { const r = await post('/backup', { op: 'link', name: it.name }); window.location.href = r.url } catch (e) { setErr(e?.message || 'تعذر التحميل') } finally { setBusy('') }
  }
  const upload = (f) => {
    if (!f) return
    const rd = new FileReader()
    rd.onload = () => run('import', { op: 'import', file: String(rd.result) }, () => 'تم رفع النسخة ✅ — تقدر تسترجعها من القايمة.')
    rd.readAsDataURL(f)
  }

  if (loading) return <div className="h-full flex items-center justify-center text-slate-500 text-sm" dir="rtl">جارِ التحقق…</div>
  if (!isOwner) return <AccessDenied need="المالك" />
  const items = data?.items || []
  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-[#090c14] text-white p-3 pb-24" dir="rtl">
      <div className="flex items-center gap-2 mb-3">
        <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <h1 className="flex-1 font-black flex items-center gap-2"><DatabaseBackup size={18} /> النسخ الاحتياطية</h1>
      </div>
      <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-3 text-[12px] leading-6 text-slate-300">
        <p className="font-black text-emerald-200 flex items-center gap-1"><ShieldCheck size={14} /> نسخة تلقائية كل أسبوع، والنظام بيحتفظ بآخر 4 نسخ (يعني تقدر ترجع لحد شهر).</p>
        <p>النسخة فيها كل بيانات النظام: الطلبات، العملاء، الفنيين، الحسابات والعهد، الأسعار، الإعدادات والمستخدمين. ومحفوظة في مكان مقفول على السيرفر.</p>
        {data?.next ? <p>النسخة الأسبوعية الجاية: <b>{when(new Date(data.next * 1000).toISOString())}</b></p> : null}
      </div>
      <div className="grid grid-cols-2 gap-2 mt-3">
        <button disabled={!!busy} onClick={() => run('create', { op: 'create' }, () => 'اتعملت نسخة جديدة ✅')} className="rounded-xl bg-primary text-black font-black py-3 text-sm flex items-center justify-center gap-1 disabled:opacity-50">
          {busy === 'create' ? <Loader2 size={15} className="animate-spin" /> : <Plus size={15} />} نسخة الآن</button>
        <button disabled={!!busy} onClick={() => file.current?.click()} className="rounded-xl bg-white/10 border border-white/15 font-black py-3 text-sm flex items-center justify-center gap-1 disabled:opacity-50">
          {busy === 'import' ? <Loader2 size={15} className="animate-spin" /> : <Upload size={15} />} استيراد نسخة</button>
        <input ref={file} type="file" accept=".gz,application/gzip" className="hidden" onChange={(e) => { upload(e.target.files?.[0]); e.target.value = '' }} />
      </div>
      {msg && <p className="mt-3 text-sm text-emerald-300 font-bold">{msg}</p>}
      {err && <p className="mt-3 text-sm text-rose-300">{err}</p>}
      <div className="mt-4 grid gap-2">
        {!data && !err && <p className="text-sm text-slate-500 text-center py-6">جارِ التحميل…</p>}
        {data && items.length === 0 && <p className="text-sm text-slate-500 text-center py-6">لسه مفيش نسخ — اضغط «نسخة الآن» أو استنى النسخة الأسبوعية.</p>}
        {items.map((it) => {
          const k = KIND[it.kind] || KIND.manual
          return (
            <div key={it.name} className="rounded-2xl border border-white/10 bg-white/[0.04] p-3">
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-black ${k[1]}`}>{k[0]}</span>
                <span className="text-sm font-black flex-1">{when(it.at)}</span>
                <span className="text-[11px] text-slate-400">{size(it.size)}</span>
              </div>
              <p className="text-[11px] text-slate-400 mt-1">{it.rows?.docs ?? '—'} سجل · {it.rows?.users ?? '—'} مستخدم{it.by ? ` · ${it.by}` : ''}{it.note ? ` · ${it.note}` : ''}</p>
              <div className="grid grid-cols-3 gap-2 mt-2">
                <button disabled={!!busy} onClick={() => restore(it)} className="rounded-lg bg-amber-500 text-black text-[11px] font-black py-2 flex items-center justify-center gap-1 disabled:opacity-50">{busy === 'r' + it.name ? <Loader2 size={12} className="animate-spin" /> : <RotateCcw size={12} />} استرجاع</button>
                <button disabled={!!busy} onClick={() => download(it)} className="rounded-lg bg-white/10 text-[11px] font-black py-2 flex items-center justify-center gap-1 disabled:opacity-50"><Download size={12} /> تصدير</button>
                <button disabled={!!busy} onClick={() => { if (window.confirm('حذف النسخة دي نهائيًا؟')) run('x' + it.name, { op: 'delete', name: it.name }, () => 'اتحذفت النسخة.') }} className="rounded-lg bg-rose-500/20 text-rose-200 text-[11px] font-black py-2 flex items-center justify-center gap-1 disabled:opacity-50"><Trash2 size={12} /> حذف</button>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
