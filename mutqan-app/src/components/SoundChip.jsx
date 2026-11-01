import { useEffect, useState } from 'react'
import { audioState, onAudioState, unlockAudio } from '../lib/alert'
import { alarmOn, onAlarm, stopAlarm } from '../lib/alarm'

// Always visible in the staff top bar: tells at a glance whether new orders will be HEARD on this device. Browsers keep sound locked until one tap — this chip is that tap.
export default function SoundChip() {
  const [st, setSt] = useState(audioState())
  const [ring, setRing] = useState(alarmOn())
  useEffect(() => {
    const a = onAudioState(setSt); const b = onAlarm(setRing)
    const t = setInterval(() => setSt(audioState()), 3000)
    return () => { a(); b(); clearInterval(t) }
  }, [])
  if (ring) return <button onClick={stopAlarm} data-keep-colors className="shrink-0 h-9 rounded-full px-3 text-[11px] font-black bg-rose-600 text-white animate-pulse">🔕 إسكات</button>
  if (st === 'running') return <button onClick={() => setSt(unlockAudio())} data-keep-colors aria-label="اختبار الصوت" title="الصوت يعمل — اضغط للتجربة" className="shrink-0 w-9 h-9 rounded-full bg-emerald-500/20 border border-emerald-400/50 text-base flex items-center justify-center">🔔</button>
  return <button onClick={() => setSt(unlockAudio())} data-keep-colors className="shrink-0 h-9 rounded-full px-3 text-[11px] font-black bg-amber-400 text-black">🔇 فعّل الصوت</button>
}
