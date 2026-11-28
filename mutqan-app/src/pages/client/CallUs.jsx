import { Headphones } from 'lucide-react'
import { useCustomerCall, OutgoingCall } from '../../components/CustomerHotline'
import { brandName } from '../../lib/link'

// «الخط الساخن» link the WhatsApp bot sends when a customer wants to talk to us (owner, 2026-11-23): one big button, a
// free voice call over the internet straight to operations — no number, no sign-in.
export default function CallUs() {
  const hook = useCustomerCall()
  return (
    <div className="h-[100dvh] overflow-y-auto flex flex-col items-center justify-center gap-5 p-6 bg-[#06101f] text-white text-center" dir="rtl" data-call-us>
      <p className="text-2xl font-black">📞 كلّمنا مباشرة</p>
      <p className="text-sm text-slate-300 max-w-xs leading-7">مكالمة صوتية مجانية عن طريق النت، بتوصلك على طول بمشرف العمليات في {brandName()}.</p>
      {hook.enabled ? (
        <button onClick={() => hook.start()} className="w-40 h-40 rounded-full bg-gradient-to-br from-emerald-400 to-teal-700 shadow-2xl shadow-emerald-500/40 flex flex-col items-center justify-center gap-2 active:scale-95 transition-transform" data-call-btn>
          <Headphones size={44} /><span className="font-black">اتصل الآن</span>
        </button>
      ) : <p className="text-amber-300 text-sm">الخط الساخن مقفول دلوقتي — اكتب لنا على واتساب وهنكلمك.</p>}
      <p className="text-[11px] text-slate-500">لو المتصفح سأل عن الميكروفون اضغط «سماح».</p>
      <OutgoingCall hook={hook} />
    </div>
  )
}
