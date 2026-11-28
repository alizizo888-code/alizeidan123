import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Search, Loader2, Building2, User, Upload, Download, Share2, Trash2, Eye, Receipt, FileText, Undo2, Printer, Save, Plus, Phone, MessageCircle } from 'lucide-react'
import AccessDenied from '../components/AccessDenied'
import DocReader from '../components/docs/DocReader'
import { post, errText } from '../lib/_api'
import { useTeamRole } from '../hooks/useTeamRole'
import { money, printInvoice } from '../lib/invoice'
import { printQuote, QSTATUS } from '../lib/quote'

// «العملاء والشركات» (owner, 2026-11-22): every customer's file — his data, his papers (CR, VAT certificate, national
// address… private, opened by signed links, shared by links that die), his quotes, invoices and returns, the sums,
// and from here: a new invoice or quote for him, a reprint, a return.

const ops = (op, body = {}) => post('/ops', { op, ...body })
const FIELDS = [['name', 'الاسم / اسم الشركة'], ['nameEn', 'الاسم بالإنجليزي'], ['responsible', 'الشخص المسؤول'], ['phone', 'الجوال'], ['email', 'الإيميل'], ['vatNo', 'الرقم الضريبي'], ['crNo', 'السجل التجاري'],
  ['city', 'المدينة'], ['district', 'الحي'], ['province', 'المنطقة'], ['street', 'الشارع'], ['building', 'رقم المبنى'], ['zip', 'الرمز البريدي'], ['addNo', 'الرقم الإضافي'], ['activity', 'النشاط'], ['notes', 'ملاحظات']]
const KINDS = { cr: 'السجل التجاري', vat: 'الشهادة الضريبية', address: 'العنوان الوطني', contract: 'عقد', other: 'ملف آخر' }
const toB64 = (file) => new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.onerror = bad; r.readAsDataURL(file) })
const date = (s) => (s ? new Date(s).toLocaleDateString('ar-SA-u-nu-latn', { dateStyle: 'medium' }) : '')

export default function Customers() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const [sp, setSp] = useSearchParams()
  const id = sp.get('id') || ''
  const allowed = isOwner || ['operations', 'supervisor'].includes(role)
  const canWrite = isOwner || role === 'operations'
  if (loading) return <div className="p-8 text-slate-400">جارِ التحقق…</div>
  if (!allowed) return <AccessDenied refresh={refresh} />
  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-6xl mx-auto w-full px-4 md:px-8 py-4 flex items-center gap-3">
          <button onClick={() => (id ? setSp({}) : navigate('/staff/admin'))} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center hover:bg-white/10"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1 min-w-0"><p className="text-[11px] text-emerald-400 font-bold tracking-widest">CUSTOMERS</p><h1 className="font-display text-lg font-black text-white">العملاء والشركات</h1></div>
          <button onClick={() => navigate('/staff/invoices')} className="rounded-xl bg-sky-500/20 text-sky-200 px-3 py-1.5 text-xs font-bold">🧾 الفواتير</button>
          <button onClick={() => navigate('/staff/quotes')} className="rounded-xl bg-violet-500/20 text-violet-200 px-3 py-1.5 text-xs font-bold">📄 العروض</button>
        </div>
      </header>
      <main className="max-w-6xl mx-auto w-full px-4 md:px-8 py-5">
        {id ? <Profile id={id} canWrite={canWrite} isOwner={isOwner} onGone={() => setSp({})} /> : <List canWrite={canWrite} onOpen={(x) => setSp({ id: x })} />}
      </main>
    </div>
  )
}

function List({ canWrite, onOpen }) {
  const [q, setQ] = useState(''); const [kind, setKind] = useState(''); const [rows, setRows] = useState(null); const [adding, setAdding] = useState(false)
  useEffect(() => { const t = setTimeout(() => ops('account_list', { q, kind }).then((r) => setRows(r.items || [])).catch(() => setRows([])), 250); return () => clearTimeout(t) }, [q, kind])
  const sum = (rows || []).reduce((a, r) => a + r.net, 0)
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap gap-2 items-center">
        <div className="flex-1 min-w-[220px] relative"><Search size={14} className="absolute right-3 top-2.5 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث: الاسم، الجوال، الرقم الضريبي، السجل التجاري" className="w-full rounded-xl bg-white/5 border border-white/10 pr-8 pl-3 py-2 text-sm text-white" /></div>
        {[['', 'الكل'], ['company', 'شركات'], ['person', 'أفراد']].map(([k, l]) => <button key={k} onClick={() => setKind(k)} className={`rounded-xl px-3 py-2 text-xs font-bold ${kind === k ? 'bg-emerald-500 text-black' : 'bg-white/5 text-slate-300'}`}>{l}</button>)}
        {canWrite && <button onClick={() => setAdding(true)} className="rounded-xl bg-emerald-500 text-black px-3 py-2 text-xs font-black flex items-center gap-1"><Plus size={13} />عميل / شركة جديدة</button>}
      </div>
      {adding && <Editor onDone={(p) => { setAdding(false); if (p?.client?.id) onOpen(p.client.id) }} />}
      {rows && <p className="text-[12px] text-slate-400">{rows.length} عميل · صافي الفواتير <b className="text-white">{money(sum)} ر.س</b></p>}
      {!rows ? <Loader2 className="animate-spin text-slate-500" /> : rows.length === 0 ? <p className="text-slate-500 text-sm">لسه مفيش عملاء — أول فاتورة أو عرض سعر بيعمل ملف العميل لوحده.</p> : (
        <div className="grid sm:grid-cols-2 gap-2">
          {rows.map((r) => (
            <button key={r.id} onClick={() => onOpen(r.id)} className="text-right rounded-2xl border border-white/10 bg-[#121826] hover:bg-[#172033] p-3 flex gap-3 items-start">
              <span className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${r.kind === 'company' ? 'bg-sky-500/15 text-sky-300' : 'bg-white/5 text-slate-300'}`}>{r.kind === 'company' ? <Building2 size={18} /> : <User size={18} />}</span>
              <span className="flex-1 min-w-0"><b className="text-white text-sm block truncate">{r.name}</b>
                <span className="text-[11px] text-slate-400 block" dir="ltr">{[r.phone, r.vatNo && 'VAT ' + r.vatNo, r.crNo && 'CR ' + r.crNo].filter(Boolean).join(' · ')}</span>
                <span className="text-[11px] text-slate-400 block">{r.invoices} فاتورة · {r.quotes} عرض · {r.files} ملف{r.last ? ' · آخر فاتورة ' + date(r.last) : ''}</span></span>
              <b className="text-emerald-300 text-sm shrink-0">{money(r.net)}</b>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function Editor({ client, onDone }) {
  const [f, setF] = useState(() => ({ ...Object.fromEntries(FIELDS.map(([k]) => [k, ''])), kind: 'company', ...(client || {}) }))
  const [fileIds, setFileIds] = useState([]); const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  const save = async () => { setBusy(true); setErr(''); try { onDone(await ops('account_save', { account: { ...f, fileIds } })) } catch (e) { setErr(errText(e, 'ما اتحفظش')) } finally { setBusy(false) } }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  return (
    <div className="rounded-2xl bg-[#121826] border border-emerald-400/30 p-3 grid gap-2">
      <DocReader accountId={client?.id || ''} onFill={(x, files) => { setF((s) => ({ ...s, ...Object.fromEntries(Object.entries(x).filter(([k, v]) => k in s && v && !s[k])) })); setFileIds((a) => [...a, ...(files || []).map((y) => y.id)]) }} />
      <div className="flex gap-2">{[['company', 'شركة / مؤسسة'], ['person', 'فرد']].map(([k, l]) => <button key={k} type="button" onClick={() => setF({ ...f, kind: k })} className={`rounded-xl px-3 py-1.5 text-xs font-bold ${f.kind === k ? 'bg-emerald-500 text-black' : 'bg-white/5 text-slate-300'}`}>{l}</button>)}</div>
      <div className="grid sm:grid-cols-3 gap-2">{FIELDS.map(([k, l]) => (k === 'notes' ? <textarea key={k} value={f[k] || ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} placeholder={l} rows={2} className={inp + ' sm:col-span-3'} /> : <input key={k} value={f[k] || ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} placeholder={l} dir={['phone', 'email', 'vatNo', 'crNo', 'zip'].includes(k) ? 'ltr' : undefined} className={inp} />))}</div>
      {err && <p className="text-rose-300 text-sm">{err}</p>}
      <div className="flex gap-2"><button disabled={busy} onClick={save} className="rounded-xl bg-emerald-500 text-black font-black px-4 py-2 text-sm flex items-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}حفظ</button><button onClick={() => onDone(null)} className="rounded-xl bg-white/10 px-4 py-2 text-sm">إلغاء</button></div>
    </div>
  )
}

function Profile({ id, canWrite, isOwner, onGone }) {
  const navigate = useNavigate()
  const [p, setP] = useState(null); const [err, setErr] = useState(''); const [edit, setEdit] = useState(false); const [tab, setTab] = useState('invoices')
  const [up, setUp] = useState(false); const [kind, setKind] = useState('cr'); const inp = useRef(null)
  const [ret, setRet] = useState(null)
  const load = () => ops('account_get', { id }).then(setP).catch((e) => setErr(errText(e, 'تعذر التحميل')))
  useEffect(() => { load() }, [id])
  if (err) return <p className="text-rose-300">{err}</p>
  if (!p) return <Loader2 className="animate-spin text-slate-500" />
  const c = p.client, S = p.stats
  const upload = async (files) => {
    setUp(true)
    for (const f of Array.from(files || []).slice(0, 5)) {
      if (f.size > 6 * 1024 * 1024) { alert(f.name + ': أكبر من 6 ميجا'); continue }
      try { await ops('account_upload', { id, data: await toB64(f), mime: f.type || 'application/pdf', name: f.name.replace(/\.\w+$/, ''), kind }) } catch (e) { alert(errText(e, 'ما اترفعش')) }
    }
    setUp(false); if (inp.current) inp.current.value = ''; load()
  }
  const share = async (f) => {
    const days = prompt('الرابط يفضل شغال كام يوم؟ (1 لـ 30)', '7'); if (days === null) return
    try {
      const r = await ops('account_share', { fileId: f.id, days })
      const text = `${f.kindLabel} — ${c.name}\n${r.url}\n(الرابط شغال ${r.days} يوم)`
      if (navigator.share) { try { await navigator.share({ title: f.name, text }); return } catch { /* fall back */ } }
      await navigator.clipboard?.writeText(text).catch(() => {})
      window.open('https://wa.me/' + (c.phone ? '966' + c.phone.replace(/^0/, '') : '') + '?text=' + encodeURIComponent(text), '_blank')
    } catch (e) { alert(errText(e, 'تعذر')) }
  }
  const del = async (f) => { if (!confirm(`تمسح «${f.name}» نهائيًا؟`)) return; try { await ops('account_file_del', { id, fileId: f.id }); load() } catch (e) { alert(errText(e, 'تعذر')) } }
  const openInv = async (iid) => { try { await printInvoice(await ops('invoice_get', { id: iid })) } catch (e) { alert(errText(e, 'تعذر')) } }
  const openQ = async (qid) => { try { printQuote(await ops('quote_get', { id: qid })) } catch (e) { alert(errText(e, 'تعذر')) } }
  const card = (l, v, cls = 'text-white') => <div className="rounded-xl bg-white/5 p-2.5"><p className="text-[11px] text-slate-400">{l}</p><b className={`text-base ${cls}`}>{v}</b></div>
  return (
    <div className="grid gap-3">
      <section className="rounded-2xl bg-[#121826] border border-white/10 p-4 grid gap-3">
        <div className="flex flex-wrap items-start gap-3">
          <span className={`w-12 h-12 rounded-2xl flex items-center justify-center ${c.kind === 'company' ? 'bg-sky-500/15 text-sky-300' : 'bg-white/5 text-slate-300'}`}>{c.kind === 'company' ? <Building2 size={22} /> : <User size={22} />}</span>
          <div className="flex-1 min-w-[200px]"><h2 className="text-white font-black text-lg">{c.name}</h2>{c.nameEn && <p className="text-slate-400 text-xs" dir="ltr">{c.nameEn}</p>}
            <p className="text-[12px] text-slate-300 mt-1">{[c.responsible && 'المسؤول: ' + c.responsible, c.vatNo && 'الرقم الضريبي: ' + c.vatNo, c.crNo && 'السجل: ' + c.crNo].filter(Boolean).join(' · ')}</p>
            <p className="text-[12px] text-slate-400">{[c.building, c.street, c.district, c.city, c.zip, c.addNo && 'إضافي ' + c.addNo].filter(Boolean).join('، ')}</p></div>
          <div className="flex flex-wrap gap-1.5">
            {c.phone && <a href={'tel:' + c.phone} className="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold flex items-center gap-1"><Phone size={13} />{c.phone}</a>}
            {c.phone && <a href={'https://wa.me/966' + c.phone.replace(/^0/, '')} target="_blank" rel="noreferrer" className="rounded-xl bg-green-600 px-3 py-2 text-xs font-bold flex items-center gap-1"><MessageCircle size={13} />واتساب</a>}
          </div>
        </div>
        {canWrite && <div className="flex flex-wrap gap-2">
          <button onClick={() => navigate('/staff/invoices?client=' + id)} className="rounded-xl bg-sky-500 text-black px-4 py-2 text-sm font-black flex items-center gap-1"><Receipt size={15} />فاتورة جديدة له</button>
          <button onClick={() => navigate('/staff/quotes?client=' + id)} className="rounded-xl bg-violet-500 text-white px-4 py-2 text-sm font-black flex items-center gap-1"><FileText size={15} />عرض سعر جديد له</button>
          <button onClick={() => setEdit((x) => !x)} className="rounded-xl bg-white/10 px-4 py-2 text-sm font-bold">✏️ تعديل البيانات</button>
          {isOwner && S.invoices === 0 && p.credits.length === 0 && <button onClick={async () => { if (confirm('تمسح العميل وملفاته؟')) { try { await ops('account_del', { id }); onGone() } catch (e) { alert(errText(e, 'تعذر')) } } }} className="rounded-xl bg-rose-500/15 text-rose-200 px-3 py-2 text-xs font-bold">مسح العميل</button>}
        </div>}
        {edit && <Editor client={c} onDone={(x) => { setEdit(false); if (x) setP(x) }} />}
        <div className="grid grid-cols-2 sm:grid-cols-6 gap-2">
          {card('الفواتير', S.invoices)}{card('إجمالي الفواتير', money(S.invoiced) + ' ر.س', 'text-sky-300')}{card('الضريبة', money(S.vat))}{card('المرتجعات', money(S.returned), 'text-rose-300')}{card('الصافي', money(S.net) + ' ر.س', 'text-emerald-300')}{card('عروض الأسعار', `${S.quotes} (${S.accepted} مقبول)`)}
        </div>
        {S.first && <p className="text-[12px] text-slate-400">بيتعامل معانا من {date(S.first)} · آخر فاتورة {date(S.last)}{p.top.length ? ' · أكتر حاجة بياخدها: ' + p.top.slice(0, 3).map((t) => `${t.name} (${t.qty})`).join('، ') : ''}</p>}
      </section>

      <section className="rounded-2xl bg-[#121826] border border-white/10 p-3">
        <div className="flex items-center gap-2 flex-wrap mb-2"><h3 className="text-sm font-black text-white flex-1">📁 ملفات العميل (خاصة — بتتفتح بروابط موقّعة)</h3>
          {canWrite && <><select value={kind} onChange={(e) => setKind(e.target.value)} className="rounded-lg bg-white/5 text-xs text-slate-200 px-2 py-1.5">{Object.entries(KINDS).map(([k, l]) => <option key={k} value={k} className="text-black">{l}</option>)}</select>
            <button disabled={up} onClick={() => inp.current?.click()} className="rounded-lg bg-emerald-500 text-black px-3 py-1.5 text-xs font-black flex items-center gap-1">{up ? <Loader2 size={12} className="animate-spin" /> : <Upload size={12} />}رفع ملف</button>
            <input ref={inp} type="file" accept="image/*,application/pdf" multiple hidden onChange={(e) => upload(e.target.files)} /></>}
        </div>
        {p.files.length === 0 ? <p className="text-slate-500 text-xs">مفيش ملفات — ارفع السجل التجاري والشهادة الضريبية والعنوان الوطني.</p> : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
            {p.files.map((f) => (
              <div key={f.id} className="rounded-xl bg-white/5 border border-white/10 p-2 flex gap-2 items-center">
                <a href={f.url} target="_blank" rel="noreferrer" className="w-14 h-14 rounded-lg bg-white flex items-center justify-center overflow-hidden shrink-0">{f.mime.startsWith('image/') ? <img src={f.url} alt="" className="object-cover w-full h-full" /> : <span className="text-rose-600 font-black text-xs">PDF</span>}</a>
                <div className="flex-1 min-w-0"><b className="text-white text-xs block truncate">{f.name}</b><span className="text-[10px] text-slate-400">{f.kindLabel} · {Math.round(f.size / 1024)} KB · {date(f.at)}</span>
                  <div className="flex gap-2 mt-1">
                    <a href={f.url} target="_blank" rel="noreferrer" className="text-sky-300" title="عرض"><Eye size={14} /></a>
                    <a href={f.url} download={f.name} className="text-sky-300" title="تحميل"><Download size={14} /></a>
                    {canWrite && <button onClick={() => share(f)} className="text-emerald-300" title="مشاركة برابط مؤقت"><Share2 size={14} /></button>}
                    {canWrite && <button onClick={() => del(f)} className="text-rose-300" title="مسح"><Trash2 size={14} /></button>}
                  </div></div>
              </div>
            ))}
          </div>
        )}
        {canWrite && <div className="mt-2"><DocReader accountId={id} label="ارفع مستند والذكاء يكمّل البيانات الناقصة ويحفظه في الملف" onFill={async (x) => { const fill = Object.fromEntries(Object.entries(x).filter(([k, v]) => v && !c[k] && FIELDS.some(([f]) => f === k))); if (Object.keys(fill).length) { await ops('account_save', { account: { ...c, ...fill } }).catch(() => {}) } load() }} /></div>}
      </section>

      <section className="rounded-2xl bg-[#121826] border border-white/10 p-3">
        <div className="flex gap-1.5 mb-2">{[['invoices', `الفواتير (${p.invoices.length})`], ['quotes', `عروض الأسعار (${p.quotes.length})`], ['credits', `المرتجعات (${p.credits.length})`]].map(([k, l]) => <button key={k} onClick={() => setTab(k)} className={`rounded-xl px-3 py-1.5 text-xs font-bold ${tab === k ? 'bg-sky-500 text-black' : 'bg-white/5 text-slate-300'}`}>{l}</button>)}</div>
        <div className="grid gap-1.5">
          {tab === 'invoices' && (p.invoices.length === 0 ? <p className="text-slate-500 text-xs">مفيش فواتير.</p> : p.invoices.map((r) => (
            <div key={r.id} className={`rounded-xl p-2.5 flex flex-wrap items-center gap-2 ${r.status === 'void' ? 'bg-rose-500/5 opacity-60' : 'bg-white/5'}`}>
              <span className={`rounded-lg px-2 py-0.5 text-[11px] font-black ${r.type === 'tax' ? 'bg-sky-500/20 text-sky-300' : 'bg-amber-500/20 text-amber-200'}`}>{r.type === 'tax' ? 'ضريبية' : 'عادية'} #{r.number}</span>
              <span className="flex-1 min-w-[160px] text-xs text-slate-300">{date(r.at)} · {r.items}{r.returned ? ` · مرتجع ${money(r.returned)}` : ''}{r.status === 'void' ? ' · ملغاة' : ''}</span>
              <b className="text-white text-sm">{money(r.net)} ر.س</b>
              <button onClick={() => openInv(r.id)} className="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold flex items-center gap-1"><Printer size={12} />طباعة</button>
              {canWrite && r.status !== 'void' && <button onClick={() => setRet(r.id)} className="rounded-lg bg-rose-500/15 text-rose-200 px-2.5 py-1 text-xs font-bold flex items-center gap-1"><Undo2 size={12} />مرتجع</button>}
            </div>
          )))}
          {tab === 'quotes' && (p.quotes.length === 0 ? <p className="text-slate-500 text-xs">مفيش عروض.</p> : p.quotes.map((r) => (
            <div key={r.id} className="rounded-xl bg-white/5 p-2.5 flex flex-wrap items-center gap-2">
              <span className="rounded-lg px-2 py-0.5 text-[11px] font-black bg-violet-500/20 text-violet-200">{QSTATUS[r.status]?.[0]}</span>
              <span className="flex-1 min-w-[160px] text-xs text-slate-300">{r.number} · {r.issueDate} · {r.items}{r.invoice ? ` · 🧾 فاتورة #${r.invoice.number}` : ''}</span>
              <b className="text-white text-sm">{money(r.net)} ر.س</b>
              <button onClick={() => openQ(r.id)} className="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold flex items-center gap-1"><Printer size={12} />عرض</button>
            </div>
          )))}
          {tab === 'credits' && (p.credits.length === 0 ? <p className="text-slate-500 text-xs">مفيش مرتجعات.</p> : p.credits.map((r) => (
            <div key={r.id} className={`rounded-xl p-2.5 flex flex-wrap items-center gap-2 ${r.status === 'void' ? 'bg-white/[0.02] opacity-60' : 'bg-rose-500/5'}`}>
              <span className="rounded-lg px-2 py-0.5 text-[11px] font-black bg-rose-500/20 text-rose-200">مرتجع #{r.number}</span>
              <span className="flex-1 min-w-[160px] text-xs text-slate-300">{date(r.at)} · على فاتورة #{r.ref?.number} · {r.items}{r.status === 'void' ? ' · ملغي' : ''}</span>
              <b className="text-rose-300 text-sm">−{money(r.net)} ر.س</b>
              <button onClick={() => openInv(r.id)} className="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold flex items-center gap-1"><Printer size={12} />طباعة</button>
            </div>
          )))}
        </div>
      </section>
      {ret && <ReturnDialog invoiceId={ret} onClose={(done) => { setRet(null); if (done) load() }} />}
    </div>
  )
}

/** The return of an invoice: which lines, how many (never more than is left), why — then printed. */
export function ReturnDialog({ invoiceId, onClose }) {
  const [d, setD] = useState(null); const [q, setQ] = useState({}); const [reason, setReason] = useState(''); const [stamped, setStamped] = useState(true); const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  useEffect(() => { ops('invoice_returnable', { id: invoiceId }).then(setD).catch((e) => setErr(errText(e, 'تعذر'))) }, [invoiceId])
  const go = async () => {
    const lines = Object.entries(q).map(([no, qty]) => ({ no: Number(no), qty: Number(qty) })).filter((l) => l.qty > 0)
    if (!lines.length) { setErr('اختار الكمية المرتجعة'); return }
    if (!reason.trim()) { setErr('اكتب سبب المرتجع'); return }
    setBusy(true); setErr('')
    try { const cr = await ops('invoice_credit', { ref: invoiceId, lines, reason, stamped }); await printInvoice(cr); onClose(true) } catch (e) { setErr(errText(e, 'ما اتعملش')) } finally { setBusy(false) }
  }
  return (
    <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" onClick={() => onClose(false)}>
      <div className="rounded-3xl bg-[#121826] border border-white/10 p-4 w-full max-w-lg grid gap-3" onClick={(e) => e.stopPropagation()}>
        <h3 className="text-white font-black">↩️ مرتجع {d ? `على ${d.invoice.type === 'tax' ? 'الفاتورة الضريبية' : 'الفاتورة'} #${d.invoice.number}` : ''}</h3>
        {!d ? <Loader2 className="animate-spin text-slate-500" /> : <>
          <p className="text-[11px] text-slate-400">{d.invoice.type === 'tax' ? 'هيطلع «إشعار دائن» برمز QR ورقم مسلسل، ومرجع الفاتورة الأصلية.' : 'هيطلع «مرتجع» برقم مسلسل ومرجع الفاتورة.'}</p>
          {d.rows.map((r) => (
            <div key={r.no} className="flex items-center gap-2 text-sm">
              <span className="flex-1 text-slate-200">{r.name} <span className="text-[11px] text-slate-500">(اتباع {r.qty}{r.returned ? ` · رجع ${r.returned}` : ''})</span></span>
              <input type="number" min="0" max={r.left} step="any" value={q[r.no] ?? ''} onChange={(e) => setQ({ ...q, [r.no]: Math.min(r.left, Math.max(0, Number(e.target.value))) })} placeholder={`حتى ${r.left}`} disabled={r.left <= 0} className="w-24 rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-white" />
              <button onClick={() => setQ({ ...q, [r.no]: r.left })} disabled={r.left <= 0} className="text-[11px] text-sky-300">الكل</button>
            </div>
          ))}
          <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="سبب المرتجع *" className="rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white" />
          <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={stamped} onChange={(e) => setStamped(e.target.checked)} />بختم المؤسسة</label>
        </>}
        {err && <p className="text-rose-300 text-sm">{err}</p>}
        <div className="flex gap-2"><button disabled={busy || !d} onClick={go} className="flex-1 rounded-2xl bg-rose-500 text-white font-black py-2.5 flex items-center justify-center gap-1">{busy ? <Loader2 size={15} className="animate-spin" /> : <Undo2 size={15} />}إصدار المرتجع</button><button onClick={() => onClose(false)} className="rounded-2xl bg-white/10 px-4">إلغاء</button></div>
      </div>
    </div>
  )
}
