// Topic source. Priority trades (A/C + cold chain) come from the hub's own matrix (topics.js + pro.js + kb_hvac.js). Every other specialty keeps the older
// buy-intent builder (blogger/content/build_buyintent.js + commercial_plan.json). Blend: ~70% priority entries / ~30% the site's own trade (non-cooling sites only).
const path = require('path'), fs = require('fs'), T = require('../topics'), PRO = require('./pro')
const REPO = path.join(__dirname, '..', '..', '..', 'blogger')
let NET = [], PLAN = {}, build = null
try { NET = require(path.join(REPO, 'network.json')); PLAN = require(path.join(REPO, 'content', 'commercial_plan.json')); build = require(path.join(REPO, 'content', 'build_buyintent')).build } catch (e) { /* standalone */ }
const specOf = (slug) => (NET.find((x) => x.id === slug) || {}).spec || 'general'
const cache = {}
exports.plan = (slug) => {
  if (cache[slug]) return cache[slug]
  const spec = specOf(slug), pro = T.build(slug, spec, 700), own = (PLAN[slug] || []).filter((e) => !e.cool)           // legacy cooling entries are replaced by the pro matrix
  if (spec === 'ac' || spec === 'fridge') return (cache[slug] = pro)
  const out = []; for (let i = 0, j = 0; i < pro.length; i++) { out.push(pro[i]); if (i % 7 === 2 && j < own.length) out.push(own[j++]) }       // ~1 specialty entry per 2-3 priority entries is too many: this gives ~30%
  return (cache[slug] = out)
}
exports.make = (slug, entry) => { if (entry.pro) return PRO.build(entry, slug); const i = NET.findIndex((x) => x.id === slug); if (i < 0 || !build) return null; const a = build(NET[i], entry, i); return { title: a[0], labels: a[1], html: a[2] } }
