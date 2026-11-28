// Messages the hub takes back from everyone's chat later (owner, 2026-11-23): the payment-methods link disappears from
// the customer's WhatsApp just before WhatsApp stops allowing «delete for everyone» (about two days); if he asks for the
// payment methods again, it is simply sent again. Kept in the hub's store, so a restart does not forget them.
export const PAYLINK_TTL_H = Number(process.env.PAYLINK_TTL_HOURS || 46)   // WhatsApp's limit is about 48 hours — stay inside it
export const REVOKE_PURPOSES = ['paylink']

export function createRevoker({ store, now = Date.now, ttlH = PAYLINK_TTL_H }) {
  const list = () => { const v = store.get('revokes', []); return Array.isArray(v) ? v : [] }
  const save = (v) => store.set('revokes', v.slice(-2000))
  return {
    /** After a send: remember it if its kind is one that expires. */
    remember(row, lineId, waId) {
      if (!REVOKE_PURPOSES.includes(row.purpose) || !waId) return false
      save([...list(), { line: lineId, phone: row.phone, waId: String(waId), due: now() + ttlH * 3600000 }])
      return true
    },
    /** The ones whose time has come. */
    due() { return list().filter((x) => x.due <= now()) },
    /** Done (deleted, or past saving): off the list. */
    drop(x) { save(list().filter((y) => !(y.waId === x.waId && y.line === x.line))) },
    pending() { return list().length },
  }
}
