import { useState } from 'react'

const DHIKR = [
  'استغفر الله', 'سبحان الله', 'لا حول ولا قوة إلا بالله', 'الله أكبر',
  'أشهد أن لا إله إلا الله وأشهد أن محمدًا رسول الله', 'سبحان الله وبحمده', 'سبحان الله العظيم',
  'لا إله إلا الله', 'الحمد لله', 'اللهم صلِّ وسلِّم على نبينا محمد', 'أستغفر الله العظيم وأتوب إليه',
  'لا إله إلا الله وحده لا شريك له، له الملك وله الحمد وهو على كل شيء قدير', 'حسبنا الله ونعم الوكيل',
]

// Slim scrolling dhikr ticker for the home header. Tap = the bar flashes yellow, blue and light green, then settles.
export default function DhikrBanner({ className = '' }) {
  const [n, setN] = useState(0)
  const line = DHIKR.join('   ✦   ') + '   ✦   '
  return (
    <button
      key={n}
      onClick={() => setN((x) => x + 1)}
      aria-label="أذكار"
      className={`${n ? 'dhikr-flash' : ''} flex-1 min-w-0 overflow-hidden bg-white/90 border border-sky-100 rounded-full py-2.5 text-[12px] font-black text-emerald-800 shadow-sm active:scale-[0.98] transition-transform ${className}`}
      dir="ltr"
    >
      <span className="dhikr-track" dir="rtl"><span className="px-2">{line}</span><span className="px-2" aria-hidden="true">{line}</span></span>
    </button>
  )
}
