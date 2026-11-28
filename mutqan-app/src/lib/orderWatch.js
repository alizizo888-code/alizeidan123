// The follow-up rules for open orders (owner, 2026-11-23 — ideas 1–4). Twin of Mutqan_Watch::flags_of() on the server:
// the same minutes and the same sentences, so the screen and the alert on the phone say the same thing.
const ACTIVE = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
const MOVING = ['accepted', 'en_route', 'nearby']
const ONSITE = ['arrived', 'in_progress']

export function watchCfg(s = {}) {
  const n = (k, d) => { const v = parseInt(s?.[k], 10); return v > 0 ? v : d }
  return { unconfirmed: n('unconfirmedMinutes', 10), accept: n('acceptMinutes', 5), stall: n('stallMinutes', 60), onsite: n('onsiteMinutes', 180), late: n('lateMinutes', 30) }
}

const ts = (iso) => { const t = Date.parse(iso || ''); return Number.isFinite(t) ? t : 0 }
const firstAt = (o, statuses) => { for (const h of o.statusHistory || []) if (statuses.includes(h?.status)) return ts(h.at); return 0 }
export const lastMove = (o) => { const h = o.statusHistory || []; return ts(h[h.length - 1]?.at) || ts(o.assignedAt) || ts(o.createdAt) }

/** What is wrong with one order now: { unconfirmed, not_accepted, stalled, late } → sentence. */
export function flagsOf(o, now = Date.now(), cfg = watchCfg()) {
  if (!o || !ACTIVE.includes(o.status || 'new')) return {}
  const out = {}
  const st = o.status || 'new'
  const age = (now - ts(o.createdAt)) / 60000
  if (!o.customerConfirmed && age >= cfg.unconfirmed && age < 2 * 1440) out.unconfirmed = `العميل ما أكدش على واتساب من ${Math.floor(age)} دقيقة`
  if (st === 'assigned' && o.technicianId && o.dispatch !== 'site' && !o.inhouse) {
    const wait = (now - (ts(o.assignedAt) || lastMove(o))) / 60000
    if (wait >= cfg.accept) out.not_accepted = `${o.technicianName || 'الفني'} ما قبلش الطلب من ${Math.floor(wait)} دقيقة`
  }
  const still = (now - lastMove(o)) / 60000
  if (MOVING.includes(st) && still >= cfg.stall) out.stalled = `${o.technicianName || 'الفني'} مااتحركش في الطلب من ${Math.floor(still)} دقيقة`
  else if (ONSITE.includes(st) && still >= cfg.onsite) out.stalled = `${o.technicianName || 'الفني'} عند العميل من ${Math.floor(still)} دقيقة ومقفلش الطلب`
  const appt = ts(o.appointmentAt)
  if (appt && !ONSITE.includes(st) && (now - appt) / 60000 >= cfg.late && now - appt < 86400000) out.late = `الموعد عدّى من ${Math.floor((now - appt) / 60000)} دقيقة والفني لسه ما وصلش`
  return out
}

export const FLAG_META = {
  unassigned: { icon: '🙋', label: 'من غير فني', cls: 'bg-rose-500/20 text-rose-200' },
  unconfirmed: { icon: '⏳', label: 'ما اتأكدش', cls: 'bg-rose-500/20 text-rose-200' },
  not_accepted: { icon: '⌛', label: 'الفني ما قبلش', cls: 'bg-amber-500/20 text-amber-200' },
  stalled: { icon: '🐢', label: 'متعطل', cls: 'bg-amber-500/20 text-amber-200' },
  late: { icon: '🚨', label: 'متأخر عن الموعد', cls: 'bg-rose-500/25 text-rose-100' },
  objection: { icon: '⚠️', label: 'فيه اعتراض', cls: 'bg-fuchsia-500/20 text-fuchsia-200' },
}

const isUnassigned = (o) => ['new', 'forced'].includes(o.status) && !o.technicianId && !o.inhouse && o.dispatch !== 'whatsapp' && o.dispatch !== 'external'

/** «محتاج تدخل» (idea 4): every reason this order needs a person now — the flags above, no technician, an objection. */
export function attentionOf(o, now = Date.now(), cfg = watchCfg()) {
  if (!o || !ACTIVE.includes(o.status || 'new')) return []
  const f = flagsOf(o, now, cfg)
  const out = []
  if (isUnassigned(o)) out.push({ key: 'unassigned', text: 'لسه ما اتسندش لفني' })
  for (const k of ['unconfirmed', 'not_accepted', 'stalled', 'late']) if (f[k]) out.push({ key: k, text: f[k] })
  if (o.escalation) out.push({ key: 'objection', text: 'تصعيد: ' + (o.escalation.reason || o.escalation.note || 'اعتراض من العميل') })
  return out
}
export const needsAttention = (o, now, cfg) => attentionOf(o, now, cfg).length > 0

const span = (a, b) => (a && b && b >= a ? Math.round((b - a) / 60000) : null)
export const minsText = (m) => (m == null ? '—' : m < 60 ? `${m}د` : m < 1440 ? `${Math.floor(m / 60)}س${m % 60 ? ' ' + (m % 60) + 'د' : ''}` : `${Math.floor(m / 1440)}ي`)

/**
 * The order's clock (idea 2): how long each step took — order → confirmed → assigned → accepted → arrived → done.
 * A step not reached yet counts up from the step before it (`open: true`), so a stuck step shows how long it is stuck.
 */
export function orderTimes(o, now = Date.now()) {
  const created = ts(o.createdAt)
  const confirmed = ts(o.confirmedAt) || (o.customerConfirmed ? created : 0)
  const assigned = ts(o.assignedAt) || firstAt(o, ['assigned'])
  const accepted = firstAt(o, ['accepted']) || (assigned && ['en_route', 'nearby', 'arrived', 'in_progress', 'completed', 'closed'].includes(o.status) ? firstAt(o, ['en_route', 'nearby', 'arrived', 'in_progress']) : 0)
  const arrived = firstAt(o, ['arrived', 'in_progress'])
  const done = ts(o.completedAt) || firstAt(o, ['completed', 'closed'])
  const live = ACTIVE.includes(o.status || 'new')
  const steps = [
    { key: 'confirm', label: 'التأكيد', from: created, to: confirmed },
    { key: 'assign', label: 'الإسناد', from: created, to: assigned },
    { key: 'accept', label: 'القبول', from: assigned, to: accepted },
    { key: 'arrive', label: 'الوصول', from: accepted || assigned, to: arrived },
    { key: 'done', label: 'الإقفال', from: arrived, to: done },
  ]
  return steps.map((s) => {
    if (s.to) return { ...s, mins: span(s.from, s.to), open: false }
    if (live && s.from) return { ...s, mins: span(s.from, now), open: true }
    return { ...s, mins: null, open: false }
  })
}
/** From the order to now (or to its close). */
export const totalMins = (o, now = Date.now()) => span(ts(o.createdAt), ts(o.completedAt) || firstAt(o, ['completed', 'closed']) || (ACTIVE.includes(o.status || 'new') ? now : 0))
