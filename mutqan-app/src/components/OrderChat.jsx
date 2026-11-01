import { useEffect, useRef, useState } from 'react'
import { Send, Loader2 } from 'lucide-react'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'

// The conversation inside one order. Two channels: «tech» (customer ↔ technician, operations can read and join) and «ops» (customer ↔ customer service).
// `as` = which side the viewer is: client | tech | ops. Server fills in who sent it and who is told (push), so nothing here can be forged.
const SYS = {
  eta: { icon: '🚗', label: 'الفني سيصل خلال 10 دقائق' },
  arrived: { icon: '📍', label: 'وصل الفني إلى الموقع' },
  postponed: { icon: '⏳', label: 'تم تأجيل الطلب' },
  nudge: { icon: '🔔', label: 'استعجال الطلب' },
  postpone_req: { icon: '⏳', label: 'طلب تأجيل' },
  system: { icon: 'ℹ️', label: '' },
}
const hm = (iso) => { try { return new Date(iso).toLocaleTimeString('ar-SA-u-ca-gregory-nu-latn', { hour: 'numeric', minute: '2-digit' }) } catch { return '' } }

export default function OrderChat({ order, channel = 'tech', as = 'client', dark = false, height = 'h-64' }) {
  const { data } = useLiveShared('order_msgs', { filters: { orderId: order.id, channel }, order: 'at', limit: 200 })
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const box = useRef(null)
  const n = (data || []).length
  useEffect(() => { const el = box.current; if (el) el.scrollTop = el.scrollHeight }, [n])
  const mine = (m) => m.from === as
  async function send() {
    const t = text.trim()
    if (!t || busy) return
    setBusy(true); setErr('')
    try {
      await db.insertShared('order_msgs', { orderId: order.id, channel, kind: 'text', text: t }, undefined, { groupId: await team.groupId(), visibleTo: 'group' })
      setText('')
    } catch (e) { setErr((e && e.message) || 'تعذر الإرسال') } finally { setBusy(false) }
  }
  const who = (m) => (m.from === 'client' ? 'العميل' : m.from === 'tech' ? (m.fromName || 'الفني') : 'خدمة العملاء')
  const bub = (m) => (mine(m) ? (dark ? 'bg-primary text-black' : 'bg-primary text-white') : (dark ? 'bg-white/10 text-white' : 'bg-slate-100 text-slate-800'))
  return (
    <div className="flex flex-col gap-2" dir="rtl">
      <div ref={box} className={`${height} overflow-y-auto rounded-xl p-2.5 flex flex-col gap-1.5 ${dark ? 'bg-black/20 border border-white/10' : 'bg-slate-50 border border-black/5'}`}>
        {n === 0 && <p className={`m-auto text-xs ${dark ? 'text-slate-500' : 'text-slate-400'}`}>لا رسائل بعد — ابدأ المحادثة.</p>}
        {(data || []).map((m) => SYS[m.kind] && m.kind !== 'text' ? (
          <p key={m.id} className={`self-center text-[11px] font-bold rounded-full px-3 py-1 ${dark ? 'bg-amber-500/15 text-amber-200' : 'bg-amber-50 text-amber-700'}`}>{SYS[m.kind].icon} {m.text || SYS[m.kind].label} · {hm(m.at)}</p>
        ) : (
          <div key={m.id} className={`max-w-[82%] rounded-2xl px-3 py-1.5 text-sm ${mine(m) ? 'self-start' : 'self-end'} ${bub(m)}`}>
            {!mine(m) && <p className="text-[10px] font-black opacity-70">{who(m)}</p>}
            <p className="whitespace-pre-line break-words">{m.text}</p>
            <p className="text-[9px] opacity-60 text-left">{hm(m.at)}</p>
          </div>
        ))}
      </div>
      {err && <p className="text-xs font-bold text-rose-500">{err}</p>}
      <div className="flex gap-2">
        <input value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') send() }} placeholder="اكتب رسالتك…" maxLength={600}
          className={`flex-1 rounded-xl px-3 py-2.5 text-sm outline-none ${dark ? 'bg-white/10 border border-white/15 text-white placeholder:text-slate-500' : 'bg-white border border-black/10 text-slate-900'}`} />
        <button onClick={send} disabled={busy || !text.trim()} aria-label="إرسال" className="w-11 rounded-xl bg-primary text-white disabled:opacity-40 flex items-center justify-center">{busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}</button>
      </div>
    </div>
  )
}
