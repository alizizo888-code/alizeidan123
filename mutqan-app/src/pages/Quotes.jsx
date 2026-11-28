import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Plus, Trash2, Printer, Save, Loader2, Search, Copy, Settings2, FileText, Sparkles, BookmarkPlus, CheckCircle2, Send, XCircle, Receipt } from 'lucide-react'
import AccessDenied from '../components/AccessDenied'
import DocReader from '../components/docs/DocReader'
import ImagePick from '../components/docs/ImagePick'
import ClientPicker from '../components/docs/ClientPicker'
import CatalogPicker from '../components/docs/CatalogPicker'
import { post, errText } from '../lib/_api'
import { useTeamRole } from '../hooks/useTeamRole'
import { calcInvoice, money, arAmount } from '../lib/invoice'
import { printInvoice } from '../lib/invoice'
import { quoteHtml, quoteAssets, printQuote, QSTATUS } from '../lib/quote'

// «عروض الأسعار» (owner, 2026-11-22): his template, draft until approved, the AI writes the wording from what he
// explains (never a price he did not give), saved wordings («صيغ»), the customer read from his CR / VAT / address
// documents, and an approved quote turned into a tax invoice.

const ops = (op, body = {}) => post('/ops', { op, ...body })
const blankItem = () => ({ name: '', specs: '', unit: 'عدد', qty: 1, price: '' })
const blankClient = { name: '', responsible: '', phone: '', email: '', vatNo: '', crNo: '', city: '', district: '', street: '', building: '', zip: '', addNo: '' }
const blank = (cfg) => ({ id: '', clientId: '', fileIds: [], client: { ...blankClient }, remark: '', intro: '', items: [blankItem()], terms: [...(cfg?.terms || [])], extraNote: '', showBanks: true, stamped: false, signed: false,
  staff: { name: '', title: cfg?.staffTitle?.ar || 'أخصائي المبيعات', titleEn: cfg?.staffTitle?.en || 'Sales man' }, issueDate: '', validTill: '', inclusive: cfg?.inclusive ?? false })
const fromSaved = (q, cfg, copy = false) => ({ ...blank(cfg), id: copy ? '' : q.id, clientId: q.clientId || '', branch: q.branch || '', client: { ...blankClient, ...q.client }, remark: q.remark || '', intro: q.intro || '', terms: q.terms || [], extraNote: q.extraNote || '',
  showBanks: q.showBanks !== false, stamped: !!q.stamped, signed: !!q.signed, staff: q.staff || blank(cfg).staff, issueDate: copy ? '' : q.issueDate, validTill: copy ? '' : q.validTill, inclusive: !!q.inclusive,
  items: (q.rows || []).map((r) => ({ name: r.name, specs: r.specs || '', unit: r.unit, qty: r.qty, price: q.inclusive ? r.price : r.qty ? r.afterDiscount / r.qty : r.price })) })

export default function Quotes() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const [sp] = useSearchParams()
  const [tab, setTab] = useState(sp.get('client') || sp.get('new') ? 'new' : 'list'); const [cfg, setCfg] = useState(null); const [draft, setDraft] = useState(null)
  const allowed = isOwner || ['operations', 'supervisor'].includes(role)
  const canWrite = isOwner || role === 'operations'
  useEffect(() => { if (allowed) ops('quote_cfg').then(setCfg).catch(() => {}) }, [allowed])
  if (loading) return <div className="p-8 text-slate-400">جارِ التحقق…</div>
  if (!allowed) return <AccessDenied refresh={refresh} />
  const tabs = [['list', 'سجل العروض', FileText], ...(canWrite ? [['new', draft?.id ? 'تعديل العرض' : 'عرض سعر جديد', Plus], ['snips', 'الصيغ المحفوظة', BookmarkPlus]] : []), ...(isOwner ? [['settings', 'إعدادات العروض', Settings2]] : [])]
  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-6xl mx-auto w-full px-4 md:px-8 py-4 flex items-center gap-3">
          <button onClick={() => navigate('/staff/operations')} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center hover:bg-white/10"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1 min-w-0"><p className="text-[11px] text-violet-400 font-bold tracking-widest">QUOTATIONS</p><h1 className="font-display text-lg font-black text-white">عروض الأسعار</h1></div>
          <button onClick={() => navigate('/staff/customers')} className="rounded-xl bg-emerald-500/15 text-emerald-200 px-3 py-1.5 text-xs font-bold">👥 العملاء</button>
          <button onClick={() => navigate('/staff/invoices')} className="rounded-xl bg-white/5 text-slate-300 px-3 py-1.5 text-xs font-bold">🧾 الفواتير</button>
        </div>
        <div className="max-w-6xl mx-auto w-full px-4 md:px-8 pb-3 flex gap-1.5 overflow-x-auto">
          {tabs.map(([id, label, Icon]) => <button key={id} onClick={() => { if (id === 'new' && tab !== 'new') setDraft(null); setTab(id) }} className={`shrink-0 rounded-xl px-3 py-1.5 text-xs font-bold flex items-center gap-1 ${tab === id ? 'bg-violet-500 text-white' : 'bg-white/5 text-slate-300 hover:bg-white/10'}`}><Icon size={13} />{label}</button>)}
        </div>
      </header>
      <main className="max-w-6xl mx-auto w-full px-4 md:px-8 py-5">
        {!cfg ? <p className="text-slate-400 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />تحميل…</p>
          : tab === 'list' ? <Register canWrite={canWrite} isOwner={isOwner} onEdit={(q, copy) => { setDraft(fromSaved(q, cfg, copy)); setTab('new') }} />
          : tab === 'new' ? <Editor key={draft?.id || draft?.client?.name || 'new'} cfg={cfg} seed={draft} clientId={sp.get('client') || ''} onSaved={() => {}} />
          : tab === 'snips' ? <Snippets kinds={cfg.kinds} />
          : <SettingsTab cfg={cfg} onSaved={(c) => setCfg((x) => ({ ...x, ...c }))} />}
      </main>
    </div>
  )
}

/* ------------------------------------------------------------------ register */

function Register({ canWrite, isOwner, onEdit }) {
  const [q, setQ] = useState(''); const [st, setSt] = useState(''); const [rows, setRows] = useState(null); const [busy, setBusy] = useState('')
  const load = () => ops('quote_list', { q, status: st }).then((r) => setRows(r.items || [])).catch(() => setRows([]))
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [q, st])
  const act = async (id, fn) => { setBusy(id); try { await fn() } catch (e) { alert(errText(e, 'ما اتنفذش')) } finally { setBusy(''); load() } }
  const show = (id) => act(id, async () => printQuote(await ops('quote_get', { id })))
  const status = (id, to) => act(id, () => ops('quote_status', { id, to }))
  const toInvoice = (r) => act(r.id, async () => {
    const stamped = confirm('هتطلع فاتورة ضريبية بنفس العميل والأصناف.\nتلصق ختم المؤسسة؟ (موافق = بالختم، إلغاء = بدون)')
    const inv = await ops('quote_invoice', { id: r.id, type: 'tax', stamped }); await printInvoice(inv)
  })
  const colors = { draft: 'bg-slate-500/20 text-slate-300', approved: 'bg-sky-500/20 text-sky-300', sent: 'bg-violet-500/20 text-violet-300', accepted: 'bg-emerald-500/20 text-emerald-300', rejected: 'bg-rose-500/20 text-rose-300' }
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap gap-2 items-center">
        <div className="flex-1 min-w-[200px] relative"><Search size={14} className="absolute right-3 top-2.5 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث: رقم العرض، العميل، الجوال" className="w-full rounded-xl bg-white/5 border border-white/10 pr-8 pl-3 py-2 text-sm text-white" /></div>
        {[['', 'الكل'], ...Object.entries(QSTATUS).map(([k, v]) => [k, v[0]])].map(([k, l]) => <button key={k} onClick={() => setSt(k)} className={`rounded-xl px-3 py-2 text-xs font-bold ${st === k ? 'bg-violet-500 text-white' : 'bg-white/5 text-slate-300'}`}>{l}</button>)}
      </div>
      {!rows ? <Loader2 className="animate-spin text-slate-500" /> : rows.length === 0 ? <p className="text-slate-500 text-sm">لسه مفيش عروض.</p> : rows.map((r) => (
        <div key={r.id} className="rounded-2xl border border-white/10 bg-[#121826] p-3 flex flex-wrap items-center gap-2">
          <span className={`rounded-lg px-2 py-1 text-[11px] font-black ${colors[r.status]}`}>{QSTATUS[r.status]?.[0]}{r.expired ? ' · منتهي' : ''}</span>
          <div className="flex-1 min-w-[180px]"><p className="text-white font-bold text-sm">{r.client}</p><p className="text-[11px] text-slate-400">{r.number} · {r.issueDate} → {r.validTill}{r.staff ? ' · ' + r.staff : ''}{r.invoice ? ` · 🧾 فاتورة #${r.invoice.number}` : ''}</p></div>
          <b className="text-white text-sm">{money(r.net)} ر.س</b>
          <button disabled={busy === r.id} onClick={() => show(r.id)} className="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Printer size={13} />عرض / PDF</button>
          {canWrite && <>
            {r.status !== 'accepted' && <button onClick={async () => onEdit(await ops('quote_get', { id: r.id }), false)} className="rounded-xl bg-white/10 px-3 py-1.5 text-xs font-bold">تعديل</button>}
            <button onClick={async () => onEdit(await ops('quote_get', { id: r.id }), true)} className="rounded-xl bg-white/10 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Copy size={12} />نسخة</button>
            {r.status === 'draft' && <button onClick={() => status(r.id, 'approved')} className="rounded-xl bg-sky-500 text-black px-3 py-1.5 text-xs font-black flex items-center gap-1"><CheckCircle2 size={13} />اعتماد</button>}
            {r.status === 'approved' && <button onClick={() => status(r.id, 'sent')} className="rounded-xl bg-violet-500/30 text-violet-100 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Send size={12} />اتبعت للعميل</button>}
            {['approved', 'sent'].includes(r.status) && !r.invoice && <button onClick={() => toInvoice(r)} className="rounded-xl bg-amber-400 text-black px-3 py-1.5 text-xs font-black flex items-center gap-1"><Receipt size={13} />العميل وافق ← فاتورة</button>}
            {['approved', 'sent', 'draft'].includes(r.status) && <button onClick={() => status(r.id, 'rejected')} className="rounded-xl bg-rose-500/15 text-rose-200 px-2 py-1.5 text-xs font-bold flex items-center gap-1"><XCircle size={12} />رفض</button>}
          </>}
        </div>
      ))}
    </div>
  )
}

/* ------------------------------------------------------------------ editor + AI */

function Editor({ cfg, seed, clientId, onSaved }) {
  const [f, setF] = useState(() => seed || blank(cfg))
  const fillClient = (c) => setF((x) => ({ ...x, clientId: c.id || '', client: { ...x.client, ...Object.fromEntries(Object.keys(x.client).map((k) => [k, c[k] ?? x.client[k]])) } }))
  useEffect(() => { if (clientId && !seed) ops('account_get', { id: clientId }).then((p) => fillClient(p.client)).catch(() => {}) }, [clientId])
  const [busy, setBusy] = useState(false); const [err, setErr] = useState(''); const [saved, setSaved] = useState(null)
  const [brief, setBrief] = useState(''); const [ai, setAi] = useState(null); const [aiBusy, setAiBusy] = useState(false)
  const [snips, setSnips] = useState([])
  useEffect(() => { ops('quote_snippets').then((r) => setSnips(r.items || [])).catch(() => {}) }, [])
  const set = (patch) => setF((x) => ({ ...x, ...patch }))
  const setCl = (k, v) => setF((x) => ({ ...x, client: { ...x.client, [k]: v } }))
  const setIt = (i, patch) => setF((x) => ({ ...x, items: x.items.map((it, j) => (j === i ? { ...it, ...patch } : it)) }))
  const live = useMemo(() => calcInvoice(f.items, true, cfg.vatRate, f.inclusive), [f.items, cfg.vatRate, f.inclusive])
  const preview = useMemo(() => ({ ...f, number: saved?.number || '—', status: saved?.status || 'draft', company: (() => { const b = (cfg.branches || []).find((x) => x.id === (f.branch || cfg.branch)); return b ? { ...cfg.company, cityLine: b.cityLine, address: b.address || cfg.company.address, phones: b.phones?.length ? b.phones : cfg.company.phones, branchId: b.id, branchFooter: b.footer } : cfg.company })(), issueDate: f.issueDate || new Date().toISOString().slice(0, 10),
    validTill: f.validTill || new Date(Date.now() + cfg.validDays * 864e5).toISOString().slice(0, 10), rows: live.rows.map((r, i) => ({ ...r, specs: f.items.filter((x) => String(x.name).trim())[i]?.specs || '' })), totals: live.totals }), [f, live, cfg, saved])
  const write = async () => {
    setAiBusy(true); setErr('')
    try { setAi(await ops('quote_ai', { brief, items: f.items.filter((x) => String(x.name).trim()), client: f.client })) } catch (e) { setErr(errText(e, 'الذكاء الاصطناعي مش متاح دلوقتي')) } finally { setAiBusy(false) }
  }
  const applyAi = (what) => {
    if (!ai) return
    if (what === 'items' || what === 'all') setF((x) => ({ ...x, items: ai.items.length ? ai.items.map((it) => ({ ...blankItem(), ...it })) : x.items }))
    if (what === 'terms' || what === 'all') setF((x) => ({ ...x, terms: ai.terms.length ? ai.terms : x.terms, extraNote: ai.extraNote || x.extraNote }))
    if (what === 'intro' || what === 'all') setF((x) => ({ ...x, intro: ai.intro || x.intro }))
  }
  const saveSnip = async (kind, text) => {
    const title = prompt('اسم الصيغة (عشان تلاقيها بعدين):', text.slice(0, 30)); if (title === null) return
    try { const s = await ops('quote_snippet_save', { kind, title, text }); setSnips((x) => [...x, s]) } catch (e) { alert(errText(e, 'ما اتحفظتش')) }
  }
  const save = async () => {
    if (!f.client.name.trim()) { setErr('اكتب اسم العميل'); return }
    if (!live.rows.length) { setErr('ضيف صنف واحد على الأقل'); return }
    if (live.rows.some((r) => r.price <= 0)) { setErr('فيه صنف من غير سعر'); return }
    setBusy(true); setErr('')
    try { const q = await ops('quote_save', { quote: { ...f, items: f.items.filter((x) => String(x.name).trim()) } }); setSaved(q); setF((x) => ({ ...x, id: q.id })); onSaved(q); printQuote(q) } catch (e) { setErr(errText(e, 'ما اتحفظش')) } finally { setBusy(false) }
  }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  const kindsOf = (k) => snips.filter((s) => s.kind === k || (k === 'terms' && ['terms', 'payment', 'warranty', 'duration', 'scope', 'note'].includes(s.kind)))
  return (
    <div className="grid lg:grid-cols-[1fr_460px] gap-4 items-start">
      <div className="grid gap-3">
        <Box title="🤖 اشرح المطلوب والذكاء الاصطناعي يكتب العرض">
          <textarea value={brief} onChange={(e) => setBrief(e.target.value)} rows={4} placeholder="مثال: عميل عايز طلمبة حريق 750 جالون 10 بار كهربا 380 فولت بسعر 52 ألف، التوريد والتركيب والتشغيل، الدفع نص مقدم ونص قبل التوريد، ضمان 3 شهور" className={inp} />
          <div className="flex flex-wrap gap-2 mt-2">
            <button disabled={aiBusy} onClick={write} className="rounded-xl bg-violet-500 text-white font-black px-4 py-2 text-sm flex items-center gap-1.5">{aiBusy ? <Loader2 size={15} className="animate-spin" /> : <Sparkles size={15} />}{aiBusy ? 'بيكتب…' : 'اكتب الصيغة'}</button>
            <span className="text-[11px] text-slate-500 self-center">بيكتب أسماء الأصناف والمواصفات والشروط — الأسعار بتاعتك، مش بيحط سعر من عنده.</span>
          </div>
          {ai && (
            <div className="mt-3 rounded-xl bg-violet-500/10 border border-violet-400/30 p-3 grid gap-2 text-sm text-slate-100">
              {ai.intro && <p><b className="text-violet-300">المقدمة:</b> {ai.intro}</p>}
              {ai.items.map((it, i) => <div key={i} className="rounded-lg bg-black/20 p-2"><b>{it.name}</b> × {it.qty} {it.price ? `— ${money(it.price)} ر.س` : <span className="text-amber-300">— من غير سعر</span>}<p className="text-[12px] text-slate-300 whitespace-pre-line">{it.specs}</p></div>)}
              {ai.terms.length > 0 && <ul className="text-[12px] list-disc pr-5">{ai.terms.map((t, i) => <li key={i}>{t}</li>)}</ul>}
              {ai.extraNote && <p className="text-[12px]">** {ai.extraNote}</p>}
              {ai.warnings.length > 0 && <p className="text-amber-300 text-[12px]">⚠️ {ai.warnings.join(' · ')}</p>}
              <div className="flex flex-wrap gap-1.5">
                <button onClick={() => applyAi('all')} className="rounded-lg bg-violet-500 text-white px-3 py-1.5 text-xs font-black flex items-center gap-1"><Sparkles size={12} />اعتمد الصيغة كلها</button>
                <button onClick={() => applyAi('items')} className="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold">الأصناف بس</button>
                <button onClick={() => applyAi('terms')} className="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold">الشروط بس</button>
                <button onClick={() => setAi(null)} className="rounded-lg bg-white/5 px-3 py-1.5 text-xs">تجاهل</button>
              </div>
            </div>
          )}
        </Box>
        <Box title="العميل">
          <div className="grid gap-2"><ClientPicker clientId={f.clientId} onPick={fillClient} onClear={() => set({ clientId: '' })} />
          <DocReader accountId={f.clientId || ''} onFill={(x, files) => setF((s) => ({ ...s, fileIds: [...(s.fileIds || []), ...(files || []).map((y) => y.id)], client: { ...s.client, ...Object.fromEntries(Object.entries(x).filter(([k, v]) => k in blankClient && v)) } }))} /></div>
          <div className="grid sm:grid-cols-2 gap-2 mt-2">
            <input value={f.client.name} onChange={(e) => setCl('name', e.target.value)} placeholder="اسم العميل / الشركة *" className={inp} />
            <input value={f.client.responsible} onChange={(e) => setCl('responsible', e.target.value)} placeholder="الشخص المسؤول" className={inp} />
            <input value={f.client.phone} onChange={(e) => setCl('phone', e.target.value)} placeholder="الجوال" dir="ltr" className={inp} />
            <input value={f.client.vatNo} onChange={(e) => setCl('vatNo', e.target.value.replace(/\D/g, '').slice(0, 15))} placeholder="الرقم الضريبي" dir="ltr" className={inp} />
            <input value={f.client.crNo} onChange={(e) => setCl('crNo', e.target.value.replace(/\D/g, '').slice(0, 10))} placeholder="السجل التجاري" dir="ltr" className={inp} />
            <input value={f.client.city} onChange={(e) => setCl('city', e.target.value)} placeholder="المدينة" className={inp} />
          </div>
          <details className="mt-2"><summary className="text-xs text-violet-300 cursor-pointer">العنوان الوطني والإيميل</summary>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-2">{[['district', 'الحي'], ['street', 'الشارع'], ['building', 'رقم المبنى'], ['zip', 'الرمز البريدي'], ['addNo', 'الرقم الإضافي'], ['email', 'الإيميل']].map(([k, l]) => <input key={k} value={f.client[k]} onChange={(e) => setCl(k, e.target.value)} placeholder={l} className={inp} />)}</div>
          </details>
          <input value={f.remark} onChange={(e) => set({ remark: e.target.value })} placeholder="ملاحظات (Remark)" className={inp + ' mt-2'} />
        </Box>
        <Box title="الأصناف والمواصفات">
          <div className="mb-2"><CatalogPicker items={cfg.catalog || []} cats={cfg.cats || []} onPick={(it) => setF((x) => ({ ...x, items: [...x.items.filter((y) => String(y.name).trim()), { name: it.name, specs: it.specs || '', unit: it.unit, qty: 1, price: it.price || '' }] }))} /></div>
          {f.intro !== '' && <textarea value={f.intro} onChange={(e) => set({ intro: e.target.value })} rows={2} placeholder="مقدمة العرض" className={inp + ' mb-2'} />}
          <div className="grid gap-2">
            {f.items.map((it, i) => (
              <div key={i} className="rounded-xl bg-black/20 p-2 grid gap-1.5">
                <div className="grid grid-cols-12 gap-1.5">
                  <input value={it.name} onChange={(e) => setIt(i, { name: e.target.value })} placeholder="اسم الصنف" className={inp + ' col-span-12 sm:col-span-6'} />
                  <input value={it.unit} onChange={(e) => setIt(i, { unit: e.target.value })} placeholder="الوحدة" className={inp + ' col-span-3 sm:col-span-2 px-2'} />
                  <input value={it.qty} onChange={(e) => setIt(i, { qty: e.target.value })} type="number" min="0" placeholder="الكمية" className={inp + ' col-span-3 sm:col-span-1 px-2'} />
                  <input value={it.price} onChange={(e) => setIt(i, { price: e.target.value })} type="number" min="0" placeholder={f.inclusive ? 'السعر شامل' : 'السعر قبل الضريبة'} className={inp + ' col-span-5 sm:col-span-2 px-2'} />
                  <button onClick={() => setF((x) => ({ ...x, items: x.items.length > 1 ? x.items.filter((_, j) => j !== i) : [blankItem()] }))} className="col-span-1 text-rose-300 flex justify-center items-center"><Trash2 size={15} /></button>
                </div>
                <textarea value={it.specs} onChange={(e) => setIt(i, { specs: e.target.value })} rows={2} placeholder="المواصفات (سطر لكل مواصفة)" className={inp + ' text-xs'} />
                <div className="flex gap-1.5 flex-wrap">
                  {it.specs && <button onClick={() => saveSnip('item', `${it.name}\n${it.specs}`)} className="text-[11px] text-violet-300 flex items-center gap-1"><BookmarkPlus size={11} />احفظ المواصفات كصيغة</button>}
                  {kindsOf('item').length > 0 && <select onChange={(e) => { const s = snips.find((x) => x.id === e.target.value); if (s) { const [n, ...rest] = s.text.split('\n'); setIt(i, { name: it.name || n, specs: rest.join('\n') || s.text }) } e.target.value = '' }} className="rounded-lg bg-white/5 text-[11px] text-slate-300 px-2"><option value="">📋 من الصيغ المحفوظة…</option>{kindsOf('item').map((s) => <option key={s.id} value={s.id} className="text-black">{s.title}</option>)}</select>}
                </div>
              </div>
            ))}
            <button onClick={() => setF((x) => ({ ...x, items: [...x.items, blankItem()] }))} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة صنف</button>
            <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={f.inclusive} onChange={(e) => set({ inclusive: e.target.checked })} />الأسعار اللي كتبتها شاملة الضريبة</label>
          </div>
        </Box>
        <Box title="الشروط والأحكام">
          <div className="grid gap-1.5">
            {f.terms.map((t, i) => (
              <div key={i} className="flex gap-1.5">
                <textarea value={t} onChange={(e) => set({ terms: f.terms.map((x, j) => (j === i ? e.target.value : x)) })} rows={1} className={inp + ' text-xs'} />
                <button onClick={() => saveSnip('terms', t)} title="احفظها صيغة" className="text-violet-300 px-1"><BookmarkPlus size={14} /></button>
                <button onClick={() => set({ terms: f.terms.filter((_, j) => j !== i) })} className="text-rose-300 px-1"><Trash2 size={14} /></button>
              </div>
            ))}
            <div className="flex gap-1.5 flex-wrap">
              <button onClick={() => set({ terms: [...f.terms, ''] })} className="rounded-lg border border-dashed border-white/20 px-3 py-1.5 text-xs text-slate-300 flex items-center gap-1"><Plus size={12} />شرط جديد</button>
              {kindsOf('terms').length > 0 && <select onChange={(e) => { const s = snips.find((x) => x.id === e.target.value); if (s) set({ terms: [...f.terms, s.text] }); e.target.value = '' }} className="rounded-lg bg-white/5 text-xs text-slate-300 px-2 py-1.5"><option value="">📋 إضافة صيغة محفوظة…</option>{kindsOf('terms').map((s) => <option key={s.id} value={s.id} className="text-black">{s.title}</option>)}</select>}
            </div>
            <textarea value={f.extraNote} onChange={(e) => set({ extraNote: e.target.value })} rows={2} placeholder="** ملاحظة مهمة تظهر تحت الشروط (اختياري)" className={inp + ' text-xs'} />
          </div>
        </Box>
        <Box title="بيانات العرض">
          <div className="grid sm:grid-cols-4 gap-2 text-xs text-slate-400">
            <label className="grid gap-1">{f.staff.title}<input value={f.staff.name} onChange={(e) => set({ staff: { ...f.staff, name: e.target.value } })} placeholder="الاسم" className={inp} /></label>
            <label className="grid gap-1">المسمى<select value={f.staff.title} onChange={(e) => set({ staff: { ...f.staff, title: e.target.value, titleEn: e.target.value === 'مهندس الصيانة' ? 'Maintenance engineer' : e.target.value === 'أخصائي المبيعات' ? 'Sales man' : f.staff.titleEn } })} className={inp}>{['أخصائي المبيعات', 'مهندس الصيانة', cfg.staffTitle?.ar].filter((v, i, a) => v && a.indexOf(v) === i).map((t) => <option key={t} className="text-black">{t}</option>)}</select></label>
            {(cfg.branches || []).length > 1 && <label className="grid gap-1">الفرع<select value={f.branch || cfg.branch || ''} onChange={(e) => set({ branch: e.target.value })} className={inp}>{cfg.branches.map((b) => <option key={b.id} value={b.id} className="text-black">📍 {b.name}</option>)}</select></label>}
            <label className="grid gap-1">تاريخ الإصدار<input type="date" value={f.issueDate} onChange={(e) => set({ issueDate: e.target.value })} className={inp} /></label>
            <label className="grid gap-1">صالح حتى<input type="date" value={f.validTill} onChange={(e) => set({ validTill: e.target.value })} className={inp} /></label>
          </div>
          <div className="flex flex-wrap gap-4 mt-2 text-xs text-slate-300">
            <label className="flex items-center gap-1.5"><input type="checkbox" checked={f.showBanks} onChange={(e) => set({ showBanks: e.target.checked })} />إظهار الحسابات البنكية</label>
            <label className="flex items-center gap-1.5"><input type="checkbox" checked={f.stamped} onChange={(e) => set({ stamped: e.target.checked })} />ختم المؤسسة</label>
            {cfg.company?.signature && <label className="flex items-center gap-1.5"><input type="checkbox" checked={f.signed} onChange={(e) => set({ signed: e.target.checked })} />التوقيع</label>}
          </div>
        </Box>
        <div className="rounded-2xl bg-[#121826] border border-white/10 p-3 grid gap-1 text-sm">
          <Row k="الإجمالي بدون الضريبة" v={live.totals.afterDiscount} /><Row k={`الضريبة ${cfg.vatRate}%`} v={live.totals.vat} /><Row k="الإجمالي شامل الضريبة" v={live.totals.net} big />
          <p className="text-[12px] text-slate-400">{arAmount(live.totals.net)}</p>
        </div>
        {err && <p className="text-rose-300 text-sm">{err}</p>}
        {saved && <p className="text-emerald-300 text-sm">✅ اتحفظ {saved.number} كمسودة — اعتمده من سجل العروض عشان يبقى رسمي.</p>}
        <button disabled={busy} onClick={save} className="rounded-2xl bg-emerald-500 text-black font-black py-3 flex items-center justify-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}{f.id ? 'حفظ التعديل' : 'حفظ العرض'} (مسودة)</button>
      </div>
      <div className="lg:sticky lg:top-28 rounded-2xl overflow-hidden border border-white/10 bg-white">
        <p className="bg-slate-800 text-slate-200 text-[11px] px-3 py-1">معاينة عرض السعر</p>
        <iframe title="preview" srcDoc={quoteHtml(preview, quoteAssets(preview), { bar: false })} className="w-full" style={{ height: 700 }} />
      </div>
    </div>
  )
}

const Box = ({ title, children }) => <section className="rounded-2xl bg-[#121826] border border-white/10 p-3"><h3 className="text-xs font-black text-slate-300 mb-2">{title}</h3>{children}</section>
const Row = ({ k, v, big }) => <div className={`flex justify-between ${big ? 'text-white font-black text-base' : 'text-slate-300'}`}><span>{k}</span><span>{money(v)} ر.س</span></div>

/* ------------------------------------------------------------------ saved wordings */

function Snippets({ kinds }) {
  const [rows, setRows] = useState(null); const [f, setF] = useState({ kind: 'terms', title: '', text: '' }); const [busy, setBusy] = useState(false)
  const load = () => ops('quote_snippets').then((r) => setRows(r.items || [])).catch(() => setRows([]))
  useEffect(() => { load() }, [])
  const save = async () => { setBusy(true); try { await ops('quote_snippet_save', f); setF({ kind: f.kind, title: '', text: '' }); load() } catch (e) { alert(errText(e, 'ما اتحفظتش')) } finally { setBusy(false) } }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  return (
    <div className="grid gap-3 max-w-4xl">
      <Box title={f.id ? 'تعديل صيغة' : 'صيغة جديدة'}>
        <div className="grid sm:grid-cols-3 gap-2">
          <select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })} className={inp}>{Object.entries(kinds || {}).map(([k, l]) => <option key={k} value={k} className="text-black">{l}</option>)}</select>
          <input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="اسم الصيغة" className={inp + ' sm:col-span-2'} />
        </div>
        <textarea value={f.text} onChange={(e) => setF({ ...f, text: e.target.value })} rows={4} placeholder="نص الصيغة (للمواصفات: أول سطر اسم الصنف وبعده المواصفات)" className={inp + ' mt-2'} />
        <div className="flex gap-2 mt-2"><button disabled={busy} onClick={save} className="rounded-xl bg-violet-500 text-white font-black px-4 py-2 text-sm">{f.id ? 'حفظ التعديل' : 'حفظ الصيغة'}</button>{f.id && <button onClick={() => setF({ kind: 'terms', title: '', text: '' })} className="rounded-xl bg-white/10 px-3 text-sm">إلغاء</button>}</div>
      </Box>
      {!rows ? <Loader2 className="animate-spin text-slate-500" /> : rows.length === 0 ? <p className="text-slate-500 text-sm">لسه مفيش صيغ — احفظ أي شرط أو مواصفات من محرر العرض بزرار 🔖.</p> : rows.map((s) => (
        <div key={s.id} className="rounded-2xl bg-[#121826] border border-white/10 p-3">
          <div className="flex items-center gap-2"><span className="rounded-lg bg-violet-500/20 text-violet-200 px-2 py-0.5 text-[11px] font-bold">{kinds?.[s.kind] || s.kind}</span><b className="text-white text-sm flex-1">{s.title}</b>
            <button onClick={() => setF(s)} className="text-xs text-slate-300">تعديل</button>
            <button onClick={async () => { if (confirm('تمسح الصيغة؟')) { await ops('quote_snippet_del', { id: s.id }).catch(() => {}); load() } }} className="text-rose-300"><Trash2 size={14} /></button></div>
          <p className="text-[12px] text-slate-300 whitespace-pre-line mt-1">{s.text}</p>
        </div>
      ))}
    </div>
  )
}

/* ------------------------------------------------------------------ settings */

function SettingsTab({ cfg, onSaved }) {
  const [c, setC] = useState(() => JSON.parse(JSON.stringify(cfg))); const [busy, setBusy] = useState(false); const [msg, setMsg] = useState('')
  const set = (k, v) => setC((x) => ({ ...x, [k]: v }))
  const base = import.meta.env.BASE_URL || '/'
  const save = async () => { setBusy(true); setMsg(''); try { const r = await ops('quote_cfg_set', { cfg: c }); onSaved(r); setMsg('✅ اتحفظت') } catch (e) { setMsg('❌ ' + errText(e, 'ما اتحفظتش')) } finally { setBusy(false) } }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  return (
    <div className="grid gap-3 max-w-4xl">
      <p className="text-xs text-slate-400">اسم المؤسسة والرقم الضريبي والسجل والشعار والختم والتوقيع مشتركين مع الفواتير — بتتعدل من <b>إعدادات الفواتير</b>.</p>
      <Box title="الترقيم والصلاحية">
        <div className="grid sm:grid-cols-4 gap-2 text-xs text-slate-400">
          <label className="grid gap-1">بادئة الرقم<input value={c.prefix} onChange={(e) => set('prefix', e.target.value)} dir="ltr" className={inp} /></label>
          <label className="grid gap-1">الرقم الجاي<input type="number" value={c.next} onChange={(e) => set('next', e.target.value)} className={inp} /></label>
          <label className="grid gap-1">صلاحية العرض (يوم)<input type="number" value={c.validDays} onChange={(e) => set('validDays', e.target.value)} className={inp} /></label>
          <label className="grid gap-1">نسبة الضريبة %<input type="number" value={c.vatRate} onChange={(e) => set('vatRate', e.target.value)} className={inp} /></label>
          <label className="grid gap-1">المسمى الافتراضي<input value={c.staffTitle?.ar} onChange={(e) => set('staffTitle', { ...c.staffTitle, ar: e.target.value })} className={inp} /></label>
          <label className="grid gap-1">بالإنجليزي<input value={c.staffTitle?.en} onChange={(e) => set('staffTitle', { ...c.staffTitle, en: e.target.value })} dir="ltr" className={inp} /></label>
          <label className="flex items-center gap-2 sm:col-span-2 mt-5"><input type="checkbox" checked={!!c.inclusive} onChange={(e) => set('inclusive', e.target.checked)} />الأسعار شاملة الضريبة افتراضيًا</label>
        </div>
        <input value={c.note} onChange={(e) => set('note', e.target.value)} className={inp + ' mt-2 text-xs'} />
      </Box>
      <Box title="الشروط الافتراضية لكل عرض جديد">
        {(c.terms || []).map((t, i) => <div key={i} className="flex gap-1.5 mb-1.5"><textarea value={t} rows={1} onChange={(e) => set('terms', c.terms.map((x, j) => (j === i ? e.target.value : x)))} className={inp + ' text-xs'} /><button onClick={() => set('terms', c.terms.filter((_, j) => j !== i))} className="text-rose-300"><Trash2 size={14} /></button></div>)}
        <button onClick={() => set('terms', [...(c.terms || []), ''])} className="rounded-lg border border-dashed border-white/20 px-3 py-1.5 text-xs text-slate-300">+ شرط</button>
      </Box>
      <Box title="الحسابات البنكية">
        {(c.banks || []).map((b, i) => <div key={i} className="grid grid-cols-12 gap-1.5 mb-1.5"><input value={b.bank} onChange={(e) => set('banks', c.banks.map((x, j) => (j === i ? { ...x, bank: e.target.value } : x)))} placeholder="البنك" className={inp + ' col-span-4'} /><input value={b.iban} onChange={(e) => set('banks', c.banks.map((x, j) => (j === i ? { ...x, iban: e.target.value } : x)))} placeholder="SA…" dir="ltr" className={inp + ' col-span-7'} /><button onClick={() => set('banks', c.banks.filter((_, j) => j !== i))} className="text-rose-300 col-span-1"><Trash2 size={14} /></button></div>)}
        <button onClick={() => set('banks', [...(c.banks || []), { bank: '', iban: '' }])} className="rounded-lg border border-dashed border-white/20 px-3 py-1.5 text-xs text-slate-300">+ حساب</button>
      </Box>
      <Box title="صور القالب">
        <div className="grid sm:grid-cols-3 gap-2">
          <ImagePick label="رأس الصفحة (الشعار والشهادات)" value={c.header} onChange={(v) => set('header', v)} fallback={base + 'quote/header.png'} />
          <ImagePick label="عنوان «عرض سعر»" value={c.title} onChange={(v) => set('title', v)} fallback={base + 'quote/title.png'} />
          <ImagePick label="ذيل الصفحة (التليفونات والعنوان)" value={c.footer} onChange={(v) => set('footer', v)} fallback={base + 'quote/footer.png'} />
        </div>
      </Box>
      <div className="flex items-center gap-3"><button disabled={busy} onClick={save} className="rounded-2xl bg-violet-500 text-white font-black px-6 py-3 flex items-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}حفظ</button>{msg && <span className="text-sm text-slate-200">{msg}</span>}</div>
    </div>
  )
}
