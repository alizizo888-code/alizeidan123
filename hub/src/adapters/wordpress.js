// WordPress REST API with an Application Password (Users → Profile → Application Passwords).
const { dec } = require('../crypto')
exports.publish = async (site, acc, a) => {
  if (process.env.HUB_DRY === '1') return { url: 'dry://wp/' + a.title.slice(0, 10), remote_id: 'dry' }
  const s = JSON.parse(dec(acc.secret_enc))                                  // {user, app_password}
  const r = await fetch(site.url.replace(/\/$/, '') + '/wp-json/wp/v2/posts', { method: 'POST', headers: { authorization: 'Basic ' + Buffer.from(s.user + ':' + s.app_password).toString('base64'), 'content-type': 'application/json' },
    body: JSON.stringify({ title: a.title, content: a.html, status: 'publish' }) })
  const j = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(`wp ${r.status} ${j.message || ''}`); e.status = r.status; throw e }
  return { url: j.link, remote_id: String(j.id) }
}
