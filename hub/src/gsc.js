// Search Console: submit sitemaps, read their status, collect problems into gsc_issues. Needs an account with scope https://www.googleapis.com/auth/webmasters
// (one of the Google accounts that owns/verified the property; the domain property sc-domain:oxygen11.com covers every blog-*.oxygen11.com).
const { token } = require('./google'), { q } = require('./db'), API = 'https://www.googleapis.com/webmasters/v3'
async function call(acc, method, p) {
  if (process.env.HUB_DRY === '1') return { sitemap: [] }
  const r = await fetch(API + p, { method, headers: { authorization: 'Bearer ' + await token(acc) } })
  if (r.status === 204) return {}
  const j = await r.json().catch(() => ({})); if (!r.ok) { const e = new Error(`gsc ${r.status} ${(j.error && j.error.message) || ''}`); e.status = r.status; throw e } return j
}
exports.submit = async (acc, property, sitemapUrl) => call(acc, 'PUT', `/sites/${encodeURIComponent(property)}/sitemaps/${encodeURIComponent(sitemapUrl)}`)
exports.checkAll = async () => {
  const acc = q.get("SELECT * FROM accounts WHERE id=?", q.setting('gsc_account', 0)); if (!acc) return { skipped: 'no gsc_account set' }
  const out = { submitted: 0, issues: 0 }
  for (const s of q.all("SELECT * FROM sites WHERE status='live' AND url IS NOT NULL")) {
    const prop = s.gsc_property || q.setting('gsc_default_property', 'sc-domain:oxygen11.com'), base = s.url.replace(/\/$/, '')
    try {
      if (!s.sitemap_ok) { for (const f of ['/sitemap.xml', '/sitemap-pages.xml']) await exports.submit(acc, prop, base + f); q.run('UPDATE sites SET sitemap_ok=1 WHERE id=?', s.id); out.submitted++ }
      const st = await call(acc, 'GET', `/sites/${encodeURIComponent(prop)}/sitemaps`)
      for (const m of st.sitemap || []) if (m.path.startsWith(base) && (+m.errors || +m.warnings)) {
        const d = `${m.path}: errors=${m.errors} warnings=${m.warnings}`
        if (!q.get("SELECT 1 x FROM gsc_issues WHERE site_id=? AND detail=? AND resolved=0", s.id, d)) { q.run('INSERT INTO gsc_issues(site_id,kind,detail) VALUES(?,?,?)', s.id, 'sitemap', d); out.issues++ }
      }
    } catch (e) { q.ev('error', 'gsc ' + s.slug + ': ' + e.message, acc.id, s.id); if (e.status === 403) { q.run("UPDATE sites SET sitemap_ok=0 WHERE id=?", s.id) } }
  }
  return out
}
