// «من لحظات / من 5 دقايق / من ساعتين / من 3 أيام» — one wording for every order, message, step, notification
// and offer, so nobody reads an old line as new (or a new one as old).
const toMs = (v) => {
  if (v == null || v === '') return NaN
  if (typeof v === 'number') return v < 1e12 ? v * 1000 : v
  if (v instanceof Date) return v.getTime()
  if (typeof v === 'object' && typeof v.toDate === 'function') return v.toDate().getTime()
  return Date.parse(v)
}

const unit = (n, one, two, few, many) => (n === 1 ? one : n === 2 ? two : n <= 10 ? `${n} ${few}` : `${n} ${many}`)

export function ago(v, now = Date.now()) {
  const t = toMs(v)
  if (!Number.isFinite(t)) return ''
  let s = Math.round((now - t) / 1000)
  const future = s < 0
  s = Math.abs(s)
  let w
  if (s < 45) return future ? 'خلال لحظات' : 'من لحظات'
  if (s < 3600) { const m = Math.max(1, Math.round(s / 60)); w = unit(m, 'دقيقة', 'دقيقتين', 'دقايق', 'دقيقة') }
  else if (s < 86400) { const h = Math.round(s / 3600); w = unit(h, 'ساعة', 'ساعتين', 'ساعات', 'ساعة') }
  else if (s < 30 * 86400) { const d = Math.round(s / 86400); w = unit(d, 'يوم', 'يومين', 'أيام', 'يوم') }
  else if (s < 365 * 86400) { const mo = Math.round(s / (30 * 86400)); w = unit(mo, 'شهر', 'شهرين', 'شهور', 'شهر') }
  else { const y = Math.round(s / (365 * 86400)); w = unit(y, 'سنة', 'سنتين', 'سنين', 'سنة') }
  return (future ? 'بعد ' : 'من ') + w
}

/** The exact moment, for the tooltip next to the relative wording. */
export function exact(v) {
  const t = toMs(v)
  if (!Number.isFinite(t)) return ''
  try { return new Date(t).toLocaleString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' }) } catch { return new Date(t).toISOString() }
}

/** The same wording without «من», for places that already say «منذ» or sit in a column called «منذ». */
export function since(v, now = Date.now()) { const w = ago(v, now); return w.replace(/^من /, '') }
