// Appointment engine: working hours, order-reception hours, peak windows and per-hour capacity.
// Pure functions (no I/O) so the bot, the order pages and the owner screen share one set of rules.
export const DEFAULT_SCHEDULE = {
  openFrom: '08:00', openTo: '22:00',            // technicians work in this window
  receiveFrom: '07:00', receiveTo: '23:00',      // the bot accepts new orders in this window
  closedDays: [],                                // 0=Sunday … 6=Saturday (e.g. [5] to close Friday)
  peak: [{ from: '16:00', to: '21:00' }],        // rush hours
  capNormal: 4, capPeak: 2,                      // visits per hour slot
  leadMinutes: 60,                               // earliest visit = now + lead
  delaySteps: [60, 180],                         // postpone ladder (minutes) before the open "during the day" window
}

export function normalizeSchedule(raw) {
  const d = DEFAULT_SCHEDULE
  const r = raw && typeof raw === 'object' ? raw : {}
  const hm = (v, def) => (/^\d{1,2}:\d{2}$/.test(String(v || '')) ? String(v) : def)
  const num = (v, def, lo, hi) => { const n = Number(v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : def }
  return {
    openFrom: hm(r.openFrom, d.openFrom), openTo: hm(r.openTo, d.openTo),
    receiveFrom: hm(r.receiveFrom, d.receiveFrom), receiveTo: hm(r.receiveTo, d.receiveTo),
    closedDays: Array.isArray(r.closedDays) ? r.closedDays.map(Number).filter((x) => x >= 0 && x <= 6) : d.closedDays,
    peak: Array.isArray(r.peak) ? r.peak.filter((p) => p && /^\d{1,2}:\d{2}$/.test(p.from) && /^\d{1,2}:\d{2}$/.test(p.to)).slice(0, 4) : d.peak,
    capNormal: num(r.capNormal, d.capNormal, 1, 50), capPeak: num(r.capPeak, d.capPeak, 1, 50),
    leadMinutes: num(r.leadMinutes, d.leadMinutes, 0, 600),
    delaySteps: Array.isArray(r.delaySteps) && r.delaySteps.length ? r.delaySteps.map((x) => num(x, 60, 15, 720)).slice(0, 4) : d.delaySteps,
  }
}

const mins = (hm) => { const [h, m] = String(hm).split(':').map(Number); return h * 60 + m }
const minOf = (d) => d.getHours() * 60 + d.getMinutes()
const WD = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
export const hourText = (d) => { const h = d.getHours(), m = d.getMinutes(); return `${((h + 11) % 12) + 1}${m ? ':' + String(m).padStart(2, '0') : ''} ${h < 12 ? 'ص' : 'م'}` }
export function dayText(d, now = new Date()) {
  const a = new Date(now); a.setHours(0, 0, 0, 0); const b = new Date(d); b.setHours(0, 0, 0, 0)
  const diff = Math.round((b - a) / 86400000)
  return diff === 0 ? 'اليوم' : diff === 1 ? 'بكرة' : `${WD[d.getDay()]} ${d.getDate()}/${d.getMonth() + 1}`
}

export const isClosedDay = (cfg, d) => cfg.closedDays.includes(d.getDay())
export const isOpenAt = (cfg, d) => !isClosedDay(cfg, d) && minOf(d) >= mins(cfg.openFrom) && minOf(d) < mins(cfg.openTo)
export const isPeak = (cfg, d) => cfg.peak.some((p) => minOf(d) >= mins(p.from) && minOf(d) < mins(p.to))
export const isReceiving = (cfg, d) => minOf(d) >= mins(cfg.receiveFrom) && minOf(d) < mins(cfg.receiveTo)
const capOf = (cfg, d) => (isPeak(cfg, d) ? cfg.capPeak : cfg.capNormal)
const keyOf = (d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}-${d.getHours()}`

/** Bucket booked appointment times (ISO strings) per local hour. */
export function bucketLoad(times = []) {
  const m = {}
  for (const t of times) { const d = new Date(t); if (!isNaN(d)) m[keyOf(d)] = (m[keyOf(d)] || 0) + 1 }
  return m
}

/** First free hourly slot at or after `minTs` (ms). Looks 10 days ahead. */
export function nextSlot(cfg, load, minTs) {
  const d = new Date(minTs); d.setMinutes(0, 0, 0)
  if (d.getTime() < minTs) d.setHours(d.getHours() + 1)
  for (let i = 0; i < 24 * 10; i++, d.setHours(d.getHours() + 1)) {
    if (!isOpenAt(cfg, d)) continue
    if ((load[keyOf(d)] || 0) >= capOf(cfg, d)) continue
    return { at: new Date(d), peak: isPeak(cfg, d), left: capOf(cfg, d) - (load[keyOf(d)] || 0) }
  }
  return null
}

/** The open "during the day" window that contains/follows `ts`: starts at the next free slot, ends at closing. */
export function dayWindow(cfg, load, ts) {
  const s = nextSlot(cfg, load, ts)
  if (!s) return null
  const end = new Date(s.at); end.setHours(0, 0, 0, 0); end.setMinutes(mins(cfg.openTo))
  return { from: s.at, to: end > s.at ? end : s.at }
}

/**
 * One offer in the postpone ladder. step 0 = earliest slot; 1..n = +delaySteps[i-1] minutes after the first offer;
 * the last step = open window "any time during the day" (the technician calls ~1h before arriving).
 */
export function offerFor(cfg, load, now, step, baseAt) {
  const lead = now.getTime() + cfg.leadMinutes * 60000
  const first = baseAt ? new Date(baseAt) : (nextSlot(cfg, load, lead) || {}).at
  if (!first) return null
  const day = (d) => `${dayText(d, now)} الساعة ${hourText(d)}`
  const steps = cfg.delaySteps
  if (step <= steps.length) {
    const min = step === 0 ? first.getTime() : first.getTime() + steps[step - 1] * 60000
    const s = nextSlot(cfg, load, Math.max(min, lead))
    if (!s) return null
    return { step, kind: 'slot', at: s.at.toISOString(), peak: s.peak, label: `${day(s.at)}`, base: first.toISOString() }
  }
  const w = dayWindow(cfg, load, Math.max(first.getTime(), lead))
  if (!w) return null
  return { step, kind: 'window', at: w.from.toISOString(), label: `${dayText(w.from, now)} خلال اليوم (من ${hourText(w.from)} إلى ${hourText(w.to)}) — الفني يتصل فيك قبل الوصول بساعة`, base: first.toISOString() }
}
