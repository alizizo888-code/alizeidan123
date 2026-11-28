// The order's conversation through the real screens: the customer writes, the assistant answers from the order's facts,
// the floating buttons step aside so they never cover «إرسال», and the technician answers «فاضل لك قد إيه؟» with one tap.
const { chromium, devices } = require('playwright')
const fs = require('fs'); const path = require('path')
const BASE = 'http://127.0.0.1:8099/app/'; const LOCAL = 'http://127.0.0.1:8099'
const PW = process.env.E2E_PW || 'Flow-Test-1234'
const OUT = path.join(__dirname, 'out'); fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true })
let fails = 0
const check = (n, ok, x) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  -> ' + JSON.stringify(x || '').slice(0, 400))); if (!ok) fails++ }
const body = (p) => p.innerText('body').catch(() => '')
async function arrives(p, re, ms = 20000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (re.test(await body(p))) return true; await p.waitForTimeout(500) } return false }
const go = async (p, r, w = 2200) => { await p.goto(BASE + '#' + r, { waitUntil: 'networkidle' }).catch(() => {}); await p.waitForTimeout(w) }
const click = async (p, t, w = 1200) => { await p.locator('button:visible', { hasText: t }).first().click({ timeout: 10000 }); await p.waitForTimeout(w) }
async function api(pathname, b, token) { const r = await fetch(LOCAL + '/wp-json/mutqan/v1/' + pathname, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(token ? { 'X-Mutqan-Token': token } : {}) }, body: JSON.stringify(b) }); const j = await r.json(); return j.data || j }
;(async () => {
  const browser = await chromium.launch()
  const mk = async (dev, geo) => { const c = await browser.newContext({ ...dev, locale: 'ar-SA', geolocation: geo, permissions: geo ? ['geolocation'] : [] }); const p = await c.newPage(); p.errs = []; p.on('pageerror', (e) => p.errs.push(e.message)); p.on('dialog', (d) => d.accept()); return p }
  const customer = await mk(devices['Pixel 7'], { latitude: 21.42, longitude: 39.83 })
  const tech = await mk(devices['Pixel 7'], { latitude: 21.43, longitude: 39.84 })
  try {
    await go(customer, '/new', 2000)
    await click(customer, 'صيانة وتكييف'); await click(customer, 'غسيل', 600)
    await customer.locator('select', { has: customer.locator('option', { hasText: 'سبليت' }) }).first().selectOption({ label: 'سبليت' })
    const ta = customer.locator('textarea').first(); if (await ta.count()) await ta.fill('طلب تجريبي للمحادثة')
    await click(customer, 'أقرب وقت ممكن', 400); await click(customer, 'تأكيد الطلب وإرسال الموقع', 3000)
    const orderId = (customer.url().match(/order\/([0-9a-f-]{36})/) || [])[1]
    check('the customer lands on his order page', !!orderId, customer.url())

    await click(customer, 'مراسلة خدمة العملاء', 1200)
    const input = customer.locator('input[placeholder="اكتب رسالتك…"]').first()
    await input.click(); await customer.waitForTimeout(500)
    const fab = await customer.locator('[aria-label="تحدث مع MUTQAN AI"]').first().boundingBox().catch(() => null)
    const visible = fab && await customer.locator('[aria-label="تحدث مع MUTQAN AI"]').first().evaluate((el) => getComputedStyle(el.parentElement).opacity !== '0').catch(() => false)
    check('while chatting, the floating buttons step aside', !visible)
    const send = await customer.locator('button[aria-label="إرسال"]').first().boundingBox()
    const hit = send && await customer.evaluate(([x, y]) => { const el = document.elementFromPoint(x, y); return !!el && !!el.closest('button[aria-label="إرسال"]') }, [send.x + send.width / 2, send.y + send.height / 2])
    check('nothing covers the send button', hit)
    await input.fill('السلام عليكم ايه الاخبار؟'); await input.press('Enter')
    check('the assistant answers in the conversation', await arrives(customer, /المساعد الذكي[\s\S]*استلام الطلب/))
    await customer.screenshot({ path: path.join(OUT, 'shots', 'orderchat-customer-1.png') })

    // operations assigns it to the technician, and he is on his way
    const owner = await api('team', { op: 'login', handle: 'ali', password: PW })
    const t = await api('team', { op: 'login', handle: 'fahd', password: PW })
    const techId = t.user?.id || t.id || t.userId
    await api('db', { op: 'update', coll: 'orders', id: orderId, data: { technicianId: techId, technicianName: 'فهد', status: 'en_route' } }, owner.token)
    await customer.reload({ waitUntil: 'networkidle' }).catch(() => {}); await customer.waitForTimeout(2000)
    await click(customer, 'خدمة العملاء / الفني', 1200)
    const in2 = customer.locator('input[placeholder="اكتب رسالتك…"]').first()
    await in2.fill('الفني فين؟ فاضل له قد ايه'); await in2.press('Enter')
    check('asked where the technician is, it says it asked him', await arrives(customer, /سألت الفني/))

    // the technician sees the question and answers with one tap
    await go(tech, '/staff', 1500)
    const ins = tech.locator('input:visible'); await ins.nth(0).fill('fahd'); await ins.nth(1).fill(PW); await click(tech, 'دخول', 2500)
    await go(tech, '/staff/order/' + orderId, 3000)
    check('the technician sees «فاضل لك قد إيه؟» at the top of his tools, without opening the chat', await arrives(tech, /فاضل لك قد إيه؟[\s\S]*20 د/, 15000), (await body(tech)).slice(-500))
    await tech.screenshot({ path: path.join(OUT, 'shots', 'orderchat-tech.png') })
    await tech.locator('button:visible', { hasText: '20 د' }).first().click(); await tech.waitForTimeout(1500)
    check('his answer reaches the customer\'s conversation by itself', await arrives(customer, /الفني رد: هيوصل خلال حوالي 20 دقيقة/, 25000))
    await customer.screenshot({ path: path.join(OUT, 'shots', 'orderchat-customer-2.png') })
    const wa = await customer.locator('a', { hasText: 'واتساب بخصوص طلبي' }).first().getAttribute('href').catch(() => '')
    const txt = decodeURIComponent((wa || '').split('text=')[1] || '')
    check('«واتساب بخصوص طلبي» carries the step, the technician and the link', /المرحلة: الفني في الطريق/.test(txt) && /فهد/.test(txt) && txt.includes('/#/order/' + orderId), txt)
    check('no page errors', !customer.errs.length && !tech.errs.length, [...customer.errs, ...tech.errs])
  } catch (e) { check('ran to the end', false, e.message) }
  await browser.close()
  console.log(fails ? `\norderchat e2e: ${fails} failed` : '\norderchat e2e: all passed'); process.exit(fails ? 1 : 0)
})()
