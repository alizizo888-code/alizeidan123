import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Wallet, HandCoins, Plus, Loader2 } from 'lucide-react'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'
import { useTeamRole } from '../hooks/useTeamRole'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { orderAmount } from '../payment'
import { specialtyLabel } from '../constants'
import AccessDenied from '../components/AccessDenied'

// Field finance for the owner: per technician — completed work, his commission (share % set in the operations room),
// cash he collected from customers (his custody), what has already been settled with him, and what is still due either way.
//   commission = price × share%      custody (cash in his hand) = cash collected      balance = custody − commission − settled
//   balance > 0 → he owes the company ;  balance < 0 → the company owes him.
const DONE = ['completed', 'closed']
const money = (n) => `${Math.round(n * 100) / 100} ر.س`
const dayKey = (iso) => (iso ? new Date(iso).toDateString() : '')
const isCash = (o) => /cash/i.test(String(o.paymentMethod || '')) || o.paymentMethod === 'نقدًا'

export default function Accounts() {
  const nav = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const { profiles } = useTechProfiles()
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 500 })
  const { data: payRows } = useLiveShared('tech_pay', { limit: 5 })
  const { data: setl } = useLiveShared('settlements', { order: '-at', limit: 500 })
  const share = Number(payRows?.[0]?.sharePct) || 0
  const [sel, setSel] = useState(null)
  const [amt, setAmt] = useState('')
  const [dir, setDir] = useState('in')      // in = technician hands cash to the company · out = company pays the technician
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const rows = useMemo(() => {
    const byTech = new Map()
    const get = (id, name) => { if (!byTech.has(id)) byTech.set(id, { id, name: name || 'فني', jobs: 0, sales: 0, commission: 0, cash: 0, settledIn: 0, settledOut: 0 }); return byTech.get(id) }
    for (const t of profiles) if (!t.isSite) get(t.userId, t.displayName)
    for (const o of orders || []) {
      if (!DONE.includes(o.status) || o.inhouse || !o.technicianId || o.dispatch === 'site') continue
      const r = get(o.technicianId, o.technicianName); const a = orderAmount(o)
      r.jobs++; r.sales += a; r.commission += a * share / 100
      if (o.paymentStatus === 'paid' && isCash(o)) r.cash += a
    }
    for (const s of setl || []) { const r = byTech.get(s.techId); if (!r) continue; if (s.dir === 'out') r.settledOut += Number(s.amount) || 0; else r.settledIn += Number(s.amount) || 0 }
    return [...byTech.values()].map((r) => ({ ...r, balance: r.cash - r.commission - r.settledIn + r.settledOut })).sort((a, b) => b.sales - a.sales)
  }, [profiles, orders, setl, share])

  const today = useMemo(() => {
    const k = new Date().toDateString(); const d = (orders || []).filter((o) => DONE.includes(o.status) && !o.inhouse && dayKey(o.completedAt || o.updatedAt) === k)
    const sales = d.reduce((s, o) => s + orderAmount(o), 0); const comm = d.filter((o) => o.technicianId && o.dispatch !== 'site').reduce((s, o) => s + orderAmount(o) * share / 100, 0)
    return { n: d.length, sales, comm, net: sales - comm }
  }, [orders, share])

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!isOwner && role !== 'operations') return <AccessDenied refresh={refresh} />
  const tot = rows.reduce((a, r) => ({ sales: a.sales + r.sales, commission: a.commission + r.commission, cash: a.cash + r.cash }), { sales: 0, commission: 0, cash: 0 })
  const cur = rows.find((r) => r.id === sel)

  async function save() {
    const n = Number(amt)
    if (!cur || !(n > 0)) { setErr('اكتب مبلغًا صحيحًا'); return }
    setBusy(true); setErr('')
    try {
      const gid = await team.groupId()
      await db.insertShared('settlements', { techId: cur.id, techName: cur.name, amount: n, dir, note: note.trim(), at: new Date().toISOString(), by: role }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
      setAmt(''); setNote(''); setSel(null)
    } catch (e) { setErr((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }

  return (
    <div className="h-full overflow-y-auto pb-24 text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1"><h1 className="font-display font-black text-base">الحسابات والعمولات والعهد</h1><p className="text-[11px] text-white/60">{share ? `نسبة الفني ${share}% من الخدمة` : 'حدّد نسبة الفني من غرفة العمليات ← استقبال الطلبات'}</p></div>
      </header>
      <div className="p-4 max-w-3xl mx-auto grid gap-4">
        <section className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          {[['طلبات اليوم', today.n], ['مبيعات اليوم', money(today.sales)], ['عمولات اليوم', money(today.comm)], ['صافي اليوم', money(today.net)]].map(([l, v]) => <div key={l} className="rounded-xl bg-white/[0.05] border border-white/10 p-3 text-center"><p className="text-lg font-black">{v}</p><p className="text-[10px] text-slate-400 font-bold">{l}</p></div>)}
        </section>
        <section className="grid grid-cols-3 gap-2 text-center text-[11px] font-bold">
          <div className="rounded-xl bg-sky-500/10 border border-sky-400/20 p-2">إجمالي المبيعات<p className="text-sm font-black">{money(tot.sales)}</p></div>
          <div className="rounded-xl bg-amber-500/10 border border-amber-400/20 p-2">إجمالي العمولات<p className="text-sm font-black">{money(tot.commission)}</p></div>
          <div className="rounded-xl bg-emerald-500/10 border border-emerald-400/20 p-2">نقد بيد الفنيين<p className="text-sm font-black">{money(tot.cash)}</p></div>
        </section>
        <section className="grid gap-2">
          {rows.length === 0 && <p className="text-slate-500 text-sm text-center py-8">لا فنيين أو طلبات مكتملة بعد.</p>}
          {rows.map((r) => (
            <div key={r.id} className="rounded-2xl bg-white/[0.05] border border-white/10 p-3">
              <div className="flex items-center gap-2"><p className="flex-1 font-black text-sm">{r.name}</p><span className="text-[11px] text-slate-400">{r.jobs} طلب مكتمل</span></div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5 mt-2 text-[11px] text-slate-300">
                <p>المبيعات: <b className="text-white">{money(r.sales)}</b></p><p>عمولته: <b className="text-amber-300">{money(r.commission)}</b></p>
                <p>نقد محصَّل: <b className="text-white">{money(r.cash)}</b></p><p>سُوّي: <b className="text-white">{money(r.settledIn - r.settledOut)}</b></p>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <p className={`flex-1 text-xs font-black ${r.balance > 0 ? 'text-rose-300' : r.balance < 0 ? 'text-emerald-300' : 'text-slate-300'}`}>{r.balance > 0 ? `عليه للشركة ${money(r.balance)}` : r.balance < 0 ? `له من الشركة ${money(-r.balance)}` : 'الحساب متزن ✓'}</p>
                <button onClick={() => { setSel(sel === r.id ? null : r.id); setDir(r.balance < 0 ? 'out' : 'in'); setAmt(r.balance ? String(Math.abs(Math.round(r.balance * 100) / 100)) : ''); setErr('') }} className="rounded-lg bg-primary text-black text-xs font-black px-3 py-1.5 flex items-center gap-1"><HandCoins size={13} />تسوية</button>
              </div>
              {sel === r.id && (
                <div className="mt-2 rounded-xl bg-black/30 border border-white/10 p-3 grid gap-2">
                  <div className="flex gap-2 text-xs font-bold">
                    <button onClick={() => setDir('in')} className={`flex-1 rounded-lg py-2 border ${dir === 'in' ? 'bg-rose-500/30 border-rose-300' : 'border-white/10'}`}>استلمت منه نقدًا</button>
                    <button onClick={() => setDir('out')} className={`flex-1 rounded-lg py-2 border ${dir === 'out' ? 'bg-emerald-500/30 border-emerald-300' : 'border-white/10'}`}>دفعت له عمولته</button>
                  </div>
                  <input value={amt} onChange={(e) => setAmt(e.target.value)} inputMode="decimal" dir="ltr" placeholder="المبلغ ر.س" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm" />
                  <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="ملاحظة (اختياري)" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm" />
                  {err && <p className="text-xs font-bold text-rose-300">{err}</p>}
                  <button onClick={save} disabled={busy} className="rounded-lg bg-emerald-500 text-black font-black py-2 text-sm flex items-center justify-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}تسجيل التسوية</button>
                </div>
              )}
            </div>
          ))}
        </section>
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3">
          <p className="text-xs font-black mb-2 flex items-center gap-1"><Wallet size={14} />آخر التسويات</p>
          {(setl || []).slice(0, 12).map((s) => <p key={s.id} className="text-[11px] text-slate-300 py-1 border-b border-white/5">{new Date(s.at).toLocaleDateString('ar-SA')} · {s.techName} · {s.dir === 'out' ? 'دُفع له' : 'استُلم منه'} <b className="text-white">{money(Number(s.amount))}</b>{s.note ? ` — ${s.note}` : ''}</p>)}
          {!(setl || []).length && <p className="text-[11px] text-slate-500">لا توجد تسويات بعد.</p>}
        </section>
        <p className="text-[10px] text-slate-500 leading-relaxed">المعادلة: الرصيد = النقد المحصَّل − العمولة − ما استُلم منه + ما دُفع له. الطلبات المنفّذة بفني الموقع تُستثنى لأنها تنفيذ داخلي. العمولة تُحتسب بنسبة الفني الحالية على الطلبات المكتملة.</p>
      </div>
    </div>
  )
}
