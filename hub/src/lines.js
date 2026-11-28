// WhatsApp lines registry: numbers are uploaded without names; names are a separate pool dealt out at random; sites are spread over lines in a balanced random way.
// The customer who taps the button in an article lands on the line bound to that site, with the article's ref code in the first message —
// the AI bot asks GET /api/ref/<code> (or /api/line/<number>) to know exactly which site/article/platform the customer came from, and answers from the same number.
const { q, db } = require('./db')
const shuffle = (a, r = Math.random) => { a = a.slice(); for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(r() * (i + 1)); [a[i], a[j]] = [a[j], a[i]] } return a }
exports.normalize = (n) => { let d = String(n).replace(/[٠-٩]/g, (c) => '٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/\D/g, ''); if (d.startsWith('00')) d = d.slice(2); if (/^05\d{8}$/.test(d)) d = '966' + d.slice(1); if (/^5\d{8}$/.test(d)) d = '966' + d; return /^\d{10,15}$/.test(d) ? d : null }
exports.importNumbers = (text) => { let added = 0, bad = []; for (const t of String(text).split(/[\n,;،\s]+/).filter(Boolean)) { const n = exports.normalize(t); if (!n) { bad.push(t); continue } if (q.run('INSERT OR IGNORE INTO lines(number) VALUES(?)', n).changes) added++ } return { added, bad } }
exports.importNames = (text) => { let added = 0; for (const t of String(text).split(/[\n,;،]+/).map((x) => x.trim()).filter(Boolean)) if (q.run('INSERT OR IGNORE INTO names(name) VALUES(?)', t).changes) added++; return { added } }
// random display names over lines (unique while the pool lasts), then sites over active lines with the load kept within ±1
exports.assign = ({ renameAll = false } = {}) => {
  const lines = q.all("SELECT * FROM lines WHERE status='active'"); if (!lines.length) return { error: 'no lines' }
  const used = new Set(q.all('SELECT display_name n FROM lines WHERE display_name IS NOT NULL').map((r) => r.n)), pool = shuffle(q.all('SELECT name FROM names').map((r) => r.name).filter((n) => renameAll || !used.has(n)))
  for (const l of shuffle(lines)) if (renameAll || !l.display_name) { const n = pool.shift(); if (n) q.run('UPDATE lines SET display_name=? WHERE id=?', n, l.id) }
  const sites = shuffle(q.all("SELECT id,line_id FROM sites WHERE status IN ('live','setup')")), load = Object.fromEntries(lines.map((l) => [l.id, 0]))
  for (const s of sites) if (s.line_id && load[s.line_id] !== undefined) load[s.line_id]++
  let moved = 0
  for (const s of sites) { if (s.line_id && load[s.line_id] !== undefined) continue; const l = lines.sort((a, b) => load[a.id] - load[b.id] || Math.random() - 0.5)[0]; q.run('UPDATE sites SET line_id=? WHERE id=?', l.id, s.id); load[l.id]++; moved++ }
  return { lines: lines.length, sitesAssigned: moved, load }
}
exports.lineOf = (siteId) => q.get('SELECT l.* FROM lines l JOIN sites s ON s.line_id=l.id WHERE s.id=?', siteId)
