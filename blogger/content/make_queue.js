// Build content/queue.json for one publishing pass: node blogger/content/make_queue.js <brand:mode[:city]>...
//   mode = ac | fridge   next unused commercial topic of that kind (owner order 2026-11-08: 60% A/C, 40% cold rooms/freezers)
//          old           next pending generator article of the blog's own specialty that reaches MIN_WORDS; if none, falls back to a new topic
//          buy           legacy: next unused cooling topic
//   city = mk | jd (optional) preferred city for a new topic; any city is used when that one runs out
// Wraps every article with the shop front (storefront.js) and prints the pass summary (mix counts).
const fs = require('fs'), path = require('path')
const { build } = require('./build_buyintent'), { wrap, bodyWords, MIN_WORDS } = require('../storefront')
const NET = require('../network.json'), PLAN = require('./commercial_plan.json'), LONG = require('./articles_long.json')
const ST = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'state', 'published.json'), 'utf8'))
const used = (ST.published_topics_buy = ST.published_topics_buy || {})
const out = {}, info = [], takenOld = new Set()
const CORE = /(صيان|إصلاح|اصلاح|تصليح|غسيل|تنظيف|تركيب).{0,12}(مكيف|تكييف|تكيف|سبليت|غرف التبريد|غرف تبريد|غرف التجميد|غرف تجميد|ثلاجات|مبرد)/
// OLD_MIN (set by api/daily.js when an AI key exists): old articles this long are queued too, flagged short:true, and the AI adds
// the missing sections before publishing; anything still under MIN_WORDS after that is dropped by daily.js.
const OLD_MIN = Math.min(+process.env.OLD_MIN || MIN_WORDS, MIN_WORDS)

function newTopic(id, b, bi, kind, city) {
  // used = either ledger: Chrome passes wrote published_topics, the server writes published_topics_buy (never repeat a Chrome title)
  const u = new Set([...(used[id] || []), ...((ST.published_topics || {})[id] || [])]), list = PLAN[id] || []
  // owner order 2026-11-17: core service topics first (صيانة / إصلاح / غسيل / تركيب of A/C and cold & freezer rooms), then the rest
  const tries = [(e) => CORE.test(e.t) && (!kind || e.kind === kind) && e.city === city, (e) => CORE.test(e.t) && (!kind || e.kind === kind), (e) => CORE.test(e.t),
    (e) => (!kind || e.kind === kind) && e.city === city, (e) => !kind || e.kind === kind, () => true]
  for (const want of tries) {
    const i = list.findIndex((e) => !u.has(e.t) && want(e))
    if (i >= 0) { const en = list[i]; (used[id] = used[id] || []).push(en.t); return { art: build(b, en, bi), tag: { cool: true, kind: en.kind || kind, mk: en.city === 'mk', src: 'buy#' + i, t: en.t } } }
  }
  return null
}

for (const arg of process.argv.slice(2)) {
  const [id, mode, opt] = arg.split(':'), bi = NET.findIndex((x) => x.id === id), b = NET[bi]
  if (!b) { console.error('unknown blog', id); continue }
  let got = null
  if (mode === 'old') {
    for (const pi of ST.pending_long[id] || []) {
      const art = (LONG[id] || [])[pi]; if (!art || takenOld.has(id + '#' + pi)) continue
      if (bodyWords(art[2]) < OLD_MIN) continue                        // short generator articles stay held until expanded
      const kind = /غرف|ثلاج|فريزر|تجميد|التبريد/.test(art[0] + art[1]) ? 'fridge' : /مكيف|تكييف|سبليت/.test(art[0] + art[1]) ? 'ac' : 'own'
      takenOld.add(id + '#' + pi); got = { art, tag: { cool: kind !== 'own', kind, mk: /مكة/.test(art[0]), src: 'old#' + pi } }; break
    }
    if (!got) { got = newTopic(id, b, bi, opt === 'fridge' ? 'fridge' : 'ac', 'mk'); if (got) got.tag.fallback = 'old→new' }
  } else {
    const kind = mode === 'fridge' ? 'fridge' : mode === 'ac' ? 'ac' : null
    got = newTopic(id, b, bi, kind, opt === 'jd' ? 'jd' : 'mk')
  }
  if (!got) { console.error('no topic left for', id); continue }
  let { art, tag } = got
  const words = bodyWords(art[2]), min = tag.src.startsWith('old') ? OLD_MIN : MIN_WORDS
  if (words < min) { console.error(`REJECTED ${id}: ${words} words < ${min}: ${art[0]}`); continue }
  if (words < MIN_WORDS) tag.short = true
  art = [art[0], art[1], wrap(b, art[0], art[2])]
  ;(out[id] = out[id] || []).push(art); info.push({ id, qi: out[id].length - 1, title: art[0], words, ...tag })
}
fs.writeFileSync(path.join(__dirname, 'queue.json'), JSON.stringify(out))
const n = info.length, c = info.filter((x) => x.kind === 'ac').length, f = info.filter((x) => x.kind === 'fridge').length, m = info.filter((x) => x.mk).length
console.log(JSON.stringify(info, null, 1)); console.log(`n=${n} ac=${c} fridge=${f} makkah=${m} (${n ? Math.round(100 * m / n) : 0}%)`)
