import { useEffect, useState } from 'react'
import { KeyRound, Link2 } from 'lucide-react'
import { team } from '../lib/team'
import { errText } from '../lib/_api'

// Owner only: the shared operations password, and the switch that sends /staff on every site to one address.
export default function StaffDoorSettings() {
  const [st, setSt] = useState(null)
  const [pw, setPw] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { team.opsShared().then(setSt).catch(() => setSt(false)) }, [])
  const run = async (fn, ok) => { setBusy(true); setMsg(''); try { setSt(await fn()); setMsg(ok) } catch (e) { setMsg(errText(e)) } finally { setBusy(false) } }
  const card = 'rounded-2xl bg-white/5 border border-white/10 p-4 grid gap-2'
  if (st === null) return <section className={card}><p className="text-xs text-slate-400">جارِ التحميل…</p></section>
  if (st === false) return <section className={card}><p className="text-xs text-rose-300">تعذّر قراءة إعدادات الدخول.</p></section>
  const home = st.staffHome || ''
  return (
    <>
      <section className={card}>
        <p className="font-black flex items-center gap-2"><KeyRound size={18} className="text-primary" /> كلمة سر العمليات الموحّدة</p>
        <p className="text-xs text-slate-400 leading-relaxed">كلمة واحدة لفريق العمليات كله. كل واحد يكتب اسمه جنبها، فكل إجراء (توزيع، تسوية…) يتسجّل باسمه، وتقدر توقف اسم لوحده من صفحة الفريق. تغيير الكلمة يخرج كل اللي دخلوا بيها.</p>
        <p className="text-xs">الحالة: <b className={st.on ? 'text-emerald-300' : 'text-amber-300'}>{st.on ? `مفتوح — ${st.people} اسم دخلوا بيها` : 'مقفول'}</b></p>
        <input type="password" dir="ltr" autoComplete="new-password" value={pw} onChange={(e) => setPw(e.target.value)} placeholder="كلمة سر جديدة (٦ أحرف على الأقل)" className="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-primary" />
        <div className="flex gap-2">
          <button disabled={busy || pw.length < 6} onClick={() => run(() => team.setOpsShared(pw), 'تم ✓ — ابعت الكلمة لفريق العمليات بنفسك').then(() => setPw(''))} className="flex-1 rounded-xl bg-emerald-500 text-black font-black py-2.5 text-sm disabled:opacity-40">{st.on ? 'تغيير الكلمة' : 'فتح بالكلمة دي'}</button>
          {st.on && <button disabled={busy} onClick={() => window.confirm('قفل دخول العمليات بالكلمة الموحّدة؟ كل اللي دخلوا بيها هيخرجوا.') && run(() => team.setOpsShared(''), 'اتقفل ✓')} className="rounded-xl bg-white/10 px-4 text-xs font-bold">قفل</button>}
        </div>
      </section>
      <section className={card}>
        <p className="font-black flex items-center gap-2"><Link2 size={18} className="text-primary" /> عنوان واحد للفريق</p>
        <p className="text-xs text-slate-400 leading-relaxed">لما يتفعّل، أي حد يفتح صفحات الفريق (/staff) على أي موقع من المواقع يتحوّل تلقائيًا لنفس الصفحة على الديسك. العملاء ما يتأثروش. تقدر ترجّعه في أي وقت.</p>
        <p className="text-xs">الحالة: <b className={home ? 'text-emerald-300' : 'text-amber-300'}>{home ? `مفعّل ← ${home}` : 'مش مفعّل — كل موقع بصفحاته'}</b></p>
        {home
          ? <button disabled={busy} onClick={() => run(() => team.setStaffHome(''), 'رجع ✓ — كل موقع بصفحاته')} className="rounded-xl bg-white/10 py-2.5 text-sm font-bold">إلغاء التحويل</button>
          : <button disabled={busy} onClick={() => run(() => team.setStaffHome(st.staffHomeDefault), 'اتفعّل ✓')} className="rounded-xl bg-primary text-black font-black py-2.5 text-sm">تفعيل التحويل للديسك</button>}
      </section>
      {msg && <p className="text-xs font-bold text-amber-300">{msg}</p>}
    </>
  )
}
