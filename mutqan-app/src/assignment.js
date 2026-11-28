// Pure auto-assignment logic for Metqan's operations room.
// Shared by the operations supervisor (distribution) and the technician radar.
import { starsOf } from './lib/stars'

const isToday = (iso) => {
  if (!iso) return false
  const d = new Date(iso)
  const now = new Date()
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate()
}

// How many jobs this technician is carrying today (still-active or assigned today).
export function assignedToday(techUserId, orders) {
  return orders.filter((o) => o.technicianId === techUserId && isToday(o.assignedAt || o.createdAt)).length
}

// The capacity the technician's own form starts at. A profile made any other way — approved from a
// join request, created by the assistant, imported — may carry none, and reading "none" as zero made
// that technician permanently "full": invisible to auto-dispatch and counted as unavailable.
export const DEFAULT_CAPACITY = 8
export const capacityOf = (tech) => { const c = Number(tech?.dailyCapacity); return c > 0 ? c : DEFAULT_CAPACITY }

// Daily capacity remaining = dailyCapacity − today's assigned jobs.
export function remainingCapacity(tech, orders) {
  const cap = capacityOf(tech)
  return Math.max(0, cap - assignedToday(tech.userId, orders))
}

// Working hours (owner, 2026-11-23): `shift = { from: '08:00', to: '23:00', off: [5] }` in Makkah time — `off` holds the
// week days he doesn't work (0 = Sunday … 5 = Friday). A shift that ends after midnight (18:00 → 02:00) is fine.
// No shift written = works any time. PHP twin: Mutqan_Dispatch::on_shift().
const RIYADH = (now) => { try { const p = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Riyadh', hour12: false, weekday: 'short', hour: '2-digit', minute: '2-digit' }).formatToParts(now); const g = (k) => p.find((x) => x.type === k)?.value; return { day: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(g('weekday')), min: (Number(g('hour')) % 24) * 60 + Number(g('minute')) } } catch { return null } }
const hhmm = (v) => { const m = /^(\d{1,2}):(\d{2})$/.exec(String(v || '').trim()); return m ? Number(m[1]) * 60 + Number(m[2]) : null }
export function onShift(tech, now = new Date()) {
  const sh = tech?.shift
  if (!sh || typeof sh !== 'object') return true
  const t = RIYADH(now)
  if (!t) return true
  const from = hhmm(sh.from), to = hhmm(sh.to)
  const off = Array.isArray(sh.off) ? sh.off.map(Number) : []
  if (from === null || to === null || from === to) return !off.includes(t.day)
  if (from < to) return !off.includes(t.day) && t.min >= from && t.min < to
  // over midnight: the early-morning part belongs to the day before
  if (t.min >= from) return !off.includes(t.day)
  return t.min < to && !off.includes((t.day + 6) % 7)
}

// Coverage area (owner, 2026-11-23): `zones = ['مكة', 'العزيزية', …]`. An order whose address names none of them is not
// his. An order with no address yet, or a technician with no zones, matches. PHP twin: Mutqan_Dispatch::in_zone().
export const normAr = (v) => String(v || '').toLowerCase().replace(/[أإآا]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[\u064B-\u0652\u0640]/g, '').replace(/^ال|\sال/g, ' ').replace(/\s+/g, ' ').trim()
export function inZone(tech, order) {
  const zones = (Array.isArray(tech?.zones) ? tech.zones : []).map(normAr).filter(Boolean)
  if (!zones.length || !order) return true
  const where = normAr([order.address, order.city, order.district, order.area, order.neighborhood].filter(Boolean).join(' '))
  if (!where) return true
  return zones.some((z) => where.includes(z))
}

// A technician can take a job only if they are active, on shift AND have capacity left today.
export function isTechAvailable(tech, orders, now = new Date()) {
  if (tech.active === false) return false
  if (tech.walletFrozen) return false // frozen by the owner in «إدارة الفني» (mirrored from tech_wallet)
  if (!onShift(tech, now)) return false
  return remainingCapacity(tech, orders) > 0
}
/** Available for THIS order: also inside his coverage area and not one he already passed on. */
export function canTake(tech, order, orders) {
  if (!isTechAvailable(tech, orders) || !inZone(tech, order)) return false
  const skip = [...(order?.abortedTechIds || []), ...(order?.skipTechIds || [])].map(String)
  return !skip.includes(String(tech.userId))
}

const RUN = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
const minsSince = (iso) => (iso ? (Date.now() - new Date(iso).getTime()) / 60000 : Infinity)
// online = app seen in the last 10 min, recent = last hour.
export function presence(tech) {
  const m = minsSince(tech.lastSeenAt)
  return m <= 10 ? 'online' : m <= 60 ? 'recent' : 'offline'
}
// "Free now" = not in the middle of a job.
export const runningJobs = (tech, orders) => orders.filter((o) => o.technicianId === tech.userId && RUN.includes(o.status)).length
export const doneJobs = (tech, orders, days = 30) => orders.filter((o) => o.technicianId === tech.userId && ['completed', 'closed'].includes(o.status) && minsSince(o.completedAt || o.updatedAt) <= days * 1440).length
// Urgent first, then oldest first — the order in which the auto distributor works through the queue.
export const sortByPriority = (list) => [...list].sort((a, b) => ((b.priority === 'urgent') - (a.priority === 'urgent')) || (new Date(a.createdAt || 0) - new Date(b.createdAt || 0)))

function techDistance(tech, orderLat, orderLng, distanceFn) {
  if (tech.lat != null && tech.lng != null && minsSince(tech.lastSeenAt) <= 10 && distanceFn) {
    try { return distanceFn(tech.lat, tech.lng, orderLat, orderLng) } catch { return Infinity }
  }
  if (tech.homeLat != null && tech.homeLng != null && distanceFn) {
    try {
      return distanceFn(tech.homeLat, tech.homeLng, orderLat, orderLng)
    } catch {
      return Infinity
    }
  }
  return Infinity
}

export const TECH_LEVELS = [
  { id: 'junior', label: 'مبتدئ', w: 0.4 },
  { id: 'senior', label: 'محترف', w: 0.7 },
  { id: 'expert', label: 'خبير', w: 1 },
]
export const techLevelLabel = (id) => TECH_LEVELS.find((l) => l.id === id)?.label || 'محترف'

function techRating(tech, orders) {
  const rated = orders.filter((o) => o.technicianId === tech.userId && starsOf(o))
  if (!rated.length) return null
  return rated.reduce((s, o) => s + starsOf(o), 0) / rated.length
}

// Smart matching: scores every technician on location/distance, specialty,
// availability, workload, service type, priority and qualification. It only
// RANKS — the final link still follows MUTQAN rules (capacity, acceptance, roles).
export function scoreTechnicians(order, techs, orders, { distanceFn } = {}) {
  const urgent = order?.priority === 'urgent'
  return techs
    .map((t) => {
      const reasons = []
      const available = canTake(t, order, orders)
      const rem = remainingCapacity(t, orders)
      const cap = capacityOf(t)
      const distM = order?.lat != null ? techDistance(t, order.lat, order.lng, distanceFn) : Infinity
      const km = Number.isFinite(distM) ? Math.round(distM / 100) / 10 : null

      const distScore = km == null ? 0.3 : Math.max(0, 1 - km / 40)
      if (km != null) reasons.push(`${km} كم`)
      else reasons.push('بدون موقع')

      const specs = Array.isArray(t.specialties) ? t.specialties : []
      const specScore = !specs.length ? 0.5 : specs.includes(order?.specialtyId) ? 1 : 0
      if (specs.length) reasons.push(specScore ? 'تخصص مطابق' : 'خارج تخصصه')

      const workScore = cap ? rem / cap : 0
      reasons.push(`سعة ${rem}/${cap}`)

      const scopeScore = t.scope === 'wash_maintenance_install' ? 1 : t.scope === 'wash_maintenance' ? 0.75 : 0.5
      const level = TECH_LEVELS.find((l) => l.id === t.level) || TECH_LEVELS[1]
      const rating = techRating(t, orders)
      const qualScore = level.w * 0.6 + (rating ? rating / 5 : 0.7) * 0.4
      reasons.push(level.label + (rating ? ` · ⭐${rating.toFixed(1)}` : ''))

      const pres = presence(t)
      const presScore = pres === 'online' ? 1 : pres === 'recent' ? 0.5 : 0
      reasons.push(pres === 'online' ? 'أونلاين' : pres === 'recent' ? 'ظهر مؤخرًا' : 'غير متصل')
      const running = runningJobs(t, orders)
      const freeScore = running === 0 ? 1 : running === 1 ? 0.4 : 0
      reasons.push(running === 0 ? 'متفرغ الآن' : `مشغول (${running})`)
      const done = doneJobs(t, orders)
      const maxDone = Math.max(1, ...techs.map((x) => doneJobs(x, orders)))
      const prodScore = done / maxDone
      if (done) reasons.push(`أنجز ${done}`)

      // Urgent orders weigh closeness + presence + being free more; normal ones balance workload and track record.
      const w = urgent
        ? { dist: 0.3, pres: 0.2, free: 0.16, spec: 0.12, work: 0.04, scope: 0.04, qual: 0.07, prod: 0.07 }
        : { dist: 0.22, pres: 0.16, free: 0.12, spec: 0.14, work: 0.1, scope: 0.04, qual: 0.1, prod: 0.12 }
      let score = distScore * w.dist + presScore * w.pres + freeScore * w.free + specScore * w.spec + workScore * w.work + scopeScore * w.scope + qualScore * w.qual + prodScore * w.prod
      if (pres === 'offline') score *= 0.7
      if (!available) score = 0
      if (!inZone(t, order)) reasons.push('خارج منطقته')
      else if (!onShift(t)) reasons.push('خارج ساعات شغله')
      const boost = Math.max(-30, Math.min(30, Number(t.scoreBoost) || 0)) // the owner's push (PHP twin: Mutqan_Wallet::boost)
      return { tech: t, available, score: available ? Math.max(0, Math.round(score * 100) + boost) : 0, km, rem, reasons }
    })
    .sort((a, b) => b.score - a.score)
}

// Choose the best technician for an order, or null when nobody qualifies (→ the backup technician, then إجباري).
export function pickTechnician(order, techs, orders, { strategy = 'nearest', distanceFn } = {}) {
  const available = techs.filter((t) => canTake(t, order, orders))
  if (available.length === 0) return null
  if (strategy === 'smart') {
    const ranked = scoreTechnicians(order, available, orders, { distanceFn }).filter((r) => r.available && r.score > 0)
    // Connected technicians first; offline ones only when nobody is online.
    const top = ranked.find((r) => presence(r.tech) !== 'offline') || ranked[0]
    return top?.tech || null
  }
  if (strategy === 'capacity') {
    return [...available].sort((a, b) => remainingCapacity(b, orders) - remainingCapacity(a, orders))[0]
  }
  // nearest — technicians with no location fall to the end of the line.
  return [...available].sort(
    (a, b) => techDistance(a, order.lat, order.lng, distanceFn) - techDistance(b, order.lat, order.lng, distanceFn),
  )[0]
}
