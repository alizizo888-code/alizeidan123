// A technician's performance over the last N days (owner, 2026-11-23 — idea 10): how many orders he got, finished,
// dropped or let pass, how fast he accepts and arrives, his customers' rating and complaints, and what he brought in.
import { starsOf } from './stars'

const ts = (iso) => { const t = Date.parse(iso || ''); return Number.isFinite(t) ? t : 0 }
const firstAt = (o, statuses) => { for (const h of o.statusHistory || []) if (statuses.includes(h?.status)) return ts(h.at); return 0 }
const avg = (xs) => (xs.length ? Math.round(xs.reduce((a, b) => a + b, 0) / xs.length) : null)
const price = (o) => Number(o.finalPrice ?? o.agreedPrice ?? o.price ?? o.quotedTotal) || 0

export function techPerformance(techId, orders, { days = 30, now = Date.now() } = {}) {
  const id = String(techId || '')
  const from = now - days * 86400000
  const recent = (orders || []).filter((o) => ts(o.createdAt) >= from)
  const mine = recent.filter((o) => String(o.technicianId || '') === id)
  const passed = recent.filter((o) => (o.skipTechIds || []).map(String).includes(id)).length          // given to him, he didn't accept
  const aborted = recent.filter((o) => (o.abortedTechIds || []).map(String).includes(id)).length       // «couldn't fix it»
  const done = mine.filter((o) => ['completed', 'closed'].includes(o.status))
  const cancelled = mine.filter((o) => o.status === 'cancelled').length
  const acceptMins = []; const arriveMins = []
  for (const o of mine) {
    const a = ts(o.assignedAt) || firstAt(o, ['assigned'])
    const ac = firstAt(o, ['accepted'])
    const ar = firstAt(o, ['arrived', 'in_progress'])
    if (a && ac && ac >= a) acceptMins.push((ac - a) / 60000)
    if ((ac || a) && ar && ar >= (ac || a)) arriveMins.push((ar - (ac || a)) / 60000)
  }
  const rated = done.map(starsOf).filter(Boolean)
  const complaints = mine.filter((o) => o.escalation || o.warrantyClaim || o.complaint).length
  const total = mine.length + passed
  return {
    id,
    received: total,
    done: done.length,
    open: mine.filter((o) => !['completed', 'closed', 'cancelled'].includes(o.status)).length,
    cancelled,
    passed,
    aborted,
    acceptAvg: avg(acceptMins),
    arriveAvg: avg(arriveMins),
    rating: rated.length ? Math.round((rated.reduce((a, b) => a + b, 0) / rated.length) * 10) / 10 : null,
    ratedCount: rated.length,
    complaints,
    revenue: done.reduce((s, o) => s + price(o), 0),
    ...qualityOf(mine, done, rated),
    doneRate: total ? Math.round((done.length / total) * 100) : null,
  }
}

// «سكور الفني» (owner, 2026-11-23) — the twin of Mutqan_Dispatch::quality() on the server: stars 55, the good things
// customers ticked 20, how many rated jobs 10, then minus jobs hanging open > a day, jobs only operations closed, returns.
const ACTIVE = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
function qualityOf(mine, done, rated) {
  const withTraits = done.filter((o) => (o.rating?.traits || o.ratingTags || []).length)
  const traits = withTraits.reduce((s, o) => s + Math.min(8, (o.rating?.traits || o.ratingTags).length), 0)
  const stuck = mine.filter((o) => ACTIVE.includes(o.status) && Date.now() - ts(o.createdAt) > 86400000).length
  const opsClosed = done.filter((o) => { const h = [...(o.statusHistory || [])].reverse().find((x) => x?.status === 'completed'); return h && ['operations', 'owner', 'supervisor'].includes(h.by) && !h.actedBy }).length
  const returns = mine.filter((o) => o.warrantyClaim || o.returnOf || o.complaint).length
  const r = rated.length ? rated.reduce((a, b) => a + b, 0) / rated.length : null
  let q = (r ? r / 5 : 0.6) * 55 + (withTraits.length ? traits / (withTraits.length * 8) : 0.5) * 20 + Math.min(1, rated.length / 10) * 10 + 15
  q -= Math.min(15, stuck * 5) + (done.length ? Math.min(15, (30 * opsClosed) / done.length) : 0) + Math.min(20, returns * 4)
  return { score: Math.max(0, Math.min(100, Math.round(q))), stuck, opsClosed, returns, praised: withTraits.length }
}

/** The whole team, best first (most finished, then best rating). */
export function teamPerformance(profiles, orders, opts) {
  return (profiles || [])
    .filter((t) => t.userId)
    .map((t) => ({ tech: t, ...techPerformance(t.userId, orders, opts) }))
    .sort((a, b) => b.done - a.done || (b.rating || 0) - (a.rating || 0))
}
