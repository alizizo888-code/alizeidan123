import { useMemo, useState } from 'react'
import { techPerformance, teamPerformance } from '../../lib/techPerf'
import { minsText } from '../../lib/orderWatch'

const money = (n) => `${Math.round(Number(n) || 0).toLocaleString('ar-SA')} ر.س`
const PERIODS = [[7, 'آخر 7 أيام'], [30, 'آخر 30 يوم'], [90, 'آخر 3 شهور']]

/** One technician's card (in «إدارة الفني»). */
export function TechPerfCard({ tech, orders }) {
  const [days, setDays] = useState(30)
  const p = useMemo(() => techPerformance(tech.userId || tech.id, orders, { days }), [tech, orders, days])
  const tiles = [
    ['استلم', p.received], ['خلّص', p.done], ['شغال دلوقتي', p.open], ['ما قبلش (اتحوّل)', p.passed], ['ما قدرش يصلّح', p.aborted], ['اتلغى', p.cancelled],
    ['متوسط القبول', minsText(p.acceptAvg)], ['متوسط الوصول', minsText(p.arriveAvg)], ['🏅 السكور', `${p.score}/100`], ['التقييم', p.rating ? `⭐ ${p.rating} (${p.ratedCount})` : '—'], ['🌟 اتشكر فيه', p.praised], ['مفتوحة +يوم', p.stuck], ['قفلتها العمليات', p.opsClosed], ['مرتجعات', p.returns], ['شكاوى', p.complaints], ['نسبة الإنجاز', p.doneRate == null ? '—' : p.doneRate + '%'], ['الإيراد', money(p.revenue)],
  ]
  return (
    <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-3 grid gap-2" data-tech-perf>
      <div className="flex items-center gap-2">
        <p className="flex-1 text-xs font-black text-white">📊 أداء {tech.displayName || 'الفني'}</p>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} className="bg-white/10 border border-white/15 rounded-lg px-2 py-1 text-[11px] text-white">{PERIODS.map(([d, l]) => <option key={d} value={d} className="text-black">{l}</option>)}</select>
      </div>
      <div className="grid grid-cols-3 sm:grid-cols-4 gap-1.5 text-center">
        {tiles.map(([l, v]) => <div key={l} className="rounded-xl bg-black/20 p-2"><p className="text-[10px] text-slate-400 font-bold">{l}</p><p className="text-xs font-black text-white" dir="auto">{v}</p></div>)}
      </div>
    </section>
  )
}

/** The whole team side by side (on the orders board). */
export default function TechPerformanceTable({ profiles, orders }) {
  const [days, setDays] = useState(30)
  const rows = useMemo(() => teamPerformance(profiles, orders, { days }), [profiles, orders, days])
  if (!rows.length) return null
  return (
    <section className="mt-4 rounded-2xl border border-white/10 bg-white/[0.03] p-3 grid gap-2" data-team-perf>
      <div className="flex items-center gap-2">
        <p className="flex-1 text-sm font-black text-white">📊 أداء الفنيين</p>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} className="bg-white/10 border border-white/15 rounded-lg px-2 py-1 text-[11px] text-white">{PERIODS.map(([d, l]) => <option key={d} value={d} className="text-black">{l}</option>)}</select>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-right text-[11px] min-w-[760px]">
          <thead className="text-slate-400"><tr>{['الفني', 'استلم', 'خلّص', 'ما قبلش', 'ما قدرش', 'متوسط القبول', 'متوسط الوصول', 'التقييم', 'شكاوى', 'الإنجاز', 'الإيراد'].map((h) => <th key={h} className="px-2 py-1.5 font-black whitespace-nowrap">{h}</th>)}</tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t border-white/5 text-slate-200" data-team-perf-row>
                <td className="px-2 py-1.5 font-black text-white whitespace-nowrap">{r.tech.displayName || 'فني'}{r.tech.isSite ? ' 🏠' : ''}</td>
                <td className="px-2 py-1.5">{r.received}</td>
                <td className="px-2 py-1.5 text-emerald-300 font-bold">{r.done}</td>
                <td className={`px-2 py-1.5 ${r.passed ? 'text-amber-300 font-bold' : ''}`}>{r.passed}</td>
                <td className={`px-2 py-1.5 ${r.aborted ? 'text-rose-300 font-bold' : ''}`}>{r.aborted}</td>
                <td className="px-2 py-1.5" dir="ltr">{minsText(r.acceptAvg)}</td>
                <td className="px-2 py-1.5" dir="ltr">{minsText(r.arriveAvg)}</td>
                <td className="px-2 py-1.5">{r.rating ? `⭐ ${r.rating}` : '—'} <span className="text-amber-300 font-bold">· 🏅{r.score}</span></td>
                <td className={`px-2 py-1.5 ${r.complaints ? 'text-rose-300 font-bold' : ''}`}>{r.complaints}</td>
                <td className="px-2 py-1.5">{r.doneRate == null ? '—' : r.doneRate + '%'}</td>
                <td className="px-2 py-1.5 whitespace-nowrap">{money(r.revenue)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
