// Search Console from the server (owner order 2026-11-19): every blog and every WordPress site of the network has its
// sitemaps submitted in the domain property sc-domain:oxygen11.com, and the day's status is written to a report.
//   node blogger/api/gsc.js            submit what is missing or failed, then report
//   node blogger/api/gsc.js status     report only
// Env: BLOGGER_CLIENT_ID, BLOGGER_CLIENT_SECRET, GSC_REFRESH_TOKEN (scope webmasters; falls back to BLOGGER_REFRESH_TOKEN).
const fs = require('fs'), path = require('path')
const ROOT = path.join(__dirname, '..'), NET = require('../network.json')
const PROP = 'sc-domain:oxygen11.com'
const API = 'https://www.googleapis.com/webmasters/v3/sites/' + encodeURIComponent(PROP)
const WP = (() => { try { return JSON.parse(fs.readFileSync(path.join(ROOT, '..', 'network', 'wp-sites.json'), 'utf8')) } catch { return [] } })()

async function token() {
  const r = await fetch('https://oauth2.googleapis.com/token', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: process.env.BLOGGER_CLIENT_ID, client_secret: process.env.BLOGGER_CLIENT_SECRET, refresh_token: process.env.GSC_REFRESH_TOKEN || process.env.BLOGGER_REFRESH_TOKEN, grant_type: 'refresh_token' }) })
  const j = await r.json(); if (!r.ok) throw new Error('token: ' + (j.error_description || j.error)); return j.access_token
}

/** Every sitemap the network should have: Blogger posts + pages, WordPress index. */
function wanted() {
  const out = []
  for (const b of NET) if (b.blog_id && b.host) { out.push(`https://${b.host}/sitemap.xml`, `https://${b.host}/sitemap-pages.xml`) }
  const hosts = WP.length ? WP.map((s) => s.host) : ['oxygen11.com', 'mutqan.oxygen11.com']
  for (const h of hosts) out.push(`https://${h}/sitemap_index.xml`)
  return [...new Set(out)]
}

async function main(mode) {
  const at = await token(), H = { authorization: 'Bearer ' + at }
  const list = await (await fetch(API + '/sitemaps', { headers: H })).json()
  if (list.error) throw new Error('sitemaps.list: ' + list.error.message)
  const have = new Map((list.sitemap || []).map((s) => [s.path, s]))
  const done = [], errors = []
  if (mode !== 'status') {
    for (const u of wanted()) {
      const s = have.get(u)
      // resubmit what is missing, failed, or not read for 3 days (Google re-fetches a resubmitted sitemap soon)
      const stale = !s || s.errors > 0 || s.isPending === false && (!s.lastDownloaded || Date.now() - Date.parse(s.lastDownloaded) > 3 * 864e5)
      if (!stale) continue
      const r = await fetch(API + '/sitemaps/' + encodeURIComponent(u), { method: 'PUT', headers: H })
      if (r.ok) done.push(u); else errors.push(u + ' → ' + r.status)
      await new Promise((ok) => setTimeout(ok, 400))
    }
  }
  const rows = (list.sitemap || []).map((s) => ({ path: s.path, read: s.lastDownloaded || '', errors: +s.errors || 0, warnings: +s.warnings || 0, pending: !!s.isPending, urls: (s.contents || []).reduce((n, c) => n + (+c.submitted || 0), 0) }))
  const ok = rows.filter((r) => r.read && !r.errors).length
  const rep = { date: new Date().toISOString().slice(0, 10), property: PROP, sitemaps: rows.length, readOk: ok, failing: rows.filter((r) => r.errors).map((r) => r.path), submittedNow: done, submitErrors: errors, urls: rows.reduce((n, r) => n + r.urls, 0) }
  const dir = path.join(ROOT, 'state'); fs.mkdirSync(dir, { recursive: true })
  fs.writeFileSync(path.join(dir, 'gsc.json'), JSON.stringify({ ...rep, rows }, null, 1))
  console.log(JSON.stringify(rep))
}

main(process.argv[2]).catch((e) => { console.error(e.message); process.exit(1) })
