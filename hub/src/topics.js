// Topic matrix for the priority trades. Per site: a seeded shuffle of (equipment × action × city/district × search angle), Makkah-heavy (70%),
// AC/cold weighted by the site's own trade; no two entries share a title. Entries carry everything the generator and the planner need.
const path = require('path'), P = require('./generators/pro'), DI = require(path.join(__dirname, '..', '..', 'blogger', 'content', 'districts.json'))
const rng = (seed) => { let h = 2166136261; for (const c of String(seed)) h = Math.imul(h ^ c.charCodeAt(0), 16777619); return () => ((h = Math.imul(h ^ (h >>> 15), 2246822519) >>> 0) / 4294967296) }
const AC = ['split', 'window', 'duct', 'concealed', 'vrf', 'central', 'package'], COLD = ['coldroom', 'freezer', 'blast', 'icemaker', 'tunnel']
const AW = { repair: 24, maintenance: 20, wash: 22, install: 18, buy_used: 9, sell_used: 7 }
const wpick = (r, o) => { const t = Object.values(o).reduce((a, b) => a + b, 0); let x = r() * t; for (const [k, v] of Object.entries(o)) if ((x -= v) < 0) return k; return Object.keys(o)[0] }
exports.build = (siteSlug, spec, n = 600) => {
  const r = rng('topics' + siteSlug), acShare = spec === 'ac' ? 0.75 : spec === 'fridge' ? 0.25 : 0.55, seen = new Set(), out = []
  for (let guard = 0; out.length < n && guard < n * 8; guard++) {
    const equip = r() < acShare ? AC[Math.floor(r() * AC.length)] : COLD[Math.floor(r() * COLD.length)], action = wpick(r, AW), city = r() < 0.7 ? 'mk' : 'jd'
    if (['vrf', 'central', 'tunnel', 'blast'].includes(equip) && ['buy_used', 'sell_used'].includes(action) && r() < 0.5) continue
    const pool = city === 'jd' ? DI.jeddah : DI.makkah, district = r() < 0.72 ? pool[Math.floor(r() * pool.length)] : null, angle = P.ANGLES[Math.floor(r() * P.ANGLES.length)]
    const t = P.title(equip, action, city, district, angle, siteSlug + equip + action + (district || '') + angle)
    if (seen.has(t)) continue; seen.add(t)
    const kb = P.KB[equip]
    out.push({ t, kw: `${angle} ${P.ACTIONS[action]} ${kb.n} ${city === 'jd' ? 'جدة' : 'مكة'}`, city, district, equip, action, angle, cool: true, kind: kb.group === 'cold' ? 'fridge' : 'ac', pro: true })
  }
  return out
}
