// Registers the existing network in the hub: 22 WordPress sites and every Blogger blog from blogger/network.json (blogs wait for a Google account; "discover" links them).
const path = require('path'), { q } = require('./db'), PE = require('./generators/personas')
const R = path.join(__dirname, '..', '..'), NET = require(path.join(R, 'blogger', 'network.json')), WP = require(path.join(R, 'network', 'wp-sites.json'))
exports.run = () => {
  let wp = 0, bl = 0, i = q.get('SELECT COUNT(*) n FROM sites').n
  for (const w of WP) { const slug = 'wp-' + w.id; if (q.get('SELECT 1 x FROM sites WHERE slug=?', slug)) continue
    const n = NET.find((x) => x.id === w.id) || {}; q.run("INSERT INTO sites(platform,slug,title,url,trade,persona,status) VALUES('wordpress',?,?,?,?,?,'setup')", slug, w.name, 'https://' + w.host, n.spec || 'ac', PE.pick(i++)); wp++ }
  for (const n of NET) { if (!n.blog_id || q.get('SELECT 1 x FROM sites WHERE slug=?', n.id)) continue
    q.run("INSERT INTO sites(platform,slug,title,url,remote_id,trade,persona,status) VALUES('blogger',?,?,?,?,?,?,'setup')", n.id, n.name, n.live_url || n.blogspot || '', String(n.blog_id), n.spec || 'ac', PE.pick(i++)); bl++ }
  return { wordpress: wp, blogger: bl }
}
