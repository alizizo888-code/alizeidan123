// Network dashboard + detailed daily report (owner order 2026-11-08). No network calls: reads the ledger only.
//   node blogger/api/report.js [YYYY-MM-DD]  -> writes blogger/state/DASHBOARD.md (GitHub renders it) and blogger/state/reports/<date>.md
// The publishing workflow runs it after every pass, so the dashboard is always current on GitHub.
const fs = require('fs'), path = require('path')
const ROOT = path.join(__dirname, '..'), st = JSON.parse(fs.readFileSync(path.join(ROOT, 'state', 'published.json'), 'utf8'))
const NET = require('../network.json'), name = Object.fromEntries(NET.map((b) => [b.id, b.name]))
const today = process.argv[2] || new Date(Date.now() + 3 * 3600e3).toISOString().slice(0, 10)
const pct = (a, b) => (b ? Math.round((100 * a) / b) + '%' : '—')
const log = st.log || []
const pubs = (e) => (Array.isArray(e.published) ? e.published : [])
const burst = log.filter((e) => typeof e.published === 'number').reduce((s, e) => s + e.published, 0)
const all = log.flatMap(pubs), todayRuns = log.filter((e) => e.date === today), todayPubs = todayRuns.flatMap(pubs)
const live = NET.filter((b) => b.blog_id), waiting = NET.filter((b) => !b.blog_id)
const R = st.ramp || {}, day = st.api_day && st.api_day.date === today ? st.api_day : null
const kind = (p) => p.kind || (/غرف|ثلاج|فريزر|تجميد|التبريد/.test(p.title) ? 'fridge' : /مكيف|تكييف|سبليت/.test(p.title) ? 'ac' : 'other')
const ac = todayPubs.filter((p) => kind(p) === 'ac').length, fr = todayPubs.filter((p) => kind(p) === 'fridge').length
const mk = todayPubs.filter((p) => p.makkah || /مكة/.test(p.title)).length, old = todayPubs.filter((p) => p.old).length
const perBlog = (list) => list.reduce((m, p) => ((m[p.brand] = (m[p.brand] || 0) + 1), m), {})
const pbToday = perBlog(todayPubs), pbAll = perBlog(all)
const problems = todayRuns.flatMap((e) => [...(e.errors || []), ...(e.skipped || []).map((s) => 'تخطّي (قصير): ' + s)]).filter((x) => !/^ai /.test(x))
const aiErr = todayRuns.flatMap((e) => (e.errors || []).filter((x) => /^ai /.test(x)))
const blocked = todayRuns.some((e) => e.blocked)
const pendingOld = Object.values(st.pending_long || {}).reduce((s, v) => s + v.length, 0)
const plan = (() => { try { return require('../content/commercial_plan.json') } catch { return {} } })()
const usedBuy = st.published_topics_buy || {}
const newLeft = live.reduce((s, b) => s + (plan[b.id] || []).filter((e) => !(usedBuy[b.id] || []).includes(e.t)).length, 0)
const aiUsed = todayPubs.reduce((m, p) => (p.ai ? ((m[p.ai] = (m[p.ai] || 0) + 1), m) : m), {})

const L = []
L.push(`# لوحة شبكة بلوجر — ${today}`, '')
L.push(`> تتحدث تلقائياً بعد كل دفعة نشر. الوقت بتوقيت الرياض.`, '')
L.push('## اليوم', '')
L.push('| البند | القيمة |', '|---|---|')
L.push(`| الهدف اليومي (زيادة تدريجية حتى 180) | ${day ? day.target : R.base || 60} |`)
L.push(`| المنشور اليوم | ${todayPubs.length} |`)
L.push(`| جديد / قديم | ${todayPubs.length - old} / ${old} (الهدف ثلثين جديد وثلث قديم) |`)
L.push(`| مكيفات / غرف تبريد وتجميد | ${ac} (${pct(ac, ac + fr)}) / ${fr} (${pct(fr, ac + fr)}) — الهدف 60% / 40% |`)
L.push(`| في مكة | ${mk} (${pct(mk, todayPubs.length)}) — الهدف 70% |`)
L.push(`| حالة بلوجر | ${blocked ? '⛔ حظر مؤقت — النشر واقف لحد بكرة والهدف بينزل 20' : 'سليم'} |`)
L.push(`| الذكاء الاصطناعي | ${Object.keys(aiUsed).length ? Object.entries(aiUsed).map(([k, v]) => `${k}: ${v}`).join('، ') : 'غير مفعّل أو لم يُستخدم'} |`, '')
L.push('## المواقع', '')
L.push('| الموقع | اليوم | الإجمالي | الرابط |', '|---|---|---|---|')
for (const b of live) L.push(`| ${b.name} | ${pbToday[b.id] || 0} | ${pbAll[b.id] || 0} | ${b.live_url || b.blogspot || ''} |`)
L.push('', `شغالة: **${live.length}** من ${NET.length} — لسه مستنية: ${waiting.length} (${waiting.slice(0, 5).map((b) => b.name).join('، ')}${waiting.length > 5 ? '…' : ''})`, '')
L.push('## مقالات اليوم', '')
for (const [id, n] of Object.entries(pbToday)) {
  L.push(`### ${name[id] || id} (${n})`)
  for (const p of todayPubs.filter((x) => x.brand === id)) L.push(`- [${p.title}](${p.url})${p.old ? ' — قديم' : ''}${p.ai ? ' — AI' : ''}`)
  L.push('')
}
if (!todayPubs.length) L.push('لا يوجد نشر اليوم.', '')
L.push('## المشاكل', '')
L.push(problems.length ? problems.map((x) => '- ' + x).join('\n') : '- لا توجد', '')
if (aiErr.length) L.push(`- أخطاء AI (المقال اتنشر من غيرها): ${aiErr.length}`, '')
L.push('## الإجمالي', '')
L.push(`- كل المنشور على الشبكة: **${all.length + burst}** (منهم ${burst} في دفعة 2026-11-02 بدون تفاصيل)`)
L.push(`- مقالات قديمة من المولّد لسه مستنية: ${pendingOld}`)
L.push(`- عناوين جديدة جاهزة للمواقع الشغالة: ${newLeft}`)
L.push(`- آخر حظر من بلوجر: ${R.last_block || 'لا يوجد'}`, '')
L.push('## آخر الدفعات', '', '| التاريخ | الوقت | النوع | منشور | أخطاء |', '|---|---|---|---|---|')
for (const e of log.slice(-12).reverse()) L.push(`| ${e.date} | ${e.time || ''} | ${e.task || 'pass ' + (e.pass || '')} | ${Array.isArray(e.published) ? e.published.length : e.published || 0} | ${(e.errors || []).length} |`)
const md = L.join('\n') + '\n'
fs.mkdirSync(path.join(ROOT, 'state', 'reports'), { recursive: true })
fs.writeFileSync(path.join(ROOT, 'state', 'DASHBOARD.md'), md)
fs.writeFileSync(path.join(ROOT, 'state', 'reports', today + '.md'), md)
console.log(md)
