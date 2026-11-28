import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronLeft, PhoneCall, MessageCircle, ClipboardList, Landmark, ShieldCheck } from 'lucide-react'
import { BN, NET } from '../../lib/netBrand'
import { SPECIALTIES } from '../../constants'
import { setWaMode } from '../../lib/waMode'

// «متجر الواتساب» (owner, 2026-11-24 — like the restaurant's store that opens inside WhatsApp): the link in our welcome
// message opens this light, simple page in WhatsApp's own browser. One big «طلب جديد» → pick the service → the order
// form, already on that service. The bot can also send a link straight to a service (?s=ac) once he named it.
const TOP = ['ac', 'fridge', 'plumbing', 'electric', 'appliances', 'general']
const AC_KINDS = [
  { k: 'wash', t: 'غسيل مكيفات', d: 'غسيل كامل داخلي وخارجي', i: '🧼' },
  { k: 'maintenance', t: 'صيانة وإصلاح', d: 'تهريب مية، تبريد ضعيف، فصل', i: '🛠️' },
  { k: 'install', t: 'فك وتركيب', d: 'تركيب جديد أو نقل', i: '🔧' },
  { k: 'freon', t: 'تعبئة فريون', d: 'بعد فحص التسريب', i: '❄️' },
]

export default function WaStart() {
  const nav = useNavigate()
  setWaMode()
  const [params] = useSearchParams()
  const [step, setStep] = useState(params.get('s') === 'ac' ? 'ac' : 'home')
  const src = params.get('src') || 'wa'
  const list = useMemo(() => {
    const on = SPECIALTIES.filter((s) => TOP.includes(s.id))
    return [...on, ...SPECIALTIES.filter((s) => !TOP.includes(s.id))]
  }, [])
  const go = (specialty, note = '', kind = '') => nav(`/new?specialty=${specialty}&src=${src}${kind ? '&kind=' + kind : ''}${note ? '&note=' + encodeURIComponent(note) : ''}`)
  const wa = NET.wa ? `https://wa.me/${NET.wa}` : ''
  const color = NET.color || '#0e7490'

  const Row = ({ icon: Icon, title, onClick, href }) => {
    const cls = 'w-full flex items-center gap-3 bg-white border border-slate-200 px-4 py-4 text-right active:bg-slate-50'
    const body = (<><Icon size={20} style={{ color }} /><span className="flex-1 font-bold text-slate-800 text-[15px]">{title}</span><ChevronLeft size={18} style={{ color }} /></>)
    return href ? <a href={href} className={cls}>{body}</a> : <button onClick={onClick} className={cls}>{body}</button>
  }

  return (
    <div className="min-h-[100dvh] h-full overflow-y-auto bg-slate-50 text-slate-900 flex flex-col" dir="rtl" data-wa-start>
      <header className="bg-white border-b border-slate-200 px-4 py-3 flex items-center gap-3">
        <img src="icon-192.png" alt="" className="w-11 h-11 rounded-xl object-cover" />
        <p className="font-black text-lg">{BN}</p>
        {step !== 'home' && <button onClick={() => setStep('home')} className="mr-auto rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600">الرئيسية</button>}
      </header>

      <main className="flex-1 w-full max-w-md mx-auto px-4 py-6 grid gap-3 content-start">
        {step === 'home' && (
          <>
            <h1 className="text-2xl font-black">محتاج إيه النهارده؟</h1>
            <p className="text-slate-500 text-sm -mt-1">اطلب من هنا وتابع طلبك — من غير تسجيل ولا تطبيق.</p>
            <div className="grid grid-cols-2 gap-3 mt-2">
              <button onClick={() => setStep('pick')} className="text-white font-black text-lg py-5" style={{ background: color }} data-wa-new>طلب جديد</button>
              <button onClick={() => nav('/services')} className="bg-white border border-slate-200 font-black text-lg py-5" style={{ color }}>الخدمات والأسعار</button>
            </div>
            <Row icon={ClipboardList} title="متابعة طلبي" onClick={() => nav('/orders')} />
            <Row icon={PhoneCall} title="كلّمنا — مكالمة مجانية" onClick={() => nav('/call')} />
            <Row icon={Landmark} title="حسابات التحويل" onClick={() => nav('/pay')} />
            {wa && <Row icon={MessageCircle} title="شكوى أو سؤال" href={wa + '?text=' + encodeURIComponent('عندي سؤال / ملاحظة: ')} />}
            <p className="flex items-center justify-center gap-1 text-[12px] text-slate-500 mt-3"><ShieldCheck size={14} /> فنيين معتمدين · ضمان على الشغل · الفني بيفحص قبل أي شغل</p>
          </>
        )}

        {step === 'pick' && (
          <>
            <h1 className="text-xl font-black">اختار الخدمة</h1>
            <p className="text-slate-500 text-sm -mt-1">وبعدها تكمل بيانات طلبك في صفحة واحدة.</p>
            <div className="grid grid-cols-2 gap-2 mt-1">
              {list.map((s) => (
                <button key={s.id} onClick={() => (s.id === 'ac' ? setStep('ac') : go(s.id))} className="bg-white border border-slate-200 p-3 text-right active:bg-slate-50" data-wa-spec={s.id}>
                  <span className="text-2xl">{s.icon}</span>
                  <p className="font-black text-[14px] mt-1">{s.label}</p>
                  <p className="text-[11px] text-slate-500 leading-snug">{s.desc}</p>
                </button>
              ))}
            </div>
          </>
        )}

        {step === 'ac' && (
          <>
            <h1 className="text-xl font-black">❄️ المكيفات — محتاج إيه؟</h1>
            {AC_KINDS.map((a) => (
              <button key={a.k} onClick={() => go('ac', '', a.k === 'freon' ? 'maintenance' : a.k)} className="w-full flex items-center gap-3 bg-white border border-slate-200 px-4 py-4 text-right" data-wa-ac={a.k}>
                <span className="text-2xl">{a.i}</span>
                <span className="flex-1"><span className="block font-black text-[15px]">{a.t}</span><span className="block text-[12px] text-slate-500">{a.d}</span></span>
                <ChevronLeft size={18} style={{ color }} />
              </button>
            ))}
            <p className="text-[12px] text-slate-500 leading-relaxed bg-amber-50 border border-amber-200 p-3">💡 مش متأكد؟ لو التبريد ضعيف أو فيه ريحة أو تهريب مية والمكيف ما اتغسلش من 3 شهور، ابدأ بالغسيل — غالبًا بيحلها، والفني بيفحص ويأكد.</p>
          </>
        )}
      </main>
      <footer className="text-center text-[11px] text-slate-400 pb-6">{BN} · صيانة تكييف وغرف تبريد وتجميد</footer>
    </div>
  )
}
