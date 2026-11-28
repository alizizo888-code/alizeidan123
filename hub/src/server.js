// Control panel + API (Basic auth). n8n calls POST /api/run/<window>, /api/gsc, /api/report with the same credentials.
const http = require('http'), fs = require('fs'), path = require('path'), { q } = require('./db'), { enc } = require('./crypto'), P = require('./planner'), AD = require('./adapters')
const LN = require('./lines'), C = require('./contact')
const { runWindow, dailyReset } = require('./runner'), GSC = require('./gsc'), R = require('./report'), GIT = require('./gitsync'), PE = require('./generators/personas')
const USER = process.env.HUB_USER || 'admin', PASS = process.env.HUB_PASS, BASE = process.env.HUB_BASE_URL || 'http://localhost:8787'
const SCOPES = ['https://www.googleapis.com/auth/blogger', 'https://www.googleapis.com/auth/webmasters', 'email']
const json = (res, code, o) => { res.writeHead(code, { 'content-type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(o)) }
const body = (req) => new Promise((ok) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => { try { ok(JSON.parse(b || '{}')) } catch { ok({}) } }) })
let busy = null
const state = () => ({
  day: P.day(), target: P.todayTarget(), mix: P.mix(P.day()), personas: Object.fromEntries(Object.entries(PE.PERSONAS).map(([k, v]) => [k, v.label])), busy,
  accounts: q.all('SELECT id,label,kind,email,auth,status,profile_dir,note,last_ok_at FROM accounts ORDER BY label').map((a) => ({ ...a, sites: q.get('SELECT COUNT(*) n FROM sites WHERE account_id=?', a.id).n, writes1h: P.writesLastHour(a.id) })),
  sites: q.all("SELECT s.*, (SELECT COUNT(*) FROM articles a WHERE a.site_id=s.id AND a.status='published') posts, (SELECT COUNT(*) FROM articles a WHERE a.site_id=s.id AND a.status='published' AND substr(a.published_at,1,10)=?) today FROM sites s ORDER BY s.slug", P.day()),
  lines: q.all('SELECT l.*, (SELECT COUNT(*) FROM sites s WHERE s.line_id=l.id) sites FROM lines l ORDER BY l.id'), names: q.get('SELECT COUNT(*) n FROM names').n,
  recipes: q.all("SELECT substr(recipe,1,instr(recipe||'|','|')-1) p, COUNT(*) n FROM articles WHERE recipe IS NOT NULL GROUP BY 1"),
  runs: q.all('SELECT * FROM runs ORDER BY id DESC LIMIT 12'), issues: q.all('SELECT g.*, s.slug FROM gsc_issues g JOIN sites s ON s.id=g.site_id WHERE resolved=0'), events: q.all('SELECT * FROM events ORDER BY id DESC LIMIT 25'),
})
async function oauthCallback(url, res) {
  const code = url.searchParams.get('code'), label = decodeURIComponent(url.searchParams.get('state') || '')
  const r = await fetch('https://oauth2.googleapis.com/token', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ code, client_id: process.env.GOOGLE_CLIENT_ID, client_secret: process.env.GOOGLE_CLIENT_SECRET, redirect_uri: BASE + '/oauth/callback', grant_type: 'authorization_code' }) })
  const j = await r.json(); if (!r.ok || !j.refresh_token) { res.writeHead(400); return res.end('OAuth failed: ' + (j.error_description || j.error || 'no refresh_token (remove the app from your Google account permissions and retry)')) }
  const email = (JSON.parse(Buffer.from(j.id_token.split('.')[1], 'base64url')).email) || ''
  const secret = enc(JSON.stringify({ client_id: process.env.GOOGLE_CLIENT_ID, client_secret: process.env.GOOGLE_CLIENT_SECRET, refresh_token: j.refresh_token }))
  const ex = q.get('SELECT id FROM accounts WHERE label=?', label || email)
  if (ex) q.run("UPDATE accounts SET secret_enc=?, email=?, status='active' WHERE id=?", secret, email, ex.id)
  else q.run("INSERT INTO accounts(label,kind,email,auth,secret_enc) VALUES(?,?,?,?,?)", label || email, 'google', email, 'oauth', secret)
  res.writeHead(302, { location: '/' }); res.end()
}
const routes = {
  'GET /api/state': async () => state(),
  'POST /api/accounts': async (b) => { const r = q.run('INSERT INTO accounts(label,kind,email,auth,secret_enc,profile_dir,note) VALUES(?,?,?,?,?,?,?)', b.label, b.kind || 'google', b.email || null, b.auth || 'oauth', b.secret ? enc(typeof b.secret === 'string' ? b.secret : JSON.stringify(b.secret)) : null, b.profile_dir || null, b.note || null); return { id: r.lastInsertRowid } },
  'POST /api/accounts/status': async (b) => (q.run('UPDATE accounts SET status=? WHERE id=?', b.status, b.id), { ok: 1 }),
  'POST /api/accounts/discover': async (b) => {                                      // pull every blog of a Google account into sites
    const acc = q.get('SELECT * FROM accounts WHERE id=?', b.id), j = await AD.blogger.listBlogs(acc); let n = 0
    for (const x of j.items || []) { const ex = q.get("SELECT id FROM sites WHERE platform='blogger' AND remote_id=? AND account_id IS NULL", x.id); if (ex) { q.run('UPDATE sites SET account_id=? WHERE id=?', acc.id, ex.id); n++ } } 
    for (const x of j.items || []) if (!q.get("SELECT 1 x FROM sites WHERE platform='blogger' AND remote_id=?", x.id)) { q.run("INSERT INTO sites(account_id,platform,slug,title,url,remote_id,persona,status) VALUES(?,?,?,?,?,?,?,'setup')", acc.id, 'blogger', (x.url || x.id).replace(/^https?:\/\//, '').replace(/\.oxygen11\.com.*|\.blogspot.*|\W+/g, '-').replace(/^blog-|-$/g, '') || x.id, x.name, x.url, x.id, PE.pick(q.get('SELECT COUNT(*) n FROM sites').n)); n++ }
    return { added: n } },
  'POST /api/sites': async (b) => { const r = q.run('INSERT INTO sites(account_id,platform,slug,title,url,remote_id,persona,trade,city_focus,daily_cap,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)', b.account_id || null, b.platform, b.slug, b.title, b.url, b.remote_id || null, b.persona || PE.pick(q.get('SELECT COUNT(*) n FROM sites').n), b.trade || null, b.city_focus || 'mk', b.daily_cap || 2, b.status || 'setup'); return { id: r.lastInsertRowid } },
  'POST /api/sites/update': async (b) => { for (const k of ['daily_cap', 'persona', 'status', 'url', 'title', 'gsc_property']) if (b[k] !== undefined) q.run(`UPDATE sites SET ${k}=? WHERE id=?`, b[k], b.id); return { ok: 1 } },
  'POST /api/run': async (b) => { if (busy) return { busy }; busy = b.win; try { if (b.win === 'morning') dailyReset(); return await runWindow(b.win, { max: b.max || 15 }) } finally { busy = null } },
  'POST /api/lines/import': async (b) => ({ numbers: b.numbers ? LN.importNumbers(b.numbers) : null, names: b.names ? LN.importNames(b.names) : null }),
  'POST /api/lines/assign': async (b) => LN.assign({ renameAll: !!b.renameAll }),
  'POST /api/lines/update': async (b) => { for (const k of ['display_name', 'status', 'role', 'daily_cap']) if (b[k] !== undefined) q.run(`UPDATE lines SET ${k}=? WHERE id=?`, b[k], b.id); return { ok: 1 } },
  'POST /api/seed': async () => require('./seed').run(),
  'POST /api/wp/connect': async (b) => {                                           // WordPress app password -> account + live site
    const site = q.get("SELECT * FROM sites WHERE id=? AND platform='wordpress'", b.site_id); if (!site) throw new Error('site not found')
    const t = await fetch(site.url.replace(/\/$/, '') + '/wp-json/wp/v2/users/me', { headers: { authorization: 'Basic ' + Buffer.from(b.user + ':' + b.app_password).toString('base64') } })
    if (!t.ok) throw new Error('WordPress rejected the login (' + t.status + ') - check user and application password')
    const label = 'wp-' + site.slug, secret = enc(JSON.stringify({ user: b.user, app_password: b.app_password })), ex = q.get('SELECT id FROM accounts WHERE label=?', label)
    const id = ex ? (q.run("UPDATE accounts SET secret_enc=?, status='active' WHERE id=?", secret, ex.id), ex.id) : q.run("INSERT INTO accounts(label,kind,auth,secret_enc) VALUES(?,?,?,?)", label, 'wordpress', 'apppass', secret).lastInsertRowid
    q.run("UPDATE sites SET account_id=?, status='live' WHERE id=?", id, site.id); return { ok: 1 } },
  'POST /api/sites/activate': async (b) => { const s = q.get('SELECT * FROM sites WHERE id=?', b.id); if (!s || !s.account_id) throw new Error('link an account first'); q.run("UPDATE sites SET status='live' WHERE id=?", b.id); return { ok: 1 } },
  'POST /api/sites/activate-all': async () => ({ n: q.run("UPDATE sites SET status='live' WHERE account_id IS NOT NULL AND status='setup'").changes }),
  'POST /api/gsc': async () => GSC.checkAll(),
  'POST /api/report': async () => { const r = R.build(); await R.send(r); return { summary: r.summary, git: GIT.push('Hub report ' + r.day) } },
}
if (require.main === module) {
  if (!PASS) { console.error('set HUB_PASS'); process.exit(1) }
  http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x'), auth = Buffer.from((req.headers.authorization || 'Basic ').split(' ')[1] || '', 'base64').toString()
    if (url.pathname === '/oauth/callback') return oauthCallback(url, res).catch((e) => { res.writeHead(500); res.end(e.message) })     // the state value is only a label; the code is single-use
    if (url.pathname.startsWith('/bot/')) {                                      // AI bot context: Bearer HUB_BOT_TOKEN
      if (!process.env.HUB_BOT_TOKEN || req.headers.authorization !== 'Bearer ' + process.env.HUB_BOT_TOKEN) { res.writeHead(401); return res.end() }
      const [, , kind, key] = url.pathname.split('/'), v = kind === 'ref' ? C.context(decodeURIComponent(key || '').toUpperCase()) : kind === 'line' ? C.byLine(decodeURIComponent(key || '').replace(/\D/g, '')) : null
      return v ? json(res, 200, v) : json(res, 404, { error: 'not found' })
    }
    if (auth !== USER + ':' + PASS) { res.writeHead(401, { 'www-authenticate': 'Basic realm="hub"' }); return res.end() }
    if (url.pathname === '/oauth/start') { const u = new URL('https://accounts.google.com/o/oauth2/v2/auth'); Object.entries({ client_id: process.env.GOOGLE_CLIENT_ID, redirect_uri: BASE + '/oauth/callback', response_type: 'code', scope: SCOPES.join(' '), access_type: 'offline', prompt: 'consent select_account', state: url.searchParams.get('label') || '' }).forEach(([k, v]) => u.searchParams.set(k, v)); res.writeHead(302, { location: u.href }); return res.end() }
    const h = routes[req.method + ' ' + url.pathname]
    if (h) { try { json(res, 200, await h(req.method === 'POST' ? await body(req) : {})) } catch (e) { json(res, 500, { error: e.message }) } return }
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(fs.readFileSync(path.join(__dirname, '..', 'ui', url.pathname === '/connect' ? 'connect.html' : 'index.html')))
  }).listen(+process.env.PORT || 8787, process.env.HOST || '127.0.0.1', () => console.log('hub on :' + (process.env.PORT || 8787)))
  if (process.env.HUB_SCHEDULER !== '0') require('./scheduler').start(async (job) => {
    if (job === 'gsc') return GSC.checkAll()
    if (job === 'report') { const r = R.build(); await R.send(r); return GIT.push('Hub report ' + r.day) }
    if (job === 'morning') dailyReset()
    if (!busy) { busy = job; try { await runWindow(job) } finally { busy = null } }
  })
}
module.exports = { routes }
