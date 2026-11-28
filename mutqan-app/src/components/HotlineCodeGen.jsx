import { useCallback, useEffect, useState } from 'react'
import { Loader2, Copy, Check, KeyRound } from 'lucide-react'
import { hotlink } from '../lib/link'
import { errText } from '../lib/_api'

// One button for the owner: the server generates a strong link code, saves it (as a hash), pairs this device and turns on
// automatic joining, so every operations device that opens the app answers the hotline on all the sites — nobody types anything.
// The code is shown here this one time (to add a device by hand); it can never be read back.
export default function HotlineCodeGen({ isOwner = true, onDone }) {
  const [st, setSt] = useState(null)
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const [code, setCode] = useState('')
  const [copied, setCopied] = useState(false)
  const load = useCallback(async () => { try { setSt(await hotlink.status()); setErr('') } catch (e) { setErr(errText(e, 'تعذر قراءة حالة الربط')) } }, [])
  useEffect(() => { load() }, [load])
  const gen = async () => {
    if (st?.set && !confirm('توليد رمز جديد يفصل الأجهزة المربوطة بالرمز القديم (أجهزة العمليات ترجع تلقائيًا إذا كان الربط التلقائي شغالًا). متابعة؟')) return
    setBusy('gen'); setErr(''); setCode('')
    try { const r = await hotlink.generate(true); setCode(r.code || ''); setSt(r); onDone && onDone(r) } catch (e) { setErr(errText(e, 'تعذر توليد الرمز')) } finally { setBusy('') }
  }
  const auto = async (on) => {
    setBusy('auto'); setErr('')
    try { setSt(await hotlink.setAuto(on)) } catch (e) { setErr(errText(e, 'تعذر الحفظ')) } finally { setBusy('') }
  }
  const copy = async () => { try { await navigator.clipboard.writeText(code); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { /* ignore */ } }
  const btn = 'rounded-xl font-black py-2.5 px-4 text-sm flex items-center justify-center gap-2 disabled:opacity-50'
  return (
    <div className="grid gap-2 rounded-xl bg-black/20 border border-white/10 p-3" dir="rtl">
      <p className="font-black text-sm flex items-center gap-2"><KeyRound size={16} className="text-primary" />رمز ربط الخط الساخن بغرفة العمليات</p>
      <p className="text-[12px] font-bold">
        {st == null ? '…' : st.set
          ? <>الحالة: <span className={st.online ? 'text-emerald-300' : 'text-amber-300'}>{st.online ? 'الخط متصل الآن ✓' : 'مربوط — لا يوجد جهاز عمليات مفتوح الآن'}</span> · الأجهزة: {(st.devices || []).length} · هذا الجهاز: <span className={st.linkedHere ? 'text-emerald-300' : 'text-slate-400'}>{st.linkedHere ? 'يستقبل ✓' : 'غير مربوط'}</span></>
          : <span className="text-amber-300">لا يوجد رمز ربط بعد — اضغط «توليد الرمز» وسيُحفظ ويُربط تلقائيًا.</span>}
      </p>
      {isOwner && <button disabled={!!busy} onClick={gen} className={`${btn} bg-primary text-black`}>{busy === 'gen' && <Loader2 size={14} className="animate-spin" />}{st?.set ? 'توليد رمز جديد وحفظه وربط هذا الجهاز' : 'توليد الرمز وحفظه وربط هذا الجهاز'}</button>}
      {code && (
        <div className="rounded-xl bg-emerald-500/10 border border-emerald-400/40 p-3 grid gap-1.5">
          <p className="text-[11px] font-bold text-emerald-200">الرمز الجديد (يظهر مرة واحدة فقط — احفظه لو حتربط جهازًا يدويًا):</p>
          <div className="flex items-center gap-2"><code dir="ltr" className="flex-1 text-lg font-black tracking-widest text-white">{code}</code>
            <button onClick={copy} aria-label="نسخ" className="shrink-0 w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center">{copied ? <Check size={16} className="text-emerald-300" /> : <Copy size={16} />}</button></div>
        </div>
      )}
      {isOwner && st?.set && (
        <label className="flex items-center gap-2 text-xs font-bold">
          <input type="checkbox" checked={!!st.auto} disabled={!!busy} onChange={(e) => auto(e.target.checked)} />
          ربط تلقائي: كل جهاز للمالك أو العمليات أو المشرف يفتح التطبيق يستقبل مكالمات الخط الساخن في كل المواقع بدون كتابة الرمز
        </label>
      )}
      {err && <p className="text-xs font-bold text-rose-300">{err}</p>}
    </div>
  )
}
