// «تدريب المساعد» — what the assistant practised last night, how it scored, and the better answers waiting for the
// owner's OK. The practice itself runs on the server every night (Mutqan_Trainer); this page only reads and decides.
import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, GraduationCap, Check, X, Loader2, Play, RefreshCw } from 'lucide-react'
import { post, errText } from '../lib/_api'

const KIND = { chat: 'محادثة عميل', lead: 'استقطاب عميل', real: 'رسالة حقيقية', drill: 'توزيع', error: 'خطأ' }

function Lesson({ l, onDone }) {
  const [a, setA] = useState(l.a)
  const [busy, setBusy] = useState('')
  const decide = async (decision) => {
    setBusy(decision)
    try { await post('/trainer', { op: 'lesson', id: l.id, decision, a }); onDone() } catch (e) { alert(errText(e)) } finally { setBusy('') }
  }
  return (
    <div className="rounded-xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
      <p className="text-xs text-slate-300"><b className="text-slate-100">العميل:</b> {l.q}</p>
      <textarea value={a} onChange={(e) => setA(e.target.value)} rows={3} className="w-full rounded-lg bg-black/30 border border-white/10 text-xs text-emerald-200 p-2" />
      <div className="flex gap-2">
        <button disabled={!!busy} onClick={() => decide('approved')} className="flex-1 flex items-center justify-center gap-1 bg-emerald-500 text-black font-black rounded-lg py-1.5 text-xs">{busy === 'approved' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} اعتماد — يتعلمه كل المساعدين</button>
        <button disabled={!!busy} onClick={() => decide('rejected')} className="px-3 flex items-center gap-1 bg-white/10 text-slate-300 rounded-lg text-xs"><X size={14} /> رفض</button>
      </div>
    </div>
  )
}

export default function AiTrainer() {
  const navigate = useNavigate()
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [open, setOpen] = useState(null)
  const [running, setRunning] = useState(false)
  const load = useCallback(async () => {
    try { setD(await post('/trainer', { op: 'status' })); setErr('') } catch (e) { setErr(errText(e)) }
  }, [])
  useEffect(() => { load() }, [load])
  const runNow = async () => {
    setRunning(true)
    try { await post('/trainer', { op: 'run', id: 'price-split' }); await load() } catch (e) { alert(errText(e)) } finally { setRunning(false) }
  }

  const results = d ? Object.entries(d.results || {}) : []
  const passed = results.filter(([, r]) => r.pass).length
  return (
    <div className="h-full overflow-y-auto bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-4xl mx-auto w-full px-4 py-4 flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-slate-300" /></button>
          <GraduationCap className="text-amber-400" size={22} />
          <div className="flex-1">
            <h1 className="font-black text-white text-base">تدريب المساعد</h1>
            <p className="text-[11px] text-slate-400">١٢ تمرين كل ليلة: محادثات عملاء، استقطاب، توزيع، ورسائل حقيقية</p>
          </div>
          <button onClick={load} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><RefreshCw size={16} className="text-slate-300" /></button>
        </div>
      </header>
      <main className="max-w-4xl mx-auto w-full px-4 py-4 grid gap-4">
        {err && <p className="text-rose-400 text-sm">{err}</p>}
        {!d && !err && <p className="text-slate-400 text-sm">جارِ التحميل…</p>}
        {d && (
          <>
            <section className="rounded-2xl border border-white/10 p-4 grid gap-1">
              <p className="text-sm text-white font-black">{d.active ? 'شغّال' : 'متوقف'} — من {d.start} لحد {d.end}</p>
              <p className="text-xs text-slate-400">{d.day ? `آخر يوم تدريب: ${d.day} — نجح ${passed} من ${results.length}` : 'أول تدريب الليلة دي (بين ١ و٧ الفجر بتوقيت مكة).'}</p>
              <button disabled={running} onClick={runNow} className="mt-2 self-start flex items-center gap-1 bg-amber-500/15 border border-amber-400/40 rounded-xl px-3 py-1.5 text-[11px] font-black text-amber-300">{running ? <Loader2 size={13} className="animate-spin" /> : <Play size={13} />} جرّب تمرين دلوقتي</button>
            </section>

            <section className="grid gap-2">
              <p className="text-sm font-black text-white">ردود أفضل مستنية موافقتك ({d.lessons.length})</p>
              {d.lessons.length === 0 && <p className="text-xs text-slate-500">مفيش حاجة مستنية.</p>}
              {d.lessons.map((l) => <Lesson key={l.id} l={l} onDone={load} />)}
            </section>

            <section className="grid gap-2">
              <p className="text-sm font-black text-white">آخر التمارين</p>
              {d.runs.map((r) => (
                <button key={r.id} onClick={() => setOpen(open === r.id ? null : r.id)} className="text-right rounded-xl bg-white/[0.03] border border-white/10 p-3 grid gap-1">
                  <div className="flex items-center gap-2 text-xs">
                    <span className={r.pass ? 'text-emerald-400 font-black' : 'text-rose-400 font-black'}>{r.pass ? '✓' : '✗'} {r.score}/10</span>
                    <span className="text-slate-300">{KIND[r.kind] || r.kind}</span>
                    <span className="text-slate-500">{r.scenario}</span>
                    <span className="mr-auto text-slate-600">{r.day}</span>
                  </div>
                  {(r.issues || []).length > 0 && <p className="text-[11px] text-amber-300">{r.issues.join(' • ')}</p>}
                  {r.kind === 'drill' && !r.pass && <p className="text-[11px] text-amber-300">اختار «{r.got || 'لا أحد'}» والمتوقع «{r.expect}»</p>}
                  {open === r.id && (
                    <div className="grid gap-1 mt-1">
                      {(r.transcript || []).map((line, i) => <p key={i} className={`text-[11px] ${line.startsWith('العميل') ? 'text-slate-300' : 'text-sky-300'}`}>{line}</p>)}
                      {r.better && <p className="text-[11px] text-emerald-300"><b>الأفضل:</b> {r.better}</p>}
                    </div>
                  )}
                </button>
              ))}
            </section>
          </>
        )}
      </main>
    </div>
  )
}
