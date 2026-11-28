import { useMemo, useState } from 'react'
import { BookOpen } from 'lucide-react'

// The items catalogue by category: pick a category, then an item — it fills the line (name, unit, price, specs).
export default function CatalogPicker({ items = [], cats = [], onPick }) {
  const [cat, setCat] = useState('')
  const list = useMemo(() => items.filter((i) => !cat || (i.cat || 'أخرى') === cat), [items, cat])
  const used = useMemo(() => Array.from(new Set([...cats, ...items.map((i) => i.cat || 'أخرى')])).filter((c) => items.some((i) => (i.cat || 'أخرى') === c)), [items, cats])
  if (!items.length) return null
  return (
    <div className="flex flex-wrap gap-1.5 items-center rounded-xl bg-white/[0.03] border border-white/10 p-2">
      <BookOpen size={14} className="text-amber-300" />
      <select value={cat} onChange={(e) => setCat(e.target.value)} className="rounded-lg bg-white/5 text-xs text-slate-200 px-2 py-1.5"><option value="" className="text-black">كل الأقسام</option>{used.map((c) => <option key={c} className="text-black">{c}</option>)}</select>
      <select value="" onChange={(e) => { const it = items.find((x) => x.code === e.target.value); if (it) onPick(it); }} className="flex-1 min-w-[180px] rounded-lg bg-white/5 text-xs text-slate-200 px-2 py-1.5">
        <option value="" className="text-black">➕ أضف صنف من الأصناف ({list.length})</option>
        {list.map((i) => <option key={i.code} value={i.code} className="text-black">{i.code} — {i.name}{i.price ? ` (${i.price} ر.س)` : ''}</option>)}
      </select>
    </div>
  )
}
