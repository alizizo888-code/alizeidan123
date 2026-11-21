// «حملات الاستقطاب» on the hub (owner, 2026-11-21): which line sends what, and how slowly.
//
// The website owns the plan (who is due, which text, each line's daily ceiling — Mutqan_Outreach). The hub owns the
// lines: it tells the website which of its connected lines are outreach numbers and what each already sent today, and
// it sends what comes back one message at a time per line, with a random pause between two messages of one line.
// Lanes never mix: a customer's reply, the room and the technicians never leave from an outreach line, and a campaign
// message never leaves from a sales line or the manager — a ban on an outreach number touches nothing else.
import { pkey } from './text.js'

/** The job of a connected line from the website's list of our numbers (role by phone); '' when it is not listed. */
export function roleOf(phone, ourNumbers = {}) {
  const r = ourNumbers[pkey(phone)]
  return r ? r.role : ''
}

/** The hub's own role names for a number's job: the manager is team, outreach is outreach, sales serve customers. */
export function hubRole(role, current = 'customers', status = 'active') {
  if (status === 'paused') return 'paused'
  return role === 'manager' ? 'team' : role === 'outreach' ? 'outreach' : role === 'sales' ? 'customers' : current
}

/**
 * The line a queued message leaves from. o = {line, phone, purpose, brand}; open = connected lines [{id, phone, role}].
 * Campaign messages only from outreach lines (none connected → it waits); everything else never from an outreach line
 * unless no other line is connected at all.
 */
export function pickLine(o, open, set = {}) {
  if (!open.length) return null
  if (o.purpose === 'outreach') {
    const ol = open.filter((l) => l.role === 'outreach')
    return (ol.find((l) => l.id === o.line) || null)?.id || null
  }
  if (o.line && open.some((l) => l.id === o.line)) return o.line
  const notSelf = open.filter((l) => pkey(l.phone) !== pkey(o.phone))
  let pool = notSelf.length ? notSelf : open
  const noOut = pool.filter((l) => l.role !== 'outreach' && l.role !== 'paused')
  if (noOut.length) pool = noOut
  if (o.brand && set.brandLines && set.brandLines[o.brand] && pool.some((l) => l.id === set.brandLines[o.brand])) return set.brandLines[o.brand]
  const want = ['room', 'tech', 'agent'].includes(o.purpose) ? 'team' : 'customers'
  return (pool.find((l) => l.role === want) || pool.find((l) => l.role === 'customers') || pool[0]).id
}

/**
 * Lines to prepare for linking (owner, 2026-11-21): every number of ours with a job that has no line yet gets one,
 * named and given its role, waiting for its QR scan. ours = {last9: {role, label, status, local}}; lines = store lines.
 */
export function toPrepare(ours = {}, lines = []) {
  const have = new Set(lines.flatMap((l) => [l.phone ? pkey(l.phone) : '', String(l.id).startsWith('n') ? String(l.id).slice(1) : '']).filter(Boolean))
  return Object.entries(ours).filter(([k, r]) => !have.has(k) && r.role !== 'reserve').map(([k, r]) => ({ id: 'n' + k, label: (r.label || 'رقم') + ' — ' + (r.local || '0' + k), role: hubRole(r.role, 'customers', r.status), phone: '' }))
}

/** Seconds a line waits between two campaign messages: 70–200, different every time (a fixed beat looks like a bot). */
export const gapSec = (rnd = Math.random) => 70 + Math.floor(rnd() * 130)

/** May this line send its next campaign message now? last = {at, gap} per line id. */
export function due(lineId, last, now = Date.now()) {
  const l = last.get(lineId)
  return !l || now - l.at >= l.gap * 1000
}
