// Daily network test (owner order 2026-11-22: «اعمل تست للتكرار والسرعة … أزرار ما بتفتحش، صفحات وأيقونات عطلانة»).
// For every site of the network: the home page, the app, the WhatsApp/contact page, the payment page, an article
// link from the sitemap, and the plugin's REST — status, time, JavaScript errors, the «حصل خلل» screen, and every
// button / link of the app's home that leads nowhere. The desk too. Writes health-report.md; exit 1 if anything failed.
import { chromium } from 'playwright'
import fs from 'node:fs'

const LABELS = ['motakhases', 'muhandis', 'aqsa', 'raad', 'mutamakkin', 'khabeer', 'homeservice', 'ibtikar', 'mutamad', 'misriya', 'nukhba', 'riyada', 'baraa', 'safwa', 'tamayuz', 'nasmat', 'wahat', 'amaan', 'kafaa', 'jawda']
const HOSTS = ['oxygen11.com', 'mutqan.oxygen11.com', ...LABELS.map((l) => `${l}-maintenance-general-contracting.oxygen11.com`)]
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean)
const SLOW = 6000
const ROUTES = ['/', '/pay', '/contact', '/warranty', '/new']
const rows = []
const fail = (host, what, detail) => rows.push({ host, what, detail, bad: true })
const ok = (host, what, detail) => rows.push({ host, what, detail, bad: false })

async function timed(url, opts = {}) {
  const t = Date.now()
  try {
    const r = await fetch(url, { redirect: 'follow', ...opts, headers: { 'user-agent': 'OxygenHealth/1.0', ...(opts.headers || {}) }, signal: AbortSignal.timeout(25000) })
    const body = await r.text()
    return { status: r.status, ms: Date.now() - t, body, url: r.url }
  } catch (e) { return { status: 0, ms: Date.now() - t, body: String(e.message || e) } }
}

async function http(host) {
  const h = await timed(`https://${host}/?h=${Date.now()}`)
  if (h.status !== 200) fail(host, 'الصفحة الرئيسية', `HTTP ${h.status}`)
  else (h.ms > SLOW ? fail : ok)(host, 'الصفحة الرئيسية', `${h.ms} ms`)
  if (/Fatal error|Parse error|There has been a critical error|حدث خطأ فادح/i.test(h.body)) fail(host, 'خطأ PHP في الصفحة', 'critical error')
  const rest = await timed(`https://${host}/wp-json/mutqan/v1/field`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{"op":"posts"}' })
  if (![200, 401, 403].includes(rest.status)) fail(host, 'واجهة النظام (REST)', `HTTP ${rest.status}`)
  // one live article from the sitemap must open (no 404 / no critical error)
  const sm = await timed(`https://${host}/wp-sitemap-posts-post-1.xml`)
  const locs = [...(sm.body || '').matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1])
  if (locs.length) {
    for (const u of [locs[0], locs[Math.floor(locs.length / 2)], locs[locs.length - 1]]) {
      const a = await timed(u)
      if (a.status !== 200) fail(host, 'مقال من الخريطة', `${a.status} ${u}`)
      else if (a.ms > SLOW) fail(host, 'مقال بطيء', `${a.ms} ms ${u}`)
    }
    ok(host, 'مقالات', `${locs.length} في خريطة الموقع`)
  } else if (sm.status !== 200) ok(host, 'خريطة المقالات', `HTTP ${sm.status} (مفيش مقالات منشورة؟)`)
}

async function app(browser, host) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36 OxygenHealth' })
  const page = await ctx.newPage()
  const errs = []
  page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)))
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|ERR_BLOCKED|google|gtag|analytics|Failed to load resource/i.test(m.text())) errs.push(m.text().slice(0, 160)) })
  for (const r of ROUTES) {
    const t = Date.now()
    try {
      await page.goto(`https://${host}/app/#${r}`, { waitUntil: 'domcontentloaded', timeout: 30000 })
      await page.waitForTimeout(r === '/' ? 3500 : 2000)
      const txt = await page.evaluate(() => document.body.innerText || '')
      if (/حصل خلل بسيط في الشاشة/.test(txt)) fail(host, `التطبيق ${r}`, 'شاشة «حصل خلل»')
      else if (txt.trim().length < 20) fail(host, `التطبيق ${r}`, 'الصفحة فاضية')
      else ok(host, `التطبيق ${r}`, `${Date.now() - t} ms`)
    } catch (e) { fail(host, `التطبيق ${r}`, String(e.message).slice(0, 120)) }
  }
  // the home's buttons: each one must do something (navigate, open a panel, or open a link)
  try {
    await page.goto(`https://${host}/app/#/`, { waitUntil: 'domcontentloaded', timeout: 30000 }); await page.waitForTimeout(3000)
    const n = await page.locator('main button:visible, main a[href]:visible').count()
    let dead = []
    for (let i = 0; i < Math.min(n, 25); i++) {
      const el = page.locator('main button:visible, main a[href]:visible').nth(i)
      const label = ((await el.innerText().catch(() => '')) || (await el.getAttribute('aria-label').catch(() => '')) || '').trim().slice(0, 40)
      const href = await el.getAttribute('href').catch(() => null)
      // hidden carousel slides (inert) and the dot of the slide already showing are not clickable for a real visitor
      await el.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {})
      // skip what a visitor can't actually press: hidden slides (inert / off-screen behind the carousel edge) and the dot of the slide already showing
      if (await el.evaluate((e) => {
        if (e.closest('[inert],[aria-hidden="true"]') || e.getAttribute('aria-current') === 'true') return true
        const r = e.getBoundingClientRect(); const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
        return !top || !(e === top || e.contains(top))
      }).catch(() => false)) continue
      if (href && /^(tel:|mailto:|https?:|whatsapp:)/.test(href)) continue
      const own0 = await el.evaluate((e) => e.outerHTML).catch(() => '')
      const before = await page.evaluate(() => location.hash + '|' + document.querySelectorAll('[role=dialog],.fixed').length + '|' + document.body.innerText.length)
      const pop = page.waitForEvent('popup', { timeout: 1500 }).catch(() => null)
      let missed = false
      await el.click({ timeout: 3000 }).catch(() => { missed = true })
      const p = await pop
      await page.waitForTimeout(700)
      const after = await page.evaluate(() => location.hash + '|' + document.querySelectorAll('[role=dialog],.fixed').length + '|' + document.body.innerText.length)
      const own1 = await el.evaluate((e) => e.outerHTML).catch(() => '')
      // the button itself changing (a flash, a selected state) counts as doing something
      // a carousel that moved on mid-click hides the button (inert): that's the slide changing, not a dead button
      const gone = missed && await el.evaluate((e) => !!e.closest('[inert],[aria-hidden="true"]')).catch(() => true)
      if (!p && !gone && before === after && own0 === own1 && label) dead.push(label)
      if (p) await p.close().catch(() => {})
      if (after !== before) { await page.goto(`https://${host}/app/#/`, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1500) }
    }
    if (dead.length) fail(host, 'أزرار مبتعملش حاجة', dead.join('، '))
    else ok(host, 'أزرار الرئيسية', `${Math.min(n, 25)} زرار اتجربوا`)
  } catch (e) { fail(host, 'تجربة الأزرار', String(e.message).slice(0, 120)) }
  if (errs.length) fail(host, 'أخطاء JavaScript', [...new Set(errs)].slice(0, 4).join(' | '))
  await ctx.close()
}

const browser = await chromium.launch()
for (const host of HOSTS.filter((h) => !ONLY.length || ONLY.some((o) => h.startsWith(o)))) {
  await http(host)
  await app(browser, host)
}
// the desk
for (const p of ['/', '/m', '/ops', '/t/', '/desk-version.json']) {
  const r = await timed(`https://desk.oxygen11.com${p}`)
  if (r.status !== 200) fail('desk.oxygen11.com', p, `HTTP ${r.status}`); else ok('desk.oxygen11.com', p, `${r.ms} ms`)
}
await browser.close()

const bad = rows.filter((r) => r.bad)
const md = [`# فحص الشبكة — ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC`, '', `${bad.length ? `❌ ${bad.length} مشكلة` : '✅ كله سليم'} — ${rows.length} اختبار على ${HOSTS.length} موقع + الديسك`, '']
if (bad.length) { md.push('| الموقع | الاختبار | التفاصيل |', '|---|---|---|'); for (const r of bad) md.push(`| ${r.host} | ${r.what} | ${String(r.detail).replace(/\|/g, '/')} |`) }
md.push('', '<details><summary>كل الاختبارات</summary>', '', ...rows.map((r) => `- ${r.bad ? '❌' : '✅'} ${r.host} — ${r.what}: ${r.detail}`), '</details>')
fs.writeFileSync('health-report.md', md.join('\n'))
console.log(md.slice(0, 40).join('\n'))
process.exit(bad.length ? 1 : 0)
