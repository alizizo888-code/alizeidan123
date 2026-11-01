import { useCallback, useMemo } from 'react'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { normalizeAc } from '../acPricing'
import { BUSINESS_WHATSAPP } from '../constants'

// AC price table lives in the public `pricing` collection as the row "ac_calc".
export function useAcConfig() {
  const { data, loading } = useLiveShared('pricing', { limit: 100 })
  const { data: offersAll } = useLiveShared('offers', { limit: 100 })
  const { data: media } = useLiveShared('ac_media', { limit: 200 })
  // The owner's «أرقام الموقع» (contact_numbers) is THE WhatsApp number: every order / inquiry button follows it, so there is nothing second to keep in sync.
  const cfg = useMemo(() => {
    const base = normalizeAc((data || []).find((r) => (r.serviceId || r.id) === 'ac_calc'))
    const c = (data || []).find((r) => (r.serviceId || r.id) === 'contact_numbers') || {}
    const wa = String(c.whatsapp || '').replace(/\D/g, '')
    return wa ? { ...base, waNumber: wa } : { ...base, waNumber: base.waNumber || BUSINESS_WHATSAPP }
  }, [data])
  const offers = useMemo(
    () => (offersAll || []).filter((o) => o.acMode).sort((a, b) => (a.sort ?? 99) - (b.sort ?? 99)),
    [offersAll]
  )
  const saveCfg = useCallback(async (next) => {
    const gid = await team.groupId()
    await db.upsertShared('pricing', { ...normalizeAc(next), serviceId: 'ac_calc' }, 'ac_calc', { groupId: gid, writableBy: 'admins' })
  }, [])
  return { cfg, offers, media: media || [], loading, saveCfg }
}
