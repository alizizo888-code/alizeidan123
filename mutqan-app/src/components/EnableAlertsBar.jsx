// A thin bar that stays until this device can ring with the app closed (owner order 2026-11-20): staff whose browser has
// not allowed notifications heard nothing with the screen off. One tap asks; «لاحقًا» hides it for 12 hours.
import { useEffect, useState } from 'react'
import { BellOff, X } from 'lucide-react'
import { push, enableBackgroundPush } from '../lib/push'
import { isNativeShell } from '../lib/nativeApps'

const KEY = 'mq_alerts_bar_later'
export default function EnableAlertsBar({ customer = false }) {
  const [perm, setPerm] = useState(() => { try { return 'Notification' in window ? Notification.permission : 'unsupported' } catch { return 'unsupported' } })
  const [later, setLater] = useState(() => { try { return Date.now() < Number(localStorage.getItem(KEY) || 0) } catch { return false } })
  useEffect(() => { if (perm === 'granted') enableBackgroundPush() }, [perm])
  if (isNativeShell() || perm === 'granted' || perm === 'unsupported' || later) return null
  const denied = perm === 'denied'
  return (
    <div className={`flex items-center gap-2 px-3 py-2 text-[12px] font-bold ${customer ? 'bg-amber-50 text-amber-900 border border-amber-200 rounded-2xl' : 'bg-rose-600 text-white'}`} dir="rtl" data-keep-colors>
      <BellOff size={15} className="shrink-0" />
      <span className="flex-1 min-w-0 leading-5">{denied
        ? 'الإشعارات ممنوعة على المتصفح ده — مش هيوصلك أي تنبيه والشاشة مقفولة. افتح 🔒 جنب العنوان ← الإشعارات ← سماح.'
        : customer ? 'فعّل الإشعارات عشان يوصلك لما الفني يتحرك ويقرب منك، حتى والموبايل مقفول.' : 'الإشعارات مش متفعلة — الطلبات والرسايل مش هترن والشاشة مقفولة.'}</span>
      {!denied && <button onClick={async () => { const r = await push.requestPermission(); setPerm(r) }} className={`shrink-0 rounded-lg px-3 py-1.5 font-black ${customer ? 'bg-amber-500 text-white' : 'bg-white text-rose-700'}`}>فعّلها</button>}
      <button onClick={() => { try { localStorage.setItem(KEY, String(Date.now() + 12 * 3600 * 1000)) } catch { /* ignore */ } setLater(true) }} aria-label="لاحقًا" className="shrink-0 opacity-80"><X size={15} /></button>
    </div>
  )
}
