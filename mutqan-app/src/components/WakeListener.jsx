import { useEffect } from 'react'
import { SR, wakeSplit, wakeEnabled, onMic, micHolder, openAssistant } from '../lib/voice'

// The wake call: while it is turned on (by this person, on this device) and the app is open and visible, the phone listens
// for «يا مساعد / يا متقن / يا أكسجين». On hearing it, the assistant opens, answers «لبيك» and takes the command —
// or runs it straight away when it was said in the same breath («يا مساعد فين الفني محمود»).
// The browser keeps nothing; it stops the moment the screen is hidden or the assistant itself is listening.
export default function WakeListener() {
  useEffect(() => {
    if (!SR) return undefined
    let rec = null; let alive = true; let on = wakeEnabled(); let retry = null; let fails = 0
    const stop = () => { clearTimeout(retry); if (rec) { const r = rec; rec = null; try { r.onend = null; r.abort() } catch { /* ignore */ } } }
    const start = () => {
      stop()
      if (!alive || !on || micHolder() || document.visibilityState === 'hidden') return
      const r = new SR(); r.lang = 'ar-SA'; r.continuous = true; r.interimResults = true
      r.onresult = (e) => {
        for (let i = e.resultIndex; i < e.results.length; i++) {
          const t = e.results[i][0].transcript
          const w = wakeSplit(t)
          if (!w.woke) continue
          if (!e.results[i].isFinal && w.rest.length < 3) { stop(); openAssistant({ wake: true, rest: '' }); return }
          if (e.results[i].isFinal) { stop(); openAssistant({ wake: true, rest: w.rest }); return }
        }
      }
      r.onerror = (e) => { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { on = false } else { fails++ } }
      r.onend = () => { if (rec === r) { rec = null; retry = setTimeout(start, Math.min(30000, 400 * (1 + fails))) } }
      rec = r
      try { r.start(); fails = 0 } catch { rec = null; retry = setTimeout(start, 3000) }
    }
    const onSetting = (e) => { on = !!e.detail; on ? start() : stop() }
    const onVis = () => (document.visibilityState === 'hidden' ? stop() : start())
    const offMic = onMic((h) => (h ? stop() : setTimeout(start, 600)))
    window.addEventListener('mq-wake-setting', onSetting)
    document.addEventListener('visibilitychange', onVis)
    start()
    return () => { alive = false; stop(); offMic(); window.removeEventListener('mq-wake-setting', onSetting); document.removeEventListener('visibilitychange', onVis) }
  }, [])
  return null
}
