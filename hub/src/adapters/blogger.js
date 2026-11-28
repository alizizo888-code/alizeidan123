// Blogger API v3 through the account's own OAuth refresh token (stored encrypted). No browser, no session.
const API = 'https://www.googleapis.com/blogger/v3'
const { token } = require('../google')
async function call(acc, method, p, body) {
  if (process.env.HUB_DRY === '1') return { id: 'dry-' + Date.now(), url: 'dry://' + p, items: [] }
  const r = await fetch(API + p, { method, headers: { authorization: 'Bearer ' + await token(acc), 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  const j = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(`${r.status} ${(j.error && j.error.message) || ''}`); e.status = r.status; throw e }
  return j
}
exports.publish = async (site, acc, a) => { const j = await call(acc, 'POST', `/blogs/${site.remote_id}/posts?isDraft=false`, { kind: 'blogger#post', title: a.title, content: a.html, labels: (a.labels || '').split(',').filter(Boolean) }); return { url: j.url, remote_id: j.id } }
exports.listBlogs = (acc) => call(acc, 'GET', '/users/self/blogs')
exports.livePosts = async (site, acc) => (await call(acc, 'GET', `/blogs/${site.remote_id}/posts?status=live&maxResults=500&fetchBodies=false`)).items || []
