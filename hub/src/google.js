// OAuth refresh-token → access-token, shared by Blogger and Search Console. Secret JSON: {client_id, client_secret, refresh_token}.
const { dec } = require('./crypto'), cache = new Map()
exports.token = async (acc) => {
  const c = cache.get(acc.id); if (c && Date.now() - c.at < 45 * 60e3) return c.t
  const s = JSON.parse(dec(acc.secret_enc))
  const r = await fetch('https://oauth2.googleapis.com/token', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ client_id: s.client_id, client_secret: s.client_secret, refresh_token: s.refresh_token, grant_type: 'refresh_token' }) })
  const j = await r.json(); if (!r.ok) { const e = new Error('token: ' + (j.error_description || j.error)); e.needsLogin = j.error === 'invalid_grant'; throw e }
  cache.set(acc.id, { t: j.access_token, at: Date.now() }); return j.access_token
}
