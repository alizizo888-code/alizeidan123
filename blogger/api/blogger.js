// Minimal Blogger API v3 client (no dependencies). Auth = OAuth2 refresh token (scope https://www.googleapis.com/auth/blogger).
// Env: BLOGGER_CLIENT_ID, BLOGGER_CLIENT_SECRET, BLOGGER_REFRESH_TOKEN. BLOGGER_DRY=1 → no network, calls are only logged (used by the self-test).
const API = 'https://www.googleapis.com/blogger/v3'
const DRY = process.env.BLOGGER_DRY === '1'
let token = null, tokenAt = 0
async function auth() {
  if (DRY) return 'dry'
  if (token && Date.now() - tokenAt < 45 * 60 * 1000) return token
  // A one-hour key borrowed from the site (oxygen11.com holds the Google authorization; see the workflow): nothing to refresh here.
  if (process.env.BLOGGER_ACCESS_TOKEN) { token = process.env.BLOGGER_ACCESS_TOKEN; tokenAt = Date.now(); return token }
  const need = ['BLOGGER_CLIENT_ID', 'BLOGGER_CLIENT_SECRET', 'BLOGGER_REFRESH_TOKEN'].filter((k) => !process.env[k])
  if (need.length) throw new Error('missing secrets: ' + need.join(', '))
  const r = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: process.env.BLOGGER_CLIENT_ID, client_secret: process.env.BLOGGER_CLIENT_SECRET, refresh_token: process.env.BLOGGER_REFRESH_TOKEN, grant_type: 'refresh_token' }),
  })
  const j = await r.json(); if (!r.ok) throw new Error('token refresh failed: ' + (j.error_description || j.error))
  token = j.access_token; tokenAt = Date.now(); return token
}
async function call(method, path, body) {
  if (DRY) { console.log('[dry]', method, path, body ? JSON.stringify(body).slice(0, 90) : ''); return method === 'GET' ? { items: [] } : { id: 'dry', url: 'dry://' + path } }
  const r = await fetch(API + path, { method, headers: { authorization: 'Bearer ' + await auth(), 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  const t = await r.text(); let j; try { j = JSON.parse(t) } catch { j = { raw: t } }
  if (!r.ok) { const e = new Error(`${method} ${path} → ${r.status} ${(j.error && j.error.message) || t.slice(0, 200)}`); e.status = r.status; throw e }
  return j
}
module.exports = {
  insert: (blogId, title, content, labels) => call('POST', `/blogs/${blogId}/posts?isDraft=false`, { kind: 'blogger#post', title, content, labels }),
  insertPage: (blogId, title, content) => call('POST', `/blogs/${blogId}/pages?isDraft=false`, { kind: 'blogger#page', title, content }),
  async pageTitles(blogId) { if (DRY) return []; const j = await call('GET', `/blogs/${blogId}/pages?status=live&status=draft&fetchBodies=false`); return (j.items || []).map((x) => x.title) },
  patch: (blogId, postId, content) => call('PATCH', `/blogs/${blogId}/posts/${postId}`, { content }),
  async livePosts(blogId) { const out = []; let pt = ''; do { const j = await call('GET', `/blogs/${blogId}/posts?status=live&fetchBodies=true&maxResults=100${pt ? '&pageToken=' + pt : ''}`); out.push(...(j.items || [])); pt = j.nextPageToken } while (pt); return out },
}
