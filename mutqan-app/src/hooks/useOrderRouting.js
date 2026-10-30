import { useCallback, useMemo } from 'react'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { team } from '../lib/team'

// Owner switches (one admin-written row `order_routing` in the public `pricing` collection):
//   mode 'whatsapp'   → after confirming, the customer continues the order on WhatsApp (default)
//   mode 'operations' → the order goes straight to the operations supervisor's queue, who assigns a technician; no WhatsApp step
//   opsCreate         → the operations supervisor may create orders by hand (e.g. from a WhatsApp conversation)
export function useOrderRouting() {
  const { data } = useLiveShared('pricing', { limit: 100 })
  const row = useMemo(() => (data || []).find((r) => (r.serviceId || r.id) === 'order_routing') || {}, [data])
  const mode = row.mode === 'operations' ? 'operations' : 'whatsapp'
  const opsCreate = !!row.opsCreate
  const save = useCallback(async (patch) => {
    const gid = await team.groupId()
    await db.upsertShared('pricing', { serviceId: 'order_routing', mode, opsCreate, ...patch, updatedAt: new Date().toISOString() }, 'order_routing', { groupId: gid, writableBy: 'admins' })
  }, [mode, opsCreate])
  return { mode, opsCreate, setMode: (m) => save({ mode: m }), setOpsCreate: (v) => save({ opsCreate: !!v }) }
}
export const OPERATIONS_CLIENT_MSG = 'وصل طلبك لمشرف العمليات وسيُسنده لأقرب فني متاح، وسنتواصل معك للتأكيد.'
