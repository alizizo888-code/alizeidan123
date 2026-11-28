// Daily report: markdown for GitHub + JSON; optional webhook (n8n → WhatsApp/e-mail). One message per day.
const { q } = require('./db'), P = require('./planner'), fs = require('fs'), path = require('path')
exports.build = (day = P.day()) => {
  const pubs = q.all("SELECT a.title,a.url,a.kind,a.city,s.slug FROM articles a JOIN sites s ON s.id=a.site_id WHERE a.status='published' AND substr(a.published_at,1,10)=? ORDER BY a.id", day)
  const rej = q.all("SELECT s.slug,a.title,a.reject_reason FROM articles a JOIN sites s ON s.id=a.site_id WHERE a.status='rejected' AND substr(a.created_at,1,10)=?", day)
  const runs = q.all('SELECT * FROM runs WHERE day=? ORDER BY id', day), accs = q.all('SELECT label,status,last_ok_at FROM accounts ORDER BY label')
  const gsc = q.all('SELECT s.slug,g.detail FROM gsc_issues g JOIN sites s ON s.id=g.site_id WHERE g.resolved=0'), m = P.mix(day), pct = (a, b) => (b ? Math.round((100 * a) / b) + '%' : '—')
  const bad = accs.filter((a) => a.status !== 'active')
  const L = [`# تقرير النشر — ${day}`, '', `- منشور اليوم: **${pubs.length}** من هدف ${P.todayTarget()}`, `- التبريد/التكييف: ${m.cooling}/${m.n} (${pct(m.cooling, m.n)}) · مكة: ${m.makkah}/${m.n} (${pct(m.makkah, m.n)})`, `- رُفض لضعف الجودة/التشابه: ${rej.length}`, `- حسابات تحتاج تدخل: ${bad.length ? bad.map((a) => a.label + ' (' + a.status + ')').join('، ') : 'لا يوجد'}`, `- مشاكل Search Console مفتوحة: ${gsc.length}`, '']
  L.push('## الجولات', ...runs.map((r) => `- ${r.win}: نُشر ${r.published}${r.blocked ? ' · **حظر/حد**' : ''}${JSON.parse(r.errors || '[]').length ? ' · أخطاء: ' + JSON.parse(r.errors).join(' | ') : ''}`), '')
  L.push('## المقالات', ...pubs.map((p) => `- [${p.title}](${p.url}) — ${p.slug}`), '')
  if (rej.length) L.push('## المرفوضة', ...rej.map((r) => `- ${r.slug}: ${r.title.slice(0, 50)} — ${r.reject_reason}`), '')
  if (gsc.length) L.push('## Search Console', ...gsc.map((g) => `- ${g.slug}: ${g.detail}`), '')
  return { day, md: L.join('\n'), summary: `${day}: نُشر ${pubs.length}/${P.todayTarget()} · تبريد ${pct(m.cooling, m.n)} · مكة ${pct(m.makkah, m.n)} · ${bad.length ? 'تدخل: ' + bad.map((a) => a.label).join('،') : 'لا مشاكل بالحسابات'}${gsc.length ? ' · SC: ' + gsc.length : ''}` }
}
exports.send = async (r) => {
  const dir = path.join(__dirname, '..', 'reports'); fs.mkdirSync(dir, { recursive: true }); fs.writeFileSync(path.join(dir, r.day + '.md'), r.md)
  if (process.env.REPORT_WEBHOOK) await fetch(process.env.REPORT_WEBHOOK, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ day: r.day, summary: r.summary, markdown: r.md }) }).catch((e) => console.error('webhook', e.message))
}
