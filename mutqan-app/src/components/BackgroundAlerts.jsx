// «الإشعارات والشاشة مقفولة» (owner order 2026-11-20): what this device does when the app is closed, the screen is off
// or the phone is asleep — and the buttons that fix it: allow notifications, check delivery now, and a real test that
// arrives 15 seconds later so the person can lock the screen and hear it.
import { useEffect, useState } from 'react'
import { BellRing, Loader2, CheckCircle2, AlertTriangle, Download } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { push, enableBackgroundPush } from '../lib/push'
import { isNativeShell } from '../lib/nativeApps'
import { auth } from '../lib/auth'

const ua = () => (typeof navigator !== 'undefined' ? navigator.userAgent || '' : '')
const isIOS = () => /iPhone|iPad|iPod/i.test(ua())
const standalone = () => { try { return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true } catch { return false } }
const APK = (f) => 'https://oxygen11.com/wp-json/mutqan/v1/apk?f=' + f

export default function BackgroundAlerts({ compact = false }) {
  const [perm, setPerm] = useState(() => { try { return 'Notification' in window ? Notification.permission : 'unsupported' } catch { return 'unsupported' } })
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState(null)
  const native = isNativeShell()
  const role = auth.getCurrentUser()?.role || ''
  const apk = role === 'technician' ? 'oxygen-desktech.apk' : ['owner', 'operations', 'supervisor'].includes(role) ? 'oxygen-deskops.apk' : 'oxygen-app.apk'
  useEffect(() => { if (perm === 'granted') enableBackgroundPush() }, [perm])

  async function allow() {
    setBusy('allow'); setMsg(null)
    try { const r = await push.requestPermission(); setPerm(r); if (r === 'granted') { await enableBackgroundPush(); setMsg({ ok: true, t: 'تمام — الجهاز ده هيوصله الإشعار والتطبيق مقفول.' }) } else setMsg({ ok: false, t: r === 'denied' ? 'الإشعارات مقفولة من المتصفح: افتح إعدادات الموقع (🔒 جنب العنوان) ← الإشعارات ← سماح.' : 'المتصفح ده مش بيدعم الإشعارات.' }) }
    finally { setBusy('') }
  }
  async function test(delay) {
    setBusy(delay ? 'later' : 'now'); setMsg(null)
    try {
      if (!native) await enableBackgroundPush()
      const r = await post('/webpush', { op: 'test', delay })
      if (delay) setMsg({ ok: true, t: `اقفل الشاشة دلوقتي 🔒 — هيوصلك إشعار تجربة خلال ${delay} ثانية على ${r.devices || 0} جهاز.` })
      else setMsg({ ok: r.ok > 0, t: r.devices ? `اتبعت لـ ${r.devices} جهاز مسجّل لحسابك — وصل لـ ${r.ok}${r.failed ? ` · فشل ${r.failed} (اتشال الاشتراك القديم)` : ''}.` : 'مفيش جهاز مسجّل لحسابك للإشعارات الخلفية — اضغط «فعّل الإشعارات» الأول.' })
    } catch (e) { setMsg({ ok: false, t: errText(e, 'التجربة ما اشتغلتش') }) } finally { setBusy('') }
  }

  const state = native ? { ok: true, t: 'تطبيق أندرويد: خدمة التنبيهات شغالة في الخلفية بنغمة لكل نوع.' }
    : perm === 'granted' ? { ok: true, t: 'الإشعارات مسموحة على المتصفح ده.' }
      : perm === 'denied' ? { ok: false, t: 'الإشعارات ممنوعة على المتصفح ده — مش هيوصلك حاجة والتطبيق مقفول.' }
        : perm === 'unsupported' ? { ok: false, t: 'المتصفح ده ما بيدعمش الإشعارات الخلفية.' }
          : { ok: false, t: 'الإشعارات لسه مش متفعلة — مش هيوصلك حاجة والشاشة مقفولة.' }

  return (
    <div className="rounded-xl border border-sky-200 bg-sky-50/60 p-3 flex flex-col gap-2" dir="rtl">
      <p className="text-xs font-black text-slate-800 flex items-center gap-1.5"><BellRing size={14} className="text-primary" />الإشعارات والتطبيق مقفول / الشاشة مطفية</p>
      <p className={`text-[11px] font-bold flex items-start gap-1 ${state.ok ? 'text-emerald-700' : 'text-rose-600'}`}>{state.ok ? <CheckCircle2 size={13} className="shrink-0 mt-0.5" /> : <AlertTriangle size={13} className="shrink-0 mt-0.5" />}{state.t}</p>
      <div className="flex flex-wrap gap-1.5">
        {!native && perm !== 'granted' && perm !== 'unsupported' && <button onClick={allow} disabled={!!busy} className="rounded-lg bg-primary text-white text-[11px] font-black px-3 py-2 disabled:opacity-50">{busy === 'allow' ? <Loader2 size={12} className="animate-spin inline" /> : '🔔'} فعّل الإشعارات</button>}
        <button onClick={() => test(0)} disabled={!!busy} className="rounded-lg bg-white border border-slate-200 text-[11px] font-bold px-3 py-2 disabled:opacity-50">{busy === 'now' && <Loader2 size={12} className="animate-spin inline ml-1" />}فحص التوصيل</button>
        <button onClick={() => test(15)} disabled={!!busy} className="rounded-lg bg-slate-900 text-white text-[11px] font-black px-3 py-2 disabled:opacity-50">{busy === 'later' && <Loader2 size={12} className="animate-spin inline ml-1" />}🔒 تجربة والشاشة مقفولة (بعد 15 ث)</button>
      </div>
      {msg && <p className={`text-[11px] font-bold ${msg.ok ? 'text-emerald-700' : 'text-rose-600'}`}>{msg.t}</p>}
      {!compact && !native && (
        <ul className="text-[10.5px] text-slate-600 leading-5 list-disc pr-4">
          {isIOS() && !standalone() && <li><b>آيفون:</b> الإشعارات بتشتغل بس لو التطبيق متضاف للشاشة الرئيسية: زر المشاركة ⬆️ ← «إضافة إلى الشاشة الرئيسية»، وافتحه من الأيقونة وفعّل الإشعارات.</li>}
          <li>المتصفح بيستخدم نغمة الإشعارات الافتراضية للموبايل. <b>النغمات المختلفة لكل نوع</b> والرنين والشاشة مقفولة شغالة كاملة في تطبيق أندرويد.</li>
          <li>شيل «توفير البطارية» عن Chrome، وخلي إشعارات الموقع «مسموحة» في إعدادات Chrome ← إعدادات المواقع ← الإشعارات.</li>
        </ul>
      )}
      {!native && !isIOS() && <a href={APK(apk)} className="self-start inline-flex items-center gap-1 rounded-lg bg-emerald-600 text-white text-[11px] font-black px-3 py-2"><Download size={12} />تطبيق أندرويد (نغمات ورنين والشاشة مقفولة)</a>}
    </div>
  )
}
