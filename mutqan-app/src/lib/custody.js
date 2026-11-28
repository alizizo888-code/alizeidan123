// Money between the company and one technician, worked out the same way on both sides.
//
// The owner's accounts page and the technician's own screen used to be two different stories: the
// owner could see «عليه للشركة 140», the technician saw nothing at all — not the cash in his
// pocket, not what he owed, not that the owner had received it. One function now answers for both,
// so the two screens cannot disagree. (Mirrored for dispatch in PHP: Mutqan_Wallet.)
//
// Two ways of working with a technician (owner order 2026-11-22), chosen per technician in tech_wallet:
//  «نسبة» (commission, the default) — he collects, keeps his share, hands the rest in:
//     commission = price × share%      custody = cash he collected
//     balance    = custody − commission − handed in + paid to him + penalties − bonuses − adjustments
//     balance > 0 → he owes the company ;  balance < 0 → the company owes him.
//  «رصيد مسبق» (prepaid) — he transfers money first and gets credit; every finished job takes the company's
//  cut out of that credit (cash jobs), or adds his share to it when the company was paid directly:
//     credit = top-ups + bonuses + adjustments − penalties − refunds − Σ company cut (cash) + Σ his share (non-cash)
//     credit below the minimum → no new jobs until he tops up.
import { orderAmount } from '../payment'

export const DONE = ['completed', 'closed']
export { starsOf } from './stars'

export const isCash = (o) => /cash/i.test(String(o.paymentMethod || '')) || o.paymentMethod === 'نقدًا'

/** Whether a finished job counts toward a technician's account (site-team jobs are in-house). */
// A job he reported «تعذّر الإصلاح» on (noCommission) is never his work and carries no share.
export const countsFor = (o, techId) => DONE.includes(o.status) && !o.inhouse && o.technicianId === techId && o.dispatch !== 'site' && !o.noCommission

// Ledger line kinds. `in`/`out` are the original two (old rows have only `dir`).
export const KINDS = {
  in: { label: 'استلام نقدية منه', sign: -1, prepaid: 0, icon: '📥' },
  out: { label: 'صرف أرباح له', sign: 1, prepaid: -1, icon: '📤' },
  topup: { label: 'شحن رصيد (حوّل لنا)', sign: -1, prepaid: 1, icon: '💳' },
  bonus: { label: 'مكافأة', sign: -1, prepaid: 1, icon: '🎁' },
  penalty: { label: 'عقوبة / خصم', sign: 1, prepaid: -1, icon: '⚠️' },
  adjust: { label: 'تعديل يدوي', sign: -1, prepaid: 1, icon: '✏️' }, // amount may be negative
  refund: { label: 'رد رصيد له', sign: 1, prepaid: -1, icon: '↩️' },
}
export const kindOf = (s) => (KINDS[s.kind] ? s.kind : s.dir === 'out' ? 'out' : 'in')

// cashSharePct (owner order 2026-11-22): his share when the customer paid CASH — lower than when the money came to the
// company by transfer / card, so the technician has a reason to ask for a transfer. Empty = the same share either way.
export function custody(techId, orders, settlements, sharePct, wallet = null, cashSharePct = 0, cashCap = 0) {
  const w = wallet || {}
  // «نظام راتب»: no share of any job — the cash he collects is all the company's (his pay is lib/salary.js)
  const share = w.mode === 'salary' ? 0 : Number(w.sharePct) > 0 && Number(w.sharePct) <= 100 ? Number(w.sharePct) : Number(sharePct) || 0
  const cs = w.mode === 'salary' ? 0 : [Number(w.cashSharePct), Number(cashSharePct)].find((v) => v > 0 && v <= 100) || share
  const mode = w.mode === 'prepaid' ? 'prepaid' : w.mode === 'salary' ? 'salary' : 'commission'
  const since = mode === 'prepaid' && w.modeSince ? Date.parse(w.modeSince) : 0
  const r = { mode, share, cashShare: cs, jobs: 0, sales: 0, commission: 0, cash: 0, settledIn: 0, settledOut: 0, bonus: 0, penalty: 0, adjust: 0, topup: 0, refund: 0, cutCash: 0, shareNonCash: 0, jobLines: [] }
  for (const o of orders || []) {
    if (!countsFor(o, techId)) continue
    const a = orderAmount(o)
    const cash = o.paymentStatus === 'paid' && isCash(o)
    const sh = cash || o.paymentStatus !== 'paid' ? cs : share
    r.jobs++; r.sales += a; r.commission += a * sh / 100
    if (cash) r.cash += a
    const t = Date.parse(o.completedAt || o.updatedAt || '') || 0
    // every finished job is a line of its own: what the company took (or credited) automatically
    const line = { orderId: o.id, at: o.completedAt || o.updatedAt || '', amount: a, share: sh, cash: cash || o.paymentStatus !== 'paid', mine: a * sh / 100, company: a * (100 - sh) / 100, auto: 0 }
    if (mode === 'prepaid' && t >= since) {
      if (cash || o.paymentStatus !== 'paid') { r.cutCash += line.company; line.auto = -line.company }
      else { r.shareNonCash += line.mine; line.auto = line.mine }
    }
    r.jobLines.push(line)
  }
  for (const s of settlements || []) {
    if (s.techId !== techId) continue
    const n = Number(s.amount) || 0
    const k = kindOf(s)
    if (k === 'out') r.settledOut += n
    else if (k === 'in') r.settledIn += n
    else r[k] += n
  }
  // salary: bonuses, penalties and his pay belong to the payroll, not to the cash he holds
  r.balance = mode === 'salary' ? r.cash - r.settledIn : r.cash - r.commission - r.settledIn + r.settledOut + r.penalty - r.bonus - r.adjust
  r.credit = r.topup + r.bonus + r.adjust - r.penalty - r.refund - r.settledOut - r.cutCash + r.shareNonCash
  r.min = Number(w.minBalance) || 0
  r.frozen = !!w.frozen
  r.low = mode === 'prepaid' && r.credit < r.min
  // too much company cash in his pocket (commission mode) → hand it in before new jobs (PHP: Mutqan_Wallet::owes)
  r.cap = Number(w.cashCap) > 0 ? Number(w.cashCap) : Number(cashCap) || 0
  r.capped = mode !== 'prepaid' && r.cap > 0 && r.balance > r.cap
  r.blocked = r.frozen || r.low || r.capped
  return r
}
