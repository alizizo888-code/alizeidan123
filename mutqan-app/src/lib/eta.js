// Straight-line distance → a realistic arrival estimate. Roads are ~35% longer than the straight line; city driving averages ~35 km/h.
const ROAD = 1.35
const KMH = 35
export const etaMinutes = (meters) => (Number.isFinite(meters) ? Math.max(1, Math.round((meters * ROAD / 1000) / KMH * 60)) : null)
export const etaText = (meters) => {
  const m = etaMinutes(meters)
  if (m == null) return ''
  return m < 60 ? `≈ ${m} د` : `≈ ${Math.floor(m / 60)} س ${m % 60 ? `${m % 60} د` : ''}`.trim()
}
export const kmText = (m) => (!Number.isFinite(m) ? '' : m < 1000 ? `${Math.round(m)} م` : `${(m / 1000).toFixed(1)} كم`)
