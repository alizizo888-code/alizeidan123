// One staff address: the desk app, three doors, every role — and the switch that sends /staff on a face there.
//
// The real desk build (dist-desk) is served as https://desk.oxygen11.com and its calls to
// https://oxygen11.com are answered by the local test copy, so this is the app staff will install,
// talking to a local database. Nothing here touches the live sites.
const { chromium, devices } = require('playwright')
const fs = require('fs')
const path = require('path')

const LOCAL = 'http://127.0.0.1:8099'
const DIST = path.join(__dirname, '../../mutqan-app/dist-desk')
const PW = process.env.E2E_PW || 'Flow-Test-1234'
const OUT = path.join(__dirname, 'out')
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true })
let fails = 0
const check = (n, ok, x) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  -> ' + JSON.stringify(x || '').slice(0, 300))); if (!ok) fails++; return !!ok }
const body = (p) => p.innerText('body').catch(() => '')
async function leaves(p, re, ms = 25000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { const b = await body(p); if (b && !re.test(b)) return true; await p.waitForTimeout(500) } return false }
async function arrives(p, re, ms = 20000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (re.test(await body(p))) return true; await p.waitForTimeout(500) } return false }
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json' }
const CORS = { 'access-control-allow-origin': 'https://desk.oxygen11.com', 'access-control-allow-headers': 'Content-Type, Authorization, X-Mutqan-Token, X-Mutqan-Anon, X-WP-Nonce', 'access-control-allow-methods': 'GET, POST, OPTIONS' }
async function api(pathname, body, token) {
  const r = await fetch(LOCAL + '/wp-json/mutqan/v1/' + pathname, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(token ? { 'X-Mutqan-Token': token } : {}) }, body: JSON.stringify(body) })
  const j = await r.json(); return j.data || j
}

;(async () => {
  const owner = await api('team', { op: 'login', handle: 'ali', password: PW })
  check('owner signs in (local copy)', !!owner.token)
  await api('team', { op: 'setOpsShared', password: 'Ops-Shared-77' }, owner.token)
  const browser = await chromium.launch()
  const mk = async (dev) => {
    const ctx = await browser.newContext({ ...dev, locale: 'ar-SA' })
    await ctx.route('https://desk.oxygen11.com/**', (route) => {
      const u = new URL(route.request().url())
      let f = u.pathname.replace(/^\/+/, '') || 'index.html'
      if (/^ops\/?$/.test(f)) f = 'desk-ops.html'
      if (/^m\/?$/.test(f)) f = 'desk.html'
      const file = path.join(DIST, f)
      if (!fs.existsSync(file)) return route.fulfill({ status: 404, body: '' })
      route.fulfill({ status: 200, body: fs.readFileSync(file), headers: { 'content-type': MIME[path.extname(file)] || 'application/octet-stream' } })
    })
    await ctx.route('https://oxygen11.com/**', async (route) => {
      const req = route.request()
      if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: CORS })
      const u = new URL(req.url())
      const res = await route.fetch({ url: LOCAL + u.pathname + u.search })
      route.fulfill({ response: res, headers: { ...res.headers(), ...CORS } })
    })
    const p = await ctx.newPage(); p.errs = []; p.on('pageerror', (e) => p.errs.push(e.message))
    return p
  }
  const desk = { viewport: { width: 1280, height: 820 } }

  // ---------------------------------------------------------------- operations: shared password + own name
  const ops = await mk(desk)
  await ops.goto('https://desk.oxygen11.com/ops', { waitUntil: 'networkidle' })
  check('the desk opens on the three doors', await arrives(ops, /العمليات[\s\S]*فني[\s\S]*المالك/))
  await ops.screenshot({ path: path.join(OUT, 'shots', 'desk-door.png') })
  await ops.fill('input[aria-label="اسمك"]', 'منى')
  await ops.fill('input[aria-label="كلمة السر"]', 'wrong-pass')
  await ops.locator('button', { hasText: 'دخول' }).last().click()
  check('a wrong shared password is refused out loud', await arrives(ops, /كلمة سر العمليات غير صحيحة/))
  await ops.fill('input[aria-label="كلمة السر"]', 'Ops-Shared-77')
  await ops.locator('button', { hasText: 'دخول' }).last().click()
  check('the right one opens the operations room', await leaves(ops, /دخول فريق العمل/) && await arrives(ops, /الطلبات|طلب/, 25000), (await body(ops)).slice(0, 300))
  const me = await ops.evaluate(() => { try { return JSON.parse(localStorage.getItem('desk_sess_mutqan')).user } catch { return null } })
  check('signed in under the name typed, as operations', me && me.displayName === 'منى' && me.role === 'operations', me)
  await ops.screenshot({ path: path.join(OUT, 'shots', 'desk-ops-in.png') })
  await ops.reload({ waitUntil: 'networkidle' })
  await ops.waitForTimeout(3000)
  check('still in after reopening the app', !/دخول فريق العمل/.test(await body(ops)), (await body(ops)).slice(0, 200))

  // ---------------------------------------------------------------- technician: own account, same address
  const tech = await mk(devices['Pixel 7'])
  await tech.goto('https://desk.oxygen11.com/ops', { waitUntil: 'networkidle' })
  await tech.locator('[role=tab]', { hasText: 'فني' }).click()
  await tech.fill('input[aria-label="رقم الجوال أو اسم المستخدم"]', 'fahd')
  await tech.fill('input[aria-label="كلمة السر"]', PW)
  await tech.locator('button', { hasText: 'دخول' }).last().click()
  check('the technician lands on his own screen', await arrives(tech, /مهامي|مهام/, 25000), (await body(tech)).slice(0, 300))
  const tu = await tech.evaluate(() => { try { return JSON.parse(localStorage.getItem('desk_sess_mutqan')).user } catch { return null } })
  check('as himself, not as operations or owner', tu && tu.role === 'technician', tu)
  await tech.screenshot({ path: path.join(OUT, 'shots', 'desk-tech-in.png') })

  // ---------------------------------------------------------------- a new shared password signs operations out, not the technician
  await api('team', { op: 'setOpsShared', password: 'Ops-Shared-88' }, owner.token)
  await ops.reload({ waitUntil: 'networkidle' })
  check('changing the shared password sends operations back to the door', await arrives(ops, /دخول فريق العمل/, 20000), (await body(ops)).slice(0, 200))
  await tech.reload({ waitUntil: 'networkidle' })
  check('the technician stays in', await arrives(tech, /مهامي|مهام/) && !/دخول فريق العمل/.test(await body(tech)))

  // ---------------------------------------------------------------- the switch on a face
  const face = await (await browser.newContext({ viewport: { width: 1280, height: 820 } })).newPage()
  await face.goto(LOCAL + '/app/#/staff/operations', { waitUntil: 'networkidle' }); await face.waitForTimeout(2500)
  check('switch off: a face keeps its own /staff', face.url().startsWith(LOCAL))
  await api('team', { op: 'setStaffHome', url: 'https://desk.oxygen11.com/ops' }, owner.token)
  const f2 = await (await browser.newContext({ viewport: { width: 1280, height: 820 } })).newPage()
  await f2.route('https://desk.oxygen11.com/**', (r) => r.fulfill({ status: 200, body: '<html>desk</html>', headers: { 'content-type': 'text/html' } }))
  await f2.goto(LOCAL + '/app/#/staff/operations', { waitUntil: 'networkidle' }); await f2.waitForTimeout(3000)
  check('switch on: /staff on a face opens the same page on the desk', f2.url() === 'https://desk.oxygen11.com/ops#/staff/operations', f2.url())
  await f2.goto(LOCAL + '/app/#/services', { waitUntil: 'networkidle' }); await f2.waitForTimeout(2500)
  check('customers\' pages are never moved', f2.url().startsWith(LOCAL), f2.url())
  await api('team', { op: 'setStaffHome', url: '' }, owner.token)
  const f3 = await (await browser.newContext({ viewport: { width: 1280, height: 820 } })).newPage()
  await f3.goto(LOCAL + '/app/#/staff/operations', { waitUntil: 'networkidle' }); await f3.waitForTimeout(2500)
  check('and switched back, /staff stays on the face again', f3.url().startsWith(LOCAL), f3.url())

  // ---------------------------------------------------------------- the owner, through the PIN door, does what Ali did: saves and opens WhatsApp
  const own = await mk(devices['Pixel 7'])
  await own.goto('https://desk.oxygen11.com/m', { waitUntil: 'networkidle' })
  await own.locator('[role=tab]', { hasText: 'المالك' }).click()
  await own.fill('input[aria-label="الرمز السري"]', '٢٤٦٨')
  await own.locator('button', { hasText: 'دخول' }).last().click()
  check('the owner gets in with the PIN (typed in Arabic digits)', await leaves(own, /دخول فريق العمل/), (await body(own)).slice(0, 200))
  const saveRes = []
  own.on('response', async (r) => { if (/\/wp-json\/mutqan\/v1\/(db|wa|team)/.test(r.url()) && r.request().method() === 'POST') saveRes.push(r.status()) })
  // WhatsApp left the app for «مركز الواتساب» on the VPS: the old addresses land on the page that points there.
  await own.goto('https://desk.oxygen11.com/m#/staff/whatsapp', { waitUntil: 'networkidle' }); await own.waitForTimeout(2500)
  check('the old WhatsApp numbers screen now points to the hub', /افتح مركز الواتساب/.test(await body(own)), (await body(own)).slice(0, 300))
  await own.goto('https://desk.oxygen11.com/m#/staff/wa-inbox', { waitUntil: 'networkidle' }); await own.waitForTimeout(2000)
  check('…and so does the old inbox — no live inbox in the app any more', /افتح مركز الواتساب/.test(await body(own)) && !/سجّل الدخول/.test(await body(own)), (await body(own)).slice(0, 300))
  await own.screenshot({ path: path.join(OUT, 'shots', 'desk-owner-wa.png') })
  // the site forgets every session (expired / wiped): the desk must not stay on screen half-signed-in
  await api('team', { op: 'setOpsShared', password: 'Ops-Shared-99' }, owner.token)   // (shared sessions only — then wipe all below)
  const { execSync } = require('child_process')
  execSync(`php -r 'require "${path.join(__dirname, '../mutqan/wpshim.php')}"; $GLOBALS["wpdb"]->query("DELETE FROM " . Mutqan_Store::table("sessions"));'`, { env: process.env })
  await own.evaluate(() => { try { localStorage.removeItem('desk_pin_oxygen') } catch {} })
  await own.reload({ waitUntil: 'networkidle' })
  check('a session the site no longer knows sends the desk back to the door (not half-working screens)', await arrives(own, /دخول فريق العمل/, 20000), (await body(own)).slice(0, 200))

  check('no script errors on the desk', !ops.errs.length && !tech.errs.length && !own.errs.length, [...ops.errs, ...tech.errs, ...own.errs])
  await browser.close()
  console.log(fails ? `\n${fails} failed` : '\nall passed')
  process.exit(fails ? 1 : 0)
})().catch((e) => { console.error(e); process.exit(1) })
