// «تعذّر الإصلاح» (owner order 2026-11-20): the technician on site can't fix the fault. He picks why, writes what he
// found, and either hands the job back to operations (someone else / a decision) or cancels it. Either way the visit is
// not his: it never becomes a completed job of his and carries no share. Sent to the server (/field «abort»), which
// also tells the operations room and keeps the report on the order and in the customer's record.
import { useState } from 'react'
import { AlertTriangle, Loader2, X } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { orderNo } from '../lib/orderNo'

const REASONS = [
  ['bigger', 'العطل أكبر من المتاح / محتاج ورشة'],
  ['part', 'محتاج قطعة مش متوفرة'],
  ['specialist', 'محتاج فني تخصص تاني'],
  ['price', 'العميل رفض السعر'],
  ['absent', 'العميل مش موجود / مفيش رد'],
  ['site', 'المكان مش مناسب / مفيش وصول للجهاز'],
  ['other', 'سبب تاني'],
]

export default function AbortJob({ order, mine, onClose, onDone }) {
  const [reason, setReason] = useState('')
  const [condition, setCondition] = useState('')
  const [note, setNote] = useState('')
  const [action, setAction] = useState('return')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const ok = reason && condition.trim().length >= 5

  async function send() {
    if (!ok || busy) return
    setBusy(true); setErr('')
    try { const r = await post('/field', { op: 'abort', orderId: order.id, reason, condition: condition.trim(), note: note.trim(), action }); onDone?.(r) }
    catch (e) { setErr(errText(e, 'ما اتبعتش')) } finally { setBusy(false) }
  }

  const inp = 'w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-sm text-white outline-none focus:border-rose-400'
  return (
    <div className="fixed inset-0 z-[1300] bg-black/60 flex items-end sm:items-center justify-center" dir="rtl" onClick={onClose}>
      <div className="w-full sm:max-w-md max-h-[90vh] overflow-y-auto bg-[#0d1220] border border-rose-500/30 rounded-t-3xl sm:rounded-3xl p-4 grid gap-3 pb-[max(1rem,env(safe-area-inset-bottom))]" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2">
          <AlertTriangle size={18} className="text-rose-300" />
          <p className="flex-1 font-black text-white">تعذّر الإصلاح — طلب #{order.number || orderNo(order)}</p>
          <button onClick={onClose} aria-label="إغلاق" className="text-slate-400"><X size={18} /></button>
        </div>
        <p className="text-[12px] text-slate-400 leading-6">
          {mine ? 'الزيارة دي مش هتتحسب عليك كشغلانة ولا ليك فيها نسبة.' : 'الزيارة دي مش هتتحسب على الفني ولا ليه فيها نسبة.'} التقرير بيوصل لغرفة العمليات فورًا، ويتسجل في سجل العميل.
        </p>

        <div className="grid gap-1.5">
          <p className="text-[12px] font-black text-slate-200">السبب</p>
          {REASONS.map(([id, label]) => (
            <label key={id} className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-[13px] cursor-pointer ${reason === id ? 'border-rose-400 bg-rose-500/10 text-white' : 'border-white/10 text-slate-300'}`}>
              <input type="radio" name="abort-reason" checked={reason === id} onChange={() => setReason(id)} className="accent-rose-500" />{label}
            </label>
          ))}
        </div>

        <label className="grid gap-1 text-[12px] font-black text-slate-200">حالة العطل (اللي لقيته في الموقع) *
          <textarea rows={3} value={condition} onChange={(e) => setCondition(e.target.value)} placeholder="مثال: الكمبروسر محروق ومحتاج تغيير، العميل عايز يفكر" className={inp} />
        </label>
        <label className="grid gap-1 text-[12px] font-bold text-slate-400">ملاحظة للعمليات (اختياري)
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="مثال: محتاج فني تبريد مركزي" className={inp} />
        </label>

        <div className="grid grid-cols-2 gap-2">
          <button type="button" onClick={() => setAction('return')} className={`rounded-xl border px-2 py-2.5 text-[12px] font-black ${action === 'return' ? 'border-amber-400 bg-amber-500/15 text-amber-200' : 'border-white/10 text-slate-400'}`}>⏸️ إيقاف ورجوع للعمليات<br /><span className="text-[10px] font-bold opacity-80">تبعت فني تاني أو تكلم العميل</span></button>
          <button type="button" onClick={() => setAction('cancel')} className={`rounded-xl border px-2 py-2.5 text-[12px] font-black ${action === 'cancel' ? 'border-rose-400 bg-rose-500/15 text-rose-200' : 'border-white/10 text-slate-400'}`}>❌ إلغاء الطلب<br /><span className="text-[10px] font-bold opacity-80">العميل بيتبلغ بالإلغاء</span></button>
        </div>

        {err && <p className="text-[12px] text-rose-300">{err}</p>}
        <button disabled={!ok || busy} onClick={send} className="h-12 rounded-2xl bg-rose-500 text-white font-black disabled:opacity-40 flex items-center justify-center gap-2">
          {busy && <Loader2 size={16} className="animate-spin" />}إرسال التقرير و{action === 'cancel' ? 'إلغاء الطلب' : 'إيقاف الطلب'}
        </button>
        {!ok && <p className="text-[10px] text-slate-500 text-center">اختار السبب واكتب حالة العطل الأول.</p>}
      </div>
    </div>
  )
}
