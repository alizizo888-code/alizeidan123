import { useEffect, useState } from 'react'
import { audioState, onAudioState, unlockAudio } from '../lib/alert'
import { alarmOn, onAlarm, stopAlarm } from '../lib/alarm'

// Always visible in the staff top bar: tells at a glance whether new orders will be HEARD on this device. Browsers keep sound locked until one tap — this chip is that tap.
// The browser locks sound again on every fresh page load until the first touch — that is the browser's rule, not a setting we can save.
// So once the person has turned sound on, we remember it: the chip stays a bell (not the big yellow button) and the first touch anywhere turns it back on by itself.
const OK_KEY = 'mq_sound_on'
const remembered = () => { try { return localStorage.getItem(OK_KEY) === '1' } catch { return false } }
export default function SoundChip() {
  const [st, setSt] = useState(audioState())
  const [ring, setRing] = useState(alarmOn())
  useEffect(() => {
    const a = onAudioState(setSt); const b = onAlarm(setRing)
    const t = setInterval(() => setSt(audioState()), 3000)
    return () => { a(); b(); clearInterval(t) }
  }, [])
  if (ring) return <button onClick={stopAlarm} data-keep-colors className="shrink-0 h-9 rounded-full px-3 text-[11px] font-black bg-rose-600 text-white animate-pulse">🔕 إسكات</button>
  if (st === 'running') { try { localStorage.setItem(OK_KEY, '1') } catch { /* ignore */ } }
  if (st !== 'running' && remembered()) return <button onClick={() => setSt(unlockAudio())} data-keep-colors aria-label="الصوت مفعّل — يشتغل مع أول لمسة" title="الصوت مفعّل — يشتغل لوحده مع أول لمسة على الشاشة" className="shrink-0 w-9 h-9 rounded-full bg-emerald-500/10 border border-emerald-400/40 text-base flex items-center justify-center relative">🔔<span className="absolute -top-0.5 -left-0.5 w-2.5 h-2.5 rounded-full bg-amber-400" /></button>
  if (st === 'running') return <button onClick={() => setSt(unlockAudio())} data-keep-colors aria-label="اختبار الصوت" title="الصوت يعمل — اضغط للتجربة" className="shrink-0 w-9 h-9 rounded-full bg-emerald-500/20 border border-emerald-400/50 text-base flex items-center justify-center">🔔</button>
  return <button onClick={() => setSt(unlockAudio())} data-keep-colors className="shrink-0 h-9 rounded-full px-3 text-[11px] font-black bg-amber-400 text-black">🔇 فعّل الصوت</button>
}
