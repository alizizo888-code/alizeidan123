import { useMemo, useState } from 'react'
import { KINDS, kindOf } from '../lib/custody'

// «محفظتي» (owner order 2026-11-22): the technician always sees his money — what he charged, what he withdrew,
// what the jobs consumed (the company's cut taken automatically from every finished job), penalties and bonuses —
// and a running statement where each job is a line of its own. The owner sees the same card in «إدارة الفني».
const money = (n) => `${(Math.round((Number(n) || 0) * 100) / 100).toLocaleString('ar-SA')} ر.س`

export default function WalletCard({ acc, lines, dark = true }) {
  const [open, setOpen] = useState(false)
  const pre = acc?.mode === 'prepaid'
  const rows = useMemo(() => {
    if (!acc) return []
    const r = []
    for (const j of acc.jobLines || []) {
      const num = String(j.orderId || '').slice(-5)
      if (pre) r.push({ at: j.at, icon: j.auto < 0 ? '⚙️' : '💼', text: j.auto < 0 ? `خصم تلقائي — نسبة الشركة ${100 - j.share}% من طلب #${num} (${money(j.amount)} ${j.cash ? 'كاش' : ''})` : `نصيبك ${j.share}% من طلب #${num} اتدفع للشركة`, amount: j.auto })
      else r.push({ at: j.at, icon: '💼', text: `طلب #${num} — ${money(j.amount)} · نصيبك ${j.share}%${j.cash ? ' · كاش معاك' : ''}`, amount: j.cash ? -(j.company) : j.mine, hint: j.cash ? 'نسبة الشركة عليك' : 'لك عند الشركة' })
    }
    for (const s of lines || []) {
      const k = kindOf(s); const m = KINDS[k]
      const sign = pre ? m.prepaid : -m.sign
      r.push({ at: s.at, icon: m.icon, text: `${m.label}${s.note ? ` — ${s.note}` : ''}`, amount: (Number(s.amount) || 0) * (sign || 1) })
    }
    return r.sort((a, b) => String(b.at).localeCompare(String(a.at)))
  }, [acc, lines, pre])
  if (!acc) return null
  const used = pre ? acc.cutCash : acc.commission
  const head = pre ? acc.credit : -acc.balance
  const box = dark ? 'bg-white/[0.04] border-white/10 text-white' : 'bg-white border-slate-200 text-slate-900'
  return (
    <section className={`rounded-2xl border p-3 grid gap-2 ${box}`}>
      <div className="flex items-center gap-2">
        <p className="flex-1 text-xs font-black">👛 محفظتي — {pre ? 'رصيد مسبق' : 'نظام نسبة'}</p>
        <span className={`text-sm font-black ${head < 0 ? 'text-rose-300' : 'text-emerald-300'}`}>{pre ? `رصيدك ${money(head)}` : head >= 0 ? `لك ${money(head)}` : `عليك ${money(-head)}`}</span>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5 text-center">
        {(pre
          ? [['شحنت', acc.topup], ['استهلاك الطلبات', acc.cutCash], ['سحبت', acc.settledOut + acc.refund], ['خصومات', acc.penalty], ['مكافآت', acc.bonus], ['نصيبك من التحويلات', acc.shareNonCash]]
          : [['نصيبك من الشغل', acc.commission], ['كاش حصّلته', acc.cash], ['سلّمت للشركة', acc.settledIn], ['اتحوّل لك', acc.settledOut], ['خصومات', acc.penalty], ['مكافآت', acc.bonus]]
        ).map(([l, v]) => <div key={l} className="rounded-xl bg-black/20 p-2"><p className="text-[10px] opacity-60 font-bold">{l}</p><p className="text-xs font-black">{money(v)}</p></div>)}
      </div>
      {pre && <p className="text-[10px] opacity-60">كل طلب كاش بيخلص بيتخصم من رصيدك نسبة الشركة تلقائي — وكل طلب العميل دفعه للشركة بيتضاف لك نصيبك.</p>}
      <button onClick={() => setOpen((x) => !x)} className="rounded-lg bg-black/20 py-1.5 text-[11px] font-black">{open ? 'إخفاء كشف الحساب' : `كشف الحساب (${rows.length} حركة)`}</button>
      {open && (
        <div className="grid gap-1 max-h-80 overflow-y-auto">
          {rows.map((r, i) => (
            <div key={i} className="flex items-center gap-2 text-[11px] border-b border-white/5 py-1">
              <span>{r.icon}</span><span className="flex-1 min-w-0 opacity-80">{r.text}</span>
              <b className={r.amount < 0 ? 'text-rose-300' : 'text-emerald-300'} dir="ltr">{r.amount > 0 ? '+' : ''}{money(r.amount)}</b>
              <span className="opacity-40">{r.at ? new Date(r.at).toLocaleDateString('ar-SA') : ''}</span>
            </div>
          ))}
          {!rows.length && <p className="text-[11px] opacity-50">لسه مفيش حركات.</p>}
        </div>
      )}
      {used > 0 && <p className="text-[10px] opacity-50">إجمالي {pre ? 'المستهلك من رصيدك' : 'نصيبك'} لحد دلوقتي: {money(used)}</p>}
    </section>
  )
}
