// Ali's scenario, in his order, through the real screens — every step timed and checked.
//
//   1. A customer orders through the AI assistant; another orders by hand on the form.
//   2. Operations hears each one: how many seconds until it is on the screen, the sound, the
//      alarm card, and the system notification when the room is in the background.
//   3. Operations dispatches one by hand and lets the server dispatch the other automatically.
//   4. The technician receives, moves, is tracked; customer and technician chat both ways; the
//      WhatsApp job card and customer updates are queued.
//   5. The job is finished at 200 with a report and the technician's opinion of the customer; cash.
//   6. The money is split (his 30%), the owner collects, both screens agree.
//   7. The warranty shows on the map; the customer rates the technician.
//   8. The customer's card learns what the job taught us — and the customer cannot read the opinion.
//
// Four separate browser contexts = four separate phones/logins. Waits never reload: a screen has to
// update on its own. Writes out/full-report.json with every check and timing.
const { chromium, devices } = require('playwright')
const fs = require('fs')
const path = require('path')

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8099/app/'
const API = BASE.replace(/app\/$/, 'wp-json/mutqan/v1')
const PW = process.env.E2E_PW || 'Flow-Test-1234'
const OUT = path.join(__dirname, 'out')
const report = []; const timings = {}
let current = ''
const stage = (s) => { current = s; console.log('\n== ' + s) }
const check = (name, ok, extra) => {
  report.push({ stage: current, name, ok: !!ok, extra: ok ? undefined : extra })
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  -> ' + JSON.stringify(extra ?? '').slice(0, 300)))
  return !!ok
}
const time = (k, sec) => { timings[k] = sec; console.log(`   ⏱ ${k}: ${sec === null ? 'لم يحدث' : sec + ' ث'}`) }
const shot = async (page, name) => { await page.waitForTimeout(400); await page.screenshot({ path: path.join(OUT, 'shots', name + '.png') }); report.push({ stage: current, shot: name }) }
const body = (page) => page.innerText('body').catch(() => '')
const go = async (page, route, wait = 2200) => { await page.goto(BASE + '#' + route, { waitUntil: 'networkidle' }).catch(() => {}); await page.waitForTimeout(wait) }
const button = (page, text) => page.locator('button:visible', { hasText: text }).first()
const click = async (page, text, wait = 1500) => { await button(page, text).click({ timeout: 10000 }); await page.waitForTimeout(wait) }
async function arrives(page, needle, ms = 25000) {
  const t0 = Date.now()
  while (Date.now() - t0 < ms) {
    const txt = await body(page)
    if (typeof needle === 'string' ? txt.includes(needle) : needle.test(txt)) return Math.round((Date.now() - t0) / 100) / 10
    await page.waitForTimeout(250)
  }
  return null
}
async function login(page, handle) {
  await go(page, '/staff')
  const inputs = page.locator('input:visible')
  await inputs.nth(0).fill(handle); await inputs.nth(1).fill(PW)
  await click(page, 'دخول', 2500)
}

// Counts every tone, vibration and system notification the page makes, and lets the test put the
// page "in the background" the way a phone does when the supervisor switches apps.
const INSTRUMENT = () => {
  window.__al = { tones: 0, notifs: [], vib: 0, pushes: [] }
  window.addEventListener('mutqan:push', (e) => { const m = e.detail || {}; window.__al.pushes.push({ title: m.title, priority: m.priority, silent: !!m.silent, event: m.event }) }, true)
  window.__hidden = false
  try {
    const st = OscillatorNode.prototype.start
    OscillatorNode.prototype.start = function (...a) { window.__al.tones++; return st.apply(this, a) }
  } catch (e) { /* no audio */ }
  try { const v = navigator.vibrate?.bind(navigator); navigator.vibrate = (p) => { window.__al.vib++; return v ? v(p) : true } } catch (e) { /* none */ }
  const N0 = window.Notification
  function N(t, o) { window.__al.notifs.push({ via: 'page', title: t, body: o?.body || '', hidden: window.__hidden }); try { return new N0(t, o) } catch (e) { return {} } }
  N.permission = 'granted'; N.requestPermission = async () => 'granted'
  window.Notification = N
  try {
    const sn = ServiceWorkerRegistration.prototype.showNotification
    ServiceWorkerRegistration.prototype.showNotification = function (t, o) { window.__al.notifs.push({ via: 'sw', title: t, body: o?.body || '', hidden: window.__hidden }); return sn.call(this, t, o) }
  } catch (e) { /* none */ }
  Object.defineProperty(Document.prototype, 'visibilityState', { configurable: true, get() { return window.__hidden ? 'hidden' : 'visible' } })
  Object.defineProperty(Document.prototype, 'hidden', { configurable: true, get() { return !!window.__hidden } })
}
const alerts = (p) => p.evaluate(() => JSON.parse(JSON.stringify(window.__al || {})))
const background = (p, on) => p.evaluate((h) => { window.__hidden = h; document.dispatchEvent(new Event('visibilitychange')) }, on)

async function placeFormOrder(customer, note) {
  await go(customer, '/new', 2000)
  await click(customer, 'صيانة وتكييف', 1200)
  await click(customer, 'غسيل', 600)
  await customer.locator('select', { has: customer.locator('option', { hasText: 'سبليت' }) }).first().selectOption({ label: 'سبليت' })
  const ta = customer.locator('textarea').first()
  if (await ta.count()) await ta.fill(note)
  await click(customer, 'أقرب وقت ممكن', 400)
  await click(customer, 'تأكيد الطلب وإرسال الموقع', 0)
  const t0 = Date.now()
  await customer.waitForURL(/order\/[0-9a-f-]{36}/, { timeout: 15000 }).catch(() => {})
  return { id: (customer.url().match(/order\/([0-9a-f-]{36})/) || [])[1], sentAt: t0 }
}

;(async () => {
  const browser = await chromium.launch()
  const phone = devices['Pixel 7']
  const desk = { viewport: { width: 1366, height: 860 }, deviceScaleFactor: 1 }
  const mk = async (dev, geo, opts = {}) => {
    const ctx = await browser.newContext({ ...dev, locale: 'ar-SA', ...(geo ? { geolocation: geo } : {}), permissions: [...(geo ? ['geolocation'] : []), 'notifications'] })
    if (opts.instrument) await ctx.addInitScript(INSTRUMENT)
    const page = await ctx.newPage()
    page.on('dialog', (d) => d.accept())
    page.errors = []; page.on('pageerror', (e) => page.errors.push(e.message))
    return page
  }
  const customer = await mk(phone, { latitude: 21.5433, longitude: 39.1728 })
  const customer2 = await mk(phone, { latitude: 21.5485, longitude: 39.1660 })
  const ops = await mk(desk, null, { instrument: true })
  const tech = await mk(phone, { latitude: 21.5600, longitude: 39.1800 }, { instrument: true })
  const owner = await mk(desk)
  let o1 = null, o2 = null

  try {
    /* ------------------------------------------------------------ 0. the team opens their apps */
    stage('٠ — الفريق يفتح تطبيقاته')
    await login(tech, 'fahd')
    await go(tech, '/staff?tab=profile')
    await click(tech, 'صيانة وتكييف', 400).catch(() => {})
    await click(tech, 'تحديد موقعي', 1200).catch(() => {})
    await click(tech, 'حفظ ملفي', 1500)
    check('الفني فتح التطبيق وحفظ ملفه اليومي', /تم الحفظ|تم حفظ موقعك/.test(await body(tech)))
    await go(tech, '/staff')
    const ts = button(tech, 'فعّل الصوت'); if (await ts.count()) await ts.click().catch(() => {})
    await login(ops, 'ops')
    await go(ops, '/staff/operations', 3000)
    const s = button(ops, 'فعّل الصوت'); if (await s.count()) await s.click().catch(() => {})
    await ops.waitForTimeout(500)
    const base = await alerts(ops)
    check('العمليات فتحت الغرفة والصوت مفعّل', (await body(ops)).includes('غرفة العمليات'))

    /* ------------------------------------------------------------ 1. order by the AI assistant */
    stage('١ — عميل يطلب بالذكاء الاصطناعي')
    await go(customer, '/ai', 2500)
    const box = customer.locator('[placeholder*="اكتب أو تحدث"]').first()
    const say = async (t) => { await box.fill(t); await box.press('Enter'); await customer.waitForTimeout(3000) }
    await say('السلام عليكم، عايز غسيل مكيفين سبليت في جدة')
    const quote = await body(customer)
    check('المساعد حدّد الخدمة والسعر (140 ر.س لمكيفين سبليت)', /140\s*ر\.س/.test(quote), quote.slice(-300))
    check('المساعد خدم جدة (ما رفضهاش كـ«خارج التغطية»)', !/خارج نطاق تغطيتنا|خارج تغطيتنا/.test(quote))
    await say('يناسبني')
    await say('اسمي خالد ورقمي 0551234567')
    await click(customer, 'تحديد موقعي', 3000)
    await click(customer, 'أقرب وقت ممكن', 800).catch(() => {})
    await customer.locator('input[type=checkbox]:visible').last().check()
    await shot(customer, 'f01-ai-confirm')
    await click(customer, 'تأكيد وإرسال الطلب', 0)
    const ai0 = Date.now()
    const aiSent = await arrives(customer, /تم إرسال طلبك #[0-9a-z]+/i, 15000)
    check('المساعد أرسل الطلب: «تم إرسال طلبك #… لغرفة العمليات»', aiSent !== null)
    await shot(customer, 'f02-ai-sent')

    /* ------------------------------------------------------------ 2. operations hears it */
    stage('٢ — العمليات تسمعه')
    const heard1 = await arrives(ops, /بانتظار الإسناد\s*\n*\s*\(?1|1\s*\n+\s*بانتظار الإسناد/, 25000)
    time('وصول طلب المساعد للعمليات', heard1 === null ? null : Math.round((Date.now() - ai0) / 100) / 10)
    check('طلب المساعد ظهر عند العمليات لوحده', heard1 !== null)
    const card1 = await arrives(ops, /طلب جديد —/, 15000)
    check('تنبيه مرئي: كارت «طلب جديد — صيانة وتكييف»', card1 !== null && (await body(ops)).includes('صيانة وتكييف'))
    const a1 = await alerts(ops)
    check('تنبيه صوتي: الغرفة شغّلت نغمة', a1.tones > base.tones, a1)
    await shot(ops, 'f03-ops-heard-ai')

    /* ------------------------------------------------------------ 3. manual dispatch */
    stage('٣ — العمليات تراجع وتسند يدويًا')
    await ops.locator('[data-tab=intake]').click(); await ops.waitForTimeout(1500)
    const inbox = await body(ops)
    check('العمليات شايفة تفاصيل الطلب: العميل والعنوان والسعر', inbox.includes('خالد') && /140/.test(inbox) && inbox.includes('جدة'), inbox.slice(0, 500))
    await click(ops, 'إسناد يدوي', 1500)
    const dlg = await body(ops)
    check('الفني ظاهر بمسافته الحقيقية', /فهد[\s\S]{0,80}\d+(\.\d+)?\s*كم/.test(dlg))
    await ops.locator('button:visible', { hasText: 'إسناد' }).last().click()
    const t1 = Date.now()
    const techGot = await arrives(tech, /بانتظار قبولك|مهامي \(1\)/, 25000)
    time('وصول المهمة للفني بعد الإسناد', techGot === null ? null : Math.round((Date.now() - t1) / 100) / 10)
    check('المهمة وصلت الفني لوحدها', techGot !== null)
    // The task is on his screen at once (live data); the alert with its sound comes by the push poll.
    let pushIn = null
    for (let i = 0; i < 180 && pushIn === null; i++) { const a = await alerts(tech); if ((a.pushes || []).length) pushIn = Math.round((Date.now() - t1) / 100) / 10; else await tech.waitForTimeout(250) }
    time('وصول تنبيه الفني (الإشعار والصوت) بعد الإسناد', pushIn)
    const ta1 = await alerts(tech)
    check('الفني اتنبّه: إشعار «مهمة جديدة»', (ta1.pushes || []).some((m) => /مهمة جديدة/.test(m.title || '')), ta1)
    check('الفني اتنبّه بصوت', ta1.tones > 0, ta1)
    await shot(tech, 'f04-tech-new-job')

    /* ------------------------------------------------------------ 4. manual form order, auto dispatch, room in the background */
    stage('٤ — عميل تاني يطلب يدوي، والتوزيع آلي، والعمليات في الخلفية')
    await ops.locator('button[role=radio]', { hasText: 'آلي' }).first().click()
    await ops.waitForTimeout(1500)
    check('العمليات حوّلت التوزيع لـ«آلي»', (await body(ops)).includes('آلي — مفعّل'))
    await background(ops, true)
    const before = await alerts(ops)
    const r2 = await placeFormOrder(customer2, 'طلب يدوي من النموذج — مكيف الصالة')
    o2 = r2.id
    check('الطلب اليدوي اتبعت من النموذج', !!o2)
    let bgNotif = null
    for (let i = 0; i < 80 && bgNotif === null; i++) {
      const a = await alerts(ops)
      if (a.notifs.length > before.notifs.length) bgNotif = Math.round((Date.now() - r2.sentAt) / 100) / 10
      else await ops.waitForTimeout(250)
    }
    time('إشعار الخلفية عند العمليات بعد إرسال الطلب', bgNotif)
    const after = await alerts(ops)
    const newN = after.notifs.slice(before.notifs.length)
    check('والعمليات في الخلفية: إشعار نظام ظهر', newN.length > 0 && newN.some((n) => n.hidden), after.notifs.slice(-3))
    check('والإشعار مكتوب فيه الطلب الجديد', newN.some((n) => /طلب جديد|صيانة وتكييف/.test(n.title + ' ' + n.body)), newN)
    await background(ops, false)
    const autoTech = await arrives(customer2, /فهد/, 20000)
    time('إسناد آلي من السيرفر (من الإرسال لظهور الفني عند العميل)', autoTech === null ? null : Math.round((Date.now() - r2.sentAt) / 100) / 10)
    check('السيرفر أسند الطلب اليدوي آليًا للفني', autoTech !== null)
    await shot(customer2, 'f05-customer2-auto-assigned')

    /* ------------------------------------------------------------ 5. technician works order 1, tracked */
    stage('٥ — الفني يستلم ويتحرك والعمليات تتابعه')
    await go(tech, '/staff', 2500)
    await click(tech, 'مهامي', 1500)
    await tech.getByText('خالد', { exact: true }).first().click()
    await tech.waitForTimeout(2500)
    o1 = (tech.url().match(/order\/([0-9a-f-]{36})/) || [])[1]
    check('الفني فاتح طلب خالد (طلب المساعد)', (await body(tech)).includes('خالد'))
    await click(tech, 'قبول المهمة', 2000)
    await go(customer, '/order/' + o1, 2500)
    check('العميل شاف اسم الفني', (await arrives(customer, /فهد/, 15000)) !== null)
    await click(tech, 'بدء التحرك للموقع', 2000)
    check('العميل شاف «الفني في الطريق»', (await arrives(customer, /في الطريق|قريب منك/, 15000)) !== null)
    await go(ops, '/staff/map', 3000)
    const pin = await ops.locator('[title*="فهد"]').count()
    check('العمليات شايفة الفني على الخريطة الميدانية', pin > 0)
    await shot(ops, 'f06-ops-map-tracking')

    /* ------------------------------------------------------------ 6. chat both ways + WhatsApp */
    stage('٦ — الدردشة والواتساب بين العميل والفني')
    await click(customer, 'دردشة الفني', 1200)
    const cbox = customer.locator('[placeholder="اكتب رسالتك…"]').first()
    await cbox.fill('السلام عليكم، البوابة الشرقية لو سمحت'); await cbox.press('Enter')
    const m0 = Date.now()
    await click(tech, 'دردشة مع العميل', 1200)
    const techSaw = await arrives(tech, 'البوابة الشرقية', 20000)
    time('رسالة العميل وصلت الفني', techSaw === null ? null : Math.round((Date.now() - m0) / 100) / 10)
    check('رسالة العميل ظهرت عند الفني لوحدها', techSaw !== null)
    const tbox = tech.locator('[placeholder="اكتب رسالتك…"]').first()
    await tbox.fill('تمام، أنا على بعد 5 دقائق'); await tbox.press('Enter')
    const m1 = Date.now()
    const custSaw = await arrives(customer, 'على بعد 5 دقائق', 20000)
    time('رد الفني وصل العميل', custSaw === null ? null : Math.round((Date.now() - m1) / 100) / 10)
    check('رد الفني ظهر عند العميل لوحده', custSaw !== null)
    await shot(customer, 'f07-chat-customer')
    await shot(tech, 'f08-chat-tech')
    const custWa = await customer.locator('a[href*="wa.me"]', { hasText: 'واتساب بخصوص طلبي' }).getAttribute('href').catch(() => '')
    const techWa = await tech.locator('a[href*="wa.me"]', { hasText: 'واتساب العميل' }).getAttribute('href').catch(() => '')
    check('العميل عنده «واتساب بخصوص طلبي» — لرقم الشركة ومعاه رقم الطلب', /wa\.me\/\d+/.test(custWa || '') && decodeURIComponent(custWa).includes('#' + o1.slice(-5)), custWa)
    check('الفني عنده «واتساب العميل» — لرقم العميل الدولي 9665…', /wa\.me\/9665\d{8}\?/.test(techWa || ''), techWa)

    /* ------------------------------------------------------------ 7. arrive, work, report, price, opinion */
    stage('٧ — الفني يوصل وينفذ ويكتب تقريره ورأيه في العميل')
    await go(tech, '/staff/order/' + o1, 2500)
    await click(tech, 'لقد وصلت', 2000)
    check('العميل شاف «وصل الفني»', (await arrives(customer, 'وصل', 15000)) !== null)
    await click(tech, 'بدء تنفيذ العمل', 2000)
    await click(tech, 'إنهاء الخدمة', 1500)
    await tech.locator("textarea[placeholder='وصف العمل المنجز']").fill('غسيل مكيفين سبليت. مكيف غرفة النوم ناقص فريون — اتعبّى واختُبر التبريد.')
    await tech.locator("input[placeholder='اختياري']").fill('تعبئة فريون R410')
    await tech.locator("input[placeholder='0']").fill('200')
    await tech.getByRole('radio', { name: '5 من 5' }).click()
    await click(tech, 'دفع فورًا', 200); await click(tech, 'عنده أجهزة تانية', 200)
    await tech.locator("input[placeholder='ملاحظة عن العميل (اختياري)']").fill('عنده مكيف شباك في المطبخ محتاج تغيير — فرصة بيع')
    await shot(tech, 'f09-tech-finish-with-opinion')
    await click(tech, 'تأكيد الإنهاء', 2500)
    check('الخدمة اكتملت بسعر 200', (await body(tech)).includes('اكتملت'))
    await click(tech, 'استلمت نقدًا للفني', 2500)

    /* ------------------------------------------------------------ 8. money split and collected */
    stage('٨ — المبلغ والنسبة: الفني ياخد نسبته والمالك يستلم')
    await go(tech, '/staff', 3000)
    await click(tech, 'الرئيسية', 1500).catch(() => {})
    const tt = await body(tech)
    check('أرباح الفني 60 (30% من 200)', (tt.match(/أرباح اليوم\s*\n+\s*(\d+)/) || [])[1] === '60')
    check('حساب الفني: «سلّم للشركة 140»', /سلّم للشركة\s*140/.test(tt))
    await shot(tech, 'f10-tech-wallet')
    await login(owner, 'ali')
    await go(owner, '/staff/accounts', 3500)
    const ot = await body(owner)
    check('المالك: مبيعات 200 · عمولة 60 · عهدة 140', /200(\.0+)?\s*ر\.س/.test(ot) && /60(\.0+)?\s*ر\.س/.test(ot) && /140(\.0+)?\s*ر\.س/.test(ot))
    await click(owner, 'تسوية', 1000)
    await click(owner, 'تسجيل التسوية', 2500)
    check('المالك استلم 140: «الحساب متزن ✓»', (await body(owner)).includes('الحساب متزن'))
    check('الفني شاف لوحده إن حسابه اتسوّى', (await arrives(tech, /حسابك متزن/, 20000)) !== null)
    await shot(owner, 'f11-owner-settled')

    /* ------------------------------------------------------------ 9. warranty on the map, rating */
    stage('٩ — الضمان على الخريطة وتقييم الفني')
    await go(customer, '/order/' + o1, 2500)
    const rate = customer.locator('textarea[placeholder="أضف تعليقًا (اختياري)"]')
    await rate.fill('فني محترم وشغله نظيف، شرح لي كل حاجة')
    await click(customer, 'إرسال التقييم وإغلاق الطلب', 2500)
    check('العميل قيّم الفني 5 نجوم', (await body(customer)).includes('تقييمك'))
    await shot(customer, 'f12-customer-rated')
    await go(ops, '/staff/order/' + o1, 2500)
    check('العمليات شايفة تقييم العميل للفني', /تقييم العميل:\s*5\/5/.test(await body(ops)))
    await go(ops, '/staff/map', 3500)
    // The supervisor clears the ringing alarms first, as he would. The pin is opened with its own click
    // event: the map's stylesheet comes from unpkg, which this sandbox cannot reach, so pins are not
    // laid out for a mouse here (they are on the live site).
    const closeAll = button(ops, 'إغلاق الكل'); if (await closeAll.count()) await closeAll.click().catch(() => {})
    const shield = ops.locator('[title^="ضمان"]')
    check('الضمان ظاهر على الخريطة 🛡️', (await shield.count()) > 0)
    if (await shield.count()) { await shield.first().dispatchEvent('click'); await ops.waitForTimeout(1000) }
    const wsheet = await body(ops)
    check('كارت الضمان: ساري + اسم الفني + التقرير', /ضمان ساري/.test(wsheet) && wsheet.includes('فهد') && wsheet.includes('فريون'), wsheet.slice(-400))
    await shot(ops, 'f13-ops-warranty-on-map')

    /* ------------------------------------------------------------ 10. what we learned about the customer */
    stage('١٠ — بيانات العميل المكتسبة ورأي الفني فيه')
    await ops.keyboard.press('Escape').catch(() => {})
    await go(ops, '/staff/map', 3500)
    const cpin = ops.locator('.leaflet-marker-icon[title="خالد"]').first()
    if (await cpin.count()) { await cpin.dispatchEvent('click'); await ops.waitForTimeout(1200) }
    const card = await body(ops)
    check('كارت العميل: إجمالي المدفوع 200', /إجمالي المدفوع:?\s*200/.test(card), card.slice(-600))
    check('كارت العميل: أجهزته «سبليت × 2»', card.includes('سبليت × 2'))
    check('كارت العميل: رأي الفني (دفع فورًا + ملاحظته)', card.includes('رأي الفنيين') && card.includes('دفع فورًا') && card.includes('فرصة بيع'))
    await shot(ops, 'f14-ops-customer-card')
    const leak = await customer.evaluate(async (api) => {
      const anon = localStorage.getItem('mutqan_anon') || ''
      const r = await fetch(api + '/db', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Mutqan-Anon': anon }, body: JSON.stringify({ op: 'selectShared', coll: 'orders', filters: {}, opts: { limit: 20 } }) })
      const j = await r.json().catch(() => null)
      const rows = j?.data || j?.rows || j || []
      return { n: Array.isArray(rows) ? rows.length : -1, leaked: JSON.stringify(rows).includes('فرصة بيع') || JSON.stringify(rows).includes('techOnClient') }
    }, API)
    check('العميل نفسه ما يقدرش يقرا رأي الفني فيه (حتى من الـAPI)', leak.n >= 1 && !leak.leaked, leak)

    for (const [n, p] of [['customer', customer], ['ops', ops], ['tech', tech], ['owner', owner]]) check(`لا أخطاء جافاسكربت عند ${n}`, p.errors.length === 0, p.errors.slice(0, 3))
  } catch (e) {
    check('السيناريو اكتمل بدون انقطاع', false, e.message.split('\n')[0])
    for (const [n, p] of [['customer', customer], ['customer2', customer2], ['ops', ops], ['tech', tech], ['owner', owner]]) await p.screenshot({ path: path.join(OUT, 'shots', '_fail-' + n + '.png') }).catch(() => {})
  }
  fs.writeFileSync(path.join(OUT, 'full-report.json'), JSON.stringify({ timings, report }, null, 1))
  const checks = report.filter((r) => 'ok' in r); const bad = checks.filter((r) => !r.ok)
  console.log(`\nالأوقات: ${JSON.stringify(timings)}`)
  console.log(`${checks.length - bad.length} passed, ${bad.length} failed`)
  await browser.close()
  process.exit(bad.length ? 1 : 0)
})()
