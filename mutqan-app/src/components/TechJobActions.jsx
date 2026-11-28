import { useEffect, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { post, errText } from '../lib/_api'

// The technician on a job of his (owner order 2026-11-22): say sorry / refuse it before arriving (with a reason —
// it goes back to operations and the owner sees it), and ask operations to issue the customer's invoice.
export default function TechJobActions({ order, isMyTask, toast }) {
  const [open, setOpen] = useState(false)
  const [reasons, setReasons] = useState({})
  const [r, setR] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState('')
  useEffect(() => { if (open && !Object.keys(reasons).length) post('/field', { op: 'declineReasons' }).then((x) => setReasons(x || {})).catch(() => {}) }, [open]) // eslint-disable-line
  if (!isMyTask) return null
  const canDecline = ['assigned', 'accepted', 'en_route'].includes(order.status)
  const done = ['completed', 'closed', 'in_progress', 'arrived'].includes(order.status)
  async function decline() {
    if (!r) return
    setBusy('d')
    try { await post('/field', { op: 'decline', orderId: order.id, reason: r, note }); setOpen(false); toast?.('اعتذارك وصل — الطلب رجع للعمليات') } catch (e) { toast?.(errText(e, 'تعذّر')) } finally { setBusy('') }
  }
  async function invoice() {
    setBusy('i')
    try { await post('/field', { op: 'invoice', orderId: order.id }); toast?.('طلبك وصل للعمليات — هتصدر الفاتورة') } catch (e) { toast?.(errText(e, 'تعذّر')) } finally { setBusy('') }
  }
  return (
    <div className="grid gap-2">
      <div className="grid grid-cols-2 gap-2">
        {canDecline && <button onClick={() => setOpen((x) => !x)} className="rounded-2xl py-3 text-sm font-bold bg-white/5 border border-white/15 text-slate-200">🙏 اعتذار عن الطلب</button>}
        {done && <button onClick={invoice} disabled={busy === 'i' || !!order.invoiceRequest} className="rounded-2xl py-3 text-sm font-bold bg-sky-500/15 border border-sky-400/30 text-sky-100 disabled:opacity-50">{busy === 'i' ? <Loader2 size={14} className="animate-spin inline" /> : '🧾'} {order.invoiceRequest ? 'طلبت الفاتورة ✓' : 'اطلب إصدار الفاتورة'}</button>}
      </div>
      {open && (
        <div className="rounded-2xl bg-black/30 border border-white/10 p-3 grid gap-2">
          <p className="text-[11px] text-amber-200 font-bold">الاعتذار بعد الإسناد بيتسجل عليك، والإدارة بتقرر لو فيه خصم حسب اللائحة.</p>
          <div className="grid grid-cols-2 gap-1.5">{Object.entries(reasons).map(([k, l]) => <button key={k} onClick={() => setR(k)} className={`rounded-lg py-2 text-[11px] font-bold border ${r === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{l}</button>)}</div>
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="توضيح (اختياري)" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
          <button onClick={decline} disabled={!r || busy === 'd'} className="rounded-xl bg-rose-500 text-white font-black py-2.5 text-sm disabled:opacity-40">{busy === 'd' ? 'جارِ الإرسال…' : 'تأكيد الاعتذار'}</button>
        </div>
      )}
    </div>
  )
}
