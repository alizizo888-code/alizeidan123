// A new order must be impossible to miss: the alarm repeats every few seconds until someone opens or silences it.
import { playTone } from './alert'

let timer = null
let left = 0
const subs = new Set()
export const alarmOn = () => !!timer
export const onAlarm = (fn) => { subs.add(fn); return () => subs.delete(fn) }
const emit = () => subs.forEach((f) => { try { f(alarmOn()) } catch { /* ignore */ } })

// cfg = notify settings .sound
export function startAlarm(cfg = {}) {
  stopAlarm()
  if (cfg.enabled === false) return
  const loops = Math.max(1, Math.min(60, Number(cfg.alarmLoops) || 12))
  const gap = Math.max(3, Math.min(30, Number(cfg.alarmGapSecs) || 5)) * 1000
  left = loops
  const tick = () => {
    if (left-- <= 0) { stopAlarm(); return }
    try { playTone(cfg.highTone || 'siren', { volume: cfg.volume ?? 0.8, repeat: 2 }) } catch { /* ignore */ }
    if (cfg.vibrate !== false) { try { navigator.vibrate?.([300, 120, 300, 120, 500]) } catch { /* ignore */ } }
  }
  tick(); timer = setInterval(tick, gap); emit()
}
export function stopAlarm() { if (timer) { clearInterval(timer); timer = null; emit() } }
