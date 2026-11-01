import { useEffect, useState } from 'react'
import { MessageCircle, Phone, BellRing, CalendarClock, X, Loader2 } from 'lucide-react'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useContact } from '../hooks/useContact'
import AppointmentPicker from './AppointmentPicker'
import OrderChat from './OrderChat'

// What the customer can do with his order, by where the order is right now (new → distributed → technician → on the way → arrived → done).
const STEPS = [
  { k: 'recv', icon: '📥', label: 'استلام الطلب' },
  { k: 'dist', icon: '🗂️', label: 'التوزيع' },
  { k: 'tech', icon: '🧑‍🔧', label: 'الفني' },
  { k: 'road', icon: '🚗', label: 'في الطريق' },
  { k: 'here', icon: '📍', label: 'وصل' },
  { k: 'done', icon: '✅', label: 'اكتمل' },
]
const stepOf = (s) => (['new', 'forced', 'inhouse'].includes(s) ? 0 : s === 'assigned' ? 1 : s === 'accepted' ? 2 : ['en_route', 'nearby'].includes(s) ? 3 : ['arrived', 'in_progress'].includes(s) ? 4 : ['completed', 'closed'].includes(s) ? 5 : 0)
const HINT = [
  'وصل طلبك لغرفة العمليات وهو قيد التوزيع على أقرب فني.',
  'تم إسناد طلبك لفني وننتظر قبوله.',
  'قبل الفني طلبك وسيتواصل معك قبل التحرك.',
  'الفني في الطريق إليك — تابعه على الخريطة.',
  'الفني عندك الآن.',
  'اكتملت الخدمة.',
]
const NUDGE_KEY = (id) => 'mq-nudge-' + id

export default function ClientActions({ order }) {
  const idx = order.status === 'cancelled' ? -1 : stepOf(order.status)
  const live = idx >= 0 && idx < 5
  const hasTech = !!order.technicianId
  const { phone } = useContact()
  const [open, setOpen] = useState(null)         // 'chat' | 'postpone'
  const [chan, setChan] = useState(hasTech ? 'tech' : 'ops')
  const [note, setNote] = useState('')
  const [appt, setAppt] = useState({ label: '', at: null })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [cool, setCool] = useState(0)
  useEffect(() => { if (!hasTech) setChan('ops') }, [hasTech])
  useEffect(() => {
    let last = 0
    try { last = Number(localStorage.getItem(NUDGE_KEY(order.id))) || 0 } catch { /* ignore */ }
    const tick = () => setCool(Math.max(0, Math.ceil((last + 600000 - Date.now()) / 1000)))
    tick(); const t = setInterval(tick, 1000); return () => clearInterval(t)
  }, [order.id, msg])
  const flash = (m) => { setMsg(m); setTimeout(() => setMsg(''), 4000) }
  const post = async (data) => db.insertShared('order_msgs', { orderId: order.id, ...data }, undefined, { groupId: await team.groupId(), visibleTo: 'group' })

  async function nudge() {
    if (cool > 0 || busy) return
    setBusy(true)
    try { await post({ channel: 'ops', kind: 'nudge', text: '' }); try { localStorage.setItem(NUDGE_KEY(order.id), String(Date.now())) } catch { /* ignore */ } flash('تم إبلاغ العمليات' + (hasTech ? ' والفني' : '') + ' باستعجال طلبك ✅') }
    catch (e) { flash((e && e.message) || 'تعذر الإرسال') } finally { setBusy(false) }
  }
  async function postpone() {
    if (busy) return
    if (!appt.label && !note.trim()) { flash('اختر موعدًا جديدًا أو اكتب السبب'); return }
    setBusy(true)
    try { await post({ channel: 'ops', kind: 'postpone_req', text: note.trim(), newTime: appt.label || '' }); setOpen(null); setNote(''); flash('وصل طلب التأجيل للعمليات وسنؤكد لك الموعد ✅') }
    catch (e) { flash((e && e.message) || 'تعذر الإرسال') } finally { setBusy(false) }
  }
  const btn = 'flex-1 min-w-[44%] flex items-center justify-center gap-1.5 rounded-xl border border-black/10 bg-white py-2.5 text-[12px] font-black text-slate-800 active:scale-[0.97] disabled:opacity-40'

  return (
    <div className="bg-white border border-black/5 rounded-2xl p-4 shadow-sm flex flex-col gap-3" data-testid="client-actions">
      {idx >= 0 && (
        <>
          <div className="flex items-start justify-between" role="list">
            {STEPS.map((s, i) => (
              <div key={s.k} role="listitem" className="flex-1 flex flex-col items-center gap-1 relative">
                <span className={`w-9 h-9 rounded-full flex items-center justify-center text-base border-2 ${i < idx ? 'bg-primary/15 border-primary/40' : i === idx ? 'bg-primary text-white border-primary shadow-md' : 'bg-slate-50 border-slate-200 opacity-50'}`}>{s.icon}</span>
                <span className={`text-[10px] font-bold text-center leading-tight ${i === idx ? 'text-slate-900' : 'text-slate-400'}`}>{s.label}</span>
              </div>
            ))}
          </div>
          <p className="text-[12px] font-bold text-slate-600 text-center">{HINT[idx]}</p>
        </>
      )}
      {live && (
        <div className="flex flex-wrap gap-2">
          {idx <= 3 && <button onClick={nudge} disabled={busy || cool > 0} className={btn}><BellRing size={14} /> {cool > 0 ? `استعجال (بعد ${Math.ceil(cool / 60)} د)` : 'استعجال الطلب'}</button>}
          {idx <= 2 && <button onClick={() => setOpen(open === 'postpone' ? null : 'postpone')} className={btn}><CalendarClock size={14} /> طلب تأجيل</button>}
          <button onClick={() => setOpen(open === 'chat' ? null : 'chat')} className={btn}><MessageCircle size={14} /> {hasTech ? 'دردشة الفني / الخدمة' : 'مراسلة خدمة العملاء'}</button>
          {phone && <a href={`tel:${phone}`} className={btn}><Phone size={14} /> اتصال بخدمة العملاء</a>}
        </div>
      )}
      {msg && <p className="text-[12px] font-bold text-emerald-700 text-center">{msg}</p>}
      {open === 'postpone' && (
        <div className="rounded-xl border border-black/10 p-3 flex flex-col gap-2 bg-slate-50">
          <div className="flex items-center"><p className="text-xs font-black text-slate-800 flex-1">اقترح موعدًا جديدًا</p><button onClick={() => setOpen(null)} aria-label="إغلاق"><X size={14} /></button></div>
          <AppointmentPicker onChange={setAppt} />
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="السبب (اختياري)" maxLength={200} className="rounded-xl border border-black/10 bg-white px-3 py-2.5 text-sm" />
          <button onClick={postpone} disabled={busy} className="rounded-xl bg-primary text-white font-black py-2.5 text-sm flex items-center justify-center gap-1.5">{busy && <Loader2 size={14} className="animate-spin" />}إرسال طلب التأجيل</button>
        </div>
      )}
      {open === 'chat' && (
        <div className="flex flex-col gap-2">
          <div className="flex gap-1.5" role="tablist">
            {hasTech && <button role="tab" aria-selected={chan === 'tech'} onClick={() => setChan('tech')} className={`flex-1 rounded-lg py-1.5 text-xs font-black border ${chan === 'tech' ? 'bg-primary text-white border-primary' : 'border-black/10 text-slate-600'}`}>الفني {order.technicianName ? `(${order.technicianName})` : ''}</button>}
            <button role="tab" aria-selected={chan === 'ops'} onClick={() => setChan('ops')} className={`flex-1 rounded-lg py-1.5 text-xs font-black border ${chan === 'ops' ? 'bg-primary text-white border-primary' : 'border-black/10 text-slate-600'}`}>خدمة العملاء</button>
          </div>
          <OrderChat key={chan} order={order} channel={chan} as="client" />
        </div>
      )}
    </div>
  )
}
