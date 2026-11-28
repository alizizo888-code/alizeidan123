// Dry run: temp DB, fake accounts/sites, no network. node hub/src/selftest.js
process.env.HUB_DRY = '1'; process.env.HUB_DB = require('path').join(require('os').tmpdir(), 'hub-test-' + Date.now() + '.db'); process.env.HUB_KEY = require('crypto').randomBytes(32).toString('hex')
const { q } = require('./db'), { enc, dec } = require('./crypto'), assert = require('assert')
assert.equal(dec(enc('x')), 'x')
const a = q.run("INSERT INTO accounts(label,kind,auth,secret_enc) VALUES('t1','google','oauth',?)", enc('{}')).lastInsertRowid
const N = require('../../blogger/network.json').filter((b) => b.blog_id).slice(0, 4)
N.forEach((b, i) => q.run("INSERT INTO sites(account_id,platform,slug,title,url,remote_id,persona,daily_cap,status) VALUES(?,?,?,?,?,?,?,?,'live')", a, 'blogger', b.id, b.title, b.live_url, b.blog_id, require('./generators/personas').pick(i), 2))
const LN = require('./lines'); LN.importNumbers('0551234567\n0559876543\n+966501112222'); LN.importNames('مؤسسة النور\nمركز الراحة\nشركة الصفوة'); console.log('assign', JSON.stringify(LN.assign()))
require('./runner').runWindow('morning').then(async (o) => {
  console.log('published', o.published.length, 'errors', o.errors); assert(o.published.length > 0)
  const r = require('./report').build(); console.log(r.summary); assert(/نُشر/.test(r.md))
  const ref = q.get('SELECT ref, recipe, kind2 FROM articles WHERE status=\'queued\' OR status=\'published\' LIMIT 1'); console.log('ref', ref); const ctx = require('./contact').context(ref.ref); assert(ctx && ctx.line && ctx.site); console.log(ctx.assistant_note)
  const art = q.get('SELECT html FROM articles WHERE ref=?', ref.ref); assert(art.html.includes('wa.me/'))
  console.log('recipes', q.all('SELECT recipe FROM articles WHERE recipe IS NOT NULL').map((x) => x.recipe.split('|').slice(0, 3).join('/')).join(' ; '))
  const s = require('./server').routes['GET /api/state'](); const st = await s; assert(st.sites.length === N.length); console.log('ok: state', st.accounts.length, 'accounts', st.sites.length, 'sites')
})
