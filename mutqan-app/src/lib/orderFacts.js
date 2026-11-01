// One place that turns an order (or a draft about to become one) into the distinct facts shown everywhere —
// the WhatsApp message, the operations alert, the order screens. Each fact appears ONCE:
//   kind     نوع الطلب      → what is being asked (غسيل / تركيب ونقل / صيانة وفحص)
//   units    المكيفات       → what it is done on (سبليت × 3), or العدد for other services
//   note     التفاصيل       → only what the customer wrote himself
//   when     الموعد         → ONE appointment: day word + weekday + date + time
//   sentAt   وقت الإرسال    → when the order was submitted
import { specialtyLabel } from '../constants'

const MODES = { wash: 'غسيل', install: 'تركيب ونقل', maintenance: 'صيانة وفحص' }
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
const pad = (n) => String(n).padStart(2, '0')

export function timeText(d) {
  const h = d.getHours(), m = d.getMinutes()
  return `${h % 12 || 12}:${pad(m)} ${h < 12 ? 'ص' : 'م'}`
}
export function dayWord(d, now = new Date()) {
  const diff = Math.round((startOfDay(d) - startOfDay(now)) / 86400000)
  return diff === 0 ? 'اليوم' : diff === 1 ? 'غدًا' : diff === 2 ? 'بعد غد' : diff === -1 ? 'أمس' : ''
}
export function dateText(d) { return `${DAYS[d.getDay()]} ${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}` }
// «غدًا — السبت 2 نوفمبر 2026 — 5:00 م»
export function fullWhen(iso, now = new Date()) {
  const d = new Date(iso)
  if (!iso || isNaN(d)) return ''
  return [dayWord(d, now), dateText(d), timeText(d)].filter(Boolean).join(' — ')
}

export function orderFacts(o = {}, now = new Date()) {
  const aq = o.acQuote || o.acq || null
  let kind = aq && MODES[aq.mode] ? MODES[aq.mode] : ''
  let unitType = aq?.type || ''
  const sd = String(o.serviceDetails || '').trim()
  if (!kind && sd) {                                   // "غسيل — سبليت × 3" (older orders carry only this text)
    const [k, rest] = sd.split(' — ')
    if (rest) { kind = k.trim(); unitType = unitType || rest.replace(/\s*×\s*\d+\s*$/, '').trim() } else if (sd !== String(o.description || o.problem || '').trim()) kind = sd
  }
  const qty = Number(aq?.qty || o.qty) || 0
  const isAc = o.specialtyId === 'ac'
  let units = ''
  if (isAc && (unitType || qty >= 1) && kind !== 'صيانة وفحص') units = `${unitType ? unitType + ' ' : ''}× ${qty >= 1 ? qty : 1}`.trim()
  else if (isAc && unitType) units = unitType
  else if (qty > 1) units = `× ${qty}`
  let note = String(o.description || o.problem || '').trim()
  if (note && kind && (note === kind || note === sd)) note = ''   // the same text again is not a detail
  const at = o.appointmentAt && !isNaN(new Date(o.appointmentAt)) ? o.appointmentAt : null
  const when = at ? fullWhen(at, now) : (o.priority === 'urgent' ? '⚡ عاجل — الآن' : (o.preferredTime || 'أقرب وقت'))
  const sent = o.createdAt || o.bookedAt
  const sentAt = sent && !isNaN(new Date(sent)) ? fullWhen(sent, now) : ''
  return { service: specialtyLabel(o.specialtyId) || '', kind, units, unitsLabel: isAc ? 'المكيفات' : 'العدد', note, when, sentAt }
}
