// «حذف الخدمة» (owner order 2026-11-19): a service the owner switched off disappears from every list customers
// and staff pick from (home tiles, services page, new order, bot), while old orders keep their label.
// Stored as pricing row «services_off» { ids: [...] }; cached on the device so the very first screen is right.
import { SPECIALTIES, ALL_SPECIALTIES } from '../constants'
import { db } from './db'

const KEY = 'mq_services_off'
const ROW = 'services_off'
let off = new Set()
try { off = new Set(JSON.parse(localStorage.getItem(KEY) || '[]')) } catch { /* ignore */ }
const listeners = new Set()

function apply() {
  const keep = ALL_SPECIALTIES.filter((s) => !off.has(s.id))
  SPECIALTIES.splice(0, SPECIALTIES.length, ...(keep.length ? keep : ALL_SPECIALTIES))
  for (const fn of listeners) { try { fn([...off]) } catch { /* ignore */ } }
}
apply()

export const servicesOff = () => [...off]
export const onServicesOff = (fn) => { listeners.add(fn); return () => listeners.delete(fn) }

export async function refreshServicesOff() {
  try {
    const rows = await db.selectShared('pricing', { id: ROW }, { limit: 1 })
    const ids = Array.isArray(rows?.[0]?.ids) ? rows[0].ids.filter((x) => typeof x === 'string') : []
    off = new Set(ids); try { localStorage.setItem(KEY, JSON.stringify(ids)) } catch { /* ignore */ }
    apply()
  } catch { /* offline: keep the cached list */ }
  return [...off]
}

/** Owner: switch a service off (true) or back on (false). */
export async function setServiceOff(id, isOff) {
  const next = new Set(off); if (isOff) next.add(id); else next.delete(id)
  await db.upsertShared('pricing', { serviceId: ROW, ids: [...next], updatedAt: new Date().toISOString() }, ROW, { writableBy: 'admins' })
  off = next; try { localStorage.setItem(KEY, JSON.stringify([...next])) } catch { /* ignore */ }
  apply()
  return [...off]
}

if (typeof window !== 'undefined') setTimeout(refreshServicesOff, 1500)
