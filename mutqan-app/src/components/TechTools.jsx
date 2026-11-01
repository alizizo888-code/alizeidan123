import { useMemo, useState } from 'react'
import { MessageCircle, Clock, CalendarClock, Repeat, Loader2 } from 'lucide-react'
import { db } from '../lib/db'
import { sendOrderMsg } from '../lib/orderMsgs'
import AppointmentPicker from './AppointmentPicker'
import OrderChat from './OrderChat'

// Field tools on an order. The technician: chat with the customer, «I'm 10 minutes away», postpone (with a reason and a new time), hand it to a colleague.
// Operations / owner: read and join the conversation (both channels).
const REASONS = [
  { id: 'client', label: 'بطلب من العميل' },
  { id: 'parts', label: 'توفير قطع غيار' },
  { id: 'time', label: 'الوقت غير مناسب حاليًا' },
]
const MOVE = ['accepted', 'en_route', 'nearby']

export default function TechTools({ order, role, isMyTask, myId, profiles, fire, toast }) {
  const staffView = role === 'operations' || role === 'owner' || role === 'supervisor'
  const [open, setOpen] = useState(null)
  const [chan, setChan] = useState('tech')
  const [reason, setReason] = useState('client')
  const [note, setNote] = useState('')
  const [appt, setAppt] = useState({ label: '', at: null })
  const [busy, setBusy] = useState(false)
  const mates = useMemo(() => (profiles || []).filter((t) => t.userId && t.userId !== myId && !t.isSite && t.active !== false), [profiles, myId])
  const live = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(order.status)
  if (!(isMyTask || staffView) || !live) return null
  const act = async (fn, okMsg) => { setBusy(true); try { await fn(); if (okMsg) toast?.(okMsg) } catch (e) { toast?.((e && e.message) || 'تعذر التنفيذ') } finally { setBusy(false) } }
  const btn = 'flex-1 min-w-[44%] flex items-center justify-center gap-1.5 rounded-xl border border-white/10 bg-white/5 py-2.5 text-[12px] font-black text-slate-100 disabled:opacity-40'
  const now = () => new Date().toISOString()

  const eta10 = () => act(async () => { await sendOrderMsg(order.id, 'eta', ''); await db.updateShared('orders', order.id, { etaTenAt: now() }) }, 'تم إبلاغ العميل: سأصل خلال 10 دقائق')
  const postpone = () => act(async () => {
    if (!appt.at && !note.trim()) throw new Error('اختر موعدًا جديدًا أو اكتب ملاحظة')
    const label = REASONS.find((r) => r.id === reason)?.label || ''
    const patch = { postponeLog: [...(order.postponeLog || []), { at: now(), by: myId, reason: label, note: note.trim(), to: appt.label || '' }], remindedAt: null }
    if (appt.at) { patch.appointmentAt = appt.at; patch.preferredTime = appt.label }
    if (['en_route', 'nearby'].includes(order.status)) { patch.status = 'accepted'; patch.statusHistory = [...(order.statusHistory || []), { status: 'accepted', at: now(), by: role, note: 'تأجيل: ' + label }] }
    await db.updateShared('orders', order.id, patch)
    await sendOrderMsg(order.id, 'postponed', `${label}${appt.label ? ` — الموعد الجديد: ${appt.label}` : ''}${note.trim() ? ` — ${note.trim()}` : ''}`)
    setOpen(null); setNote(''); setAppt({ label: '', at: null })
  }, 'تم تأجيل الطلب وإبلاغ العميل والعمليات')
  const handOver = (t) => act(async () => {
    const patch = { technicianId: t.userId, technicianName: t.displayName || 'فني', status: 'assigned', technicianLocation: null,
      statusHistory: [...(order.statusHistory || []), { status: 'assigned', at: now(), by: role, note: `تحويل من ${order.technicianName || 'فني'} إلى ${t.displayName || 'فني'}` }],
      transferLog: [...(order.transferLog || []), { from: 'technician', to: 'technician', at: now(), by: role, note: `إلى ${t.displayName || 'فني'}` }] }
    await db.updateShared('orders', order.id, patch)
    fire?.('assigned', { ...order, ...patch })
    await sendOrderMsg(order.id, 'system', `تم تحويل الطلب إلى الفني ${t.displayName || ''}`)
    setOpen(null)
  }, 'تم تحويل الطلب للفني الزميل')

  return (
    <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-4 flex flex-col gap-3" data-testid="tech-tools">
      <p className="text-xs font-bold text-slate-400">{isMyTask ? 'أدوات الفني' : 'محادثة الطلب'}</p>
      <div className="flex flex-wrap gap-2">
        <button onClick={() => setOpen(open === 'chat' ? null : 'chat')} className={btn}><MessageCircle size={14} /> {isMyTask ? 'دردشة مع العميل' : 'فتح المحادثة'}</button>
        {isMyTask && MOVE.includes(order.status) && <button disabled={busy} onClick={eta10} className={btn}><Clock size={14} /> سأصل خلال 10 دقائق</button>}
        {isMyTask && ['assigned', 'accepted', 'en_route', 'nearby'].includes(order.status) && <button onClick={() => setOpen(open === 'postpone' ? null : 'postpone')} className={btn}><CalendarClock size={14} /> تأجيل الطلب</button>}
        {isMyTask && ['accepted', 'en_route', 'nearby', 'assigned'].includes(order.status) && mates.length > 0 && <button onClick={() => setOpen(open === 'hand' ? null : 'hand')} className={btn}><Repeat size={14} /> تحويل لفني آخر</button>}
      </div>
      {open === 'chat' && (
        <div className="flex flex-col gap-2">
          {staffView && (
            <div className="flex gap-1.5" role="tablist">
              {[['tech', 'الفني ↔ العميل'], ['ops', 'خدمة العملاء ↔ العميل']].map(([k, l]) => <button key={k} role="tab" aria-selected={chan === k} onClick={() => setChan(k)} className={`flex-1 rounded-lg py-1.5 text-xs font-black border ${chan === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{l}</button>)}
            </div>
          )}
          <OrderChat key={chan} order={order} channel={isMyTask && !staffView ? 'tech' : chan} as={isMyTask ? 'tech' : 'ops'} dark />
        </div>
      )}
      {open === 'postpone' && (
        <div className="rounded-xl border border-white/10 p-3 flex flex-col gap-2 bg-black/20">
          <div className="flex gap-1.5 flex-wrap" role="radiogroup">
            {REASONS.map((r) => <button key={r.id} role="radio" aria-checked={reason === r.id} onClick={() => setReason(r.id)} className={`rounded-lg px-3 py-1.5 text-xs font-black border ${reason === r.id ? 'bg-amber-400 text-black border-amber-300' : 'border-white/10 text-slate-300'}`}>{r.label}</button>)}
          </div>
          <AppointmentPicker onChange={setAppt} />
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="ملاحظة للعميل (اختياري)" maxLength={200} className="rounded-xl bg-white/10 border border-white/15 px-3 py-2.5 text-sm text-white placeholder:text-slate-500" />
          <button onClick={postpone} disabled={busy} className="rounded-xl bg-amber-400 text-black font-black py-2.5 text-sm flex items-center justify-center gap-1.5">{busy && <Loader2 size={14} className="animate-spin" />}تأكيد التأجيل وإبلاغ العميل</button>
        </div>
      )}
      {open === 'hand' && (
        <div className="rounded-xl border border-white/10 p-2 flex flex-col gap-1.5 bg-black/20 max-h-52 overflow-y-auto">
          {mates.map((t) => <button key={t.userId} disabled={busy} onClick={() => handOver(t)} className="flex items-center justify-between rounded-lg bg-white/5 px-3 py-2 text-sm text-white"><span className="font-bold">{t.displayName || 'فني'}</span><span className="text-[11px] text-slate-400">تحويل ←</span></button>)}
        </div>
      )}
    </div>
  )
}
