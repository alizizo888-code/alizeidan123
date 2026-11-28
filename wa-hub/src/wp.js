// The website, as the hub sees it: a few short, signed data questions («order 1002?», «who is 0550…?», «mark it arrived»).
import crypto from 'node:crypto'

export const hmac = (secret, body) => crypto.createHmac('sha256', secret).update(body).digest('hex')
export function safeEq(a, b) {
  const x = Buffer.from(String(a || '')); const y = Buffer.from(String(b || ''))
  return x.length === y.length && x.length > 0 && crypto.timingSafeEqual(x, y)
}

export function createWp({ getPair, fetchImpl = fetch, timeoutMs = 15000 }) {
  return async function call(op, body = {}) {
    const p = getPair()
    if (!p || !p.url || !p.secret) throw new Error('الهب مش مربوط بالموقع لسه')
    const raw = JSON.stringify({ ...body, op, ts: Math.floor(Date.now() / 1000) })
    const r = await fetchImpl(p.url.replace(/\/+$/, '') + '/wp-json/mutqan/v1/hub', {
      method: 'POST', headers: { 'content-type': 'application/json', 'x-mq-hub': hmac(p.secret, raw) }, body: raw, signal: AbortSignal.timeout(timeoutMs),
    })
    const j = await r.json().catch(() => null)
    if (!r.ok) throw new Error((j && ((j.error && j.error.message) || j.message)) || ('HTTP ' + r.status))
    return j && j.data ? j.data.result : null
  }
}
