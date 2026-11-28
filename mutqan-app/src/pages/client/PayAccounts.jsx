import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import { usePaymentMethods } from '../../hooks/usePaymentMethods'
import BankAccountCard, { transferAccounts } from '../../components/BankAccountCard'
import { post } from '../../lib/_api'

// «حساباتنا» — the page the WhatsApp bot, the technician and the invoice link to (#/pay): every transfer account
// with one-tap copy and the QR to scan or save. Open to anyone, no sign-in.
// With an order's link (#/pay?o=…&k=…, sent on WhatsApp when the job is done — owner, 2026-11-23) it is that order's
// «طرق الدفع»: the amount, and the customer picks how he pays; operations and the technician see his choice.
const ICON = { cash: '💵', card: '💳', transfer: '🏦', online: '🌐', later: '📞' }

export default function PayAccounts() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const o = params.get('o') || ''
  const k = params.get('k') || ''
  const { methods, loading } = usePaymentMethods()
  const list = transferAccounts(methods)
  const [order, setOrder] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState('')
  const [pick, setPick] = useState('')   // confirmed (saved on the order, operations told)
  const [offNote, setOffNote] = useState('')   // the card machine switched off: shown, never picked (owner, 2026-11-24)
  const [sel, setSel] = useState('')     // tapped, not yet confirmed (owner, 2026-11-23: nothing is sent before «تأكيد»)
  // «معاينة» from the settings (owner, 2026-11-24): the whole page as a customer with an order sees it, nothing saved
  const preview = params.get('preview') === '1' && !o
  const cardOff = methods.some((m) => m.type === 'card_on_site' && !m.deleted) && !methods.some((m) => m.type === 'card_on_site' && !m.deleted && m.enabled)
  useEffect(() => {
    if (!preview || loading) return
    setOrder({ number: 'معاينة', amount: 0, paid: false, payLink: '#', methods: { cash: 'كاش للفني', card: 'شبكة/مدى مع الفني', transfer: 'تحويل بنكي', online: 'دفع إلكتروني بالرابط', later: 'هتواصل معاكم' }, off: cardOff ? { card: 'ماكينة الشبكة غير متاحة حاليًا — مع فنيين تانيين. تقدر تدفع كاش للفني أو تحويل بنكي أو بالرابط 🌷' } : {} })
  }, [preview, loading, cardOff])
  useEffect(() => {
    if (!o || !k) return
    post('/paychoice', { op: 'view', o, k }).then((r) => { setOrder(r); setPick(r?.choice?.method || ''); setSel(r?.choice?.method || '') }).catch((e) => setErr(e?.message || 'الرابط مش صالح'))
  }, [o, k])
  async function choose(m) {
    if (preview) { setPick(m); return }
    setBusy(m); setErr('')
    try { const r = await post('/paychoice', { op: 'choose', o, k, method: m }); setOrder(r); setPick(m) } catch (e) { setErr(e?.message || 'تعذر الحفظ') }
    setBusy('')
  }
  const showAccounts = (!o && !preview) || (sel || pick) === 'transfer'
  const available = order ? Object.entries(order.methods || {}).filter(([m]) => (m === 'online' ? !!order.payLink : m === 'transfer' ? list.length > 0 || loading : true)) : []

  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-slate-50 pb-16" dir="rtl">
      <header className="sticky top-0 z-10 bg-white/90 backdrop-blur border-b border-slate-200 pt-[env(safe-area-inset-top,0px)]">
        <div className="max-w-3xl mx-auto px-4 py-3 flex items-center gap-3">
          <button onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/'))} className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center" aria-label="رجوع"><ChevronRight size={18} /></button>
          <h1 className="font-black text-slate-900">{o ? 'طرق الدفع' : 'حسابات التحويل'}</h1>
        </div>
      </header>
      <main className="max-w-3xl mx-auto px-4 py-4 flex flex-col gap-3">
        {preview && <p className="rounded-2xl bg-sky-50 border border-sky-200 text-sky-800 text-xs font-bold p-3" data-preview-banner>👁️ معاينة — ده اللي العميل بيشوفه بعد تعديلاتك. مفيش حاجة بتتحفظ ولا بتتبعت.</p>}
        {o && err && <p className="rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold p-3">{err}</p>}
        {(o || preview) && order && (
          <section className="rounded-2xl bg-white border border-slate-200 p-4 grid gap-3" data-pay-order>
            <div>
              <p className="text-xs text-slate-500">طلب #{order.number}</p>
              {order.amount > 0 && <p className="text-2xl font-black text-slate-900">{order.amount} <span className="text-sm">ر.س</span></p>}
              {order.paid && <p className="text-emerald-600 font-black text-sm mt-1">✅ الطلب مدفوع — شكرًا ليك</p>}
            </div>
            {!order.paid && (
              <>
                <p className="text-sm font-bold text-slate-700">اختار هتدفع إزاي:</p>
                <div className="grid gap-2">
                  {available.map(([m, label]) => (
                    <button key={m} onClick={() => { if (order.off?.[m]) { setOffNote(order.off[m]); return } setOffNote(''); setSel(m) }} disabled={!!busy}
                      className={`flex items-center gap-3 rounded-xl border px-3 py-3 text-right ${order.off?.[m] ? 'border-slate-200 bg-slate-50 opacity-70' : (sel || pick) === m ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-white'}`} data-pay-method={m}>
                      <span className="text-xl">{ICON[m] || '•'}</span>
                      <span className="flex-1 font-bold text-slate-800 text-sm">{label}</span>
                      {order.off?.[m] && <span className="text-slate-500 text-[11px] font-bold">غير متاحة حاليًا</span>}
                      {pick === m && <span className="text-emerald-600 text-xs font-black">✓ اتأكد</span>}
                      {sel === m && pick !== m && <span className="text-amber-600 text-xs font-black">اخترتها</span>}
                    </button>
                  ))}
                </div>
                {offNote && <p className="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs font-bold p-3" data-pay-off>⏳ {offNote}</p>}
                {sel && sel !== pick && (
                  <button onClick={async () => { await choose(sel); if (sel === 'online' && order.payLink) window.open(order.payLink, '_blank', 'noopener') }} disabled={!!busy}
                    className="w-full rounded-xl bg-emerald-600 text-white font-black py-3 text-sm disabled:opacity-50" data-pay-confirm>
                    {busy ? 'لحظة…' : `تأكيد: ${order.methods?.[sel] || ''} ✓`}
                  </button>
                )}
                {pick && pick === sel && <p className="text-xs text-emerald-700 font-bold">وصلنا اختيارك ✅ الفني والعمليات عارفين.</p>}
              </>
            )}
          </section>
        )}
        {showAccounts && (
          <>
            <p className="text-xs text-slate-500 leading-relaxed">اضغط على أي خانة تتنسخ على طول، أو امسح الكود من تطبيق البنك. بعد التحويل ابعت صورة الإيصال على الواتساب أو من صفحة طلبك.</p>
            {loading && <p className="text-center text-slate-400 text-sm py-8">جارِ التحميل…</p>}
            {!loading && !list.length && <p className="text-center text-slate-500 text-sm py-8">{preview ? '⚠️ مفيش ولا حساب تحويل شغال دلوقتي — ضيف حساب أو شغّل واحد من «طرق الدفع».' : `لا توجد حسابات تحويل مفعّلة حاليًا — الدفع نقدًا${cardOff ? '' : ' أو شبكة'} عند الفني.`}</p>}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {list.map((m) => <BankAccountCard key={m.id} m={m} />)}
            </div>
          </>
        )}
      </main>
    </div>
  )
}
