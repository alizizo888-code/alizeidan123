import { useMemo } from 'react'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { NET } from '../lib/netBrand'

// Unlimited WhatsApp numbers (business or regular) — one public-read collection
// that only admins (owner / operations) can edit. Customers see enabled numbers on
// the public contact page; tapping opens WhatsApp to that number from their phone.
export const WA_DEPARTMENTS = [
  { id: 'orders', label: 'طلب خدمة', icon: '🛠️', prefill: 'السلام عليكم، أرغب بطلب خدمة صيانة.' },
  { id: 'support', label: 'خدمة العملاء', icon: '🎧', prefill: 'السلام عليكم، عندي استفسار.' },
  { id: 'warranty', label: 'الضمان والشكاوى', icon: '🛡️', prefill: 'السلام عليكم، عندي مشكلة في خدمة سابقة وأريد فحص الضمان.' },
  { id: 'sales', label: 'المبيعات والعروض', icon: '🏷️', prefill: 'السلام عليكم، أريد معرفة العروض المتاحة.' },
  { id: 'corporate', label: 'الشركات والعقود', icon: '🏢', prefill: 'السلام عليكم، نرغب بعقد صيانة للمنشأة.' },
  { id: 'branch', label: 'فرع / مدينة', icon: '📍', prefill: 'السلام عليكم، أرغب بخدمة في منطقتي.' },
]
export const WA_TYPES = [
  { id: 'business', label: 'واتساب أعمال' },
  { id: 'personal', label: 'واتساب عادي' },
]
export const deptMeta = (id) => WA_DEPARTMENTS.find((d) => d.id === id) || WA_DEPARTMENTS[1]

export function useWaNumbers() {
  const { data, loading } = useLiveShared('wa_numbers', { limit: 500 })
  const numbers = useMemo(() => {
    const all = (data || []).filter((n) => !n.deleted).sort((a, b) => (Number(a.sort) || 99) - (Number(b.sort) || 99))
    if (NET.core) return all
    // A satellite shows only the numbers tagged with its own brand; with none tagged, its own number (from the network registry) serves every department.
    const mine = all.filter((n) => n.brand === NET.id)
    if (mine.length || !NET.wa) return mine
    return WA_DEPARTMENTS.slice(0, 4).map((d, i) => ({ id: `brand-${d.id}`, phone: (i % 2 && NET.wa2) || NET.wa, label: NET.name, department: d.id, enabled: true, type: 'business', sort: i }))
  }, [data])
  return { numbers, loading }
}

export async function saveWaNumber(n) {
  const gid = await team.groupId()
  const { id, ...rest } = n
  const nid = id || `wa-${Date.now().toString(36)}`
  await db.upsertShared('wa_numbers', { ...rest, updatedAt: new Date().toISOString() }, nid, { groupId: gid, writableBy: 'admins' })
  return nid
}

// Soft remove — only from an explicit admin tap.
export const removeWaNumber = (n) => saveWaNumber({ ...n, enabled: false, deleted: true })

// Spread contacts across all enabled numbers of a department (random pick).
export function pickNumber(numbers, deptId) {
  const pool = numbers.filter((n) => n.enabled && n.department === deptId && n.phone)
  if (!pool.length) return null
  return pool[Math.floor(Math.random() * pool.length)]
}
