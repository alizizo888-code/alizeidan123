// Server-side Blogger pages (no browser). Owner order 2026-11-17: more pages, A/C + cold/freezer rooms in Makkah & Jeddah first.
//   node blogger/api/pages.js [N]   create up to N pages this run (default 5), stops on the first Blogger block (403/429)
// 1) finishes state.pages_pending (the 5 standard pages of blogger/content/pages.json for blogs created lately),
// 2) then adds 4 service pages to every live blog: A/C (صيانة · إصلاح · غسيل · تركيب) and cold/freezer rooms, Makkah and Jeddah.
// Never duplicates: each blog's existing page titles are read first. Ledger: state.service_pages {brand: [titles]}, log task 'api-pages'.
const fs = require('fs'), path = require('path')
const B = require('./blogger'), { build } = require('../content/build_buyintent'), { wrap } = require('../storefront')
const ROOT = path.join(__dirname, '..'), SF = path.join(ROOT, 'state', 'published.json')
const NET = require('../network.json'), PAGES = require('../content/pages.json'), st = JSON.parse(fs.readFileSync(SF, 'utf8'))
const save = () => fs.writeFileSync(SF, JSON.stringify(st, null, 1))
const today = new Date(Date.now() + 3 * 3600e3).toISOString().slice(0, 10), sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const SERVICE = [
  { title: 'صيانة وغسيل وتركيب المكيفات بمكة', t: 'صيانة وإصلاح المكيفات في مكة المكرمة', kw: 'صيانة مكيفات مكة المكرمة', city: 'mk', kind: 'ac' },
  { title: 'غرف التبريد والتجميد بمكة', t: 'صيانة وإصلاح وتركيب غرف التبريد والتجميد في مكة المكرمة', kw: 'صيانة غرف التبريد والتجميد مكة المكرمة', city: 'mk', kind: 'fridge' },
  { title: 'صيانة وغسيل وتركيب المكيفات بجدة', t: 'صيانة وإصلاح المكيفات في جدة', kw: 'صيانة مكيفات جدة', city: 'jd', kind: 'ac' },
  { title: 'غرف التبريد والتجميد بجدة', t: 'صيانة وإصلاح وتركيب غرف التبريد والتجميد في جدة', kw: 'صيانة غرف التبريد والتجميد جدة', city: 'jd', kind: 'fridge' },
]
async function main(max) {
  const done = [], errors = []; let n = 0, blocked = false
  st.service_pages = st.service_pages || {}
  const jobs = []
  for (const id of Object.keys(st.pages_pending || {})) {
    const b = NET.find((x) => x.id === id); if (!b || !b.blog_id || !B.hasAccount(b.account || 1)) continue
    jobs.push({ b, std: true, list: (PAGES[id] || []).map(([title, html]) => ({ title, html: () => html })) })
  }
  const hasKey = (b) => B.hasAccount(b.account || 1)
  for (const b of NET.filter((x) => x.blog_id && hasKey(x) && !(st.pages_pending || {})[x.id])) {
    const have = st.service_pages[b.id] || [], bi = NET.indexOf(b)
    const list = SERVICE.filter((s) => !have.includes(s.title)).map((s) => ({ title: s.title, svc: true, html: () => { const a = build(b, { t: s.t, kw: s.kw, city: s.city, kind: s.kind, cool: true }, bi); return wrap(b, s.t, a[2]) } }))
    if (list.length) jobs.push({ b, list })
  }
  for (const j of jobs) {
    if (n >= max || blocked) break
    let existing
    try { existing = await B.pageTitles(j.b.blog_id) } catch (e) { errors.push(j.b.id + ': ' + e.message); if (e.status === 403 || e.status === 429) blocked = true; continue }
    for (const p of j.list) {
      if (n >= max) break
      if (existing.includes(p.title)) { if (p.svc) (st.service_pages[j.b.id] = st.service_pages[j.b.id] || []).push(p.title); continue }
      try {
        const r = await B.insertPage(j.b.blog_id, p.title, p.html()); n++
        done.push({ brand: j.b.id, title: p.title, url: r.url }); existing.push(p.title)
        if (p.svc) (st.service_pages[j.b.id] = st.service_pages[j.b.id] || []).push(p.title)
        save(); await sleep(process.env.BLOGGER_DRY ? 0 : 15000)
      } catch (e) { errors.push(j.b.id + ': ' + e.message); if (e.status === 403 || e.status === 429) { blocked = true; break } }
    }
    if (j.std && j.list.every((p) => existing.includes(p.title))) delete st.pages_pending[j.b.id]   // standard pages complete → blog joins publishing
  }
  if (done.length || errors.length) st.log.push({ date: today, time: new Date().toISOString().slice(11, 16) + 'Z', task: 'api-pages', created: done, blocked, errors })
  save(); console.log(JSON.stringify({ pages: done.length, blocked, errors }))
}
main(+process.argv[2] || 5).catch((e) => { console.error(e.message); process.exit(1) })
