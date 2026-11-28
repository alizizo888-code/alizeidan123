import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { X, Mic, MicOff, Send, Loader2, Volume2, VolumeX, Radio, CheckCircle2, XCircle, Maximize2, Trash2, Sparkles, Zap, Coins } from 'lucide-react'
import Ago from './Ago'
import ChatLinks from './ChatLinks'
import { post, errText } from '../lib/_api'
import { canListen, canSpeak, listenOnce, stopListening, speak, hush, claimMic, releaseMic, wakeEnabled, setWakeEnabled, STOP_RE, YES_RE, NO_RE } from '../lib/voice'

// The staff assistant, one button on every staff screen, for every role:
//   owner / operations → «أوامر» (control assistant: runs on the system, important changes wait for «تنفيذ»)
//                        and «مدير العمليات» (read only: what happened, archive, cost, who is where, alerts)
//   supervisor          → «مدير العمليات»
//   technician          → «مساعد الفني» (his own jobs and account only)
// Talk to it: press the mic once, or turn on «محادثة صوتية» for back-and-forth hands-free, or turn on «النداء»
// and just say «يا مساعد» — it answers «لبيك» and listens. Replies are read aloud when you spoke.
const MODES = {
  cmd: { label: 'أوامر', path: '/agent', page: '/staff/assistant', hint: 'قول أو اكتب أمر… «اسند طلب 1024 لمحمود»' },
  ops: { label: 'مدير العمليات', path: '/ops', page: '/staff/ops-manager', hint: 'اسأل… «فين الفنيين دلوقتي؟»' },
  tech: { label: 'مساعد الفني', path: '/agent', page: '/staff/assistant', hint: 'قول أو اكتب… «أنا في الطريق للطلب الجاي»' },
  acct: { label: 'حسابي', path: '/ops', page: null, hint: 'اسأل… «عليّا كام للشركة؟»' },
}
const FOR_ROLE = { owner: ['cmd', 'ops'], operations: ['cmd', 'ops'], supervisor: ['cmd', 'ops'], technician: ['tech', 'acct'] }
const IDEAS = {
  cmd: ['ملخص طلبات النهارده', 'الليميت كام؟', 'ارفع الليميت', 'ثبت الأسعار النهارده', 'السوق زحمة: خلّي الطلبات الجديدة تتحجز لبكره لمدة 3 ساعات', 'زوّد أسعار الغسيل 10% وخلّي أقصى خصم 5%', 'وقّف الشغل على آخر طلب وبلّغ الفني', 'ابعت إشعار لكل الفنيين: اجتماع الساعة 9', 'اسند الطلبات الجديدة لأقرب فني متاح'],
  ops: ['إيه اللي حصل النهارده؟', 'فين الفنيين والمشرف دلوقتي؟', 'في تنبيهات أو طلبات متأخرة؟'],
  tech: ['إيه شغلي النهارده؟', 'أنا في الطريق للطلب الجاي', 'وصلت عند العميل', 'بلّغ العمليات إن القطعة مش موجودة', 'المكيف بيفصل بعد 5 دقايق، أفحص إيه؟'],
  acct: ['حسابي: عليّا كام للشركة؟', 'شغلي الأسبوع ده'],
}
// Commands the internal engine runs at once, with no AI provider (see Mutqan_Quick on the server).
const QUICK = {
  cmd: ['الوضع', 'الطلبات الجديدة', 'التنبيهات', 'فين الفنيين', 'فلوس النهارده', 'نضف المواقع كلها من الكاش', 'حالة الذكاء', 'الأوامر'],
  ops: ['الوضع', 'التنبيهات', 'فين الفنيين', 'ايه اللي حصل', 'فلوس الأسبوع'],
  tech: [],
  acct: [],
}
const store = (m) => 'mq_va_' + m
const loadMsgs = (m) => { try { return JSON.parse(sessionStorage.getItem(store(m)) || '[]') } catch { return [] } }

export default function VoiceAssistant({ role, open, setOpen }) {
  const nav = useNavigate()
  const modes = FOR_ROLE[role] || []
  const [mode, setMode] = useState(modes[0])
  const [msgs, setMsgs] = useState(() => loadMsgs(modes[0]))
  const [text, setText] = useState('')
  const [interim, setInterim] = useState('')
  const [busy, setBusy] = useState(false)
  const [listening, setListening] = useState(false)
  const [talk, setTalk] = useState(false)                 // hands-free conversation
  const [voiceOut, setVoiceOut] = useState(() => { try { return localStorage.getItem('mq_va_mute') !== '1' } catch { return true } })
  const [wake, setWake] = useState(wakeEnabled)
  const [err, setErr] = useState('')
  const [points, setPoints] = useState(null)
  const end = useRef(null)
  const live = useRef({ talk: false, open: false, mode: modes[0], msgs: [], voiceOut: true })
  live.current = { ...live.current, talk, open, mode, msgs, voiceOut }

  useEffect(() => { try { sessionStorage.setItem(store(mode), JSON.stringify(msgs.slice(-30))) } catch { /* ignore */ } end.current?.scrollIntoView({ block: 'end' }) }, [msgs, mode, interim])
  useEffect(() => { setMsgs(loadMsgs(mode)) }, [mode])
  useEffect(() => {
    if (open) { claimMic('sheet'); return () => { releaseMic('sheet'); stopListening(); hush(); setTalk(false) } }
    return undefined
  }, [open])

  useEffect(() => {
    if (!open || !modes.includes('cmd')) return
    post('/agent', { points: true }).then((r) => setPoints(r.points || null)).catch(() => {})
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const say = useCallback(async (t) => { if (live.current.voiceOut) await speak(t) }, [])

  // Hear one thing and send it (or end the conversation when told to).
  const listen = useCallback(async () => {
    if (!canListen) { setErr('المتصفح ده مش بيدعم الصوت — استخدم كروم على أندرويد أو الكمبيوتر، أو سفاري على آيفون.'); return }
    hush(); setErr(''); setListening(true); setInterim('')
    let heard = ''
    try { heard = await listenOnce({ onInterim: setInterim }) }
    catch (e) { setListening(false); setInterim(''); setTalk(false); setErr(e.message === 'denied' ? 'المايك مقفول: اسمح للتطبيق باستخدام الميكروفون من إعدادات المتصفح.' : 'ما قدرتش أسمع — جرّب تاني.'); return }
    setListening(false); setInterim('')
    if (!live.current.open) return
    if (!heard) { if (live.current.talk) { setTalk(false); await say('هقفل المحادثة الصوتية. ناديني لما تحتاجني.') } return }
    if (STOP_RE.test(heard)) { setTalk(false); await say('تحت أمرك.'); return }
    send(heard, true) // eslint-disable-line no-use-before-define
  }, [say]) // eslint-disable-line react-hooks/exhaustive-deps

  const after = useCallback(async (reply, spoken) => {
    if (spoken) await say(reply)
    if (live.current.open && live.current.talk) listen()
  }, [say, listen])

  const decide = useCallback(async (idx, ok, spoken) => {
    const m = live.current.msgs[idx]; const p = m && m.pending
    if (!p || p.done) return
    setBusy(true); setErr('')
    try {
      const r = await post('/agent', ok ? { confirm: p.id } : { cancel: p.id })
      setMsgs((all) => all.map((x, j) => (j === idx ? { ...x, pending: { ...p, done: ok ? (r.ok === false ? 'failed' : 'done') : 'cancelled' } } : x)).concat([{ role: 'note', text: r.text, links: r.links || [], at: new Date().toISOString() }]))
      setBusy(false); after(r.text, spoken)
    } catch (e) { setBusy(false); setErr(errText(e, 'ما اتنفذش')) }
  }, [after])

  const send = useCallback(async (raw, spoken = false) => {
    const t = String(raw ?? '').trim()
    if (!t) return
    const cur = live.current
    // A spoken «نفّذ / إلغاء» answers the last change waiting for confirmation.
    const waiting = cur.mode === 'cmd' ? cur.msgs.map((m, i) => [m, i]).reverse().find(([m]) => m.pending && !m.pending.done) : null
    if (waiting && (YES_RE.test(t) || NO_RE.test(t))) { setMsgs((m) => [...m, { role: 'user', text: t }]); return decide(waiting[1], YES_RE.test(t), spoken) }
    const next = [...cur.msgs.filter((m) => m.role !== 'note'), { role: 'user', text: t }]
    setMsgs((m) => [...m, { role: 'user', text: t, spoken, at: new Date().toISOString() }]); setText(''); setBusy(true); setErr('')
    try {
      const r = await post(MODES[cur.mode].path, { messages: next.map((m) => ({ role: m.role, text: m.text })) })
      setMsgs((m) => [...m, { role: 'assistant', text: r.text, pending: r.pending || null, links: r.links || [], at: new Date().toISOString(), engine: r.engine || '', degraded: !!r.degraded }])
      if (r.points) setPoints(r.points)
      setBusy(false)
      after(r.pending ? r.text + ' — تقول «نفّذ» ولا «إلغاء»؟' : r.text, spoken)
    } catch (e) { setBusy(false); const msg = errText(e, 'المساعد ما ردّش'); setErr(msg); if (spoken) after(msg, true) }
  }, [after, decide])

  // Opened by the button or by the wake call.
  useEffect(() => {
    const onOpen = async (e) => {
      const d = e.detail || {}
      setOpen(true)
      // «اسأل المساعد عنه» from a long-press menu: the question goes in as typed (owner, 2026-11-22)
      if (d.ask) { send(String(d.ask), false); return }
      if (!d.wake) return
      setTalk(true); live.current.talk = true; live.current.open = true
      await speak('لبيك، اتفضل')
      if (d.rest) send(d.rest, true); else listen()
    }
    window.addEventListener('mq-assistant-open', onOpen)
    return () => window.removeEventListener('mq-assistant-open', onOpen)
  }, [setOpen, send, listen])

  const toggleTalk = () => { const on = !talk; setTalk(on); live.current.talk = on; if (on && !listening && !busy) listen(); if (!on) { stopListening(); hush() } }
  const toggleWake = () => { const on = !wake; setWake(on); setWakeEnabled(on) }
  const toggleVoice = () => { const on = !voiceOut; setVoiceOut(on); if (!on) hush(); try { localStorage.setItem('mq_va_mute', on ? '0' : '1') } catch { /* ignore */ } }

  if (!open || !modes.length) return null
  const M = MODES[mode]
  return (
    <div className="fixed inset-0 z-[60] bg-black/60 flex items-end sm:items-center justify-center" onClick={() => setOpen(false)}>
      <section dir="rtl" role="dialog" aria-modal="true" aria-label="المساعد" onClick={(e) => e.stopPropagation()}
        className="w-full sm:max-w-lg h-[88vh] sm:h-[80vh] bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl flex flex-col text-white overflow-hidden">
        <header className="px-4 pt-3 pb-2 border-b border-white/10">
          <div className="flex items-center gap-2">
            <Sparkles size={18} className="text-primary shrink-0" />
            <p className="flex-1 font-black text-[15px]">المساعد</p>
            {points && (
              <span title={`اتصرف ${points.used} نقطة في ${points.calls} طلب لـ Claude · ${points.internal} أمر داخلي ببلاش`}
                className={`rounded-full px-2 py-0.5 text-[11px] font-black flex items-center gap-1 border ${points.unlimited || points.balance > 200 ? 'border-emerald-400/40 text-emerald-200' : 'border-amber-400/50 text-amber-200'}`}>
                <Coins size={12} />{points.unlimited ? 'نقاط مفتوحة' : `${Number(points.balance).toLocaleString('ar-EG')} نقطة`}
              </span>
            )}
            {M.page && <button onClick={() => { setOpen(false); nav(M.page) }} aria-label="الشاشة الكاملة" title="الشاشة الكاملة" className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center"><Maximize2 size={15} /></button>}
            <button onClick={() => setMsgs([])} aria-label="محادثة جديدة" title="محادثة جديدة" className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center"><Trash2 size={15} /></button>
            <button onClick={() => setOpen(false)} aria-label="إغلاق" className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center"><X size={16} /></button>
          </div>
          {modes.length > 1 && (
            <div role="tablist" className="mt-2 grid grid-cols-2 gap-1 rounded-xl bg-white/5 p-1">
              {modes.map((k) => <button key={k} role="tab" aria-selected={mode === k} onClick={() => setMode(k)} className={`rounded-lg py-1.5 text-[13px] font-black ${mode === k ? 'bg-primary text-black' : 'text-white/70'}`}>{MODES[k].label}</button>)}
            </div>
          )}
          <div className="mt-2 flex flex-wrap gap-1.5 text-[11.5px] font-bold">
            {canListen && <button onClick={toggleTalk} aria-pressed={talk} className={`rounded-full px-2.5 py-1 border flex items-center gap-1 ${talk ? 'bg-emerald-500 text-black border-emerald-400' : 'border-white/15 text-white/80'}`}><Radio size={13} />محادثة صوتية</button>}
            {canListen && <button onClick={toggleWake} aria-pressed={wake} className={`rounded-full px-2.5 py-1 border flex items-center gap-1 ${wake ? 'bg-amber-400 text-black border-amber-300' : 'border-white/15 text-white/80'}`}>📣 النداء «يا مساعد»</button>}
            {canSpeak && <button onClick={toggleVoice} aria-pressed={voiceOut} className="rounded-full px-2.5 py-1 border border-white/15 text-white/80 flex items-center gap-1">{voiceOut ? <Volume2 size={13} /> : <VolumeX size={13} />}{voiceOut ? 'الرد بالصوت' : 'الصوت مقفول'}</button>}
          </div>
          {wake && <p className="mt-1.5 text-[10.5px] text-amber-200/80 leading-relaxed">النداء شغال والتطبيق مفتوح على الشاشة: قول «يا مساعد» أو «يا متقن» يرد عليك «لبيك». بيقف لوحده لما تقفل الشاشة.</p>}
        </header>

        <div className="flex-1 overflow-y-auto p-3 grid content-start gap-2.5">
          {QUICK[mode]?.length > 0 && (
            <div className="flex gap-1.5 overflow-x-auto pb-1 -mx-1 px-1" aria-label="أوامر سريعة">
              {QUICK[mode].map((q) => <button key={q} disabled={busy} onClick={() => send(q)} className="shrink-0 rounded-full bg-primary/10 border border-primary/30 text-primary px-2.5 py-1 text-[12px] font-bold flex items-center gap-1 disabled:opacity-50"><Zap size={11} />{q}</button>)}
            </div>
          )}
          {!msgs.length && (
            <div className="grid gap-2">
              {IDEAS[mode].map((q) => <button key={q} onClick={() => send(q)} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-[13px]">{q}</button>)}
            </div>
          )}
          {msgs.map((m, i) => m.role === 'note'
            ? <div key={i} className="justify-self-center grid gap-1"><p className="text-center text-[12px] text-white/70 bg-white/5 rounded-full px-3 py-1">{m.text}{m.at && <Ago at={m.at} className="mr-2 text-[10.5px] text-white/45" />}</p><ChatLinks links={m.links} onGo={() => setOpen(false)} /></div>
            : (
              <div key={i} className={m.role === 'user' ? 'justify-self-start max-w-[88%]' : 'justify-self-end max-w-[92%]'}>
                <div className={`rounded-2xl px-3 py-2 text-[14px] leading-relaxed whitespace-pre-wrap break-words ${m.role === 'user' ? 'bg-primary/20 border border-primary/30' : m.degraded ? 'bg-amber-400/5 border border-amber-400/25' : 'bg-white/5 border border-white/10'}`}>
                  {m.spoken && <Mic size={11} className="inline ml-1 opacity-60" />}{m.text}
                  <ChatLinks links={m.links} onGo={() => setOpen(false)} />
                  {m.pending && (
                    <div className="mt-2 rounded-xl bg-amber-400/10 border border-amber-400/30 p-2.5 grid gap-2">
                      <p className="text-[12.5px] text-amber-100 font-bold">{m.pending.summary}</p>
                      {m.pending.done ? <p className="text-[12px] text-white/70">{m.pending.done === 'done' ? 'اتنفذ' : m.pending.done === 'failed' ? 'ما اتنفذش' : 'اتلغى'}</p> : (
                        <div className="flex gap-2">
                          <button disabled={busy} onClick={() => decide(i, true)} className="flex-1 rounded-lg bg-emerald-500/90 font-bold text-[13px] py-1.5 flex items-center justify-center gap-1 disabled:opacity-60"><CheckCircle2 size={14} />تنفيذ</button>
                          <button disabled={busy} onClick={() => decide(i, false)} className="flex-1 rounded-lg bg-white/10 font-bold text-[13px] py-1.5 flex items-center justify-center gap-1 disabled:opacity-60"><XCircle size={14} />إلغاء</button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
                <p className={`mt-0.5 px-1 flex items-center gap-1.5 text-[10.5px] text-white/45 ${m.role === 'user' ? '' : 'justify-end'}`}>
                  {m.engine === 'internal' && <span className="text-primary/80 flex items-center gap-0.5 font-bold"><Zap size={10} />فوري</span>}
                  {m.at && <Ago at={m.at} />}
                </p>
              </div>
            ))}
          {interim && <p className="justify-self-start max-w-[88%] rounded-2xl px-3 py-2 text-[14px] bg-primary/10 border border-dashed border-primary/40 text-white/80">{interim}…</p>}
          {busy && <p className="justify-self-end rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-[13px] text-white/70 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />بيشتغل…</p>}
          {err && <p role="alert" className="rounded-xl bg-rose-500/10 border border-rose-400/30 p-2.5 text-xs font-bold text-rose-200 leading-relaxed">{err}</p>}
          <div ref={end} />
        </div>

        <form onSubmit={(e) => { e.preventDefault(); send(text) }} className="border-t border-white/10 p-3" style={{ paddingBottom: 'calc(12px + env(safe-area-inset-bottom, 0px))' }}>
          <div className="flex gap-2 items-end">
            <button type="button" onClick={() => (listening ? stopListening() : listen())} disabled={busy} aria-label={listening ? 'إيقاف الاستماع' : 'تكلّم'}
              className={`w-14 h-14 rounded-full flex items-center justify-center shrink-0 disabled:opacity-50 ${listening ? 'bg-rose-500 animate-pulse' : 'bg-primary text-black'}`}>{listening ? <MicOff size={22} /> : <Mic size={22} />}</button>
            <label htmlFor="va-input" className="sr-only">اكتب</label>
            <textarea id="va-input" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(text) } }}
              placeholder={M.hint} className="flex-1 resize-none rounded-xl bg-white/5 border border-white/15 px-3 py-3 text-[14px] placeholder:text-white/40 max-h-32" />
            <button type="submit" disabled={busy || !text.trim()} aria-label="إرسال" className="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center disabled:opacity-40"><Send size={18} /></button>
          </div>
        </form>
      </section>
    </div>
  )
}
