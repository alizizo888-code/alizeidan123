import { useState } from 'react'

// Two short phrases at a time, a divider between them; the pair drifts to the right and the next pair follows each pass.
const PAIRS = [
  ['سبحان الله', 'الحمد لله'],
  ['لا إله إلا الله', 'الله أكبر'],
  ['استغفر الله', 'لا حول ولا قوة إلا بالله'],
  ['سبحان الله وبحمده', 'سبحان الله العظيم'],
  ['أشهد أن لا إله إلا الله', 'وأشهد أن محمدًا رسول الله'],
  ['اللهم صلِّ على محمد', 'حسبنا الله ونعم الوكيل'],
]

// Slim dhikr ticker for the home header. Tap = the bar flashes yellow, blue and light green, then settles.
export default function DhikrBanner({ className = '' }) {
  const [n, setN] = useState(0)
  const [i, setI] = useState(0)
  const [a, b] = PAIRS[i % PAIRS.length]
  const unit = (k) => <span key={k} className="px-4 inline-flex items-center gap-4"><span>{a}</span><span className="text-sky-500 font-black">/</span><span>{b}</span><span className="text-sky-500 font-black">/</span></span>
  return (
    <button
      key={n}
      onClick={() => setN((x) => x + 1)}
      aria-label="أذكار"
      className={`${n ? 'dhikr-flash' : ''} flex-1 min-w-0 overflow-hidden bg-white/90 border border-sky-100 rounded-full py-2.5 text-[12px] font-black text-emerald-800 shadow-sm active:scale-[0.98] transition-transform ${className}`}
      dir="ltr"
    >
      <span className="dhikr-track" dir="rtl" onAnimationIteration={(e) => { if (e.target === e.currentTarget) setI((x) => x + 1) }}>
        {unit(0)}{unit(1)}{unit(2)}{unit(3)}
      </span>
    </button>
  )
}
