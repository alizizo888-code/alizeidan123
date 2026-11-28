// «العقل التجاري» through the real screen: the owner opens it, every tab renders, the simulator answers, a rule saves.
const { chromium, devices } = require('playwright')
const fs = require('fs'); const path = require('path')
const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8099/app/'
const PW = process.env.E2E_PW || 'Flow-Test-1234'
const OUT = path.join(__dirname, 'out'); fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true })
let fails = 0
const check = (n, ok, x) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  -> ' + JSON.stringify(x || '').slice(0, 300))); if (!ok) fails++ }
const body = (p) => p.innerText('body').catch(() => '')
async function arrives(p, re, ms = 15000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (re.test(await body(p))) return true; await p.waitForTimeout(400) } return false }
;(async () => {
  const browser = await chromium.launch()
  for (const [name, dev] of [['desk', { viewport: { width: 1366, height: 860 } }], ['phone', devices['Pixel 7']]]) {
    const ctx = await browser.newContext({ ...dev, locale: 'ar-SA' }); const p = await ctx.newPage()
    const errs = []; p.on('pageerror', (e) => errs.push(e.message))
    await p.goto(BASE + '#/staff', { waitUntil: 'networkidle' }).catch(() => {}); await p.waitForTimeout(2000)
    const inputs = p.locator('input:visible'); await inputs.nth(0).fill('ali'); await inputs.nth(1).fill(PW)
    await p.locator('button:visible', { hasText: 'دخول' }).first().click(); await p.waitForTimeout(2500)
    await p.goto(BASE + '#/staff/sales-brain', { waitUntil: 'networkidle' }).catch(() => {})
    check(name + ': the page opens on today\'s plan for both cities', await arrives(p, /خطة [\s\S]*مكة المكرمة/) && /جدة/.test(await body(p)))
    await p.screenshot({ path: path.join(OUT, 'shots', `sales-${name}-today.png`), fullPage: false })
    await p.locator('button', { hasText: 'السيناريوهات' }).first().click()
    check(name + ': no invented percentages before any priced order', !/100%/.test(await body(p)))
    check(name + ': the playbook shows 700+ scenarios', await arrives(p, /70\d سيناريو/))
    check(name + ': with real scenario cards', await arrives(p, /الخطة:/))
    await p.screenshot({ path: path.join(OUT, 'shots', `sales-${name}-playbook.png`) })
    await p.locator('button', { hasText: 'جرّب البوت' }).first().click(); await p.waitForTimeout(500)
    await p.locator('button', { hasText: /^جرّب$/ }).first().click()
    check(name + ': the simulator answers with a price and a plan', await arrives(p, /يقوله الآن[\s\S]*الخطة:/))
    check(name + ': and shows what the model receives', await arrives(p, /العقل التجاري \(تعليمات داخلية/))
    await p.screenshot({ path: path.join(OUT, 'shots', `sales-${name}-sim.png`), fullPage: true })
    await p.locator('button', { hasText: 'السياسة' }).first().click(); await p.waitForTimeout(500)
    check(name + ': the policy shows both cities and the batching rules', await arrives(p, /أقصى خصم كلي[\s\S]*بعد كام طلب/))
    if (name === 'desk') {
      await p.locator('button', { hasText: 'حفظ السياسة' }).first().click()
      check('desk: the policy saves', await arrives(p, /تم الحفظ/))
      await p.locator('button', { hasText: 'درّب البوت' }).first().click()
      const ni = p.locator('input[placeholder="مثلاً: اذكر الضمان قبل أي خصم"]'); await ni.fill('اذكر الضمان قبل أي خصم دايمًا'); await ni.press('Enter')
      check('desk: a teaching is added and listed', await arrives(p, /تعليماتك الدائمة \(1\)[\s\S]*اذكر الضمان قبل أي خصم دايمًا/))
      await p.screenshot({ path: path.join(OUT, 'shots', `sales-desk-train.png`) })
    }
    const wide = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)
    check(name + ': no horizontal scroll', !wide)
    check(name + ': no page errors', !errs.length, errs)
    await ctx.close()
  }
  await browser.close()
  console.log(fails ? `\nsales e2e: ${fails} failed` : '\nsales e2e: all passed'); process.exit(fails ? 1 : 0)
})()
