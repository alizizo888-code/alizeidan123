// Automatic deductions (owner order 2026-11-22): the system FINDS what the rules say should be deducted — a job he
// refused after it was given to him, a visit that failed through his fault, a bad rating, a job that came back under
// warranty — and puts it in front of the owner / operations as a suggestion. Nothing is taken from him until someone
// presses «اعتماد»; «تجاهل» drops it for good. The amounts are the owner's (tech_pay.rules), editable in the accounts page.
import { starsOf } from './stars'

export const RULES = [
  { id: 'decline', label: 'اعتذار عن طلب بعد إسناده له', unit: 'sar', def: 10, hint: 'كل مرة يعتذر فيها عن طلب اتسند له (من غير ظرف تقبله الإدارة)' },
  { id: 'abort', label: 'زيارة فاشلة بسببه', unit: 'sar', def: 25, hint: 'تعذّر الإصلاح لسبب يخصه: ناقص عدة / خارج تخصصه / سبب تاني' },
  { id: 'badRating', label: 'تقييم سيء (نجمة أو اتنين)', unit: 'sar', def: 20, hint: 'تقييم العميل 1 أو 2 من 5' },
  { id: 'returnJob', label: 'شغل مرتجع في فترة الضمان', unit: 'pct', def: 50, hint: 'نسبة من قيمة الشغل الأصلي لو العطل رجع بسبب شغله' },
  { id: 'late', label: 'تأخير عن الموعد أكتر من ساعة', unit: 'sar', def: 10, hint: 'يُحسب من موعد العميل لوقت الوصول' },
  { id: 'offApp', label: 'التعامل مع عميل خارج التطبيق', unit: 'sar', def: 500, hint: 'أول مرة — والتكرار = إنهاء التعامل' },
]
// Rewards proposed the same way (approve / ignore): every 10 jobs paid by transfer or card, and the week's best technician.
export const BONUS_RULES = [
  { id: 'bonusTransfer', label: 'مكافأة كل 10 طلبات اتدفعت تحويل / شبكة', unit: 'sar', def: 50, hint: 'تشجيع الفني إنه يحوّل العميل على حساب الشركة' },
  { id: 'bonusWeekly', label: 'مكافأة أفضل فني في الأسبوع', unit: 'sar', def: 100, hint: 'أكتر طلبات مكتملة بتقييم 4.5 أو أعلى' },
]
export const ruleVal = (rules, id) => { const r = [...RULES, ...BONUS_RULES].find((x) => x.id === id); const v = Number((rules || {})[id]); return Number.isFinite(v) && v >= 0 && (rules || {})[id] !== '' && (rules || {})[id] != null ? v : r.def }
const TECH_FAULT = ['bigger', 'specialist', 'other', 'tools', 'skill']
const amountOf = (o) => Number(o?.price || o?.agreedPrice || o?.priceQuote?.total || o?.acQuote?.total || 0)

/** Suggestions not yet approved or dismissed: [{ ref, rule, amount, orderId, text }] */
export function suggest(techId, orders, settlements, wallet, rules) {
  const done = new Set((settlements || []).filter((s) => s.techId === techId && s.ruleRef).map((s) => s.ruleRef))
  const gone = new Set((wallet && wallet.dismissed) || [])
  const out = []
  const add = (ref, rule, amount, o, text) => { if (!done.has(ref) && !gone.has(ref) && amount > 0) out.push({ ref, rule, amount: Math.round(amount * 100) / 100, orderId: o?.id, text }) }
  const num = (o) => String(o?.id || '').slice(-5)
  for (const o of orders || []) {
    for (const d of o.declines || []) if (d.techId === techId) add(`decline:${o.id}:${d.at}`, 'decline', ruleVal(rules, 'decline'), o, `اعتذر عن الطلب #${num(o)} — ${d.reasonLabel || ''}`)
    for (const a of o.techAborts || []) if (a.techId === techId && TECH_FAULT.includes(a.reason)) add(`abort:${o.id}:${a.at}`, 'abort', ruleVal(rules, 'abort'), o, `زيارة فاشلة #${num(o)} — ${a.reasonLabel || ''}`)
    if (o.technicianId === techId) {
      const st = starsOf(o)
      if (st && st <= 2) add(`badRating:${o.id}`, 'badRating', ruleVal(rules, 'badRating'), o, `تقييم ${st}/5 على الطلب #${num(o)}`)
      if (o.appointmentAt && o.arrivedAt) {
        const late = (Date.parse(o.arrivedAt) - Date.parse(o.appointmentAt)) / 60000
        if (late > 60) add(`late:${o.id}`, 'late', ruleVal(rules, 'late'), o, `وصل متأخر ${Math.round(late)} دقيقة — #${num(o)}`)
      }
    }
    // a warranty job that points back to his original job
    const back = o.warrantyOf || o.returnOf
    if (back) {
      const orig = (orders || []).find((x) => x.id === back)
      if (orig && orig.technicianId === techId) add(`returnJob:${o.id}`, 'returnJob', amountOf(orig) * ruleVal(rules, 'returnJob') / 100, o, `شغل مرتجع #${num(o)} على طلبه #${num(orig)}`)
    }
  }
  return out
}

const isCashO = (o) => /cash|نقد/i.test(String(o.paymentMethod || ''))
const weekKey = (t) => { const d = new Date(t); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - ((d.getDay() + 1) % 7)); return d.toISOString().slice(0, 10) } // weeks start Saturday

export function suggestBonus(techId, orders, settlements, wallet, rules) {
  const done = new Set((settlements || []).filter((s) => s.techId === techId && s.ruleRef).map((s) => s.ruleRef))
  const gone = new Set((wallet && wallet.dismissed) || [])
  const out = []
  const add = (ref, rule, amount, text) => { if (!done.has(ref) && !gone.has(ref) && amount > 0) out.push({ ref, rule, amount, text, bonus: true }) }
  const fin = (orders || []).filter((o) => ['completed', 'closed'].includes(o.status) && !o.inhouse && o.dispatch !== 'site')
  const mineT = fin.filter((o) => o.technicianId === techId && o.paymentStatus === 'paid' && !isCashO(o))
  for (let k = 10; k <= mineT.length; k += 10) add(`bonusTransfer:${k}`, 'bonusTransfer', ruleVal(rules, 'bonusTransfer'), `وصل ${k} طلب اتدفعوا تحويل / شبكة`)
  // last full week's best (by completed jobs, average rating ≥ 4.5)
  const now = Date.now(); const lastWeek = weekKey(now - 7 * 86400000)
  const by = {}
  for (const o of fin) {
    const t = Date.parse(o.completedAt || o.updatedAt || ''); if (!t || weekKey(t) !== lastWeek || !o.technicianId) continue
    const b = (by[o.technicianId] = by[o.technicianId] || { n: 0, r: [] }); b.n++; const st = starsOf(o); if (st) b.r.push(st)
  }
  const best = Object.entries(by).filter(([, b]) => b.n >= 3 && (!b.r.length || b.r.reduce((a, c) => a + c, 0) / b.r.length >= 4.5)).sort((a, b) => b[1].n - a[1].n)[0]
  if (best && best[0] === techId) add(`bonusWeekly:${lastWeek}`, 'bonusWeekly', ruleVal(rules, 'bonusWeekly'), `أفضل فني في أسبوع ${lastWeek} (${best[1].n} طلب)`)
  return out
}
