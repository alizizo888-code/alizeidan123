// Fix list for posts already published (owner order 2026-11-03): each one must reach 1200 words and carry no invented customer story.
// Writes content/expand_queue.json = {brand: {title: extraHtml}} and state.expand_pending = {brand: [titles]} (already-finished titles stay out).
//   node blogger/content/build_expand.js      then publish it with blogger/expand.js
const fs = require('fs'), path = require('path'), H = __dirname
const { build } = require('./build_buyintent')
const NET = require('../network.json'), SF = path.join(H, '..', 'state', 'published.json'), ST = JSON.parse(fs.readFileSync(SF, 'utf8'))
const done = new Set(ST.expand_done || []), list = {}
const add = (b, t) => { if (!t || done.has(b + '|' + t)) return; (list[b] = list[b] || new Set()).add(t) }
for (const l of ST.log) if (Array.isArray(l.published)) for (const p of l.published) add(p.brand, p.title)
for (const src of [ST.published_long, ST.published_topics, ST.published_topics_buy]) for (const [b, ts] of Object.entries(src || {})) for (const t of ts) add(b, typeof t === 'string' ? t : t.t || t[0])
const q = {}, pend = {}; let n = 0
for (const [id, ts] of Object.entries(list)) {
  const bi = NET.findIndex((x) => x.id === id), b = NET[bi]; if (!b) continue
  for (const t of ts) {
    const entry = { t, kw: t, city: /جدة/.test(t) ? 'jd' : 'mk', cool: /مكيف|تكييف|تبريد|تجميد|فريزر|ثلاج/.test(t) }
    ;(q[id] = q[id] || {})[t] = build(b, entry, bi)[3]; (pend[id] = pend[id] || []).push(t); n++
  }
}
fs.writeFileSync(path.join(H, 'expand_queue.json'), JSON.stringify(q))
ST.expand_pending = pend; fs.writeFileSync(SF, JSON.stringify(ST, null, 1) + '\n')
console.log(n, 'posts', Object.entries(pend).map(([k, v]) => k + ':' + v.length).join(' '))
