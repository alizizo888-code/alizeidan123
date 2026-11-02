import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Send, Loader2, Sparkles, CheckCircle2, XCircle, ShieldCheck, Wrench, Trash2, Mic, MicOff } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { post, errText } from '../lib/_api'

// «مساعد التحكم»: the owner's and operations supervisor's chat that reads and changes the business's own systems
// (orders, WhatsApp, this WordPress site, the oxygen11.com DNS on Hostinger, the Blogger network) through the server's
// closed tool list. Important changes come back as a card and run only after «تنفيذ».
const KEY = 'mutqan_agent_chat'
const TOOL = {
  orders_list: 'قراءة الطلبات', order_get: 'تفاصيل طلب', order_update: 'تعديل طلب', stats_summary: 'ملخص الأرقام',
  wa_recent: 'محادثات واتساب', wa_send: 'رسالة واتساب', wp_search: 'بحث في الموقع', wp_get: 'قراءة صفحة', wp_draft: 'مسودة جديدة',
  wp_update: 'تعديل صفحة', dns_list: 'سجلات DNS', dns_set: 'تعديل DNS', network_list: 'مواقع الشبكة', blogger_posts: 'مقالات بلوجر',
  techs_list: 'الفنيين', order_assign: 'إسناد لفني', customers_list: 'العملاء', shops_list: 'المحلات', settings_get: 'قراءة الإعدادات', settings_set: 'تعديل الإعدادات',
  ai_engine: 'محرك الذكاء', ai_engine_set: 'إعداد محرك الذكاء', ai_ask: 'سؤال محرك الموقع',
}
const IDEAS = ['إيه ملخص طلبات النهارده؟', 'وريني آخر 10 طلبات لسه جديدة', 'آخر مقالات مدونة الخبير', 'اكتب مسودة مقال عن غسيل المكيفات قبل الصيف']
const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]') } catch { return [] } }

export default function ControlAssistant() {
  const nav = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const [msgs, setMsgs] = useState(load)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const end = useRef(null)
  const rec = useRef(null)
  const [listening, setListening] = useState(false)
  const SR = typeof window !== 'undefined' && (window.SpeechRecognition || window.webkitSpeechRecognition)
  // Voice: Arabic dictation into the box (Saudi Arabic); the owner reviews and presses Enter or send.
  const toggleMic = () => {
    if (!SR) return setErr('المتصفح ده مش بيدعم الإدخال بالصوت — جرّب كروم.')
    if (listening) { rec.current?.stop(); return }
    const r = new SR(); r.lang = 'ar-SA'; r.interimResults = true; r.continuous = false
    const base = text ? text + ' ' : ''
    r.onresult = (e) => { let t = ''; for (const res of e.results) t += res[0].transcript; setText(base + t) }
    r.onend = () => setListening(false); r.onerror = () => setListening(false)
    rec.current = r; setListening(true); r.start()
  }
  const allowed = isOwner || role === 'operations'

  useEffect(() => { try { sessionStorage.setItem(KEY, JSON.stringify(msgs.slice(-40))) } catch { /* private mode */ } end.current?.scrollIntoView({ block: 'end' }) }, [msgs])

  const ask = async (q) => {
    const t = (q ?? text).trim()
    if (!t || busy) return
    const next = [...msgs, { role: 'user', text: t }]
    setMsgs(next); setText(''); setBusy(true); setErr('')
    try {
      const r = await post('/agent', { messages: next.map(({ role: ro, text: tx }) => ({ role: ro, text: tx })) })
      setMsgs((m) => [...m, { role: 'assistant', text: r.text, steps: r.steps || [], pending: r.pending || null }])
    } catch (e) { setErr(errText(e, 'المساعد ما ردّش')) }
    setBusy(false)
  }

  const decide = async (i, ok) => {
    const p = msgs[i].pending
    if (!p || busy) return
    setBusy(true); setErr('')
    try {
      const r = await post('/agent', ok ? { confirm: p.id } : { cancel: p.id })
      setMsgs((m) => m.map((x, j) => (j === i ? { ...x, pending: { ...p, done: ok ? (r.ok === false ? 'failed' : 'done') : 'cancelled' } } : x)).concat([{ role: 'user', text: r.text, note: true }]))
    } catch (e) { setErr(errText(e, 'ما اتنفذش')) }
    setBusy(false)
  }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!allowed) return <p className="p-6 text-white text-sm font-bold" dir="rtl">مساعد التحكم للمالك ومشرف العمليات فقط.</p>

  return (
    <div className="h-full flex flex-col text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">مساعد التحكم</h1><p className="text-[11px] text-white/60 truncate">الطلبات · الواتساب · الموقع · DNS · مدونات الشبكة</p></div>
        <button onClick={() => { setMsgs([]); setErr('') }} aria-label="محادثة جديدة" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><Trash2 size={16} /></button>
      </header>

      <div className="flex-1 overflow-y-auto p-4">
        <div className="max-w-3xl mx-auto grid gap-3 pb-4">
          {!msgs.length && (
            <div className="grid gap-3">
              <p className="rounded-2xl bg-sky-400/10 border border-sky-400/25 p-3 text-[12px] leading-relaxed text-sky-100 flex gap-2"><ShieldCheck size={16} className="shrink-0 mt-0.5" />المساعد شغال على أنظمة الشركة بس. القراءة والتعديلات البسيطة بتتنفذ على طول، والواتساب والـ DNS والمحتوى المنشور بيستنوا ضغطة «تنفيذ» منك.</p>
              <div className="grid sm:grid-cols-2 gap-2">
                {IDEAS.map((q) => <button key={q} onClick={() => ask(q)} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-[13px] flex gap-2 items-center"><Sparkles size={14} className="text-primary shrink-0" />{q}</button>)}
              </div>
            </div>
          )}
          {msgs.map((m, i) => (
            <div key={i} className={m.role === 'user' ? (m.note ? 'text-center' : 'flex justify-start') : 'flex justify-end'}>
              {m.note ? <p className="inline-block text-[12px] text-white/70 bg-white/5 rounded-full px-3 py-1">{m.text}</p> : (
                <div className={`max-w-[88%] rounded-2xl px-3.5 py-2.5 text-[14px] leading-relaxed whitespace-pre-wrap ${m.role === 'user' ? 'bg-primary/20 border border-primary/30' : 'bg-white/5 border border-white/10'}`}>
                  {!!m.steps?.length && (
                    <div className="flex flex-wrap gap-1.5 mb-2">
                      {m.steps.map((s, k) => <span key={k} className={`text-[11px] rounded-full px-2 py-0.5 border flex items-center gap-1 ${s.status === 'error' ? 'border-rose-400/40 text-rose-200' : s.status === 'pending' ? 'border-amber-400/40 text-amber-200' : 'border-white/15 text-white/70'}`}><Wrench size={11} />{TOOL[s.tool] || s.tool}</span>)}
                    </div>
                  )}
                  {m.text}
                  {m.pending && (
                    <div className="mt-3 rounded-xl bg-amber-400/10 border border-amber-400/30 p-3 grid gap-2">
                      <p className="text-[13px] text-amber-100 font-bold">{m.pending.summary}</p>
                      {m.pending.done ? <p className="text-[12px] text-white/70">{m.pending.done === 'done' ? 'اتنفذ' : m.pending.done === 'failed' ? 'ما اتنفذش' : 'اتلغى'}</p> : (
                        <div className="flex gap-2">
                          <button disabled={busy} onClick={() => decide(i, true)} className="flex-1 rounded-lg bg-emerald-500/90 text-white font-bold text-[13px] py-2 flex items-center justify-center gap-1.5 disabled:opacity-60"><CheckCircle2 size={15} />تنفيذ</button>
                          <button disabled={busy} onClick={() => decide(i, false)} className="flex-1 rounded-lg bg-white/10 text-white font-bold text-[13px] py-2 flex items-center justify-center gap-1.5 disabled:opacity-60"><XCircle size={15} />إلغاء</button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>
          ))}
          {busy && <div className="flex justify-end"><span className="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-2.5 text-[13px] text-white/70 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />بيشتغل…</span></div>}
          {err && <p className="rounded-xl bg-rose-500/10 border border-rose-400/30 p-3 text-xs font-bold text-rose-200 leading-relaxed">{err}</p>}
          <div ref={end} />
        </div>
      </div>

      <form onSubmit={(e) => { e.preventDefault(); ask() }} className="border-t border-white/10 bg-slate-950/90 p-3" style={{ paddingBottom: 'calc(12px + env(safe-area-inset-bottom, 0px))' }}>
        <div className="max-w-3xl mx-auto flex gap-2 items-end">
          <label htmlFor="agent-input" className="sr-only">اكتب أمرك</label>
          <textarea id="agent-input" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask() } }}
            placeholder="اكتب أمرك… مثلاً: غيّر حالة الطلب 123 لتم التنفيذ" className="flex-1 resize-none rounded-xl bg-white/5 border border-white/15 px-3 py-2.5 text-[14px] text-white placeholder:text-white/40 max-h-40" />
          <button type="button" onClick={toggleMic} aria-label={listening ? 'إيقاف التسجيل' : 'تسجيل صوتي'} className={`w-11 h-11 rounded-xl flex items-center justify-center ${listening ? 'bg-rose-500 text-white animate-pulse' : 'bg-white/10 text-white'}`}>{listening ? <MicOff size={18} /> : <Mic size={18} />}</button>
          <button type="submit" disabled={busy || !text.trim()} aria-label="إرسال" className="w-11 h-11 rounded-xl bg-primary text-white flex items-center justify-center disabled:opacity-50">{busy ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}</button>
        </div>
      </form>
    </div>
  )
}
