import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Plus, Trash2, Printer, Save, Loader2, Search, Copy, Ban, Settings2, FileText, Stamp, Phone, Undo2, Users, Boxes } from 'lucide-react'
import AccessDenied from '../components/AccessDenied'
import DocReader from '../components/docs/DocReader'
import ImagePick from '../components/docs/ImagePick'
import ClientPicker from '../components/docs/ClientPicker'
import CatalogPicker from '../components/docs/CatalogPicker'
import { ReturnDialog } from './Customers'
import { post, errText } from '../lib/_api'
import { db } from '../lib/db'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { calcInvoice, invoiceHtml, invoiceAssets, printInvoice, money, arAmount, TEMPLATES, PAYS } from '../lib/invoice'

// «نظام الفواتير» (owner, 2026-11-22): plain and tax invoices in four templates, numbered by the server, the stamp asked
// before saving, never deleted (voided by the owner). Settings: company, VAT number, CR, items with codes, templates.

const ops = (op, body = {}) => post('/ops', { op, ...body })
const blankItem = () => ({ code: '', name: '', unit: 'عدد', qty: 1, price: '', discount: '' })
const blank = (type = 'normal', cfg) => ({ type, template: cfg?.template?.[type] || (type === 'tax' ? 'official' : 'classic'), pay: 'cash', note: '', orderId: '',
  client: { name: '', phone: '', vatNo: '', number: '', city: '', district: '', province: '', zip: '', street: '', building: '', addNo: '' }, items: [blankItem()], tech: { id: '', name: '' }, inclusive: cfg?.inclusive ?? true,
  receiver: '', hideReceiver: false, signed: !!(cfg?.signDefault && cfg?.signature) })

export default function Invoices() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const [sp] = useSearchParams()
  const [tab, setTab] = useState(sp.get('order') || sp.get('client') || sp.get('new') ? 'new' : 'list')
  const [cfg, setCfg] = useState(null)
  const [draft, setDraft] = useState(null)
  const allowed = isOwner || ['operations', 'supervisor'].includes(role)
  const canIssue = isOwner || role === 'operations'
  const loadCfg = () => ops('invoice_cfg').then(setCfg).catch(() => {})
  useEffect(() => { if (allowed) loadCfg() }, [allowed])
  if (loading) return <div className="p-8 text-slate-400">جارِ التحقق…</div>
  if (!allowed) return <AccessDenied refresh={refresh} />
  const tabs = [['list', 'سجل الفواتير والمرتجعات', FileText], ...(canIssue ? [['new', 'فاتورة جديدة', Plus]] : []), ['customers', 'العملاء والشركات', Users], ...(isOwner ? [['catalog', 'الأصناف والأقسام', Boxes], ['settings', 'الإعدادات والشركات', Settings2]] : [])]
  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-6xl mx-auto w-full px-4 md:px-8 py-4 flex items-center gap-3">
          <button onClick={() => navigate('/staff/operations')} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center hover:bg-white/10"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1 min-w-0"><p className="text-[11px] text-sky-400 font-bold tracking-widest">INVOICES</p><h1 className="font-display text-lg font-black text-white">الفواتير</h1></div>
          <button onClick={() => navigate('/staff/quotes')} className="rounded-xl bg-violet-500/20 text-violet-200 px-3 py-1.5 text-xs font-bold">📄 عروض الأسعار</button>
        </div>
        <div className="max-w-6xl mx-auto w-full px-4 md:px-8 pb-3 flex gap-1.5 overflow-x-auto">
          {tabs.map(([id, label, Icon]) => (
            <button key={id} onClick={() => (id === 'customers' ? navigate('/staff/customers') : setTab(id))} className={`shrink-0 rounded-xl px-3 py-1.5 text-xs font-bold flex items-center gap-1 ${tab === id ? 'bg-sky-500 text-black' : 'bg-white/5 text-slate-300 hover:bg-white/10'}`}><Icon size={13} />{label}</button>
          ))}
        </div>
      </header>
      <main className="max-w-6xl mx-auto w-full px-4 md:px-8 py-5">
        {!cfg ? <p className="text-slate-400 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />تحميل…</p>
          : tab === 'list' ? <Register cfg={cfg} isOwner={isOwner} canIssue={canIssue} onCopy={(inv) => { setDraft(fromSaved(inv, cfg)); setTab('new') }} />
          : tab === 'new' ? <Editor cfg={cfg} seed={draft} orderId={sp.get('order') || ''} clientId={sp.get('client') || ''} onSaved={() => { setDraft(null); setTab('list') }} />
          : tab === 'catalog' ? <Catalog cfg={cfg} onSaved={setCfg} />
          : <SettingsTab cfg={cfg} onSaved={(x) => { setCfg(x); loadCfg() }} />}
      </main>
    </div>
  )
}

const fromSaved = (inv, cfg) => ({ ...blank(inv.type === 'credit' ? (inv.tax ? 'tax' : 'normal') : inv.type, cfg), clientId: inv.clientId || '', issuer: inv.issuer || 'main', template: inv.template, pay: inv.pay, note: inv.note, client: { ...blank().client, ...inv.client }, tech: inv.tech || { id: '', name: '' },
  items: (inv.rows || []).map((r) => ({ code: r.code, name: r.name, unit: r.unit, qty: r.qty, price: r.price, discount: r.discount || '' })), inclusive: inv.inclusive, receiver: inv.receiver || '', hideReceiver: !!inv.hideReceiver, signed: !!inv.signed })

/* ------------------------------------------------------------------ the register */

function Register({ cfg, isOwner, canIssue, onCopy }) {
  const navigate = useNavigate()
  const [ret, setRet] = useState(null)
  const [q, setQ] = useState(''); const [type, setType] = useState('')
  const [rows, setRows] = useState(null); const [err, setErr] = useState('')
  const load = () => ops('invoice_list', { q, type }).then((r) => setRows(r.items || [])).catch((e) => setErr(errText(e, 'تعذر التحميل')))
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [q, type])
  const open = async (id, copy = false) => {
    try { const inv = await ops('invoice_get', { id }); if (copy) onCopy(inv); else await printInvoice(inv) } catch (e) { alert(errText(e, 'تعذر الفتح')) }
  }
  const voidIt = async (r) => {
    const reason = prompt(`سبب إلغاء ${r.type === 'tax' ? 'الفاتورة الضريبية' : 'الفاتورة'} رقم ${r.number}؟ (الرقم بيفضل في السجل)`)
    if (reason === null) return
    try { await ops('invoice_void', { id: r.id, reason }); load() } catch (e) { alert(errText(e, 'تعذر الإلغاء')) }
  }
  const sum = useMemo(() => (rows || []).filter((r) => r.status !== 'void').reduce((a, r) => { const s = r.type === 'credit' ? -1 : 1; return { net: a.net + s * r.net, vat: a.vat + s * r.vat } }, { net: 0, vat: 0 }), [rows])
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap gap-2 items-center">
        <div className="flex-1 min-w-[200px] relative"><Search size={14} className="absolute right-3 top-2.5 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث: رقم الفاتورة، العميل، الجوال، الفني" className="w-full rounded-xl bg-white/5 border border-white/10 pr-8 pl-3 py-2 text-sm text-white" /></div>
        {[['', 'الكل'], ['normal', 'عادية'], ['tax', 'ضريبية'], ['credit', 'مرتجعات']].map(([k, l]) => <button key={k} onClick={() => setType(k)} className={`rounded-xl px-3 py-2 text-xs font-bold ${type === k ? 'bg-sky-500 text-black' : 'bg-white/5 text-slate-300'}`}>{l}</button>)}
      </div>
      {rows && <p className="text-[12px] text-slate-400">{rows.length} فاتورة · الإجمالي <b className="text-white">{money(sum.net)} ر.س</b>{sum.vat ? <> · الضريبة <b className="text-white">{money(sum.vat)} ر.س</b></> : null}</p>}
      {err && <p className="text-rose-300 text-sm">{err}</p>}
      {!rows ? <Loader2 className="animate-spin text-slate-500" /> : rows.length === 0 ? <p className="text-slate-500 text-sm">لسه مفيش فواتير.</p> : (
        <div className="grid gap-2">
          {rows.map((r) => (
            <div key={r.id} className={`rounded-2xl border p-3 flex flex-wrap items-center gap-3 ${r.status === 'void' ? 'border-rose-500/30 bg-rose-500/5 opacity-70' : 'border-white/10 bg-[#121826]'}`}>
              <span className={`rounded-lg px-2 py-1 text-[11px] font-black ${r.type === 'credit' ? 'bg-rose-500/20 text-rose-200' : r.type === 'tax' ? 'bg-sky-500/20 text-sky-300' : 'bg-amber-500/20 text-amber-200'}`}>{r.type === 'credit' ? (r.tax ? 'إشعار دائن' : 'مرتجع') : r.type === 'tax' ? 'ضريبية' : 'عادية'} #{r.number}</span>
              <div className="flex-1 min-w-[160px]"><p className="text-white font-bold text-sm">{r.client}</p><p className="text-[11px] text-slate-400">{new Date(r.at).toLocaleString('ar-SA-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' })}{r.tech ? ' · الفني ' + r.tech : ''} · {TEMPLATES[r.template] || ''}{r.stamped ? ' · مختومة' : ' · بدون ختم'}{r.status === 'void' ? ' · ❌ ملغاة' : ''}{r.ref ? ` · على فاتورة #${r.ref.number}` : ''}{r.returned ? ` · مرتجع ${money(r.returned)}` : ''}{(cfg.issuers || []).length ? ' · ' + r.issuer : ''}</p></div>
              <b className={`text-sm ${r.type === 'credit' ? 'text-rose-300' : 'text-white'}`}>{r.type === 'credit' ? '−' : ''}{money(r.net)} ر.س</b>
              {r.clientId && <button onClick={() => navigate('/staff/customers?id=' + r.clientId)} className="rounded-xl bg-white/5 px-2.5 py-1.5 text-xs font-bold flex items-center gap-1"><Users size={12} />ملف العميل</button>}
              {canIssue && r.type !== 'credit' && r.status !== 'void' && <button onClick={() => setRet(r.id)} className="rounded-xl bg-rose-500/15 text-rose-200 px-2.5 py-1.5 text-xs font-bold flex items-center gap-1"><Undo2 size={12} />مرتجع</button>}
              <button onClick={() => open(r.id)} className="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Printer size={13} />عرض / طباعة</button>
              {canIssue && r.type !== 'credit' && <button onClick={() => open(r.id, true)} className="rounded-xl bg-white/10 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Copy size={13} />نسخة جديدة</button>}
              {isOwner && r.status !== 'void' && <button onClick={() => voidIt(r)} className="rounded-xl bg-rose-500/15 text-rose-200 px-3 py-1.5 text-xs font-bold flex items-center gap-1"><Ban size={13} />إلغاء</button>}
            </div>
          ))}
        </div>
      )}
      {ret && <ReturnDialog invoiceId={ret} onClose={(done) => { setRet(null); if (done) load() }} />}
    </div>
  )
}

/* ------------------------------------------------------------------ the editor */

function Editor({ cfg, seed, orderId, clientId, onSaved }) {
  const [f, setF] = useState(() => seed || { ...blank('normal', cfg), clientId: '', issuer: 'main', fileIds: [] })
  const issuers = cfg.issuerList || []
  const co = useMemo(() => {
    const b = (cfg.branches || []).find((x) => x.id === (f.branch || cfg.branch))
    const base = { ...cfg, ...(issuers.find((x) => x.id === (f.issuer || 'main')) || {}) }
    return b ? { ...base, cityLine: b.cityLine || base.cityLine, address: b.address || base.address, phones: b.phones?.length ? b.phones : base.phones } : base
  }, [cfg, f.issuer, f.branch])
  // the customer from his file
  const fillClient = (c) => setF((x) => ({ ...x, clientId: c.id || '', client: { ...x.client, ...Object.fromEntries(Object.keys(x.client).map((k) => [k, c[k] ?? x.client[k]])) } }))
  useEffect(() => { if (clientId && !seed) post('/ops', { op: 'account_get', id: clientId }).then((p) => fillClient(p.client)).catch(() => {}) }, [clientId])
  const [busy, setBusy] = useState(false); const [err, setErr] = useState(''); const [ask, setAsk] = useState(false)
  const { data: techs } = useLiveShared('tech_profiles', { limit: 300 })
  const tax = f.type === 'tax'
  const set = (patch) => setF((x) => ({ ...x, ...patch }))
  const setCl = (k, v) => setF((x) => ({ ...x, client: { ...x.client, [k]: v } }))
  const setIt = (i, patch) => setF((x) => ({ ...x, items: x.items.map((it, j) => (j === i ? { ...it, ...patch } : it)) }))
  // a code typed (or a name picked) fills the item from the catalogue
  const pick = (i, v, by) => {
    const hit = (cfg.items || []).find((c) => (by === 'code' ? c.code === v.trim() : c.name === v))
    setIt(i, hit ? { code: hit.code, name: hit.name, unit: hit.unit, price: hit.price || '' } : { [by]: v })
  }
  // from an order: the customer, the service, the price, the technician
  useEffect(() => {
    if (!orderId || seed) return
    db.selectShared('orders', { id: orderId }, { limit: 1 }).then((r) => {
      const o = Array.isArray(r) ? r[0] : null
      if (!o) return
      setF((x) => ({ ...x, orderId, client: { ...x.client, name: o.clientName || '', phone: o.clientPhone || '', district: o.address || '' }, tech: { id: o.technicianId || '', name: o.technicianName || '' },
        items: [{ code: '', name: o.serviceLabel || o.service || 'خدمة صيانة', unit: 'عدد', qty: 1, price: o.agreedPrice || o.price || '', discount: '' }] }))
    }).catch(() => {})
  }, [orderId])
  const live = useMemo(() => calcInvoice(f.items, tax, cfg.vatRate, f.inclusive), [f.items, tax, cfg.vatRate, f.inclusive])
  const preview = useMemo(() => ({ ...f, number: '—', at: new Date().toISOString(), company: co, rows: live.rows, totals: live.totals, vatRate: tax ? cfg.vatRate : 0, stamped: true, signed: f.signed && !!cfg.signature, qr: tax && cfg.qrZatca !== false ? 'preview' : '', viewUrl: cfg.qrDetails !== false ? 'https://oxygen11.com/?mqi=preview' : '' }), [f, live, cfg])
  const [assets, setAssets] = useState(null)
  useEffect(() => { invoiceAssets(preview).then(setAssets) }, [tax, co.logo, co.stamp, co.signature, cfg.qrZatca, cfg.qrDetails])
  const frame = useRef(null)
  const check = () => {
    if (!f.client.name.trim()) return 'اكتب اسم العميل أو الشركة'
    if (!live.rows.length) return 'ضيف بند واحد على الأقل'
    if (live.totals.net <= 0) return 'اكتب الأسعار'
    if (f.client.vatNo && !/^3\d{13}3$/.test(f.client.vatNo)) return 'الرقم الضريبي للعميل 15 رقم يبدأ وينتهي بـ 3'
    return ''
  }
  const save = async (stamped) => {
    setAsk(false); setBusy(true); setErr('')
    try {
      const inv = await ops('invoice_save', { invoice: { ...f, stamped, signed: f.signed && !!co.signature, items: f.items.filter((it) => String(it.name).trim()) } })
      await printInvoice(inv)
      onSaved(inv)
    } catch (e) { setErr(errText(e, 'ما اتحفظتش')) } finally { setBusy(false) }
  }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  return (
    <div className="grid lg:grid-cols-[1fr_440px] gap-4 items-start">
      <div className="grid gap-3">
        <div className="flex flex-wrap gap-2">
          {[['normal', 'فاتورة عادية'], ['tax', 'فاتورة ضريبية']].map(([k, l]) => (
            <button key={k} onClick={() => set({ type: k, template: cfg.template?.[k] || f.template })} className={`rounded-2xl px-4 py-2.5 font-black text-sm ${f.type === k ? (k === 'tax' ? 'bg-sky-500 text-black' : 'bg-amber-400 text-black') : 'bg-white/5 text-slate-300'}`}>{l}</button>
          ))}
          {(cfg.branches || []).length > 1 && <select value={f.branch || cfg.branch || ''} onChange={(e) => set({ branch: e.target.value })} className="rounded-2xl bg-white/5 border border-white/10 px-3 text-sm text-white">{cfg.branches.map((b) => <option key={b.id} value={b.id} className="text-black">📍 {b.name}</option>)}</select>}
          {issuers.length > 1 && <select value={f.issuer || 'main'} onChange={(e) => set({ issuer: e.target.value })} className="rounded-2xl bg-white/5 border border-white/10 px-3 text-sm text-white">{issuers.map((i) => <option key={i.id} value={i.id} className="text-black">🏢 {i.nameAr}</option>)}</select>}
          <select value={f.template} onChange={(e) => set({ template: e.target.value })} className="rounded-2xl bg-white/5 border border-white/10 px-3 text-sm text-white">
            {Object.entries(TEMPLATES).map(([k, l]) => <option key={k} value={k} className="text-black">قالب: {l}</option>)}
          </select>
        </div>
        <Box title="العميل">
          <div className="mb-2 grid gap-2"><ClientPicker clientId={f.clientId} onPick={fillClient} onClear={() => set({ clientId: '' })} />
            <DocReader accountId={f.clientId || ''} onFill={(x, files) => setF((s) => ({ ...s, fileIds: [...(s.fileIds || []), ...(files || []).map((y) => y.id)], client: { ...s.client, ...Object.fromEntries(Object.entries(x).filter(([k, v]) => k in s.client && v)) } }))} /></div>
          <div className="grid sm:grid-cols-2 gap-2">
            <input value={f.client.name} onChange={(e) => setCl('name', e.target.value)} placeholder="اسم العميل أو الشركة *" className={inp} />
            <input value={f.client.phone} onChange={(e) => setCl('phone', e.target.value)} placeholder="الجوال" dir="ltr" className={inp} />
            {tax && <input value={f.client.vatNo} onChange={(e) => setCl('vatNo', e.target.value.replace(/\D/g, '').slice(0, 15))} placeholder="الرقم الضريبي للعميل (للشركات)" dir="ltr" className={inp} />}
            {tax && <input value={f.client.number} onChange={(e) => setCl('number', e.target.value)} placeholder="رقم العميل" className={inp} />}
          </div>
          {tax && <details className="mt-2"><summary className="text-xs text-sky-300 cursor-pointer">عنوان العميل (العنوان الوطني)</summary>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2">{[['city', 'المدينة'], ['district', 'الحي'], ['province', 'المنطقة'], ['zip', 'الرمز البريدي'], ['street', 'الشارع'], ['building', 'رقم المبنى'], ['addNo', 'الرقم الإضافي']].map(([k, l]) => <input key={k} value={f.client[k]} onChange={(e) => setCl(k, e.target.value)} placeholder={l} className={inp} />)}</div>
          </details>}
        </Box>
        <Box title="البنود">
          <div className="mb-2"><CatalogPicker items={cfg.items || []} cats={cfg.cats || []} onPick={(it) => setF((x) => { const items = x.items.filter((y) => String(y.name).trim()); return { ...x, items: [...items, { code: it.code, name: it.name, unit: it.unit, qty: 1, price: it.price || '', discount: '' }] } })} /></div>
          <datalist id="inv-items">{(cfg.items || []).map((c) => <option key={c.code} value={c.name}>{c.code}</option>)}</datalist>
          <div className="grid gap-2">
            {f.items.map((it, i) => (
              <div key={i} className="grid grid-cols-12 gap-1.5 items-center">
                <input value={it.code} onChange={(e) => pick(i, e.target.value, 'code')} placeholder="كود" className={inp + ' col-span-2 px-2'} />
                <input value={it.name} onChange={(e) => pick(i, e.target.value, 'name')} list="inv-items" placeholder="البند / الصنف" className={inp + ' col-span-10 sm:col-span-4'} />
                {tax && <input value={it.unit} onChange={(e) => setIt(i, { unit: e.target.value })} placeholder="الوحدة" className={inp + ' col-span-3 sm:col-span-1 px-2'} />}
                <input value={it.qty} onChange={(e) => setIt(i, { qty: e.target.value })} type="number" min="0" placeholder="العدد" className={inp + ' col-span-3 sm:col-span-1 px-2'} />
                <input value={it.price} onChange={(e) => setIt(i, { price: e.target.value })} type="number" min="0" placeholder="السعر" className={inp + ` col-span-4 ${tax ? 'sm:col-span-2' : 'sm:col-span-3'} px-2`} />
                {tax && <input value={it.discount} onChange={(e) => setIt(i, { discount: e.target.value })} type="number" min="0" placeholder="خصم" className={inp + ' col-span-3 sm:col-span-1 px-2'} />}
                <button onClick={() => setF((x) => ({ ...x, items: x.items.length > 1 ? x.items.filter((_, j) => j !== i) : [blankItem()] }))} className="col-span-1 text-rose-300 flex justify-center"><Trash2 size={15} /></button>
              </div>
            ))}
            <button onClick={() => setF((x) => ({ ...x, items: [...x.items, blankItem()] }))} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة بند</button>
          </div>
          {tax && <label className="mt-2 flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={f.inclusive} onChange={(e) => set({ inclusive: e.target.checked })} />الأسعار شاملة الضريبة ({cfg.vatRate}%)</label>}
        </Box>
        <Box title="التنفيذ والدفع">
          <div className="grid sm:grid-cols-3 gap-2">
            <select value={f.tech.id} onChange={(e) => { const t = techs.find((x) => (x.userId || x.id) === e.target.value); set({ tech: { id: e.target.value, name: t?.displayName || t?.name || '' } }) }} className={inp}>
              <option value="" className="text-black">الفني المنفذ…</option>
              {techs.map((t) => <option key={t.userId || t.id} value={t.userId || t.id} className="text-black">{t.displayName || t.name || 'فني'}</option>)}
            </select>
            <input value={f.tech.name} onChange={(e) => set({ tech: { ...f.tech, name: e.target.value } })} placeholder="أو اكتب اسم الفني" className={inp} />
            <select value={f.pay} onChange={(e) => set({ pay: e.target.value })} className={inp}>{Object.entries(PAYS).map(([k, l]) => <option key={k} value={k} className="text-black">{l}</option>)}</select>
          </div>
          <input value={f.note} onChange={(e) => set({ note: e.target.value })} placeholder="ملاحظات / وذلك مقابل…" className={inp + ' mt-2'} />
          <div className="grid sm:grid-cols-2 gap-2 mt-2 items-center">
            <input value={f.receiver} disabled={f.hideReceiver} onChange={(e) => set({ receiver: e.target.value })} placeholder="اسم المستلم (اللي استلم المبلغ)" className={inp + (f.hideReceiver ? ' opacity-40' : '')} />
            <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={f.hideReceiver} onChange={(e) => set({ hideReceiver: e.target.checked })} />إخفاء اسم المستلم من الفاتورة</label>
          </div>
        </Box>
        <div className="rounded-2xl bg-[#121826] border border-white/10 p-3 grid gap-1 text-sm">
          {tax && <><Row k="الإجمالي قبل الضريبة" v={live.totals.afterDiscount} /><Row k={`الضريبة ${cfg.vatRate}%`} v={live.totals.vat} /></>}
          <Row k="الإجمالي" v={live.totals.net} big />
          <p className="text-[12px] text-slate-400">{arAmount(live.totals.net)}</p>
        </div>
        {err && <p className="text-rose-300 text-sm">{err}</p>}
        <button disabled={busy} onClick={() => { const e = check(); if (e) { setErr(e); return } setErr(''); setAsk(true) }} className="rounded-2xl bg-emerald-500 text-black font-black py-3 flex items-center justify-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}حفظ وإصدار الفاتورة</button>
      </div>
      <div className="lg:sticky lg:top-28 rounded-2xl overflow-hidden border border-white/10 bg-white">
        <p className="bg-slate-800 text-slate-200 text-[11px] px-3 py-1">معاينة — {TEMPLATES[f.template]} (الرقم بيتحدد عند الحفظ)</p>
        {assets && <iframe ref={frame} title="preview" srcDoc={invoiceHtml(preview, { ...assets, qr: tax ? assets.qr || '' : '' }, { bar: false })} className="w-full" style={{ height: f.template === 'thermal' ? 720 : 620 }} />}
      </div>
      {ask && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" onClick={() => setAsk(false)}>
          <div className="rounded-3xl bg-[#121826] border border-white/10 p-5 w-full max-w-sm grid gap-3 text-center" onClick={(e) => e.stopPropagation()}>
            <Stamp size={36} className="mx-auto text-sky-300" />
            <p className="text-white font-black text-lg">تلصق ختم المؤسسة على الفاتورة؟</p>
            <p className="text-slate-400 text-xs">{f.type === 'tax' ? 'فاتورة ضريبية' : 'فاتورة'} بإجمالي {money(live.totals.net)} ر.س لـ {f.client.name}</p>
            <div className="grid grid-cols-2 gap-2">
              <button onClick={() => save(true)} className="rounded-2xl bg-sky-500 text-black font-black py-3">نعم، بالختم</button>
              <button onClick={() => save(false)} className="rounded-2xl bg-white/10 text-white font-bold py-3">لا، بدون ختم</button>
            </div>
            {co.signature && <label className="flex items-center justify-center gap-2 text-sm text-slate-200"><input type="checkbox" checked={f.signed} onChange={(e) => set({ signed: e.target.checked })} />وكمان التوقيع ✍️</label>}
            <button onClick={() => setAsk(false)} className="text-xs text-slate-400">رجوع للتعديل</button>
          </div>
        </div>
      )}
    </div>
  )
}

const Box = ({ title, children }) => <section className="rounded-2xl bg-[#121826] border border-white/10 p-3"><h3 className="text-xs font-black text-slate-300 mb-2">{title}</h3>{children}</section>
const Row = ({ k, v, big }) => <div className={`flex justify-between ${big ? 'text-white font-black text-base' : 'text-slate-300'}`}><span>{k}</span><span>{money(v)} ر.س</span></div>

/* ------------------------------------------------------------------ settings (owner) */

function SettingsTab({ cfg, onSaved }) {
  const [c, setC] = useState(() => JSON.parse(JSON.stringify(cfg)))
  const [busy, setBusy] = useState(false); const [msg, setMsg] = useState('')
  const set = (k, v) => setC((x) => ({ ...x, [k]: v }))
  const setItem = (i, patch) => setC((x) => ({ ...x, items: x.items.map((it, j) => (j === i ? { ...it, ...patch } : it)) }))
  const save = async () => {
    setBusy(true); setMsg('')
    try { const r = await ops('invoice_cfg_set', { cfg: { ...c, items: undefined, cats: undefined } }); onSaved({ ...cfg, ...r }); setC(JSON.parse(JSON.stringify({ ...cfg, ...r }))); setMsg('✅ اتحفظت الإعدادات') } catch (e) { setMsg('❌ ' + errText(e, 'ما اتحفظتش')) } finally { setBusy(false) }
  }
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  const F = ({ k, l, ltr }) => <label className="grid gap-1 text-xs text-slate-400">{l}<input value={c[k] ?? ''} onChange={(e) => set(k, e.target.value)} dir={ltr ? 'ltr' : undefined} className={inp} /></label>
  return (
    <div className="grid gap-3 max-w-4xl">
      <Box title="بيانات المؤسسة (بتتطبع على الفواتير الجديدة — الفواتير القديمة بتفضل ببياناتها)">
        <div className="grid sm:grid-cols-2 gap-2">
          {F({ k: 'nameAr', l: 'اسم المؤسسة بالعربي' })}{F({ k: 'nameEn', l: 'الاسم بالإنجليزي', ltr: true })}
          {F({ k: 'vatNo', l: 'الرقم الضريبي (15 رقم)', ltr: true })}{F({ k: 'crNo', l: 'السجل التجاري', ltr: true })}
          {F({ k: 'cityLine', l: 'سطر المدينة تحت الاسم' })}{F({ k: 'address', l: 'العنوان في ذيل الفاتورة' })}
          {F({ k: 'note', l: 'جملة الختم («أي فاتورة لا تحمل ختم المؤسسة تعتبر لاغية»)' })}
        </div>
      </Box>
      <Box title="الشعار والختم والتوقيع (بتتطبع على الفواتير وعروض الأسعار)">
        <div className="grid sm:grid-cols-3 gap-2">
          <ImagePick label="الشعار" value={c.logo} onChange={(v) => set('logo', v)} fallback={(import.meta.env.BASE_URL || '/') + 'invoice/logo.png'} hint="PNG بخلفية شفافة أحسن" />
          <ImagePick label="الختم" value={c.stamp} onChange={(v) => set('stamp', v)} fallback={(import.meta.env.BASE_URL || '/') + 'invoice/stamp.png'} hint="صورة الختم بخلفية شفافة" />
          <ImagePick label="التوقيع" value={c.signature} onChange={(v) => set('signature', v)} hint="وقّع على ورقة بيضا وصوّرها، أو PNG شفاف" />
        </div>
        <div className="grid sm:grid-cols-2 gap-2 mt-2">
          {F({ k: 'signer', l: 'الاسم تحت التوقيع' })}
          <label className="flex items-center gap-2 text-xs text-slate-300 mt-5"><input type="checkbox" checked={!!c.signDefault} onChange={(e) => set('signDefault', e.target.checked)} />التوقيع متعلّم افتراضيًا في كل فاتورة</label>
          <div className="mt-4 rounded-xl border border-slate-700 p-3 space-y-2">
            <div className="text-xs font-bold text-slate-200">أكواد الـQR على الفاتورة</div>
            <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={c.qrZatca !== false} onChange={(e) => set('qrZatca', e.target.checked)} />QR هيئة الزكاة والضريبة (للفاتورة الضريبية)</label>
            {c.qrZatca === false && <div className="text-[11px] text-amber-300">⚠️ الفاتورة الضريبية المبسّطة لازم يكون عليها QR الهيئة — إخفاؤه على مسؤوليتك.</div>}
            <label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={c.qrDetails !== false} onChange={(e) => set('qrDetails', e.target.checked)} />QR تفاصيل الفاتورة (يتقري بأي كاميرا)</label>
          </div>
        </div>
      </Box>
      <Box title="أرقام التليفونات (بتظهر في ذيل كل فاتورة جديدة)">
        <div className="grid gap-1.5">
          {(c.phones || []).map((ph, i) => (
            <div key={i} className="flex gap-1.5 items-center">
              <Phone size={14} className="text-slate-500" />
              <input value={ph} onChange={(e) => set('phones', c.phones.map((x, j) => (j === i ? e.target.value.replace(/[^\d+]/g, '') : x)))} placeholder="05xxxxxxxx" dir="ltr" className={inp} />
              <button onClick={() => set('phones', c.phones.filter((_, j) => j !== i))} className="text-rose-300 px-2"><Trash2 size={15} /></button>
            </div>
          ))}
          {(c.phones || []).length < 4 && <button onClick={() => set('phones', [...(c.phones || []), ''])} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة رقم</button>}
        </div>
      </Box>
      <Box title="الضريبة والترقيم والقوالب">
        <div className="grid sm:grid-cols-3 gap-2 text-xs text-slate-400">
          <label className="grid gap-1">نسبة الضريبة %<input type="number" value={c.vatRate} onChange={(e) => set('vatRate', e.target.value)} className={inp} /></label>
          <label className="grid gap-1">رقم الفاتورة العادية الجاية<input type="number" value={c.next?.normal} onChange={(e) => set('next', { ...c.next, normal: e.target.value })} className={inp} /></label>
          <label className="grid gap-1">رقم الفاتورة الضريبية الجاية<input type="number" value={c.next?.tax} onChange={(e) => set('next', { ...c.next, tax: e.target.value })} className={inp} /></label>
          <label className="grid gap-1">رقم المرتجع الجاي<input type="number" value={c.next?.credit ?? 1} onChange={(e) => set('next', { ...c.next, credit: e.target.value })} className={inp} /></label>
          <label className="grid gap-1">قالب الفاتورة العادية<select value={c.template?.normal} onChange={(e) => set('template', { ...c.template, normal: e.target.value })} className={inp}>{Object.entries(TEMPLATES).map(([k, l]) => <option key={k} value={k} className="text-black">{l}</option>)}</select></label>
          <label className="grid gap-1">قالب الفاتورة الضريبية<select value={c.template?.tax} onChange={(e) => set('template', { ...c.template, tax: e.target.value })} className={inp}>{Object.entries(TEMPLATES).map(([k, l]) => <option key={k} value={k} className="text-black">{l}</option>)}</select></label>
          <label className="flex items-center gap-2 mt-5"><input type="checkbox" checked={!!c.inclusive} onChange={(e) => set('inclusive', e.target.checked)} />الأسعار شاملة الضريبة (الافتراضي)</label>
        </div>
      </Box>
      <Box title="📍 الفروع / المقر (اللي بيتطبع على الفاتورة وعرض السعر — مكة، جدة…)">
        <div className="grid gap-1.5">
          {(c.branches || []).map((b, i) => {
            const setB = (patch) => set('branches', c.branches.map((y, j) => (j === i ? { ...y, ...patch } : y)))
            return (
              <div key={b.id || i} className="rounded-xl bg-black/20 p-2 grid sm:grid-cols-12 gap-1.5 items-center">
                <label className="sm:col-span-1 flex items-center gap-1 text-[11px] text-slate-300"><input type="radio" checked={(c.branch || '') === b.id} onChange={() => set('branch', b.id)} />افتراضي</label>
                <input value={b.name || ''} onChange={(e) => setB({ name: e.target.value })} placeholder="اسم الفرع (جدة)" className={inp + ' sm:col-span-2'} />
                <input value={b.cityLine || ''} onChange={(e) => setB({ cityLine: e.target.value })} placeholder="سطر المدينة تحت الاسم" className={inp + ' sm:col-span-3'} />
                <input value={b.address || ''} onChange={(e) => setB({ address: e.target.value })} placeholder="العنوان الكامل" className={inp + ' sm:col-span-3'} />
                <input value={(b.phones || []).join('، ')} onChange={(e) => setB({ phones: e.target.value.split(/[،,\s]+/).filter(Boolean) })} placeholder="تليفونات الفرع (اختياري)" dir="ltr" className={inp + ' sm:col-span-2'} />
                <button onClick={() => set('branches', c.branches.filter((_, j) => j !== i))} className="sm:col-span-1 text-rose-300 flex justify-center"><Trash2 size={15} /></button>
                <div className="sm:col-span-12"><details><summary className="text-[11px] text-slate-400 cursor-pointer">ذيل عرض السعر لهذا الفرع (صورة)</summary><div className="max-w-xs mt-1"><ImagePick label="ذيل الصفحة" value={b.footer} onChange={(v) => setB({ footer: v })} hint="لو فاضي وفرع غير مكة: بيتكتب العنوان والتليفونات نص" /></div></details></div>
              </div>
            )
          })}
          <button onClick={() => set('branches', [...(c.branches || []), { name: 'جدة', cityLine: 'جدة', address: 'المملكة العربية السعودية – جدة', phones: [] }])} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة فرع</button>
        </div>
      </Box>
      <Box title="شركات تانية بتصدر فواتير (كل شركة برقمها الضريبي وسجلها وختمها وترقيمها)">
        <div className="grid gap-2">
          {(c.issuers || []).map((x, i) => {
            const setI = (patch) => set('issuers', c.issuers.map((y, j) => (j === i ? { ...y, ...patch } : y)))
            return (
              <div key={x.id || i} className="rounded-xl bg-black/20 p-2 grid gap-1.5">
                <div className="grid sm:grid-cols-3 gap-1.5">
                  <input value={x.nameAr || ''} onChange={(e) => setI({ nameAr: e.target.value })} placeholder="اسم الشركة / المؤسسة" className={inp} />
                  <input value={x.vatNo || ''} onChange={(e) => setI({ vatNo: e.target.value })} placeholder="الرقم الضريبي" dir="ltr" className={inp} />
                  <input value={x.crNo || ''} onChange={(e) => setI({ crNo: e.target.value })} placeholder="السجل التجاري" dir="ltr" className={inp} />
                  <input value={x.nameEn || ''} onChange={(e) => setI({ nameEn: e.target.value })} placeholder="الاسم بالإنجليزي" dir="ltr" className={inp} />
                  <input value={x.cityLine || ''} onChange={(e) => setI({ cityLine: e.target.value })} placeholder="سطر المدينة" className={inp} />
                  <input value={(x.phones || []).join('، ')} onChange={(e) => setI({ phones: e.target.value.split(/[،,\s]+/).filter(Boolean) })} placeholder="التليفونات" dir="ltr" className={inp} />
                  <input value={x.address || ''} onChange={(e) => setI({ address: e.target.value })} placeholder="العنوان" className={inp} />
                  <input value={x.signer || ''} onChange={(e) => setI({ signer: e.target.value })} placeholder="الاسم تحت التوقيع" className={inp} />
                  <div className="grid grid-cols-3 gap-1 text-[10px] text-slate-400">{[['tax', 'ضريبية'], ['normal', 'عادية'], ['credit', 'مرتجع']].map(([k, l]) => <label key={k} className="grid">{l}<input type="number" value={x.next?.[k] ?? 1} onChange={(e) => setI({ next: { ...(x.next || {}), [k]: e.target.value } })} className={inp + ' px-2'} /></label>)}</div>
                </div>
                <div className="grid sm:grid-cols-3 gap-1.5">
                  <ImagePick label="الشعار" value={x.logo} onChange={(v) => setI({ logo: v })} />
                  <ImagePick label="الختم" value={x.stamp} onChange={(v) => setI({ stamp: v })} />
                  <ImagePick label="التوقيع" value={x.signature} onChange={(v) => setI({ signature: v })} />
                </div>
                <button onClick={() => { if (confirm('تشيل الشركة دي من القائمة؟ (فواتيرها القديمة بتفضل زي ما هي)')) set('issuers', c.issuers.filter((_, j) => j !== i)) }} className="text-xs text-rose-300 justify-self-start">حذف الشركة من القائمة</button>
              </div>
            )
          })}
          <button onClick={() => set('issuers', [...(c.issuers || []), { nameAr: '', vatNo: '', crNo: '', phones: [], next: { tax: 1, normal: 1, credit: 1 } }])} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة شركة تانية</button>
        </div>
      </Box>
      <div className="flex items-center gap-3">
        <button disabled={busy} onClick={save} className="rounded-2xl bg-sky-500 text-black font-black px-6 py-3 flex items-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}حفظ الإعدادات</button>
        {msg && <span className="text-sm text-slate-200">{msg}</span>}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ the catalogue: items with codes, by category (owner) */

function Catalog({ cfg, onSaved }) {
  const [items, setItems] = useState(() => JSON.parse(JSON.stringify(cfg.items || [])))
  const [cats, setCats] = useState(() => [...(cfg.cats || [])])
  const [cat, setCat] = useState(''); const [q, setQ] = useState(''); const [busy, setBusy] = useState(false); const [msg, setMsg] = useState('')
  const inp = 'w-full rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white'
  const setItem = (i, patch) => setItems((x) => x.map((it, j) => (j === i ? { ...it, ...patch } : it)))
  const shown = items.map((it, i) => [it, i]).filter(([it]) => (!cat || (it.cat || '') === cat) && (!q || `${it.code} ${it.name}`.includes(q)))
  const nextCode = () => String(Math.max(0, ...items.map((x) => Number(x.code) || 0)) + 1)
  const save = async () => {
    setBusy(true); setMsg('')
    try { const r = await post('/ops', { op: 'invoice_cfg_set', cfg: { items, cats } }); onSaved({ ...cfg, ...r }); setItems(r.items); setCats(r.cats); setMsg('✅ اتحفظت الأصناف') } catch (e) { setMsg('❌ ' + errText(e, 'ما اتحفظتش')) } finally { setBusy(false) }
  }
  return (
    <div className="grid gap-3 max-w-5xl">
      <Box title="الأقسام">
        <div className="flex flex-wrap gap-1.5">
          {cats.map((c, i) => (
            <span key={c + i} className="rounded-full bg-amber-500/15 text-amber-100 text-xs pl-1 pr-3 py-1 flex items-center gap-1">
              <button onClick={() => { const n = prompt('اسم القسم:', c); if (n && n.trim()) { setCats(cats.map((x, j) => (j === i ? n.trim() : x))); setItems(items.map((it) => ((it.cat || '') === c ? { ...it, cat: n.trim() } : it))) } }}>{c} ({items.filter((it) => it.cat === c).length})</button>
              <button onClick={() => { if (confirm(`تشيل قسم «${c}»؟ أصنافه هتفضل من غير قسم.`)) { setCats(cats.filter((_, j) => j !== i)); setItems(items.map((it) => (it.cat === c ? { ...it, cat: '' } : it))) } }} className="text-rose-300 px-1">×</button>
            </span>
          ))}
          <button onClick={() => { const n = prompt('اسم القسم الجديد:'); if (n && n.trim() && !cats.includes(n.trim())) setCats([...cats, n.trim()]) }} className="rounded-full border border-dashed border-white/20 text-xs text-slate-300 px-3 py-1">+ قسم جديد</button>
        </div>
      </Box>
      <Box title={`الأصناف بأرقامها (${items.length}) — الكود بيطلّع الصنف بسعره في الفاتورة وعرض السعر`}>
        <div className="flex flex-wrap gap-2 mb-2">
          <div className="flex-1 min-w-[180px] relative"><Search size={14} className="absolute right-3 top-2.5 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث بالكود أو الاسم" className={inp + ' pr-8'} /></div>
          <select value={cat} onChange={(e) => setCat(e.target.value)} className="rounded-xl bg-white/5 border border-white/10 px-3 text-sm text-white"><option value="" className="text-black">كل الأقسام</option>{cats.map((c) => <option key={c} className="text-black">{c}</option>)}</select>
        </div>
        <div className="grid gap-1.5">
          {shown.map(([it, i]) => (
            <div key={i} className="rounded-xl bg-black/20 p-1.5 grid grid-cols-12 gap-1.5">
              <input value={it.code} onChange={(e) => setItem(i, { code: e.target.value })} placeholder="الكود" dir="ltr" className={inp + ' col-span-3 sm:col-span-1 px-2'} />
              <input value={it.name} onChange={(e) => setItem(i, { name: e.target.value })} placeholder="اسم الصنف" className={inp + ' col-span-9 sm:col-span-4'} />
              <select value={it.cat || ''} onChange={(e) => setItem(i, { cat: e.target.value })} className={inp + ' col-span-5 sm:col-span-2 px-2'}><option value="" className="text-black">بدون قسم</option>{cats.map((c) => <option key={c} className="text-black">{c}</option>)}</select>
              <input value={it.unit} onChange={(e) => setItem(i, { unit: e.target.value })} placeholder="الوحدة" className={inp + ' col-span-3 sm:col-span-1 px-2'} />
              <input value={it.price} onChange={(e) => setItem(i, { price: e.target.value })} type="number" placeholder="السعر" className={inp + ' col-span-3 sm:col-span-2 px-2'} />
              <button onClick={() => setItems(items.filter((_, j) => j !== i))} className="col-span-1 text-rose-300 flex justify-center items-center"><Trash2 size={15} /></button>
              <input value={it.specs || ''} onChange={(e) => setItem(i, { specs: e.target.value })} placeholder="المواصفات (بتنزل مع الصنف في عرض السعر)" className={inp + ' col-span-12 text-xs'} />
            </div>
          ))}
          <button onClick={() => setItems([...items, { code: nextCode(), name: '', unit: 'عدد', price: 0, cat: cat || '', specs: '' }])} className="rounded-xl border border-dashed border-white/20 py-2 text-sm text-slate-300 flex items-center justify-center gap-1"><Plus size={14} />إضافة صنف{cat ? ' في ' + cat : ''}</button>
        </div>
      </Box>
      <div className="flex items-center gap-3"><button disabled={busy} onClick={save} className="rounded-2xl bg-amber-400 text-black font-black px-6 py-3 flex items-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}حفظ الأصناف والأقسام</button>{msg && <span className="text-sm text-slate-200">{msg}</span>}</div>
    </div>
  )
}
