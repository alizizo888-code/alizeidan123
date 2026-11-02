import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Download } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { customerKey } from '../lib/crm'
import { brandMeta } from '../lib/netBrand'
import { STATUS_META } from '../constants'
import { DONE } from '../components/ops/helpers'

// «التقارير»: one report screen over the same live data the operations room uses — per site (the 88 sites), per
// technician, per customer (new / returning / old), per shop and per status, for a chosen period, with CSV export.
const TABS = [['sites', 'المواقع'], ['techs', 'الفنيين'], ['customers', 'العملاء'], ['shops', 'المحلات'], ['status', 'الحالات']]
const PERIODS = [['7', 'آخر 7 أيام'], ['30', 'آخر 30 يوم'], ['90', 'آخر 3 شهور'], ['all', 'الكل']]
const DAY = 86400000
const t = (v) => { const x = Date.parse(v); return Number.isFinite(x) ? x : 0 }
const money = (o) => Number(o.finalPrice ?? o.price ?? o.total ?? 0) || 0
const fmt = (n) => Math.round(n).toLocaleString('ar-EG')
const date = (v) => (t(v) ? new Date(t(v)).toLocaleDateString('ar-EG') : '—')

function csv(name, head, rows) {
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const blob = new Blob(['﻿' + [head, ...rows].map((r) => r.map(esc).join(',')).join('\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name + '.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000)
}

export default function Reports() {
  const nav = useNavigate()
  const [sp, setSp] = useSearchParams()
  const tab = TABS.some((x) => x[0] === sp.get('tab')) ? sp.get('tab') : 'sites'
  const [period, setPeriod] = useState('30')
  const { isOwner, role, loading } = useTeamRole()
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 2000 })
  const { data: techs } = useLiveShared('tech_profiles', { limit: 300 })
  const { data: shops } = useLiveShared('shops', { limit: 300 })

  const all = orders || []
  const inPeriod = useMemo(() => (period === 'all' ? all : all.filter((o) => Date.now() - t(o.createdAt) <= Number(period) * DAY)), [all, period])

  const table = useMemo(() => {
    const done = (o) => DONE.includes(o.status)
    const cancelled = (o) => o.status === 'cancelled'
    if (tab === 'sites') {
      const m = new Map()
      for (const o of inPeriod) { const k = o.brand || 'mutqan'; const r = m.get(k) || { n: 0, d: 0, c: 0, rev: 0 }; r.n++; if (done(o)) { r.d++; r.rev += money(o) } if (cancelled(o)) r.c++; m.set(k, r) }
      return { head: ['الموقع', 'الطلبات', 'اتنفذت', 'اتلغت', 'الإيراد'], rows: [...m].sort((a, b) => b[1].n - a[1].n).map(([k, r]) => [brandMeta(k)?.name || (k === 'mutqan' ? 'أكسجين' : k), r.n, r.d, r.c, fmt(r.rev)]) }
    }
    if (tab === 'techs') {
      const name = Object.fromEntries((techs || []).map((x) => [x.userId || x.id, x.displayName || x.name || 'فني']))
      const m = new Map()
      for (const x of techs || []) m.set(x.userId || x.id, { n: 0, d: 0, c: 0, rev: 0, last: 0 })
      for (const o of inPeriod) { if (!o.technicianId) continue; const r = m.get(o.technicianId) || { n: 0, d: 0, c: 0, rev: 0, last: 0 }; r.n++; if (done(o)) { r.d++; r.rev += money(o) } if (cancelled(o)) r.c++; r.last = Math.max(r.last, t(o.createdAt)); m.set(o.technicianId, r) }
      return { head: ['الفني', 'الطلبات', 'اتنفذت', 'اتلغت', 'نسبة الإنجاز', 'الإيراد', 'آخر طلب'], rows: [...m].sort((a, b) => b[1].d - a[1].d).map(([k, r]) => [name[k] || o2(k), r.n, r.d, r.c, r.n ? Math.round((r.d / r.n) * 100) + '%' : '—', fmt(r.rev), r.last ? date(r.last) : '—']) }
    }
    if (tab === 'customers') {
      // Customer history is counted over ALL orders (an «old customer» is old regardless of the period); the period picks who appears.
      const m = new Map()
      for (const o of all) { const k = customerKey(o); const r = m.get(k) || { name: '', phone: '', n: 0, rev: 0, first: Infinity, last: 0, brand: '' }; r.n++; r.rev += done(o) ? money(o) : 0; const ts = t(o.createdAt); if (ts < r.first) r.first = ts; if (ts >= r.last) { r.last = ts; r.name = o.clientName || r.name; r.phone = o.clientPhone || r.phone; r.brand = o.brand || r.brand } m.set(k, r) }
      const since = period === 'all' ? 0 : Date.now() - Number(period) * DAY
      const kind = (r) => (r.n > 1 ? (Date.now() - r.last > 90 * DAY ? 'قديم (منقطع)' : 'متكرر') : Date.now() - r.first < 30 * DAY ? 'جديد' : 'قديم (مرة واحدة)')
      return { head: ['العميل', 'الجوال', 'النوع', 'الطلبات', 'الإيراد', 'أول طلب', 'آخر طلب', 'الموقع'], rows: [...m.values()].filter((r) => r.last >= since).sort((a, b) => b.n - a.n || b.last - a.last).map((r) => [r.name || '—', r.phone || '—', kind(r), r.n, fmt(r.rev), date(r.first), date(r.last), brandMeta(r.brand)?.name || 'أكسجين']) }
    }
    if (tab === 'shops') {
      return { head: ['المحل', 'الجوال', 'متاح', 'التخصصات', 'آخر تحديث'], rows: (shops || []).map((s) => [s.name || '—', s.phone || '—', s.available === false ? 'لا' : 'نعم', s.specialties || '—', date(s.lastSeenAt)]) }
    }
    const m = {}
    for (const o of inPeriod) m[o.status] = (m[o.status] || 0) + 1
    return { head: ['الحالة', 'العدد', 'النسبة'], rows: Object.entries(m).sort((a, b) => b[1] - a[1]).map(([k, n]) => [STATUS_META[k]?.label || k, n, Math.round((n / (inPeriod.length || 1)) * 100) + '%']) }
  }, [tab, inPeriod, all, techs, shops, period])

  const sum = useMemo(() => {
    const d = inPeriod.filter((o) => DONE.includes(o.status))
    const keys = new Set(inPeriod.map(customerKey))
    return [['الطلبات', inPeriod.length], ['اتنفذت', d.length], ['الإيراد', fmt(d.reduce((s, o) => s + money(o), 0))], ['عملاء', keys.size], ['مواقع', new Set(inPeriod.map((o) => o.brand || 'mutqan')).size]]
  }, [inPeriod])

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!(isOwner || role === 'operations' || role === 'supervisor')) return <p className="p-6 text-white text-sm font-bold" dir="rtl">التقارير للمالك ومشرف العمليات.</p>

  return (
    <div className="min-h-full text-white pb-10" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3">
        <div className="max-w-6xl mx-auto flex items-center gap-3">
          <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
          <h1 className="flex-1 font-display font-black text-base">التقارير</h1>
          <label className="sr-only" htmlFor="rep-period">الفترة</label>
          <select id="rep-period" value={period} onChange={(e) => setPeriod(e.target.value)} className="rounded-lg bg-white/10 border border-white/15 text-[13px] px-2 py-1.5">
            {PERIODS.map(([v, l]) => <option key={v} value={v} className="text-black">{l}</option>)}
          </select>
          <button onClick={() => csv('تقرير-' + tab, table.head, table.rows)} className="rounded-lg bg-primary text-white text-[13px] font-bold px-3 py-1.5 flex items-center gap-1"><Download size={14} />تصدير</button>
        </div>
        <div className="max-w-6xl mx-auto flex gap-2 overflow-x-auto mt-3">
          {TABS.map(([id, l]) => <button key={id} onClick={() => setSp({ tab: id }, { replace: true })} className={`shrink-0 rounded-full px-3 py-1.5 text-[12px] font-bold border ${tab === id ? 'bg-primary/25 border-primary/50' : 'bg-white/5 border-white/10'}`}>{l}</button>)}
        </div>
      </header>

      <div className="max-w-6xl mx-auto px-4">
        <div className="grid grid-cols-5 gap-2 mt-4">
          {sum.map(([l, v]) => <div key={l} className="rounded-xl bg-white/5 border border-white/10 px-2.5 py-2"><p className="text-lg font-black tabular-nums">{v}</p><p className="text-[11px] text-white/60">{l}</p></div>)}
        </div>
        <div className="mt-4 overflow-x-auto rounded-xl border border-white/10">
          <table className="w-full text-[13px]">
            <thead className="bg-white/5"><tr>{table.head.map((h) => <th key={h} className="text-right font-bold px-3 py-2 whitespace-nowrap">{h}</th>)}</tr></thead>
            <tbody>
              {table.rows.map((r, i) => <tr key={i} className="border-t border-white/5">{r.map((c, j) => <td key={j} className="px-3 py-2 whitespace-nowrap tabular-nums">{c}</td>)}</tr>)}
              {!table.rows.length && <tr><td colSpan={table.head.length} className="px-3 py-6 text-center text-white/55">مفيش بيانات في الفترة دي.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
function o2(id) { return 'فني ' + String(id).slice(-4) }
