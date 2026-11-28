// What a brand-new phone sees on the live sites: screenshot, console errors, failed requests, and whether the app drew.
const { chromium, devices } = require('playwright')
const fs = require('fs')
const OUT = process.env.OUT || 'network/state/live-check'
fs.mkdirSync(OUT, { recursive: true })
const PAGES = [
  ['oxygen-home', 'https://oxygen11.com/'],
  ['oxygen-app', 'https://oxygen11.com/app/'],
  ['oxygen-app-ai', 'https://oxygen11.com/app/#/ai'],
  ['oxygen-staff', 'https://oxygen11.com/app/#/staff'],
  ['mutqan-app', 'https://mutqan.oxygen11.com/app/'],
  ['face-raad-app', 'https://raad-maintenance-general-contracting.oxygen11.com/app/'],
  ['face-amaan-home', 'https://amaan-maintenance-general-contracting.oxygen11.com/'],
  ['face-raad-ai', 'https://raad-maintenance-general-contracting.oxygen11.com/app/#/ai'],
  ['face-motakhases-staff', 'https://motakhases-maintenance-general-contracting.oxygen11.com/app/#/staff'],
  ['desk-ops', 'https://desk.oxygen11.com/ops'],
  ['desk-control', 'https://desk.oxygen11.com/m'],
]
;(async () => {
  const browser = await chromium.launch()
  const report = []
  for (const [name, url] of PAGES) {
    const ctx = await browser.newContext({ ...devices['Pixel 7'], locale: 'ar-SA' })
    const p = await ctx.newPage()
    const errs = [], bad = []
    p.on('pageerror', (e) => errs.push('pageerror: ' + e.message))
    p.on('console', (m) => { if (m.type() === 'error') errs.push('console: ' + m.text()) })
    p.on('requestfailed', (r) => bad.push(`${r.failure()?.errorText} ${r.url()}`))
    p.on('response', (r) => { if (r.status() >= 400) bad.push(`${r.status()} ${r.url()}`) })
    const t0 = Date.now(); let nav = '', navMs = null, renderMs = null
    try {
      const r = await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 })
      nav = String(r && r.status())
      navMs = Date.now() - t0
    } catch (e) {
      nav = 'ERR ' + e.message.slice(0, 120)
      navMs = Date.now() - t0
    }
    // Wait for visible page content, not an arbitrary 14-second delay. Keep navigation and hydration separate.
    const readyAt = Date.now()
    await p.waitForFunction(() => {
      const root = document.getElementById('root')
      const bodyText = (document.body?.innerText || '').trim()
      return (root && root.children.length > 0) || bodyText.length > 80
    }, undefined, { timeout: 8000 }).catch(() => {})
    renderMs = Date.now() - readyAt
    const drawn = await p.evaluate(() => { const r = document.getElementById('root'); return r ? r.children.length : -1 }).catch(() => -2)
    const text = (await p.innerText('body').catch(() => '')).replace(/\s+/g, ' ').slice(0, 300)
    await p.screenshot({ path: `${OUT}/${name}.png` }).catch(() => {})
    report.push({ name, url, nav, ms: navMs, navMs, renderMs, rootChildren: drawn, finalUrl: p.url(), text, errors: errs.slice(0, 15), failed: bad.slice(0, 25) })
    await ctx.close()
  }
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 1))
  for (const r of report) console.log(r.name, r.nav, 'nav:', r.navMs, 'ms render:', r.renderMs, 'ms root:', r.rootChildren, 'errors:', r.errors.length, 'failed:', r.failed.length)
  await browser.close()
})()
