import { useEffect, useRef, useState } from 'react'
import { playKind } from '../lib/alert'
import Ago from './Ago'
import { Send, Loader2, Bot } from 'lucide-react'
import { db } from '../lib/db'
import { post } from '../lib/_api'
import { setChatOpen } from '../lib/chatFocus'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'

// The conversation inside one order. Two channels: «tech» (customer ↔ technician, operations can read and join) and «ops» (customer ↔ customer service).
// `as` = which side the viewer is: client | tech | ops. Server fills in who sent it and who is told (push), so nothing here can be forged.
// On the customer-service channel the order assistant («المساعد الذكي») answers every customer message from the order's
// own facts, asks the technician when it has to, and passes requests on (server: Mutqan_OrderBot). On the technician's side,
// when the assistant asks «فاضل لك قد إيه؟», one tap answers with the minutes and the customer hears it at once.
const ETA_CHIPS = [10, 20, 30, 45, 60]
const SYS = {
  eta: { icon: '🚗', label: 'الفني سيصل خلال 10 دقائق' },
  eta_ask: { icon: '⏱️', label: 'العميل يسأل: فاضل لك قد إيه؟' },
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
  const [typing, setTyping] = useState(false)
  const [waitUntil, setWaitUntil] = useState(0)   // the conversational assistant answers a few seconds later
  const [, tick] = useState(0)
  useEffect(() => { if (!waitUntil) return undefined; const t = setInterval(() => tick((n) => n + 1), 1000); const end = setTimeout(() => setWaitUntil(0), Math.max(0, waitUntil - Date.now())); return () => { clearInterval(t); clearTimeout(end) } }, [waitUntil])
  const [extra, setExtra] = useState([])   // the assistant's answer, shown before the live list catches up
  const n = (data || []).length + extra.length + (typing || waitUntil ? 1 : 0)
  useEffect(() => { const el = box.current; if (el) el.scrollTop = el.scrollHeight }, [n])
  useEffect(() => { setChatOpen('order-chat-' + channel, true); return () => setChatOpen('order-chat-' + channel, false) }, [channel])
  const list = [...(data || []), ...extra.filter((x) => !(data || []).some((m) => m.id === x.id))]
  const lastMsg = list[list.length - 1]
  const awaiting = waitUntil > Date.now() && lastMsg?.from === 'client'
  const mine = (m) => m.from === as
  const bot = as === 'client' && channel === 'ops'
  async function send() {
    const t = text.trim()
    if (!t || busy) return
    setBusy(true); setErr('')
    try {
      await db.insertShared('order_msgs', { orderId: order.id, channel, kind: 'text', text: t }, undefined, { groupId: await team.groupId(), visibleTo: 'group' })
      playKind('msg_out')
      setText('')
    } catch (e) { setErr((e && e.message) || 'تعذر الإرسال'); setBusy(false); return }
    setBusy(false)
    if (bot) {
      setTyping(true)
      try {
        const r = await post('/orderbot', { op: 'reply', orderId: order.id })
        if (r?.deferred) setWaitUntil(Date.now() + 30000)
        if (r?.reply) setExtra((x) => [...x, { id: r.id || 'ai-' + Date.now(), from: 'ai', kind: 'text', text: r.reply, at: new Date().toISOString() }])
      } catch { /* the room still sees the message; the assistant is a help, not the only door */ }
      setTyping(false)
    }
  }
  const askOpen = as === 'tech' && etaAskPending(list)
  const who = (m) => (m.from === 'client' ? 'العميل' : m.from === 'tech' ? (m.fromName || 'الفني') : m.from === 'ai' ? '🤖 المساعد الذكي' : 'خدمة العملاء')
  const bub = (m) => (mine(m) ? (dark ? 'bg-primary text-black' : 'bg-primary text-white') : m.from === 'ai' ? (dark ? 'bg-sky-500/15 text-sky-50 border border-sky-400/30' : 'bg-sky-50 text-slate-800 border border-sky-200') : (dark ? 'bg-white/10 text-white' : 'bg-slate-100 text-slate-800'))
  return (
    <div className="flex flex-col gap-2" dir="rtl">
      <div ref={box} className={`${height} overflow-y-auto rounded-xl p-2.5 flex flex-col gap-1.5 ${dark ? 'bg-black/20 border border-white/10' : 'bg-slate-50 border border-black/5'}`}>
        {list.length === 0 && <p className={`m-auto text-xs text-center px-4 ${dark ? 'text-slate-500' : 'text-slate-400'}`}>{bot ? 'اسأل عن طلبك: الفني فين، فاضل قد إيه، تأجيل، أو أي طلب للفني — المساعد الذكي يرد فورًا وخدمة العملاء معاك.' : 'لا رسائل بعد — ابدأ المحادثة.'}</p>}
        {list.map((m) => SYS[m.kind] && m.kind !== 'text' ? (
          <p key={m.id} className={`self-center text-[11px] font-bold rounded-full px-3 py-1 ${dark ? 'bg-amber-500/15 text-amber-200' : 'bg-amber-50 text-amber-700'}`}>{SYS[m.kind].icon} {m.kind === 'eta' && m.minutes ? `الفني سيصل خلال ${m.minutes} دقيقة` : (m.text || SYS[m.kind].label)} · {hm(m.at)}</p>
        ) : (
          <div key={m.id} className={`max-w-[82%] rounded-2xl px-3 py-1.5 text-sm ${mine(m) ? 'self-start' : 'self-end'} ${bub(m)}`}>
            {!mine(m) && <p className="text-[10px] font-black opacity-70">{who(m)}</p>}
            <p className="whitespace-pre-line break-words">{m.text}</p>
            <p className="text-[9px] opacity-60 text-left">{hm(m.at)} · <Ago at={m.at} /></p>
          </div>
        ))}
        {(typing || awaiting) && <p className={`self-end text-[11px] font-bold flex items-center gap-1.5 rounded-full px-3 py-1 ${dark ? 'bg-sky-500/15 text-sky-200' : 'bg-sky-50 text-sky-700'}`}><Bot size={13} />المساعد الذكي بيكتب…</p>}
      </div>
      {askOpen && <EtaQuickReply order={order} dark={dark} />}
      {err && <p className="text-xs font-bold text-rose-500">{err}</p>}
      <div className="flex gap-2">
        <input value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') send() }} placeholder="اكتب رسالتك…" maxLength={600}
          className={`flex-1 rounded-xl px-3 py-2.5 text-sm outline-none ${dark ? 'bg-white/10 border border-white/15 text-white placeholder:text-slate-500' : 'bg-white border border-black/10 text-slate-900'}`} />
        <button onClick={send} disabled={busy || !text.trim()} aria-label="إرسال" className="w-11 rounded-xl bg-primary text-white disabled:opacity-40 flex items-center justify-center">{busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}</button>
      </div>
    </div>
  )
}

/** True while the assistant's «فاضل لك قد إيه؟» is the latest word on the technician's side, unanswered. */
export function etaAskPending(list) {
  const last = [...(list || [])].reverse().find((m) => m.kind === 'eta_ask' || m.kind === 'eta' || m.kind === 'arrived')
  return last?.kind === 'eta_ask'
}

/** One tap answers the assistant with the minutes; the customer's conversation hears it at once (server: on_tech_eta). */
export function EtaQuickReply({ order, dark = true, banner = false }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function reply(min) {
    if (busy) return
    setBusy(true); setErr('')
    try { await db.insertShared('order_msgs', { orderId: order.id, channel: 'tech', kind: 'eta', minutes: min, text: `فاضل لي حوالي ${min} دقيقة` }, undefined, { groupId: await team.groupId(), visibleTo: 'group' }) }
    catch (e) { setErr((e && e.message) || 'تعذر الإرسال') } finally { setBusy(false) }
  }
  return (
    <div className={`flex flex-wrap gap-1.5 items-center ${banner ? 'rounded-xl bg-amber-500/15 border border-amber-400/40 p-2.5' : ''}`} role="group" aria-label="رد سريع بوقت الوصول">
      <span className={`text-[12px] font-black w-full sm:w-auto ${dark ? 'text-amber-200' : 'text-amber-700'}`}>⏱️ العميل بيسأل: فاضل لك قد إيه؟</span>
      {ETA_CHIPS.map((m) => <button key={m} disabled={busy} onClick={() => reply(m)} className="rounded-full bg-amber-500 text-white text-[12px] font-black px-3 py-1.5 disabled:opacity-50">{m === 60 ? 'ساعة' : `${m} د`}</button>)}
      {err && <p className="text-xs font-bold text-rose-400 w-full">{err}</p>}
    </div>
  )
}
