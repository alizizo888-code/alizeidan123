import { useEffect, useRef, useState } from 'react'
import { Search, Building2, User, X } from 'lucide-react'
import { post } from '../../lib/_api'

// Pick a customer from his database (name, phone, VAT number, CR) — or keep typing a new one.
export default function ClientPicker({ clientId, onPick, onClear }) {
  const [q, setQ] = useState(''); const [rows, setRows] = useState([]); const [open, setOpen] = useState(false)
  const box = useRef(null)
  useEffect(() => {
    if (!open) return
    const t = setTimeout(() => post('/ops', { op: 'account_list', q }).then((r) => setRows((r.items || []).slice(0, 12))).catch(() => {}), 200)
    return () => clearTimeout(t)
  }, [q, open])
  useEffect(() => { const f = (e) => { if (box.current && !box.current.contains(e.target)) setOpen(false) }; document.addEventListener('mousedown', f); return () => document.removeEventListener('mousedown', f) }, [])
  const pick = async (id) => {
    setOpen(false)
    try { const p = await post('/ops', { op: 'account_get', id }); onPick(p.client, p) } catch { /* stays as typed */ }
  }
  return (
    <div ref={box} className="relative">
      {clientId ? (
        <div className="flex items-center gap-2 rounded-xl bg-emerald-500/10 border border-emerald-400/30 px-3 py-1.5 text-xs text-emerald-200">
          <span className="flex-1">✅ عميل مسجل — الفاتورة هتتحفظ في ملفه</span>
          <button type="button" onClick={onClear} className="text-slate-300"><X size={13} /></button>
        </div>
      ) : (
        <div className="relative"><Search size={14} className="absolute right-3 top-2.5 text-slate-500" />
          <input value={q} onFocus={() => setOpen(true)} onChange={(e) => { setQ(e.target.value); setOpen(true) }} placeholder="🔎 اختار عميل مسجل (اسم، جوال، رقم ضريبي، سجل) — أو اكتب عميل جديد تحت" className="w-full rounded-xl bg-white/5 border border-sky-400/30 pr-8 pl-3 py-2 text-sm text-white" />
        </div>
      )}
      {open && !clientId && rows.length > 0 && (
        <div className="absolute z-30 inset-x-0 mt-1 rounded-xl bg-[#0d1220] border border-white/15 max-h-72 overflow-y-auto shadow-2xl">
          {rows.map((r) => (
            <button type="button" key={r.id} onClick={() => pick(r.id)} className="w-full text-right px-3 py-2 hover:bg-white/5 flex items-center gap-2 border-b border-white/5">
              {r.kind === 'company' ? <Building2 size={14} className="text-sky-300" /> : <User size={14} className="text-slate-400" />}
              <span className="flex-1 min-w-0"><b className="text-white text-sm block truncate">{r.name}</b><span className="text-[11px] text-slate-400" dir="ltr">{[r.phone, r.vatNo && 'VAT ' + r.vatNo].filter(Boolean).join(' · ')}</span></span>
              <span className="text-[11px] text-slate-400">{r.invoices} فاتورة</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
