import { useMemo } from 'react'
import { useLiveShared } from '../lib/useLive'
import { BUSINESS_PHONE, BUSINESS_WHATSAPP } from '../constants'
import { NET } from '../lib/netBrand'

// Owner-set contact numbers (pricing row `contact_numbers`) — one source for Home, quick service, floating buttons, dispatch and articles.
// Buttons render only when the number exists.
export function useContact() {
  const { data } = useLiveShared('pricing', { limit: 100 })
  return useMemo(() => {
    const r = (data || []).find((x) => (x.serviceId || x.id) === 'contact_numbers') || {}
    return { wa: String(NET.wa || r.whatsapp || BUSINESS_WHATSAPP || '').replace(/\D/g, ''), phone: String(NET.phone || r.phone || BUSINESS_PHONE || '').replace(/[^\d+]/g, '') }
  }, [data])
}
