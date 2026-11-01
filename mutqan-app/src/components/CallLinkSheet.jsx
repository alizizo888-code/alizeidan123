import { useMemo, useState } from 'react'
import { X, Search, Link2, Check, Trash2, UserPlus } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { customerKey } from '../lib/crm'
import { specialtyLabel } from '../constants'
import { addLink, removeLink, registerCustomer, setNote, mergeLinks, digits, LINK_ICON, LINK_LABEL } from '../lib/callbook'

const inp = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-slate-500 outline-none focus:border-primary'
const TABS = [['customer', '👤 عميل'], ['order', '🧾 طلب'], ['wa', '💬 واتساب'], ['bot', '🤖 البوت']]
const last9 = (v) => digits(v).slice(-9)
const norm = (v) => String(v || '').toLowerCase()

// Bottom sheet: identify a call — register the caller as a customer, or link his code to an order / a WhatsApp chat / a bot conversation.
export default function CallLinkSheet({ entry, contact, onClose }) {
  const [tab, setTab] = useState('customer')
  const [q, setQ] = useState('')
  const [links, setLinks] = useState(() => mergeLinks(entry.links || [], contact?.links || []))
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const [reg, setReg] = useState({ name: entry.name || '', phone: entry.phone || '' })
  const [note, setNoteTxt] = useState(entry.note || '')
  const [noteMsg, setNoteMsg] = useState('')
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 300 })
  const { data: crm } = useLiveShared('customer_profiles', { limit: 800 })
  const { data: threads } = useLiveShared('wa_threads', { order: '-lastAt', limit: 300 })
  const { data: handoffs } = useLiveShared('handoffs', { order: '-createdAt', limit: 100 })
  const mine = last9(entry.phone)
  const uid = entry.userId || ''
  const has = (type, ref) => links.some((l) => l.type === type && String(l.ref) === String(ref))

  const customers = useMemo(() => {
    const m = new Map()
    for (const o of orders || []) {
      const k = customerKey(o); const c = m.get(k) || { key: k, name: o.clientName || '', phone: o.clientPhone || '', n: 0 }
      c.n++; if (!c.name) c.name = o.clientName || ''; m.set(k, c)
    }
    for (const p of crm || []) if (!m.has(p.id)) m.set(p.id, { key: p.id, name: p.name || '', phone: p.phone || '', n: 0 })
    return [...m.values()]
  }, [orders, crm])

  const rows = useMemo(() => {
    const needle = norm(q).trim()
    const hit = (...f) => !needle || f.some((x) => norm(x).includes(needle) || (needle.length > 4 && last9(x) && last9(x).includes(needle.replace(/\D/g, '').slice(-9) || '§')))
    let list = []
    if (tab === 'customer') list = customers.filter((c) => hit(c.name, c.phone)).map((c) => ({ ref: c.key, label: c.name || c.phone || c.key, sub: `${c.phone || ''}${c.n ? ` · ${c.n} طلب` : ''}`, phone: c.phone, match: mine && last9(c.phone) === mine }))
    if (tab === 'order') list = (orders || []).filter((o) => hit(o.id, o.clientName, o.clientPhone, specialtyLabel(o.specialtyId))).slice(0, 80).map((o) => ({ ref: o.id, label: `${specialtyLabel(o.specialtyId)} · ${o.clientName || 'عميل'} #${String(o.id).slice(-5)}`, sub: `${o.clientPhone || ''} · ${o.status || ''}`, phone: o.clientPhone, name: o.clientName, match: (mine && last9(o.clientPhone) === mine) || (uid && o.clientId === uid) }))
    if (tab === 'wa') list = (threads || []).filter((t) => hit(t.name, t.phone)).slice(0, 80).map((t) => ({ ref: t.id, label: t.name || t.phone || 'محادثة', sub: `${t.phone || ''}${t.lastText ? ' · ' + String(t.lastText).slice(0, 40) : ''}`, phone: t.phone, name: t.name, match: mine && last9(t.phone) === mine }))
    if (tab === 'bot') list = (handoffs || []).filter((h) => hit(h.customerName, h.reason, h.customerPhone)).slice(0, 60).map((h) => ({ ref: h.id, label: h.customerName || 'محادثة البوت', sub: `${h.reason || ''} · ${h.status || ''}`, phone: h.customerPhone, name: h.customerName, match: mine && last9(h.customerPhone) === mine }))
    return list.sort((a, b) => Number(!!b.match) - Number(!!a.match))
  }, [tab, q, customers, orders, threads, handoffs, mine, uid])

  async function link(item) {
    setBusy(item.ref); setErr('')
    try {
      const l = { type: tab, ref: String(item.ref), label: item.label }
      const r = await addLink({ ...entry, links }, contact, l, { name: item.name || (tab === 'customer' ? item.label : ''), phone: item.phone || '' })
      setLinks(r.links)
    } catch { setErr('تعذر الربط — تأكد من الاتصال وأعد المحاولة.') } finally { setBusy('') }
  }
  async function unlink(l) { setBusy('x' + l.ref); try { await removeLink({ ...entry, links }, contact, l); setLinks((a) => a.filter((x) => !(x.type === l.type && String(x.ref) === String(l.ref)))) } catch { setErr('تعذر الحذف.') } finally { setBusy('') } }
  async function register() {
    if (!reg.name.trim()) return setErr('اكتب اسم العميل')
    if (digits(reg.phone).length < 7) return setErr('اكتب رقم الجوال')
    setBusy('reg'); setErr('')
    try { const r = await registerCustomer({ ...entry, links }, contact, reg); setLinks(r.links) } catch { setErr('تعذر التسجيل — أعد المحاولة.') } finally { setBusy('') }
  }
  async function saveNote() { try { await setNote({ ...entry, links }, note); setNoteMsg('تم الحفظ ✓'); setTimeout(() => setNoteMsg(''), 1800) } catch { setNoteMsg('تعذر الحفظ') } }

  return (
    <div className="fixed inset-0 z-[120] bg-black/70 backdrop-blur-sm flex items-end md:items-center justify-center p-0 md:p-4" onClick={onClose} dir="rtl">
      <div className="w-full max-w-lg max-h-[92vh] overflow-y-auto rounded-t-3xl md:rounded-3xl bg-[#0f1626] border border-white/10 text-white" onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 z-10 bg-[#0f1626] border-b border-white/10 px-4 py-3 flex items-center gap-3">
          <Link2 size={18} className="text-primary" />
          <div className="flex-1 min-w-0">
            <p className="font-black text-sm flex items-center gap-2"><span className="font-mono text-[11px] bg-sky-500/15 text-sky-300 rounded-md px-1.5 py-0.5">{entry.code}</span>{entry.name || 'عميل غير مسجّل'}</p>
            <p className="text-[11px] text-slate-400" dir="ltr">{entry.phone || 'بدون رقم'}</p>
          </div>
          <button onClick={onClose} aria-label="إغلاق" className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center"><X size={16} /></button>
        </div>
        <div className="p-4 grid gap-3">
          {links.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {links.map((l) => (
                <span key={l.type + l.ref} className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 border border-emerald-400/30 text-emerald-200 text-[11px] font-bold px-2.5 py-1">
                  {LINK_ICON[l.type]} {LINK_LABEL[l.type]}: {l.label}
                  <button onClick={() => unlink(l)} disabled={busy === 'x' + l.ref} aria-label="إزالة الربط" className="opacity-70 hover:opacity-100"><Trash2 size={12} /></button>
                </span>
              ))}
            </div>
          )}
          <div className="flex gap-1.5 overflow-x-auto">
            {TABS.map(([id, label]) => <button key={id} onClick={() => { setTab(id); setQ('') }} className={`shrink-0 rounded-xl px-3.5 py-2 text-xs font-black border ${tab === id ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{label}</button>)}
          </div>
          <div className="relative"><Search size={15} className="absolute right-3 top-3 text-slate-500" /><input className={inp + ' pr-9'} placeholder="ابحث بالاسم أو الرقم…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
          {tab === 'customer' && (
            <div className="rounded-2xl border border-white/10 bg-white/5 p-3 grid gap-2">
              <p className="text-xs font-black flex items-center gap-1.5"><UserPlus size={14} className="text-primary" /> تسجيل المتصل كعميل جديد</p>
              <input className={inp} placeholder="اسم العميل" value={reg.name} onChange={(e) => setReg({ ...reg, name: e.target.value })} />
              <input className={inp} dir="ltr" inputMode="tel" placeholder="رقم الجوال" value={reg.phone} onChange={(e) => setReg({ ...reg, phone: e.target.value })} />
              <button onClick={register} disabled={busy === 'reg'} className="rounded-xl bg-emerald-500 text-black font-black py-2.5 text-sm disabled:opacity-50">{busy === 'reg' ? 'جارِ التسجيل…' : 'تسجيل وربط الكود بالعميل'}</button>
            </div>
          )}
          <div className="grid gap-1.5 max-h-64 overflow-y-auto">
            {rows.length === 0 && <p className="text-center text-xs text-slate-500 py-4">لا توجد نتائج</p>}
            {rows.map((r) => (
              <div key={r.ref} className={`flex items-center gap-2 rounded-xl px-3 py-2 border ${r.match ? 'bg-emerald-500/10 border-emerald-400/30' : 'bg-white/5 border-white/10'}`}>
                <div className="flex-1 min-w-0 text-right"><p className="text-xs font-black truncate">{r.label}{r.match && <span className="mr-1.5 text-[10px] text-emerald-300">● نفس الرقم</span>}</p><p className="text-[10px] text-slate-400 truncate" dir="auto">{r.sub}</p></div>
                {has(tab, r.ref) ? <span className="text-emerald-300"><Check size={16} /></span> : <button onClick={() => link(r)} disabled={busy === r.ref} className="shrink-0 rounded-lg bg-primary text-black text-[11px] font-black px-3 py-1.5 disabled:opacity-50">{busy === r.ref ? '…' : 'ربط'}</button>}
              </div>
            ))}
          </div>
          <label className="grid gap-1 text-xs font-bold text-slate-300">ملاحظة على المكالمة
            <textarea className={inp + ' resize-none'} rows={2} value={note} onChange={(e) => setNoteTxt(e.target.value)} onBlur={saveNote} placeholder="ماذا طلب العميل؟ ما المتفق عليه؟" />
          </label>
          {noteMsg && <p className="text-[11px] font-bold text-emerald-300">{noteMsg}</p>}
          {err && <p className="text-xs font-bold text-amber-300">{err}</p>}
          <button onClick={onClose} className="rounded-xl bg-white/10 font-black py-2.5 text-sm">تم</button>
        </div>
      </div>
    </div>
  )
}
