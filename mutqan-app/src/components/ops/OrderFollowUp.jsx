import { useState } from 'react'
import { db } from '../../lib/db'
import { auth } from '../../lib/auth'
import { attentionOf, FLAG_META, orderTimes, minsText, totalMins } from '../../lib/orderWatch'
import { useWarrantyPolicy } from '../../hooks/useWarranty'
import { orderWarranty, warrantyText } from '../../warranty'
import { useLiveShared } from '../../lib/useLive'

// On the staff order page (owner, 2026-11-23 — ideas 2, 4, 5): what is stuck on this order, how long each step took,
// and the team's internal notes. The notes live on the order (`internalNotes`) but the server strips them from anything
// the customer reads (Mutqan_Store::HIDDEN) and refuses them from a customer's write (Mutqan_Policy).
export default function OrderFollowUp({ order, role }) {
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const why = attentionOf(order)
  const clock = orderTimes(order)
  const notes = Array.isArray(order.internalNotes) ? order.internalNotes : []
  const me = auth.getCurrentUser() || {}
  const { policy } = useWarrantyPolicy()
  const w = orderWarranty(order, policy)
  // complaints under warranty are tied to this order (idea 15)
  const { data: claims } = useLiveShared('warranty_claims', { filters: { orderId: order.id }, limit: 10 })

  async function add() {
    const t = text.trim()
    if (!t || busy) return
    setBusy(true); setErr('')
    try {
      const line = { at: new Date().toISOString(), by: me.id || '', byName: me.displayName || me.name || (role === 'owner' ? 'المالك' : role === 'technician' ? 'الفني' : 'العمليات'), role: role || '', text: t.slice(0, 500) }
      await db.updateShared('orders', order.id, { internalNotes: [...notes, line].slice(-50) })
      setText('')
    } catch (e) { setErr(e?.message || 'تعذر الحفظ') }
    setBusy(false)
  }

  return (
    <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-3 grid gap-2.5" data-order-followup>
      {why.length > 0 && (
        <div className="grid gap-1" data-attention-tags>
          <p className="text-[11px] font-black text-rose-200">🚨 الطلب ده محتاج تدخل</p>
          {why.map((w) => <p key={w.key} className={`rounded-lg px-2 py-1 text-[11px] font-bold ${FLAG_META[w.key]?.cls || ''}`}>{FLAG_META[w.key]?.icon} {w.text}</p>)}
        </div>
      )}
      <div>
        <p className="text-[11px] font-black text-slate-300 mb-1">⏱ عدّاد الطلب — الإجمالي {minsText(totalMins(order))}</p>
        <div className="grid grid-cols-5 gap-1 text-center" data-order-clock>
          {clock.map((c) => <div key={c.key} className={`rounded-lg py-1 ${c.open ? 'bg-amber-500/15 text-amber-200' : c.mins == null ? 'bg-white/[0.03] text-slate-600' : 'bg-white/[0.07] text-slate-200'}`}><p className="text-[9px] opacity-70">{c.label}</p><p className="text-[11px] font-black" dir="ltr">{minsText(c.mins)}{c.open ? '…' : ''}</p></div>)}
        </div>
      </div>
      {['arrived', 'in_progress', 'completed', 'closed'].includes(order.status) && (
        <p className="text-[11px] text-slate-300" data-receipt>🧾 استلام العميل: {order.customerReceipt ? <b className="text-emerald-300">✓ {order.customerReceipt.via === 'code' ? 'بكود الاستلام' : order.customerReceipt.via === 'whatsapp' ? 'من واتساب' : 'لما قيّم'}</b> : order.rating ? <b className="text-emerald-300">✓ قيّم الخدمة</b> : <span className="text-amber-300">⏳ لسه</span>}
          {' · '}📸 {(order.photosBefore || []).length} قبل · {(order.photosAfter || []).length} بعد
        </p>
      )}
      {(order.paymentChoice || order.paylinkSentAt) && <p className="text-[11px] text-slate-300" data-pay-choice>💳 الدفع: {order.paymentChoice ? <b className="text-emerald-300">العميل اختار «{order.paymentChoice.label}»</b> : <span className="text-amber-300">اتبعتله رابط طرق الدفع — لسه ما اختارش</span>}</p>}
      {['completed', 'closed'].includes(order.status) && (
        <p className="text-[11px] text-slate-300" data-warranty>🛡️ الضمان: {warrantyText(w)}{(claims || []).length ? <b className="text-rose-300"> · {claims.length} شكوى ضمان على الطلب ده</b> : ''}</p>
      )}
      <div className="grid gap-1.5" data-internal-notes>
        <p className="text-[11px] font-black text-slate-300">🔒 ملاحظات داخلية <span className="font-normal text-slate-500">— للفريق بس، العميل مش بيشوفها</span></p>
        {notes.length === 0 && <p className="text-[10px] text-slate-500">لسه مفيش ملاحظات.</p>}
        {notes.slice().reverse().map((n, i) => (
          <div key={i} className="rounded-lg bg-black/20 px-2 py-1.5">
            <p className="text-[11px] text-slate-100 whitespace-pre-wrap">{n.text}</p>
            <p className="text-[9px] text-slate-500">{n.byName || '—'} · {n.at ? new Date(n.at).toLocaleString('ar-SA-u-nu-latn', { dateStyle: 'short', timeStyle: 'short' }) : ''}</p>
          </div>
        ))}
        <div className="flex gap-1.5">
          <input value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') add() }} placeholder="اكتب ملاحظة للفريق…" className="flex-1 rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white outline-none" data-note-input />
          <button onClick={add} disabled={busy || !text.trim()} className="rounded-lg bg-primary text-black text-xs font-black px-3 disabled:opacity-50" data-note-add>حفظ</button>
        </div>
        {err && <p className="text-[10px] text-rose-300">{err}</p>}
      </div>
    </section>
  )
}
