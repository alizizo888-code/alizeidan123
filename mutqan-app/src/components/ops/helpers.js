import { maps } from '../../lib/maps'
import { remainingCapacity, isTechAvailable, scoreTechnicians } from '../../assignment'

export const DONE = ['completed', 'closed']
export const RUNNING = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
export const isToday = (iso) => { if (!iso) return false; const d = new Date(iso), n = new Date(); return d.toDateString() === n.toDateString() }
export const minsAgo = (iso) => (iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000)) : 0)
export const ageText = (iso) => { const m = minsAgo(iso); return m < 1 ? 'الآن' : m < 60 ? `${m} د` : m < 1440 ? `${Math.floor(m / 60)} س` : `${Math.floor(m / 1440)} يوم` }
export const isUnassigned = (o) => ['new', 'forced'].includes(o.status) && !o.technicianId && !o.inhouse
export const techPos = (t) => (t.lat != null && t.lng != null ? { lat: t.lat, lng: t.lng, live: minsAgo(t.lastSeenAt) <= 10 } : t.homeLat != null ? { lat: t.homeLat, lng: t.homeLng, live: false } : null)
export const kmText = (d) => (Number.isFinite(d) ? (d < 1 ? `${Math.round(d * 1000)} م` : `${d.toFixed(1)} كم`) : 'موقع غير معروف')
export function distTo(t, o) {
  const p = techPos(t)
  if (!p || o?.lat == null) return Infinity
  try { return maps.distance(p.lat, p.lng, o.lat, o.lng) / 1000 } catch { return Infinity }
}
// Technicians ranked for one order with the same smart score the auto distributor uses (distance, online, free now, specialty, track record).
export function rankTechs(order, profiles, orders) {
  const sc = scoreTechnicians(order, profiles || [], orders || [], { distanceFn: maps.distance })
  return sc.map((r) => ({ t: r.tech, dist: distTo(r.tech, order), rem: r.rem, ok: r.available, pos: techPos(r.tech), score: r.score, reasons: r.reasons }))
}
export const sourceLabel = (o) => (o.source === 'whatsapp_manual' ? 'واتساب (يدوي)' : o.routing === 'operations' ? 'الموقع' : o.source === 'whatsapp' ? 'واتساب' : 'التطبيق')

// Where an order was handed over: the built-in site technician, a team technician, technicians directly on WhatsApp, or an outside party.
export const DISPATCH_META = {
  site: { label: 'فني الموقع', icon: '🏠', cls: 'bg-sky-500/20 text-sky-200 border-sky-400/40' },
  tech: { label: 'فني الفريق', icon: '🧰', cls: 'bg-emerald-500/15 text-emerald-200 border-emerald-400/30' },
  whatsapp: { label: 'واتساب مباشر', icon: '📲', cls: 'bg-green-500/20 text-green-200 border-green-400/40' },
  external: { label: 'جهة خارجية', icon: '🤝', cls: 'bg-amber-500/20 text-amber-200 border-amber-400/40' },
}
// Already handed over outside the app (technicians directly on WhatsApp, or an outside party): auto-distribution must not give it a second technician.
export const isForwarded = (o) => o.dispatch === 'whatsapp' || o.dispatch === 'external'
export const dispatchOf = (o) => (o.dispatch && DISPATCH_META[o.dispatch] ? o.dispatch : o.inhouse ? 'site' : o.technicianId ? 'tech' : null)
