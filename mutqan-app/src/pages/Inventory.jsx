import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Package, Plus, Loader2, AlertTriangle } from 'lucide-react'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'
import { useTeamRole } from '../hooks/useTeamRole'
import { useTechProfiles } from '../hooks/useTechProfiles'
import AccessDenied from '../components/AccessDenied'

// Stock and technician custody. Every change is a movement (append-only), balances are computed from them:
//   in      → central store +qty            issue  → central −qty, technician +qty
//   return  → technician −qty, central +qty  use    → technician −qty (consumed on a job)
const KINDS = { in: 'وارد للمخزن', issue: 'صرف لفني', return: 'إرجاع من فني', use: 'استهلاك في طلب' }
const CATS = ['فريون وغازات', 'مواسير نحاس وعوازل', 'قطع غيار', 'مواد غسيل', 'أخرى']
const n2 = (x) => Math.round(x * 100) / 100

export default function Inventory() {
  const nav = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const { profiles } = useTechProfiles()
  const { data: items } = useLiveShared('stock_items', { order: 'name', limit: 300 })
  const { data: moves } = useLiveShared('stock_moves', { order: '-at', limit: 1000 })
  const [nf, setNf] = useState({ name: '', unit: 'قطعة', cat: CATS[0], min: '' })
  const [mv, setMv] = useState({ itemId: '', kind: 'in', techId: '', qty: '', note: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const calc = useMemo(() => {
    const central = {}, tech = {}
    for (const m of moves || []) {
      const q = Number(m.qty) || 0
      if (m.kind === 'in') central[m.itemId] = (central[m.itemId] || 0) + q
      else if (m.kind === 'issue') { central[m.itemId] = (central[m.itemId] || 0) - q; (tech[m.techId] ||= {})[m.itemId] = ((tech[m.techId] || {})[m.itemId] || 0) + q }
      else if (m.kind === 'return') { central[m.itemId] = (central[m.itemId] || 0) + q; (tech[m.techId] ||= {})[m.itemId] = ((tech[m.techId] || {})[m.itemId] || 0) - q }
      else if (m.kind === 'use') (tech[m.techId] ||= {})[m.itemId] = ((tech[m.techId] || {})[m.itemId] || 0) - q
    }
    return { central, tech }
  }, [moves])

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!isOwner && role !== 'operations') return <AccessDenied refresh={refresh} />
  const techs = profiles.filter((t) => !t.isSite)
  const byId = Object.fromEntries((items || []).map((i) => [i.id, i]))
  const low = (items || []).filter((i) => Number(i.min) > 0 && (calc.central[i.id] || 0) <= Number(i.min))
  const gopts = async () => ({ groupId: await team.groupId(), visibleTo: 'group', writableBy: 'admins' })

  async function addItem() {
    if (!nf.name.trim()) { setErr('اكتب اسم الصنف'); return }
    setBusy(true); setErr('')
    try { await db.insertShared('stock_items', { name: nf.name.trim(), unit: nf.unit.trim() || 'قطعة', cat: nf.cat, min: Number(nf.min) || 0 }, undefined, await gopts()); setNf({ ...nf, name: '', min: '' }) }
    catch (e) { setErr((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }
  async function addMove() {
    const q = Number(mv.qty)
    if (!mv.itemId || !(q > 0)) { setErr('اختر الصنف واكتب كمية صحيحة'); return }
    if (mv.kind !== 'in' && !mv.techId) { setErr('اختر الفني'); return }
    if (mv.kind === 'issue' && (calc.central[mv.itemId] || 0) < q) { setErr('الكمية أكبر من رصيد المخزن'); return }
    if ((mv.kind === 'return' || mv.kind === 'use') && ((calc.tech[mv.techId] || {})[mv.itemId] || 0) < q) { setErr('الكمية أكبر من عهدة الفني'); return }
    setBusy(true); setErr('')
    try {
      const t = techs.find((x) => x.userId === mv.techId)
      await db.insertShared('stock_moves', { itemId: mv.itemId, itemName: byId[mv.itemId]?.name, kind: mv.kind, qty: q, techId: mv.kind === 'in' ? '' : mv.techId, techName: mv.kind === 'in' ? '' : (t?.displayName || ''), note: mv.note.trim(), at: new Date().toISOString(), by: role }, undefined, await gopts())
      setMv({ ...mv, qty: '', note: '' })
    } catch (e) { setErr((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }
  const inp = 'rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white'
  return (
    <div className="h-full overflow-y-auto pb-24 text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1"><h1 className="font-display font-black text-base">المخزون وعهدة الفنيين</h1><p className="text-[11px] text-white/60">فريون · مواسير · قطع غيار · مواد غسيل</p></div>
      </header>
      <div className="p-4 max-w-3xl mx-auto grid gap-4">
        {low.length > 0 && <div className="rounded-xl bg-rose-500/15 border border-rose-400/40 p-3 text-xs font-bold text-rose-200 flex gap-2"><AlertTriangle size={16} className="shrink-0" />نقص في المخزن: {low.map((i) => `${i.name} (${n2(calc.central[i.id] || 0)})`).join('، ')}</div>}
        <section className="rounded-2xl bg-white/[0.05] border border-white/10 p-3 grid gap-2">
          <p className="text-xs font-black flex items-center gap-1"><Package size={14} />حركة جديدة</p>
          <select aria-label="الصنف" value={mv.itemId} onChange={(e) => setMv({ ...mv, itemId: e.target.value })} className={inp}><option value="" className="text-black">— الصنف —</option>{(items || []).map((i) => <option key={i.id} value={i.id} className="text-black">{i.name}</option>)}</select>
          <div className="flex gap-1.5 flex-wrap">{Object.entries(KINDS).map(([k, l]) => <button key={k} onClick={() => setMv({ ...mv, kind: k })} className={`rounded-lg px-3 py-1.5 text-xs font-bold border ${mv.kind === k ? 'bg-primary text-black border-white/60' : 'border-white/10 text-slate-300'}`}>{l}</button>)}</div>
          {mv.kind !== 'in' && <select aria-label="الفني" value={mv.techId} onChange={(e) => setMv({ ...mv, techId: e.target.value })} className={inp}><option value="" className="text-black">— الفني —</option>{techs.map((t) => <option key={t.userId} value={t.userId} className="text-black">{t.displayName || 'فني'}</option>)}</select>}
          <input value={mv.qty} onChange={(e) => setMv({ ...mv, qty: e.target.value })} inputMode="decimal" dir="ltr" placeholder="الكمية" className={inp} />
          <input value={mv.note} onChange={(e) => setMv({ ...mv, note: e.target.value })} placeholder="ملاحظة / رقم طلب (اختياري)" className={inp} />
          {err && <p className="text-xs font-bold text-rose-300">{err}</p>}
          <button onClick={addMove} disabled={busy} className="rounded-lg bg-emerald-500 text-black font-black py-2 text-sm flex items-center justify-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}تسجيل الحركة</button>
        </section>
        <section className="grid gap-2">
          <p className="text-xs font-black">الأصناف والأرصدة</p>
          {!(items || []).length && <p className="text-slate-500 text-sm text-center py-4">أضف أول صنف من الأسفل.</p>}
          {(items || []).map((i) => { const held = Object.entries(calc.tech).map(([tid, o]) => [tid, o[i.id] || 0]).filter(([, q]) => q > 0); const c = calc.central[i.id] || 0
            return <div key={i.id} className="rounded-xl bg-white/[0.05] border border-white/10 p-3">
              <div className="flex items-center gap-2"><p className="flex-1 font-black text-sm">{i.name} <span className="text-[10px] text-slate-400 font-bold">· {i.cat}</span></p><b className={Number(i.min) > 0 && c <= Number(i.min) ? 'text-rose-300' : 'text-emerald-300'}>{n2(c)} {i.unit}</b></div>
              {held.length > 0 && <p className="text-[11px] text-slate-300 mt-1">عند الفنيين: {held.map(([tid, q]) => `${techs.find((t) => t.userId === tid)?.displayName || 'فني'} ${n2(q)}`).join(' · ')}</p>}
            </div> })}
        </section>
        <section className="rounded-2xl bg-white/[0.05] border border-white/10 p-3 grid gap-2">
          <p className="text-xs font-black">صنف جديد</p>
          <input value={nf.name} onChange={(e) => setNf({ ...nf, name: e.target.value })} placeholder="اسم الصنف (مثال: فريون R410A)" className={inp} />
          <div className="grid grid-cols-3 gap-2"><input value={nf.unit} onChange={(e) => setNf({ ...nf, unit: e.target.value })} placeholder="الوحدة" className={inp} /><select value={nf.cat} onChange={(e) => setNf({ ...nf, cat: e.target.value })} className={inp}>{CATS.map((c) => <option key={c} className="text-black">{c}</option>)}</select><input value={nf.min} onChange={(e) => setNf({ ...nf, min: e.target.value })} inputMode="decimal" dir="ltr" placeholder="حد التنبيه" className={inp} /></div>
          <button onClick={addItem} disabled={busy} className="rounded-lg bg-primary text-black font-black py-2 text-sm">إضافة الصنف</button>
        </section>
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3">
          <p className="text-xs font-black mb-2">آخر الحركات</p>
          {(moves || []).slice(0, 15).map((m) => <p key={m.id} className="text-[11px] text-slate-300 py-1 border-b border-white/5">{new Date(m.at).toLocaleDateString('ar-SA')} · {KINDS[m.kind]} · {m.itemName} <b className="text-white">{n2(Number(m.qty))}</b>{m.techName ? ` · ${m.techName}` : ''}{m.note ? ` — ${m.note}` : ''}</p>)}
        </section>
      </div>
    </div>
  )
}
