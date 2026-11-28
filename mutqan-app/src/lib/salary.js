// «نظام راتب» (owner, 2026-11-24) — a technician on a monthly salary instead of a share of each job.
//
// He never sees a percentage. What he earns in the month:
//   المستحق = الراتب المتفق عليه
//           + مكافأة كل طلب تمام (صغيرة: 3–5 ر.س — طلب من غير شكوى ولا تقييم أقل من 4 ولا رجوع)
//           + مكافأة الشهر حسب عدد الطلبات (درجات: مثلًا 30 طلب → 100، 50 → 200، 70 → 300)
//           + مكافآت يدوية − عقوبات/خصومات (من الحركات المالية)
//   والمكافآت التلقائية ليها سقف في الشهر (الافتراضي 300 ر.س) عشان هو له راتب ثابت.
// The cash he collects from customers is all the company's: he hands it in (custody balance = cash − handed in).
import { countsFor } from './custody'
import { starsOf } from './stars'

export const SALARY_DEFAULTS = { salary: 0, orderBonus: 5, maxBonus: 300, tiers: [{ n: 30, amt: 100 }, { n: 50, amt: 200 }, { n: 70, amt: 300 }] }
export const isSalary = (w) => (w || {}).mode === 'salary'
export const monthOf = (iso) => String(iso || '').slice(0, 7)

/** A job that earns the per-order bonus: no complaint, no low rating, not sent back. */
export const goodJob = (o) => !o.complaint && !o.escalation && !o.warrantyClaim && !(starsOf(o) && starsOf(o) < 4) && !o.opsClosed

export function payroll(techId, orders, settlements, wallet, month = new Date().toISOString().slice(0, 7)) {
  const w = { ...SALARY_DEFAULTS, ...(wallet || {}) }
  const tiers = (Array.isArray(w.tiers) ? w.tiers : SALARY_DEFAULTS.tiers).map((t) => ({ n: Number(t.n) || 0, amt: Number(t.amt) || 0 })).filter((t) => t.n > 0).sort((a, b) => a.n - b.n)
  const jobs = (orders || []).filter((o) => countsFor(o, techId) && monthOf(o.completedAt || o.updatedAt) === month)
  const good = jobs.filter(goodJob)
  const per = Math.max(0, Number(w.orderBonus) || 0)
  const orderBonus = good.length * per
  const tier = tiers.filter((t) => jobs.length >= t.n).pop() || null
  const next = tiers.find((t) => jobs.length < t.n) || null
  const cap = Number(w.maxBonus) > 0 ? Number(w.maxBonus) : Infinity
  const autoBonus = Math.min(cap, orderBonus + (tier ? tier.amt : 0))
  const r = { salary: Number(w.salary) || 0, jobs: jobs.length, good: good.length, per, orderBonus, tier, next, toNext: next ? next.n - jobs.length : 0, autoBonus, bonus: 0, penalty: 0, adjust: 0, paid: 0, lines: [] }
  for (const s of settlements || []) {
    if (s.techId !== techId || monthOf(s.at) !== month) continue
    const n = Number(s.amount) || 0
    if (s.kind === 'bonus') r.bonus += n
    else if (s.kind === 'penalty') r.penalty += n
    else if (s.kind === 'adjust') r.adjust += n
    else if (s.kind === 'out' || (!s.kind && s.dir === 'out')) r.paid += n
    else continue
    r.lines.push(s)
  }
  r.due = r.salary + r.autoBonus + r.bonus + r.adjust - r.penalty
  r.left = r.due - r.paid
  return r
}

export const parseTiers = (t) => String(t || '').split(/[,،\n]+/).map((x) => x.split(/[:=\-→]/).map((y) => Number(String(y).trim()))).filter(([n, a]) => n > 0 && a >= 0).map(([n, amt]) => ({ n, amt }))
export const tiersText = (tiers) => (tiers || SALARY_DEFAULTS.tiers).map((t) => `${t.n}:${t.amt}`).join('، ')
