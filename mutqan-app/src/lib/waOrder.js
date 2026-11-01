// Builds the full order message and opens the customer's own WhatsApp toward the business number.
import { specialtyLabel } from '../constants'
import { post } from './_api'

// Registers the order's key facts on the server and returns a short verification link (null if offline).
export async function sealOrder({ draft = {}, price, orderId }) {
  try {
    const c = draft.coords || {}
    const r = await post('/shop', { op: 'seal', service: specialtyLabel(draft.specialtyId) || 'خدمة', price: Number(price) || 0, time: draft.preferredTime || (draft.priority === 'urgent' ? 'عاجل — الآن' : 'أقرب وقت'), address: c.address || draft.locationText || '', ref: orderId ? String(orderId).slice(-5) : '' })
    return r?.url || null
  } catch { return null }
}

export function buildOrderMessage({ draft = {}, who = {}, price, orderId, detail, verifyUrl }) {
  const c = draft.coords || {}
  const lines = [
    '🔔 *طلب جديد — متقن*',
    orderId ? `رقم الطلب: #${String(orderId).slice(-5)}` : null,
    who.name ? `👤 الاسم: ${who.name}` : null,
    who.phone ? `📱 الجوال: ${who.phone}` : null,
    `🛠️ الخدمة: ${specialtyLabel(draft.specialtyId) || '-'}`,
    detail || draft.problem ? `📝 التفاصيل: ${detail || draft.problem}` : null,
    draft.qty > 1 ? `🔢 العدد: ${draft.qty}` : null,
    `⏰ الموعد: ${draft.preferredTime || (draft.priority === 'urgent' ? 'عاجل — الآن' : 'أقرب وقت')}`,
    draft.welcomeDiscountPct ? `🎁 خصم ترحيبي ${draft.welcomeDiscountPct}%${draft.welcomeOff ? ` (−${draft.welcomeOff} ر.س)` : ' حتى 20 ر.س'} على أول طلب` : null,
    price ? `💰 السعر المتفق: ${price} ر.س` : null,
    c.address || draft.locationText ? `🏠 العنوان: ${c.address || draft.locationText}` : null,
    c.lat ? `📍 الموقع: https://maps.google.com/?q=${c.lat},${c.lng}` : null,
    verifyUrl ? `✅ للتحقق من صحة الطلب: ${verifyUrl}` : null,
  ]
  return lines.filter(Boolean).join('\n')
}

export function waLink(number, text) {
  return `https://wa.me/${String(number || '').replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
}
export function openWhatsApp(number, text) {
  try { window.open(waLink(number, text), '_blank', 'noopener') } catch {}
}

// The order exactly as operations forwards it to a technician / an outside party over WhatsApp (everything needed to do the job, nothing about prices paid to others).
export function buildDispatchMessage(order = {}, { appUrl } = {}) {
  const o = order
  const lines = [
    '📋 *طلب للتنفيذ — متقن*',
    `رقم الطلب: #${String(o.id || '').slice(-5)}`,
    `🛠️ الخدمة: ${specialtyLabel(o.specialtyId) || '-'}`,
    o.serviceDetails || o.description ? `📝 التفاصيل: ${o.serviceDetails || o.description}` : null,
    o.qty > 1 ? `🔢 العدد: ${o.qty}` : null,
    `⏰ الموعد: ${o.preferredTime || (o.priority === 'urgent' ? 'عاجل — الآن' : 'أقرب وقت')}`,
    o.clientName ? `👤 العميل: ${o.clientName}` : null,
    o.clientPhone ? `📱 الجوال: ${o.clientPhone}` : null,
    o.address ? `🏠 العنوان: ${o.address}` : null,
    o.lat != null && o.lng != null ? `📍 الموقع: https://maps.google.com/?q=${o.lat},${o.lng}` : null,
    o.agreedPrice || o.acQuote?.total ? `💰 السعر المتفق مع العميل: ${o.agreedPrice || o.acQuote.total} ر.س` : null,
    appUrl ? `🔗 افتح الطلب: ${appUrl}` : null,
  ]
  return lines.filter(Boolean).join('\n')
}
