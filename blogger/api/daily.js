// Server-side Blogger automation (no browser, no device). Run by .github/workflows/blogger-api.yml; also usable locally.
//   node blogger/api/daily.js publish [N]    publish up to N articles this run (default: today's target spread over the runs left today)
//   node blogger/api/daily.js relayout [N]   prepend the shop front to live posts lacking <!--oxf--> (default ≤15 per run)
//   node blogger/api/daily.js status         print today's target, counts and mix (no writes)
// Owner order 2026-11-08:
//   • mix of NEW articles: 60% A/C, 40% cold rooms / freezer rooms / refrigeration (every blog, through its own trade's angle); 70% Makkah
//   • per day: 2/3 new articles + 1/3 old generator articles of the blog's own specialty (target 120 new + 60 old = 180)
//   • volume ramps up safely: 60/day, +20 every 7 days without a Blogger block, up to 180; a block (403/429) stops the day and steps back 20
//   • optional AI (blogger/api/ai.js: Gemini, then Groq, then OpenRouter) adds 3 unique sections to every article
// State lives in blogger/state/published.json (the workflow commits it). Stops on first 429/403 so Blogger limits are respected.
const fs = require('fs'), path = require('path'), cp = require('child_process')
const B = require('./blogger'), AI = require('./ai'), { front, bodyWords, MIN_WORDS } = require('../storefront')
const ROOT = path.join(__dirname, '..'), SF = path.join(ROOT, 'state', 'published.json')
const NET = require('../network.json'), st = JSON.parse(fs.readFileSync(SF, 'utf8'))
// blogs fully set up (theme + pages): state.api_blogs when set, otherwise every blog with a blog_id whose pages are done
const ALLOW = st.api_blogs || NET.filter((b) => b.blog_id && !(st.pages_pending || {})[b.id]).map((b) => b.id)
const live = NET.filter((b) => b.blog_id && ALLOW.includes(b.id))
const now = new Date(Date.now() + 3 * 3600e3), today = now.toISOString().slice(0, 10), hourRiyadh = now.getUTCHours()
const RUN_HOURS = Array.from({ length: 24 }, (_, h) => h)       // VPS cron runs every hour (owner order 2026-11-17: higher volume)
// blogs whose own trade IS A/C or cold rooms (or covers them fully) get twice the slots of the other trades (owner order 2026-11-17)
const CORE_SPECS = ['ac', 'fridge', 'appliances', 'general', 'corporate', 'satellite', 'moving', 'cleaning']
const W = (b) => (CORE_SPECS.includes(b.spec) ? 2 : 1)
const PER_RUN_MAX = 15                                          // never more than 15 Blogger writes in one hour (owner rule after the 2026-11-02 block)
const save = () => fs.writeFileSync(SF, JSON.stringify(st, null, 1))
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const H2 = 'font-size:20px;font-weight:800;color:#075985;margin:22px 0 10px;padding-right:10px;border-right:5px solid #0ea5e9;border-top:3px dashed #bae6fd;padding-top:16px;margin-top:28px'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
// the network's article memory on the hub; unreachable → null (the article goes out as before)
const FP_GATE = 0.30, FP_URL = process.env.FP_URL || 'https://oxygen11.com/wp-json/mutqan/v1/fp'
async function fpCall(op, body) {
  if (process.env.BLOGGER_DRY) return null
  try { const r = await fetch(FP_URL, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ op, ...body }), signal: AbortSignal.timeout(20000) }); const j = await r.json(); return j && j.data ? j.data : null } catch { return null }
}
// Every headline the network already carries (this ledger's log + titles_all), normalised, so no two blogs share one.
const normTitle = (t) => String(t || '').replace(/[ًٌٍَُِّْـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().slice(0, 80)
const seenTitles = new Set([...(st.titles_all || []), ...(st.log || []).flatMap((l) => (Array.isArray(l.published) ? l.published : []).map((p) => normTitle(p && p.title)))])

// daily target: 60, +20 for every 7 clean days, max 180 (all overridable in state.ramp). A block restarts the count one step lower.
function target() {
  // owner order 2026-11-17: more volume — 120/day now, +30 every 7 days without a block, up to 300; 90% new buy-intent articles
  const R = (st.ramp = Object.assign({ start: today, base: 120, step: 30, max: 300, every_days: 7, last_block: null, after_block: null, new_share: 0.9 }, st.ramp || {}))
  if (!R.v2) Object.assign(R, { v2: '2026-11-17', start: today, base: 120, step: 30, max: 300, new_share: 0.9, last_block: null, after_block: null })
  const from = R.last_block || R.start, base = R.last_block ? R.after_block : R.base
  const clean = Math.max(0, Math.floor((Date.parse(today) - Date.parse(from)) / 864e5))
  return { total: Math.min(R.max, base + R.step * Math.floor(clean / R.every_days)), R }
}

async function publish(nArg) {
  const day = (st.api_day = st.api_day && st.api_day.date === today ? st.api_day : { date: today, counts: {}, cursor: 0, n: 0, new: 0, old: 0, ac: 0, fridge: 0, mk: 0 })
  const { total, R } = target()
  day.target = total
  if (day.blocked) { console.log('Blogger blocked earlier today; resuming tomorrow'); return save() }
  if (!live.length) return console.log('no live blogs')
  const runsLeft = Math.max(1, RUN_HOURS.filter((h) => h >= hourRiyadh).length)
  const left = Math.max(0, total - day.n)
  const room = process.env.MAX_WRITES !== undefined ? Math.max(0, +process.env.MAX_WRITES || 0) : PER_RUN_MAX   // writes left this hour (VPS run.sh: 15 − pages)
  let n = Math.min(PER_RUN_MAX, room, nArg || Math.ceil(left / runsLeft), left)
  if (!n) { console.log(`today's target reached (${day.n}/${total})`); return save() }
  // per-blog daily cap grows with the target so the load stays spread over all live blogs
  const wsum = live.reduce((s, b) => s + W(b), 0), unit = Math.max(1, Math.ceil(total / wsum))
  const capOf = (b) => Math.max(st.daily_cap_per_blog || 4, unit * W(b))
  const ring = live.flatMap((b) => Array(W(b)).fill(b))           // core blogs appear twice in the rotation
  const picks = []
  for (let k = 0, i = day.cursor % ring.length; k < ring.length * 2 && picks.length < n; k++, i++) {
    const b = ring[i % ring.length]
    if (!picks.includes(b.id) && (day.counts[b.id] || 0) < capOf(b)) picks.push(b.id)   // one post per blog per run
    day.cursor = (i + 1) % ring.length
  }
  if (!picks.length) { console.log('daily cap reached for all blogs'); return save() }
  // choose the slot type so the day's running shares stay on target: 2/3 new (60% A/C, 40% cold rooms; 70% Makkah), 1/3 old
  const sim = { n: day.n, new: day.new, old: day.old, ac: day.ac, fridge: day.fridge, mk: day.mk }
  const args = picks.map((id) => {
    const wantOld = sim.old < (sim.n + 1) * (1 - R.new_share) && (st.pending_long[id] || []).length
    const kind = sim.ac <= 0.6 * (sim.ac + sim.fridge + 1) - 0.0001 ? 'ac' : 'fridge'
    const city = sim.mk < 0.7 * (sim.new + 1) ? 'mk' : 'jd'
    sim.n++
    sim[kind]++                                                 // a held old slot falls back to a new topic of this kind
    if (wantOld) { sim.old++; return `${id}:old:${kind}` }
    sim.new++; if (city === 'mk') sim.mk++
    return `${id}:${kind}:${city}`
  })
  const env = Object.assign({}, process.env, AI.available().length ? { OLD_MIN: '850' } : {})
  const out = cp.execFileSync('node', [path.join(ROOT, 'content', 'make_queue.js'), ...args], { encoding: 'utf8', env })
  const info = JSON.parse(out.slice(0, out.lastIndexOf(']') + 1)), q = JSON.parse(fs.readFileSync(path.join(ROOT, 'content', 'queue.json'), 'utf8'))
  const done = [], errors = [], skipped = []
  for (const x of info) {
    const b = NET.find((y) => y.id === x.id); let [title, labels, html] = q[x.id][x.qi || 0]
    // AI: three extra unique sections (keeps two posts of the network from reading alike); required for short old articles
    let ai = null
    if (AI.available().length) {
      const where = (title.match(/حي .+? (?:بمكة المكرمة|بجدة)/) || [/جدة/.test(title) ? 'جدة' : 'مكة المكرمة'])[0]
      ai = await AI.enrich({ title, kind: x.kind, spec: b.angle_label || b.spec, where, brand: b.name, angle: b.angle_label, headings: (html.match(/<h2[^>]*>([^<]+)/g) || []).map((h) => h.replace(/<h2[^>]*>/, '')), errors })
      if (ai) html = AI.insert(html, ai.html)
    }
    const words = bodyWords(html)
    if (words < MIN_WORDS) { skipped.push(`${x.id}: ${words} words < ${MIN_WORDS} (${title.slice(0, 50)})`); continue }
    // one title once in the whole network: two blogs carrying the same headline was flagged by the review (2026-11-19)
    const tk = normTitle(title)
    if (seenTitles.has(tk)) { skipped.push(`${x.id}: title already used in the network (${title.slice(0, 50)})`); continue }
    // one article, one place on the web: compared with every WordPress site and blog of the network (hub memory, Mutqan_Fp)
    const art = String(html).split('شرح المقال').pop()   // the article itself, without the shop front every post opens with
    const fp = await fpCall('check', { text: art })
    if (fp && fp.sim >= FP_GATE) { skipped.push(`${x.id}: ${Math.round(fp.sim * 100)}% like ${fp.url} (${title.slice(0, 40)})`); continue }
    try {
      const r = await B.insert(b.blog_id, title, html, String(labels || '').split(/[,،]/).map((s) => s.trim()).filter(Boolean))
      const isOld = x.src.startsWith('old')
      done.push({ brand: x.id, title, url: r.url, kind: x.kind, old: isOld, cooling: !!x.cool, makkah: !!x.mk, words, ai: ai ? ai.provider : null })
      seenTitles.add(tk); (st.titles_all = st.titles_all || []).push(tk)
      if (r.url) await fpCall('add', { site: x.id, url: String(r.url).replace(/^http:/, 'https:'), key: 'b:' + (r.id || ''), text: art })
      day.counts[x.id] = (day.counts[x.id] || 0) + 1; day.n++
      if (x.kind === 'ac') day.ac++; else if (x.kind === 'fridge') day.fridge++
      if (isOld) day.old++; else { day.new++; if (x.mk) day.mk++ }
      if (!isOld) (st.published_topics_buy[x.id] = st.published_topics_buy[x.id] || []).push(x.t)
      else { const pi = +x.src.split('#')[1]; st.pending_long[x.id] = (st.pending_long[x.id] || []).filter((v) => v !== pi) }
      save()                                                       // keep the ledger right even if the job is cut off
      await sleep(process.env.BLOGGER_DRY ? 0 : 20000)
    } catch (e) {
      errors.push(x.id + ': ' + e.message)
      if (e.status === 403 || e.status === 429) { day.blocked = true; R.last_block = today; R.after_block = Math.max(R.base, total - R.step); break }
    }
  }
  const c = done.filter((d) => d.kind === 'ac').length, f = done.filter((d) => d.kind === 'fridge').length, m = done.filter((d) => d.makkah).length
  st.mix = { date: today, n: day.n, new: day.new, old: day.old, ac: day.ac, fridge: day.fridge, makkah: day.mk, target: { total, ac: 0.6, fridge: 0.4, makkah: 0.7, new_share: R.new_share } }
  st.log.push({ date: today, time: new Date().toISOString().slice(11, 16) + 'Z', task: 'api-publish', target: total, published: done, mix: { n: done.length, ac: c, fridge: f, makkah: m }, skipped, blocked: !!day.blocked && errors.some((e) => /403|429/.test(e)), errors })
  save(); console.log(JSON.stringify({ published: done.length, today: `${day.n}/${total}`, skipped: skipped.length, errors }))
}

function status() {
  const { total } = target(), d = st.api_day && st.api_day.date === today ? st.api_day : { n: 0 }
  console.log(JSON.stringify({ today, live: live.length, target: total, done: d.n, mix: st.mix, ai: AI.available() }, null, 1))
}

async function relayout(max) {
  let n = 0; const done = [], errors = []
  for (const b of live) {
    if (n >= max) break
    let posts; try { posts = await B.livePosts(b.blog_id) } catch (e) { errors.push(b.id + ': ' + e.message); continue }
    for (const p of posts) {
      if (n >= max) break
      if ((p.content || '').includes('<!--oxf-->')) continue
      const html = front(b, '@@T@@').split('@@T@@').join(esc(p.title)) + `<h2 style="${H2}">شرح المقال: ${esc(p.title)}</h2>` + p.content
      try { await B.patch(b.blog_id, p.id, html); n++; done.push(b.id + ': ' + p.title.slice(0, 40)); await sleep(process.env.BLOGGER_DRY ? 0 : 15000) } catch (e) { errors.push(b.id + ': ' + e.message); if (e.status === 403 || e.status === 429) { max = 0; break } }
    }
  }
  st.log.push({ date: today, time: new Date().toISOString().slice(11, 16) + 'Z', task: 'api-relayout', done, errors }); save(); console.log(JSON.stringify({ updated: n, errors }))
}

const [mode, arg] = process.argv.slice(2)
;(mode === 'publish' ? publish(+arg || 0) : mode === 'relayout' ? relayout(+arg || 15) : mode === 'status' ? Promise.resolve(status()) : Promise.reject(new Error('usage: daily.js publish|relayout|status [N]')))
  .catch((e) => { console.error(e.message); process.exit(1) })
