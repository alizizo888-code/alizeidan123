// The whole chain, through the real screens, four people at once.
//
// Each role is its own browser context — its own login — exactly like four phones. No step moves
// until the order has actually arrived on the next screen *by itself*: the waits below read the page
// without reloading it, because "ops hears the order" means the screen updates on its own, and a
// test that reloads would hide the one failure that matters most.
//
// Writes out/report.json (every check, pass or fail, with the screenshot it was taken from) so the
// result can be shown to someone who was not watching.
const { chromium, devices } = require('playwright')
const fs = require('fs')
const path = require('path')

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8099/app/'
const PW = process.env.E2E_PW || 'Flow-Test-1234'
const OUT = path.join(__dirname, 'out')
const report = []
let current = ''
const stage = (s) => { current = s; console.log('\n== ' + s) }
const check = (name, ok, extra) => {
  report.push({ stage: current, name, ok: !!ok, extra: ok ? undefined : extra })
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : '  -> ' + JSON.stringify(extra || '').slice(0, 300)))
  return !!ok
}
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: path.join(OUT, 'shots', name + '.png') }); report.push({ stage: current, shot: name }) }
const body = (page) => page.innerText('body').catch(() => '')
const go = async (page, route, wait = 2200) => { await page.goto(BASE + '#' + route, { waitUntil: 'networkidle' }).catch(() => {}); await page.waitForTimeout(wait) }
const button = (page, text) => page.locator('button:visible', { hasText: text }).first()
const click = async (page, text, wait = 1500) => { await button(page, text).click({ timeout: 10000 }); await page.waitForTimeout(wait) }

/** Waits for text to appear WITHOUT reloading — the screen has to update on its own. */
async function arrives(page, needle, ms = 25000) {
  const t0 = Date.now()
  while (Date.now() - t0 < ms) {
    const txt = await body(page)
    if (typeof needle === 'string' ? txt.includes(needle) : needle.test(txt)) return Math.round((Date.now() - t0) / 100) / 10
    await page.waitForTimeout(700)
  }
  return null
}

async function login(page, handle) {
  await go(page, '/staff')
  const inputs = page.locator('input:visible')
  await inputs.nth(0).fill(handle)
  await inputs.nth(1).fill(PW)
  await click(page, 'دخول', 2500)
}

;(async () => {
  const browser = await chromium.launch()
  const phone = devices['Pixel 7']
  const desk = { viewport: { width: 1366, height: 860 }, deviceScaleFactor: 1 }
  const mk = async (dev, geo) => {
    const ctx = await browser.newContext({ ...dev, locale: 'ar-SA', geolocation: geo, permissions: geo ? ['geolocation'] : [] })
    const page = await ctx.newPage()
    page.on('dialog', (d) => d.accept())
    page.errors = []; page.on('pageerror', (e) => page.errors.push(e.message))
    return page
  }
  const customer = await mk(phone, { latitude: 21.5433, longitude: 39.1728 })     // جدة — التحلية
  const ops = await mk(desk)
  const tech = await mk(phone, { latitude: 21.5600, longitude: 39.1800 })          // ~2 كم منه
  const owner = await mk(desk)

  try {
    /* ---------------------------------------------------------------- 0. the team opens their apps */
    stage('٠ — الفريق يفتح تطبيقاته')
    await login(tech, 'fahd')
    check('الفني يدخل', (await body(tech)).includes('مرحبًا'), (await body(tech)).slice(0, 200))
    await go(tech, '/staff?tab=profile')
    await click(tech, 'صيانة وتكييف', 400).catch(() => {})
    await click(tech, 'تحديد موقعي', 1200).catch(() => {})
    await click(tech, 'حفظ ملفي', 1500)
    check('الفني يحفظ ملفه اليومي (تخصص + موقع)', /تم الحفظ|تم حفظ موقعك/.test(await body(tech)), (await body(tech)).slice(0, 300))
    await go(tech, '/staff')
    await shot(tech, '00-tech-online')

    await go(ops, '/staff/operations', 2500)
    check('غرفة العمليات لزائر غير مسجّل تعرض زر دخول (مش حيطة سد)', (await button(ops, 'تسجيل الدخول').count()) > 0, (await body(ops)).slice(0, 200))
    await login(ops, 'ops')
    await go(ops, '/staff/operations', 3000)
    const opsTxt = await body(ops)
    check('العمليات تدخل غرفة العمليات', opsTxt.includes('غرفة العمليات'), opsTxt.slice(0, 200))
    const avail = (opsTxt.match(/(\d+)\/(\d+)\s*\n+\s*فنيون متاحون/) || [])
    check('الفني ظاهر متاح عند العمليات', avail[1] && avail[1] === avail[2] && avail[1] !== '0', avail[0] || opsTxt.slice(opsTxt.indexOf('فنيون') - 30, opsTxt.indexOf('فنيون') + 12))
    const sound = button(ops, 'فعّل الصوت')
    if (await sound.count()) await sound.click().catch(() => {})
    await shot(ops, '01-ops-ready')

    /* ---------------------------------------------------------------- 1. customer asks the assistant */
    stage('١ — العميل يسأل المساعد الذكي')
    await go(customer, '/ai', 3000)
    const box = customer.locator('[placeholder*="اكتب أو تحدث"]').first()
    await box.fill('السلام عليكم، عندي مكيفين سبليت يحتاجوا غسيل في جدة، كم السعر؟')
    await box.press('Enter')
    const replied = await arrives(customer, /ر\.س|ريال|سعر|أهلا|تم/, 20000)
    check('المساعد يرد على العميل', replied !== null, (await body(customer)).slice(-400))
    await shot(customer, '02-customer-ai')

    /* ---------------------------------------------------------------- 2. customer orders */
    stage('٢ — العميل يطلب الخدمة ويشوف السعر')
    await go(customer, '/new', 2000)
    await click(customer, 'صيانة وتكييف', 1200)
    await click(customer, 'غسيل', 600)
    await customer.locator('select', { has: customer.locator('option', { hasText: 'سبليت' }) }).first().selectOption({ label: 'سبليت' })
    await customer.locator('div:has(> div > p:text-is("عدد المكيفات")) button:has(svg.lucide-plus)').first().click()
    await customer.waitForTimeout(700)
    const est = await customer.locator('p:text-is("الإجمالي التقديري")').locator('xpath=..').innerText().catch(() => '')
    check('العميل يشوف السعر التقديري قبل الإرسال', /\d+\s*ر\.س/.test(est), est)
    const ta = customer.locator('textarea').first()
    if (await ta.count()) await ta.fill('طلب تجريبي — مكيف غرفة النوم ما يبرد، ومكيف الصالة يحتاج غسيل')
    await click(customer, 'أقرب وقت ممكن', 400)
    await shot(customer, '03-customer-form')
    await click(customer, 'تأكيد الطلب وإرسال الموقع', 2500)
    // No registration, no phone field: confirm opens WhatsApp with the order written out (recorded here instead).
    await customer.waitForTimeout(1500)
    const orderId = (customer.url().match(/order\/([0-9a-f-]{36})/) || [])[1]
    check('الطلب اتبعت ووصل العميل لصفحة متابعته', !!orderId, customer.url())
    check('مفيش خانة رقم جوال ولا تسجيل', (await customer.locator('input[inputmode=tel]').count()) === 0)
    const short = orderId ? orderId.slice(-4) : '????'
    await shot(customer, '04-customer-tracking')

    /* ---------------------------------------------------------------- 3. operations hears it */
    stage('٣ — العمليات تستلم الطلب')
    const heardIn = await arrives(ops, /بانتظار الإسناد\s*\n*\s*\(?1/, 25000) ?? await arrives(ops, '1\n\nبانتظار الإسناد', 1000)
    check('الطلب ظهر عند العمليات لوحده (بدون تحديث الصفحة)', heardIn !== null, 'not heard')
    if (heardIn !== null) console.log('   وصل بعد ' + heardIn + ' ث')
    await arrives(ops, /طلب جديد —/, 15000)
    const alarm = await body(ops)
    check('تنبيه الطلب الجديد عند العمليات مكتوب باسم الخدمة (مش «ac»)', alarm.includes('صيانة وتكييف') && !/—\s*ac\b/.test(alarm), alarm.slice(0, 300))
    await ops.locator('[data-tab=intake]').click(); await ops.waitForTimeout(1500)
    check('الطلب في توزيع الطلبات', (await body(ops)).includes('صيانة وتكييف'))
    await shot(ops, '05-ops-inbox')
    await go(ops, '/staff/orders-board', 2500)
    const board = await body(ops)
    check('لوحة الطلبات: العميل والوقت والموقع والطلب والإسناد في صف واحد', (await ops.locator('[data-board-row]').count()) >= 1 && /صيانة وتكييف/.test(board) && /واتساب/.test(board) && /للفني/.test(board), board.slice(0, 400))
    await shot(ops, '05b-orders-board')
    await go(ops, '/staff/operations?tab=orders', 2500)
    check('غرفة العمليات — «الطلبات بس»: كروت الطلبات من غير خريطة', (await ops.locator('[data-mapfirst-card]').count()) >= 1 && (await ops.locator('[data-map-first=list]').count()) === 1 && (await ops.locator('.leaflet-container').count()) === 0)
    await shot(ops, '05c-ops-orders-only')
    await go(ops, '/staff/operations?tab=live', 3500)
    check('غرفة العمليات — «الطلبات على الخريطة»: الكروت جنب الخريطة الحية', (await ops.locator('[data-mapfirst-card]').count()) >= 1 && (await ops.locator('[data-map-first=map]').count()) === 1)
    await shot(ops, '05d-ops-orders-map')
    await go(ops, '/staff/operations', 2500)
    await ops.locator('[data-tab=intake]').click(); await ops.waitForTimeout(1500)

    /* ---------------------------------------------------------------- 4. operations assigns it */
    stage('٤ — العمليات تسند الطلب للفني (يدوي)')
    await click(ops, 'إسناد يدوي', 1500)
    const dlg = await body(ops)
    check('الفني يظهر في الإسناد بمسافته الحقيقية (مش «موقع غير معروف»)', /فهد[\s\S]{0,80}\d+(\.\d+)?\s*كم/.test(dlg), dlg.slice(dlg.indexOf('فهد'), dlg.indexOf('فهد') + 120))
    await shot(ops, '06-ops-assign')
    const techRow = ops.locator('div', { has: ops.locator('p', { hasText: /^فهد$/ }) }).locator('button', { hasText: 'إسناد' }).last()
    if (await techRow.count()) await techRow.click(); else await ops.locator('button:visible', { hasText: 'إسناد' }).last().click()
    await ops.waitForTimeout(2000)
    check('العمليات أسندت الطلب', /تم إسناد|مُسندة\s*\n*\s*1|1\s*\n+\s*مُسندة/.test(await body(ops)), (await body(ops)).slice(0, 400))

    /* ---------------------------------------------------------------- 5. technician receives */
    stage('٥ — الفني يستلم')
    const techHeard = await arrives(tech, /مهامي \(1\)|بانتظار قبولك/, 25000)
    check('المهمة ظهرت عند الفني لوحدها', techHeard !== null, (await body(tech)).slice(0, 400))
    await shot(tech, '07-tech-new-job')
    await go(tech, '/staff/order/' + orderId, 2500)
    await click(tech, 'قبول المهمة', 2000)
    check('الفني قبل', (await body(tech)).includes('مقبولة'))
    const custSaw = await arrives(customer, /فهد/, 20000)
    check('العميل شاف اسم الفني لوحده', custSaw !== null)

    /* ---------------------------------------------------------------- 6. on the way, followed live */
    stage('٦ — الفني في الطريق والعمليات تتابعه')
    await click(tech, 'بدء التحرك للموقع', 2000)
    const custMove = await arrives(customer, /في الطريق|قريب منك/, 20000)
    check('العميل شاف إن الفني في الطريق', custMove !== null)
    await shot(customer, '08-customer-tech-coming')
    await go(ops, '/staff/operations?tab=techs', 2500)
    const live = await arrives(ops, /مباشر|في مهمة/, 15000)
    check('العمليات شايفة الفني مباشر/في مهمة', live !== null, (await body(ops)).slice(0, 300))
    await shot(ops, '09-ops-follows-tech')

    /* ---------------------------------------------------------------- 7. arrives, works, reports, prices */
    stage('٧ — الفني يوصل وينفذ ويكتب تقريره وسعره')
    await click(tech, 'لقد وصلت', 2000)
    check('العميل شاف «وصل الفني»', (await arrives(customer, 'وصل', 15000)) !== null)
    await click(tech, 'بدء تنفيذ العمل', 2000)
    await click(tech, 'إنهاء الخدمة', 1500)
    await tech.locator("textarea[placeholder='وصف العمل المنجز']").fill('غسيل مكيفين سبليت. مكيف غرفة النوم كان ناقص فريون — اتعبّى واختُبر التبريد.')
    await tech.locator("input[placeholder='اختياري']").fill('تعبئة فريون R410')
    await tech.locator("input[placeholder='0']").fill('200')
    await shot(tech, '10-tech-finish')
    await click(tech, 'تأكيد الإنهاء', 2500)
    check('الخدمة اكتملت', (await body(tech)).includes('اكتملت'))
    await click(tech, 'استلمت نقدًا للفني', 2500)
    await shot(tech, '11-tech-cash')

    /* ---------------------------------------------------------------- 8. his share */
    stage('٨ — الفني ياخد نسبته')
    await go(tech, '/staff', 3000)
    // the technician's app opens on the radar now; his account sits on «الرئيسية»
    if (await tech.getByText('🏠 الرئيسية').count()) { await tech.getByText('🏠 الرئيسية').first().click(); await tech.waitForTimeout(1500) }
    const tt = await body(tech)
    const earn = (tt.match(/أرباح اليوم\s*\n+\s*(\d+)/) || [])[1]
    check('أرباح الفني اليوم = 60 (30% من 200)', earn === '60', earn ?? tt.slice(tt.indexOf('أرباح'), tt.indexOf('أرباح') + 40))
    check('الفني شايف في محفظته: عليك 140', /عليك\s*(140|١٤٠)/.test(tt), tt.slice(tt.indexOf('محفظتي'), tt.indexOf('محفظتي') + 160))
    await shot(tech, '12-tech-earnings')
    const custDone = await arrives(customer, /اكتملت|اكتمل/, 15000)
    check('العميل شاف إن الخدمة اكتملت', custDone !== null)
    await shot(customer, '13-customer-done')

    /* ---------------------------------------------------------------- 9. the owner collects */
    stage('٩ — المالك يشوف رصيده ويستلم العهدة')
    await login(owner, 'ali')
    await go(owner, '/staff/accounts', 3500)
    const ot = await body(owner)
    check('المبيعات 200', /200(\.0+)?\s*ر\.س/.test(ot), ot.slice(0, 500))
    check('عمولة الفني 60', /60(\.0+)?\s*ر\.س/.test(ot))
    check('العهدة مع الفني 140 (200 نقدًا − 60 نصيبه)', /140(\.0+)?\s*ر\.س/.test(ot))
    await shot(owner, '14-owner-accounts')
    // collect: the technician hands 140 to the company — the real «تسوية» button, prefilled with the balance
    await click(owner, 'تسوية', 1000)
    const pre = await owner.locator('input[placeholder="المبلغ ر.س"]').inputValue().catch(() => '')
    check('نموذج التسوية جاهز بالمبلغ المستحق (140)', pre === '140', pre)
    await click(owner, 'تسجيل التسوية', 2500)
    const after = await body(owner)
    check('بعد الاستلام: «الحساب متزن ✓»', after.includes('الحساب متزن'), after.slice(after.indexOf('فهد'), after.indexOf('فهد') + 200))
    check('التسوية مسجّلة في «آخر التسويات»: استُلم منه 140', /(استُلم منه|استلام نقدية منه)\s*140/.test(after), after.slice(after.indexOf('آخر التسويات'), after.indexOf('آخر التسويات') + 160))
    if (await tech.getByText('🏠 الرئيسية').count()) { await tech.getByText('🏠 الرئيسية').first().click().catch(() => {}) }
    const techSettled = await arrives(tech, /سلّمت للشركة\s*\n*\s*(140|١٤٠)/, 20000)
    check('الفني شاف لوحده إن حسابه اتسوّى', techSettled !== null, (await body(tech)).slice(0, 300))
    check('ومحفظته بقت متزنة (لك 0)', /لك\s*(0|٠)\s*ر\.س/.test(await body(tech)))
    await shot(tech, '15b-tech-settled')
    await shot(owner, '15-owner-collected')

    /* ---------------------------------------------------------------- 10. auto dispatch */
    stage('١٠ — التوزيع الآلي')
    await go(customer, '/new', 2000)
    await click(customer, 'صيانة وتكييف', 1200)
    await click(customer, 'غسيل', 600)
    await customer.locator('select', { has: customer.locator('option', { hasText: 'سبليت' }) }).first().selectOption({ label: 'سبليت' })
    const ta2 = customer.locator('textarea').first()
    if (await ta2.count()) await ta2.fill('طلب تجريبي ثاني — توزيع آلي')
    await click(customer, 'أقرب وقت ممكن', 400)
    await click(customer, 'تأكيد الطلب وإرسال الموقع', 2500)
    // A first-time customer gives only his mobile (one field, one button) — no registration.
    await customer.waitForTimeout(1500)
    const id2 = (customer.url().match(/order\/([0-9a-f-]{36})/) || [])[1]
    check('الطلب الثاني اتبعت', !!id2)
    await go(ops, '/staff/operations', 2500)
    const heard2 = await arrives(ops, /توزيع الطلبات الجديدة آليًا الآن \(1\)/, 20000)
    check('الطلب الثاني وصل العمليات', heard2 !== null)
    await click(ops, 'توزيع الطلبات الجديدة آليًا الآن', 3000)
    const tech2 = await arrives(tech, /بانتظار قبولك|مهامي \(1\)/, 20000)
    check('التوزيع الآلي وصّل الطلب للفني', tech2 !== null, (await body(tech)).slice(0, 300))
    await shot(ops, '16-ops-auto')
    await shot(tech, '17-tech-auto-job')

    /* ---------------------------------------------------------------- 11. auto, with nobody watching */
    stage('١١ — التوزيع الآلي والعمليات مقفولة')
    await go(ops, '/staff/operations', 2500)
    await ops.locator('button[role=radio]', { hasText: 'آلي' }).first().click()
    await ops.waitForTimeout(2000)
    check('العمليات حوّلت التوزيع لـ«آلي»', (await body(ops)).includes('آلي — مفعّل'), (await body(ops)).slice(0, 300))
    await ops.close()                                     // nobody has the room open now
    const before = (await body(tech)).match(/مهامي \((\d+)\)/)?.[1]
    await go(customer, '/new', 2000)
    await click(customer, 'صيانة وتكييف', 1200)
    await click(customer, 'غسيل', 600)
    await customer.locator('select', { has: customer.locator('option', { hasText: 'سبليت' }) }).first().selectOption({ label: 'سبليت' })
    const ta3 = customer.locator('textarea').first()
    if (await ta3.count()) await ta3.fill('طلب تجريبي ثالث — آلي والعمليات مقفولة')
    await click(customer, 'أقرب وقت ممكن', 400)
    await click(customer, 'تأكيد الطلب وإرسال الموقع', 2500)
    // A first-time customer gives only his mobile (one field, one button) — no registration.
    await customer.waitForTimeout(1500)
    const id3 = (customer.url().match(/order\/([0-9a-f-]{36})/) || [])[1]
    check('الطلب الثالث اتبعت', !!id3)
    const custAssigned = await arrives(customer, /فهد/, 20000)
    check('العميل شاف اسم الفني فورًا — اتسند من السيرفر بدون أي شاشة عمليات مفتوحة', custAssigned !== null, (await body(customer)).slice(0, 300))
    await go(tech, '/staff', 2500)
    const after3 = (await body(tech)).match(/مهامي \((\d+)\)/)?.[1]
    check('والمهمة نزلت عند الفني', Number(after3) > Number(before || 0), [before, after3])
    await shot(customer, '18-customer-auto-assigned')
    await shot(tech, '19-tech-auto-no-ops')

    for (const [n, p] of [['customer', customer], ['ops', ops], ['tech', tech], ['owner', owner]]) {
      check(`لا أخطاء جافاسكربت عند ${n}`, p.errors.length === 0, p.errors.slice(0, 3))
    }
  } catch (e) {
    check('السيناريو اكتمل بدون انقطاع', false, e.message.split('\n')[0])
    for (const [n, p] of [['customer', customer], ['ops', ops], ['tech', tech], ['owner', owner]]) await p.screenshot({ path: path.join(OUT, 'shots', '_fail-' + n + '.png') }).catch(() => {})
  }
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1))
  const checks = report.filter((r) => 'ok' in r)
  const bad = checks.filter((r) => !r.ok)
  console.log(`\n${checks.length - bad.length} passed, ${bad.length} failed`)
  await browser.close()
  process.exit(bad.length ? 1 : 0)
})()
