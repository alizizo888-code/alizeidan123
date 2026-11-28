// Landing-page / static micro-site target: writes <LANDING_DIR>/<site>/<slug>.html and refreshes sitemap.xml. Served by Caddy/Nginx on the VPS.
const fs = require('fs'), path = require('path')
const root = process.env.LANDING_DIR || path.join(__dirname, '..', '..', 'data', 'landing')
const slug = (t) => t.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').slice(0, 70) + '-' + Date.now().toString(36)
exports.publish = async (site, _acc, a) => {
  const dir = path.join(root, site.slug); fs.mkdirSync(dir, { recursive: true })
  const f = slug(a.title) + '.html', base = (site.url || 'https://' + site.slug + '.example').replace(/\/$/, ''), url = base + '/' + encodeURI(f), t = a.title.replace(/</g, '&lt;')
  fs.writeFileSync(path.join(dir, f), a.page ? a.page.replace('<link rel="canonical" href="">','').replace('</title>', `</title><link rel="canonical" href="${url}">`) : `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${t}</title><link rel="canonical" href="${url}"><body style="max-width:860px;margin:auto;font:17px/1.9 system-ui;padding:16px"><h1>${t}</h1>${a.html}</body></html>`)
  const files = fs.readdirSync(dir).filter((x) => x.endsWith('.html'))
  fs.writeFileSync(path.join(dir, 'sitemap.xml'), `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${files.map((x) => `<url><loc>${base}/${encodeURI(x)}</loc></url>`).join('')}</urlset>`)
  return { url, remote_id: f }
}
