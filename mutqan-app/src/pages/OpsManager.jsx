import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Send, Loader2, Trash2, Mic, MicOff, RefreshCw, Activity, AlertTriangle, Search } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { post, errText } from '../lib/_api'

// «مدير العمليات»: asks about the operation and reads it back — what happened, the archive, an order's price / cost /
// sequence, where technicians and supervisors are now, money and expenses. Read only: it changes nothing.
// The alerts at the top are plain server rules (no AI, no cost) refreshed every minute.
const KEY = 'mutqan_ops_chat'
const TOOL = {
  ops_overview: 'الصورة الحالية', alerts: 'التنبيهات', orders_archive: 'الأرشيف', order_story: 'قصة الطلب',
  where_techs: 'مواقع الفنيين', where_supervisors: 'مواقع المشرفين', money_report: 'الفلوس والمصاريف', activity_log: 'سجل الأحداث',
}
const IDEAS = [
  'إيه اللي حصل النهارده؟', 'فين الفنيين دلوقتي؟', 'فين المشرف دلوقتي؟', 'في طلبات متأخرة أو من غير فني؟',
  'الإيراد والمصاريف والصافي آخر 7 أيام', 'مين من الفنيين معاه عهدة نقدية؟', 'أرشيف طلبات الشهر اللي فات الملغية', 'احكيلي قصة آخر طلب اتقفل بالتكلفة والخطوات',
]
const DOT = { high: 'bg-rose-500', mid: 'bg-amber-400', low: 'bg-yellow-200' }
const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]') } catch { return [] } }

export default function OpsManager() {
  const nav = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const allowed = isOwner || role === 'operations' || role === 'supervisor'
  const [msgs, setMsgs] = useState(load)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [alerts, setAlerts] = useState(null)
  const [alertsBusy, setAlertsBusy] = useState(false)
  const [showAll, setShowAll] = useState(false)
  const end = useRef(null)
  const rec = useRef(null)
  const [listening, setListening] = useState(false)
  const SR = typeof window !== 'undefined' && (window.SpeechRecognition || window.webkitSpeechRecognition)

  const loadAlerts = useCallback(async () => {
    setAlertsBusy(true)
    try { const r = await post('/ops', { op: 'alerts' }); setAlerts(r.alerts || []) } catch { setAlerts((a) => a || []) }
    setAlertsBusy(false)
  }, [])

  useEffect(() => {
    if (!allowed) return
    loadAlerts()
    const t = setInterval(() => { if (document.visibilityState === 'visible') loadAlerts() }, 60000)
    return () => clearInterval(t)
  }, [allowed, loadAlerts])

  useEffect(() => { try { sessionStorage.setItem(KEY, JSON.stringify(msgs.slice(-40))) } catch { /* private mode */ } end.current?.scrollIntoView({ block: 'end' }) }, [msgs])

  const toggleMic = () => {
    if (!SR) return setErr('المتصفح ده مش بيدعم الإدخال بالصوت — جرّب كروم.')
    if (listening) { rec.current?.stop(); return }
    const r = new SR(); r.lang = 'ar-SA'; r.interimResults = true; r.continuous = false
    const base = text ? text + ' ' : ''
    r.onresult = (e) => { let t = ''; for (const res of e.results) t += res[0].transcript; setText(base + t) }
    r.onend = () => setListening(false); r.onerror = () => setListening(false)
    rec.current = r; setListening(true); r.start()
  }

  const ask = async (q) => {
    const t = (q ?? text).trim()
    if (!t || busy) return
    const next = [...msgs, { role: 'user', text: t }]
    setMsgs(next); setText(''); setBusy(true); setErr('')
    try {
      const r = await post('/ops', { messages: next.map(({ role: ro, text: tx }) => ({ role: ro, text: tx })) })
      setMsgs((m) => [...m, { role: 'assistant', text: r.text, steps: r.steps || [] }])
    } catch (e) { setErr(errText(e, 'مدير العمليات ما ردّش')) }
    setBusy(false)
  }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!allowed) return <p className="p-6 text-white text-sm font-bold" dir="rtl">مدير العمليات للمالك والعمليات والمشرفين فقط.</p>

  const list = alerts || []
  const high = list.filter((a) => a.level === 'high').length
  const shown = showAll ? list : list.slice(0, 4)

  return (
    <div className="h-full flex flex-col text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="w-9 h-9 rounded-lg bg-sky-500/20 flex items-center justify-center shrink-0"><Activity size={18} className="text-sky-300" /></div>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">مدير العمليات</h1><p className="text-[11px] text-white/60 truncate">اسأل عن الطلبات والأرشيف والتكلفة ومواقع الفريق — قراءة فقط</p></div>
        <button onClick={() => { setMsgs([]); setErr('') }} aria-label="محادثة جديدة" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><Trash2 size={16} /></button>
      </header>

      <div className="flex-1 overflow-y-auto p-4">
        <div className="max-w-3xl mx-auto grid gap-3 pb-4">
          {/* Live alerts: server rules, refreshed every minute */}
          <section aria-label="التنبيهات" className={`rounded-2xl border p-3 ${high ? 'border-rose-400/40 bg-rose-500/10' : list.length ? 'border-amber-400/30 bg-amber-400/5' : 'border-emerald-400/30 bg-emerald-400/5'}`}>
            <div className="flex items-center gap-2">
              <AlertTriangle size={16} className={high ? 'text-rose-300' : list.length ? 'text-amber-300' : 'text-emerald-300'} />
              <p className="flex-1 text-[13px] font-black">{alerts === null ? 'جارِ فحص العمليات…' : list.length ? `${list.length} تنبيه${high ? ` — ${high} عاجل` : ''}` : 'كل حاجة ماشية — مفيش تنبيهات'}</p>
              <button onClick={loadAlerts} disabled={alertsBusy} aria-label="تحديث التنبيهات" className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center disabled:opacity-50"><RefreshCw size={14} className={alertsBusy ? 'animate-spin' : ''} /></button>
            </div>
            {!!list.length && (
              <ul className="mt-2 grid gap-1.5">
                {shown.map((a, i) => (
                  <li key={i} className="flex items-start gap-2 text-[12.5px] leading-relaxed">
                    <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${DOT[a.level] || DOT.low}`} />
                    <span className="flex-1">{a.text}</span>
                    {a.ref && ['unassigned', 'not_accepted', 'late', 'long_job', 'no_position', 'unpaid'].includes(a.kind) && (
                      <button onClick={() => nav('/staff/order/' + a.ref)} className="shrink-0 text-[11px] font-bold text-sky-300 underline">افتح</button>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {list.length > 4 && <button onClick={() => setShowAll((v) => !v)} className="mt-2 text-[11px] font-bold text-white/70 underline">{showAll ? 'أقل' : `عرض الكل (${list.length})`}</button>}
            {!!list.length && <button onClick={() => ask('اشرحلي التنبيهات الحالية بالترتيب، وإيه الإجراء المطلوب في كل واحد ومين يعمله')} disabled={busy} className="mt-2 w-full rounded-lg bg-white/10 py-2 text-[12px] font-bold disabled:opacity-50">اشرحها واقترح الإجراءات</button>}
          </section>

          {!msgs.length && (
            <div className="grid sm:grid-cols-2 gap-2">
              {IDEAS.map((q) => <button key={q} onClick={() => ask(q)} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-[13px] flex gap-2 items-center"><Search size={14} className="text-sky-300 shrink-0" />{q}</button>)}
            </div>
          )}

          {msgs.map((m, i) => (
            <div key={i} className={m.role === 'user' ? 'flex justify-start' : 'flex justify-end'}>
              <div className={`max-w-[90%] rounded-2xl px-3.5 py-2.5 text-[14px] leading-relaxed whitespace-pre-wrap break-words ${m.role === 'user' ? 'bg-primary/20 border border-primary/30' : 'bg-white/5 border border-white/10'}`}>
                {!!m.steps?.length && (
                  <div className="flex flex-wrap gap-1.5 mb-2">
                    {m.steps.map((s, k) => <span key={k} className={`text-[11px] rounded-full px-2 py-0.5 border ${s.status === 'error' ? 'border-rose-400/40 text-rose-200' : 'border-sky-400/30 text-sky-200'}`}>{TOOL[s.tool] || s.tool}</span>)}
                  </div>
                )}
                {m.text}
              </div>
            </div>
          ))}
          {busy && <div className="flex justify-end"><span className="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-2.5 text-[13px] text-white/70 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />بيراجع البيانات…</span></div>}
          {err && <p role="alert" className="rounded-xl bg-rose-500/10 border border-rose-400/30 p-3 text-xs font-bold text-rose-200 leading-relaxed">{err}</p>}
          <div ref={end} />
        </div>
      </div>

      <form onSubmit={(e) => { e.preventDefault(); ask() }} className="border-t border-white/10 bg-slate-950/90 p-3" style={{ paddingBottom: 'calc(12px + env(safe-area-inset-bottom, 0px))' }}>
        <div className="max-w-3xl mx-auto flex gap-2 items-end">
          <label htmlFor="ops-input" className="sr-only">اسأل مدير العمليات</label>
          <textarea id="ops-input" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask() } }}
            placeholder="اسأل… مثلاً: تكلفة طلب 1024 وخطواته" className="flex-1 resize-none rounded-xl bg-white/5 border border-white/15 px-3 py-2.5 text-[14px] text-white placeholder:text-white/40 max-h-40" />
          <button type="button" onClick={toggleMic} aria-label={listening ? 'إيقاف التسجيل' : 'تسجيل صوتي'} className={`w-11 h-11 rounded-xl flex items-center justify-center ${listening ? 'bg-rose-500 text-white animate-pulse' : 'bg-white/10 text-white'}`}>{listening ? <MicOff size={18} /> : <Mic size={18} />}</button>
          <button type="submit" disabled={busy || !text.trim()} aria-label="إرسال" className="w-11 h-11 rounded-xl bg-sky-500 text-white flex items-center justify-center disabled:opacity-50">{busy ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}</button>
        </div>
      </form>
    </div>
  )
}
