// Used-AC shop: shared constants + the negotiation call (the floor price never reaches the browser — the server answers).
import { post } from './_api'

export const KINDS = [
  { id: 'split', label: 'سبليت', icon: '❄️' },
  { id: 'window', label: 'شباك', icon: '🪟' },
]
// Condition grades — vivid, high-contrast colours so the state of each unit is obvious at a glance.
export const CONDITIONS = [
  { id: 'excellent', label: 'ممتاز', hint: 'شبه جديد، بدون عيوب', bg: '#15803d', ring: '#bbf7d0' },
  { id: 'good', label: 'جيد جداً', hint: 'يعمل بكفاءة، خدوش خفيفة', bg: '#1d4ed8', ring: '#bfdbfe' },
  { id: 'fair', label: 'مقبول', hint: 'يعمل، مظهر مستخدم', bg: '#b45309', ring: '#fde68a' },
]
export const STATUSES = [
  { id: 'available', label: 'متاح', bg: '#15803d' },
  { id: 'reserved', label: 'محجوز', bg: '#b45309' },
  { id: 'sold', label: 'مباع', bg: '#475569' },
  { id: 'hidden', label: 'مخفي', bg: '#334155' },
]
export const condOf = (id) => CONDITIONS.find((c) => c.id === id) || CONDITIONS[1]
export const kindOf = (id) => KINDS.find((k) => k.id === id) || KINDS[0]
export const statusOf = (id) => STATUSES.find((s) => s.id === id) || STATUSES[0]

export const shopOffer = (unitId, price, name, phone) => post('/shop', { op: 'offer', unitId, price: Number(price), name, phone })

export const waLink = (number, text) => `https://wa.me/${String(number || '').replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
