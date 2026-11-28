// Every on-demand screen loads without a single 404 and without the app reloading itself.
// (Live, the page named the assets/ folder and the build added «assets/» again: every lazy screen 404'd its
//  preloads and the app restarted — the boot screen, the lost settings, the bell, the sign-in.)
const { chromium, devices } = require('playwright')
const BASE = 'http://127.0.0.1:8099/app/'
let fails = 0
const check = (n, ok, x) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  -> ' + JSON.stringify(x ?? '').slice(0, 400))); if (!ok) fails++ }
;(async () => {
  const b = await chromium.launch()
  const ctx = await b.newContext({ ...devices['Pixel 7'] })
  const p = await ctx.newPage()
  const bad = [], navs = []
  p.on('response', (r) => { if (r.status() >= 400 && /\/assets\//.test(r.url())) bad.push(r.status() + ' ' + r.url()) })
  p.on('framenavigated', (f) => { if (f === p.mainFrame()) navs.push(f.url()) })
  const assetsVar = async () => p.evaluate(() => window.__MQ_ASSETS__)
  for (const r of ['/', '/ai', '/new', '/staff', '/staff/operations', '/notifications', '/orders', '/staff/notify-settings']) {
    await p.goto(BASE + '#' + r, { waitUntil: 'networkidle' }).catch(() => {}); await p.waitForTimeout(1800)
  }
  const v = await assetsVar()
  check('the page names the app folder, not its assets/ folder', v && !/assets\/$/.test(v), v)
  check('no asset is ever asked for at assets/assets/', !bad.some((u) => /assets\/assets\//.test(u)), bad)
  check('no asset 404s on any screen', !bad.length, bad)
  const reloads = navs.filter((u) => /[?&]r=\d/.test(u))
  check('the app never reloads itself to «repair»', !reloads.length, reloads)
  await b.close(); console.log(fails ? `\n${fails} failed` : '\nall passed'); process.exit(fails ? 1 : 0)
})()
