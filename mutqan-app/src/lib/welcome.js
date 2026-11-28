import { useCallback, useEffect, useState } from 'react'
import { post } from './_api'
import { getDeviceId } from './device'
import { NET, BN } from './netBrand'

export const WELCOME_PCT = 30
export const WELCOME_MAX = 20   // SAR cap, always shown next to the percentage
export const WELCOME_LABEL = `${WELCOME_PCT}% حتى ${WELCOME_MAX} ر.س`
// Real discount for a total: pct% but never above the advertised cap.
export const welcomeOff = (total, pct = WELCOME_PCT, max = WELCOME_MAX) => Math.round(Math.min((Number(total) || 0) * pct / 100, max) * 100) / 100
export const SITE_URL = NET.core || typeof location === 'undefined' ? 'https://oxygen11.com' : location.origin
export const APP_SHARE_URL = NET.core || typeof location === 'undefined' ? 'https://mutqan.oxygen11.com/' : location.origin + '/'

// state: loading | available | claimed | used | blocked
export function useWelcome() {
  const [st, setSt] = useState({ state: 'loading', pct: WELCOME_PCT })
  const call = useCallback(async (op) => {
    try {
      const r = await post('/promo', { op, deviceId: getDeviceId() })
      setSt({ state: r.state, pct: r.pct || WELCOME_PCT })
      return r
    } catch { setSt((s) => (s.state === 'loading' ? { state: 'blocked', pct: 0 } : s)); return null }
  }, [])
  useEffect(() => { call('status') }, [call])
  return { ...st, claim: () => call('claim'), refresh: () => call('status') }
}

export function shareText() {
  return `🎁 حمّل تطبيق ${BN} واحصل على خصم ${WELCOME_LABEL} على أول طلب صيانة!\nالتطبيق: ${APP_SHARE_URL}\nالموقع: ${SITE_URL}`
}
