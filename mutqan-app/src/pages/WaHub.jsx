import { useEffect } from 'react'
import { MessageCircle, ExternalLink } from 'lucide-react'
import { HUB_URL, openHub } from '../lib/hub'

// المركز بقى على نفس الدومين، فالصفحة دي بتوديك له في نفس النافذة على طول — مش تاب تاني ولا وجهة برّه.
// الكارت تحت بيظهر لحظة التحويل بس، وبيفضل ظاهر لو المتصفح منع التحويل التلقائي.
const goHub = () => { try { window.location.assign(HUB_URL) } catch { openHub() } }

export default function WaHub() {
  useEffect(() => { const t = setTimeout(goHub, 400); return () => clearTimeout(t) }, [])
  return (
    <div className="h-full overflow-y-auto p-5" dir="rtl">
      <div className="max-w-lg mx-auto rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-white">
        <div className="mx-auto mb-3 w-14 h-14 rounded-2xl bg-emerald-500 text-black flex items-center justify-center"><MessageCircle size={28} /></div>
        <h1 className="text-xl font-black mb-2">مركز الواتساب</h1>
        <p className="text-sm text-white/70 leading-7 mb-5">بيفتح دلوقتي جوه الموقع… كل المحادثات والأرقام والرد الآلي ومتابعة الطلبات مع العميل والفني — بنفس تسجيل دخولك، من غير رقم سري تاني.</p>
        <button onClick={goHub} className="w-full rounded-2xl bg-emerald-500 text-black font-black py-3 flex items-center justify-center gap-2"><ExternalLink size={18} /> افتح مركز الواتساب</button>
        <p className="text-[11px] text-white/50 mt-3" dir="ltr">{HUB_URL}</p>
      </div>
    </div>
  )
}
