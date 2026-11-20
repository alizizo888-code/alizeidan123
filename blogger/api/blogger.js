// Minimal Blogger API v3 client (no dependencies). Auth = OAuth2 refresh token (scope https://www.googleapis.com/auth/blogger).
// Env: BLOGGER_CLIENT_ID, BLOGGER_CLIENT_SECRET, BLOGGER_REFRESH_TOKEN. BLOGGER_DRY=1 → no network, calls are only logged (used by the self-test).
const API = 'https://www.googleapis.com/blogger/v3'
const DRY = process.env.BLOGGER_DRY === '1'
// Two Google accounts own blogs (owner order 2026-11-20): a blog whose network.json entry has "account": 2 is written with
// BLOGGER_REFRESH_TOKEN_2 (same OAuth client); every other blog with BLOGGER_REFRESH_TOKEN. Callers do not change.
const path = require('path'), fs = require('fs')
let NETMAP = null
function accountOf(blogId) {
  if (!NETMAP) { NETMAP = {}; try { for (const b of JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'network.json'), 'utf8'))) if (b.blog_id) NETMAP[b.blog_id] = b.account || 1 } catch (e) { /* no map: account 1 */ } }
  return NETMAP[blogId] || 1
}
const tokens = {}
async function auth(acct = 1) {
  if (DRY) return 'dry'
  const t = tokens[acct]; if (t && Date.now() - t.at < 45 * 60 * 1000) return t.v
  // A one-hour key borrowed from the site (oxygen11.com holds the Google authorization; see the workflow): nothing to refresh here.
  if (acct === 1 && process.env.BLOGGER_ACCESS_TOKEN) { tokens[1] = { v: process.env.BLOGGER_ACCESS_TOKEN, at: Date.now() }; return tokens[1].v }
  const rk = acct === 1 ? 'BLOGGER_REFRESH_TOKEN' : 'BLOGGER_REFRESH_TOKEN_' + acct
  const need = ['BLOGGER_CLIENT_ID', 'BLOGGER_CLIENT_SECRET', rk].filter((k) => !process.env[k])
  if (need.length) { const e = new Error('missing secrets: ' + need.join(', ')); e.status = 401; throw e }
  const r = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: process.env.BLOGGER_CLIENT_ID, client_secret: process.env.BLOGGER_CLIENT_SECRET, refresh_token: process.env[rk], grant_type: 'refresh_token' }),
  })
  const j = await r.json(); if (!r.ok) throw new Error(`token refresh failed (account ${acct}): ` + (j.error_description || j.error))
  tokens[acct] = { v: j.access_token, at: Date.now() }; return tokens[acct].v
}
async function call(method, path, body) {
  if (DRY) { console.log('[dry]', method, path, body ? JSON.stringify(body).slice(0, 90) : ''); return method === 'GET' ? { items: [] } : { id: 'dry', url: 'dry://' + path } }
  const m = /^\/blogs\/(\d+)/.exec(path)
  const r = await fetch(API + path, { method, headers: { authorization: 'Bearer ' + await auth(m ? accountOf(m[1]) : 1), 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
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
