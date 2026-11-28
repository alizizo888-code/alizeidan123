import { useMemo } from 'react'
import { isSalary, payroll, goodJob } from '../lib/salary'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Printer } from 'lucide-react'
import { auth } from '../lib/auth'
import { useLiveShared } from '../lib/useLive'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { orderAmount } from '../payment'
import { countsFor, isCash, KINDS, kindOf } from '../lib/custody'
import { BN } from '../lib/netBrand'
import { orderNo } from '../lib/orderNo'

// «كشف الحساب الشهري» (owner order 2026-11-22): one month of a technician's work and money, laid out to print or
// save as PDF from the phone. The technician opens his own (the WhatsApp link on the 1st of every month);
// the owner opens anyone's with ?tech=<id>.
const money = (n) => `${(Math.round((Number(n) || 0) * 100) / 100).toLocaleString('ar-SA')} ر.س`

export default function TechStatement() {
  const navigate = useNavigate()
  const [q] = useSearchParams()
  const month = /^\d{4}-\d{2}$/.test(q.get('month') || '') ? q.get('month') : new Date().toISOString().slice(0, 7)
  const me = auth.getCurrentUser()?.id
  const techId = q.get('tech') || me
  const { profiles } = useTechProfiles()
  const tech = (profiles || []).find((p) => p.userId === techId) || {}
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 1500 })
  const { data: setl } = useLiveShared('settlements', { filters: { techId: techId || '__none__' }, order: '-at', limit: 500 })
  const { data: pay } = useLiveShared('tech_pay', { limit: 1 })
  const { data: wr } = useLiveShared('tech_wallet', { filters: { techId: techId || '__none__' }, limit: 1 })
  const w = (wr || [])[0] || {}
  const share = Number(w.sharePct) > 0 ? Number(w.sharePct) : Number(pay?.[0]?.sharePct) || 0
  const cshare = Number(w.cashSharePct) > 0 ? Number(w.cashSharePct) : Number(pay?.[0]?.cashSharePct) || share
  const inMonth = (iso) => String(iso || '').slice(0, 7) === month

  const jobs = useMemo(() => (orders || []).filter((o) => countsFor(o, techId) && inMonth(o.completedAt || o.updatedAt)).map((o) => {
    const a = orderAmount(o); const cash = o.paymentStatus !== 'paid' || isCash(o); const sh = cash ? cshare : share
    return { o, a, cash, sh, mine: a * sh / 100 }
  }), [orders, techId, month, share, cshare]) // eslint-disable-line
  const lines = useMemo(() => (setl || []).filter((s) => inMonth(s.at)), [setl, month]) // eslint-disable-line
  const pr = isSalary(w) ? payroll(techId, orders, setl, w, month) : null
  const t = jobs.reduce((x, j) => ({ sales: x.sales + j.a, mine: x.mine + j.mine, cash: x.cash + (j.cash && j.o.paymentStatus === 'paid' ? j.a : 0) }), { sales: 0, mine: 0, cash: 0 })
  const byKind = lines.reduce((x, s) => { const k = kindOf(s); x[k] = (x[k] || 0) + (Number(s.amount) || 0); return x }, {})
  const [y, m] = month.split('-').map(Number)
  const prev = new Date(Date.UTC(y, m - 2, 1)).toISOString().slice(0, 7)
  const next = new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 7)
  const go = (mm) => navigate(`/staff/statement?month=${mm}${q.get('tech') ? `&tech=${q.get('tech')}` : ''}`, { replace: true })

  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-white text-slate-900 pb-16" dir="rtl">
      <style>{'@media print{.noprint{display:none!important}body{background:#fff}}'}</style>
      <header className="noprint sticky top-0 z-10 bg-white/95 border-b border-slate-200 px-4 py-3 flex items-center gap-2 pt-[calc(env(safe-area-inset-top,0px)+0.75rem)]">
        <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center" aria-label="رجوع"><ChevronRight size={18} /></button>
        <button onClick={() => go(prev)} className="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold">‹ الشهر السابق</button>
        <button onClick={() => go(next)} className="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold">التالي ›</button>
        <button onClick={() => window.print()} className="mr-auto rounded-lg bg-slate-900 text-white px-3 py-1.5 text-xs font-black flex items-center gap-1"><Printer size={14} /> حفظ PDF / طباعة</button>
      </header>
      <main className="max-w-3xl mx-auto px-5 py-5">
        <div className="flex items-start gap-3 border-b-2 border-slate-900 pb-3">
          <div className="flex-1">
            <h1 className="text-xl font-black">كشف حساب الفني</h1>
            <p className="text-sm text-slate-600">{BN || ''}</p>
          </div>
          <div className="text-left text-sm">
            <p className="font-black">{tech.displayName || 'فني'}</p>
            <p className="text-slate-600">شهر {month}</p>
            <p className="text-slate-500 text-xs">{pr ? `نظام راتب · ${money(pr.salary)} شهريًا` : <>{w.mode === 'prepaid' ? 'نظام رصيد مسبق' : 'نظام نسبة'} · نصيبك {share}%{cshare !== share ? ` (كاش ${cshare}%)` : ''}</>}</p>
          </div>
        </div>
        <section className="grid grid-cols-2 sm:grid-cols-4 gap-2 my-4 text-center">
          {(pr ? [['طلبات مكتملة', jobs.length], ['طلبات تمام', pr.good], ['المكافآت', money(pr.autoBonus + pr.bonus)], ['نقد حصّلته', money(t.cash)]] : [['طلبات مكتملة', jobs.length], ['المبيعات', money(t.sales)], ['نصيبك', money(t.mine)], ['نقد حصّلته', money(t.cash)]]).map(([l, v]) => <div key={l} className="rounded-xl border border-slate-200 p-2"><p className="text-[11px] text-slate-500">{l}</p><p className="font-black">{v}</p></div>)}
        </section>
        <h2 className="font-black text-sm mb-1">الطلبات</h2>
        <table className="w-full text-[12px] border-collapse mb-4">
          <thead><tr className="bg-slate-100"><th className="p-1.5 text-right">التاريخ</th><th className="p-1.5 text-right">الطلب</th><th className="p-1.5">القيمة</th><th className="p-1.5">الدفع</th><th className="p-1.5">{pr ? 'مكافأة' : 'نصيبك'}</th></tr></thead>
          <tbody>
            {jobs.map((j) => <tr key={j.o.id} className="border-b border-slate-100"><td className="p-1.5">{new Date(j.o.completedAt || j.o.updatedAt).toLocaleDateString('ar-SA')}</td><td className="p-1.5">#{orderNo(j.o)} {j.o.clientName ? `— ${String(j.o.clientName).split(' ')[0]}` : ''}</td><td className="p-1.5 text-center">{money(j.a)}</td><td className="p-1.5 text-center">{j.cash ? 'كاش' : 'تحويل/شبكة'}</td><td className="p-1.5 text-center font-bold">{pr ? (goodJob(j.o) ? `+${pr.per}` : '—') : <>{money(j.mine)} <span className="text-slate-400">({j.sh}%)</span></>}</td></tr>)}
            {!jobs.length && <tr><td colSpan={5} className="p-3 text-center text-slate-400">مفيش طلبات مكتملة في الشهر ده</td></tr>}
          </tbody>
        </table>
        <h2 className="font-black text-sm mb-1">الحركات المالية</h2>
        <table className="w-full text-[12px] border-collapse mb-4">
          <thead><tr className="bg-slate-100"><th className="p-1.5 text-right">التاريخ</th><th className="p-1.5 text-right">البيان</th><th className="p-1.5">المبلغ</th></tr></thead>
          <tbody>
            {lines.map((s) => <tr key={s.id} className="border-b border-slate-100"><td className="p-1.5">{new Date(s.at).toLocaleDateString('ar-SA')}</td><td className="p-1.5">{KINDS[kindOf(s)].icon} {KINDS[kindOf(s)].label}{s.note ? ` — ${s.note}` : ''}</td><td className="p-1.5 text-center font-bold">{money(s.amount)}</td></tr>)}
            {!lines.length && <tr><td colSpan={3} className="p-3 text-center text-slate-400">مفيش حركات</td></tr>}
          </tbody>
        </table>
        <section className="rounded-xl border-2 border-slate-900 p-3 text-sm grid gap-1">
          {Object.entries(byKind).map(([k, v]) => <p key={k} className="flex justify-between"><span>{KINDS[k]?.label}</span><b>{money(v)}</b></p>)}
          {pr ? (
            <>
              <p className="flex justify-between"><span>الراتب</span><b>{money(pr.salary)}</b></p>
              <p className="flex justify-between"><span>مكافأة الطلبات ({pr.good} × {pr.per})</span><b>{money(pr.orderBonus)}</b></p>
              {pr.tier && <p className="flex justify-between"><span>مكافأة {pr.tier.n} طلب</span><b>{money(pr.tier.amt)}</b></p>}
              {pr.autoBonus < pr.orderBonus + (pr.tier ? pr.tier.amt : 0) && <p className="flex justify-between text-slate-500"><span>(سقف المكافآت التلقائية)</span><b>{money(pr.autoBonus)}</b></p>}
              <p className="flex justify-between border-t border-slate-300 pt-1 mt-1"><span>المستحق عن الشهر</span><b>{money(pr.due)}</b></p>
              <p className="flex justify-between"><span>المتبقي بعد اللي اتصرف</span><b>{money(pr.left)}</b></p>
            </>
          ) : <p className="flex justify-between border-t border-slate-300 pt-1 mt-1"><span>نصيبك من شغل الشهر</span><b>{money(t.mine)}</b></p>}
        </section>
        <p className="text-[10px] text-slate-400 mt-4">صدر من نظام {BN || ''} بتاريخ {new Date().toLocaleString('ar-SA')} — أي ملاحظة على الكشف تبلّغ الإدارة خلال 7 أيام.</p>
      </main>
    </div>
  )
}
