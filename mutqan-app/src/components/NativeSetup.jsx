import { useEffect, useState } from 'react'
import { MapPin, MessageCircle, BatteryCharging, Bell, Navigation, Terminal, CheckCircle2, Smartphone, PhoneCall, BellRing } from 'lucide-react'
import { isNative, nativeStatus, openListenerSettings, keepAwake, nativeStart, openTool, openNav, nativeAlerts, openFullScreenSettings } from '../lib/native'
import { nativeAskPermission } from '../lib/nativeNotify'

// «أذونات التطبيق»: one card, one button per permission, each showing whether it is on. Inside the installed app only.
export default function NativeSetup({ compact = false }) {
  const [st, setSt] = useState(null)
  const refresh = () => nativeStatus().then(setSt)
  useEffect(() => {
    if (!isNative()) return undefined
    refresh()
    const onVis = () => { if (document.visibilityState === 'visible') refresh() }
    document.addEventListener('visibilitychange', onVis)
    return () => document.removeEventListener('visibilitychange', onVis)
  }, [])
  if (!isNative()) {
    return compact ? null : (
      <div className="rounded-2xl border border-white/10 bg-white/5 p-4 text-[12px] text-white/70 flex gap-2 items-start">
        <Smartphone size={16} className="shrink-0 mt-0.5" />
        <p>الأذونات (الموقع في الخلفية، قراءة إشعارات الواتساب، فتح Waze وجوجل ماب وTermux) بتشتغل من جوه التطبيق المتثبّت على الموبايل. نزّل التطبيق من الزرار «APK» وافتح الصفحة دي منه.</p>
      </div>
    )
  }
  const old = st === null
  const Item = ({ icon: Icon, title, hint, on, onClick, label }) => (
    <div className="flex items-center gap-3 rounded-xl bg-white/5 border border-white/10 px-3 py-2.5">
      <Icon size={18} className={on ? 'text-emerald-300' : 'text-amber-300'} />
      <div className="flex-1 min-w-0"><p className="text-[13px] font-bold">{title}</p><p className="text-[11px] text-white/55">{hint}</p></div>
      {on ? <CheckCircle2 size={18} className="text-emerald-400" /> : <button onClick={async () => { await onClick(); setTimeout(refresh, 800) }} className="rounded-lg bg-primary text-black px-3 py-1.5 text-[12px] font-black">{label || 'فعّل'}</button>}
    </div>
  )
  return (
    <div className="rounded-2xl border border-primary/30 bg-primary/5 p-4 grid gap-2" dir="rtl">
      <p className="font-black text-[14px]">أذونات التطبيق</p>
      {old && <p className="text-[11px] text-amber-200">النسخة دي من التطبيق قديمة — نزّل الأحدث من «APK» عشان تظهر كل الأذونات.</p>}
      <Item icon={MapPin} title="الموقع في الخلفية" hint="العميل يتابع خط سيرك ويتبعتله «الفني قريب» لوحده" on={!!window.__MQ_BG_GPS} onClick={nativeStart} />
      <Item icon={Bell} title="الإشعارات" hint="طلبات جديدة ومكالمات وتنبيهات" on={false} onClick={nativeAskPermission} label="اسمح" />
      {!old && <Item icon={BellRing} title="التنبيهات والشاشة مقفولة" hint="طلب جديد أو رسالة بترن وتظهر حتى والموبايل ساكن" on={st?.alerts} onClick={nativeAlerts} label="شغّل" />}
      {!old && <Item icon={PhoneCall} title="المكالمات بشاشة كاملة" hint="الخط الساخن يرن وينوّر الشاشة زي المكالمة العادية" on={st?.fullScreen} onClick={openFullScreenSettings} label="اسمح" />}
      {!old && <Item icon={MessageCircle} title="قراءة إشعارات الواتساب" hint="رسايل الواتساب اللي بتوصل الموبايل تظهر في غرفة العمليات" on={st?.listener} onClick={openListenerSettings} label="افتح الإعداد" />}
      {!old && <Item icon={BatteryCharging} title="منع إيقاف التطبيق" hint="عشان الموقع والإشعارات ما يقفوش والشاشة مقفولة" on={st?.battery} onClick={keepAwake} />}
      {!compact && (
        <div className="flex flex-wrap gap-2 pt-1">
          <button onClick={() => openTool('com.waze', 'https://waze.com')} className="rounded-lg bg-white/10 px-3 py-1.5 text-[12px] font-bold flex items-center gap-1"><Navigation size={13} />Waze</button>
          <button onClick={() => openTool('com.google.android.apps.maps', 'https://maps.google.com')} className="rounded-lg bg-white/10 px-3 py-1.5 text-[12px] font-bold flex items-center gap-1"><Navigation size={13} />جوجل ماب</button>
          <button onClick={() => openTool('com.termux', 'https://f-droid.org/packages/com.termux/')} className="rounded-lg bg-white/10 px-3 py-1.5 text-[12px] font-bold flex items-center gap-1"><Terminal size={13} />Termux</button>
          <button onClick={() => openTool('com.whatsapp.w4b', 'https://wa.me')} className="rounded-lg bg-white/10 px-3 py-1.5 text-[12px] font-bold flex items-center gap-1"><MessageCircle size={13} />واتساب أعمال</button>
        </div>
      )}
    </div>
  )
}

export { openNav }
