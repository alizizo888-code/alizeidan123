// Ringtone and vibration, chosen per device, actually used when a notification arrives.
//
// Sound and vibration are recorded at the browser's own doors (every oscillator started, every
// navigator.vibrate call), so this checks what the phone would really do — not what a setting says.
const { chromium, devices } = require('playwright')
const path = require('path')
const BASE = 'http://127.0.0.1:8099/app/'
const PW = process.env.E2E_PW || 'Flow-Test-1234'
let fails = 0
const check = (n, ok, x) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  -> ' + JSON.stringify(x ?? '').slice(0, 300))); if (!ok) fails++ }
const INSTRUMENT = () => {
  window.__rec = { tones: [], vibes: [] }
  Object.defineProperty(navigator, 'vibrate', { configurable: true, value: (p) => { window.__rec.vibes.push(Array.isArray(p) ? p : [p]); return true } })
  const st = OscillatorNode.prototype.start
  OscillatorNode.prototype.start = function (...a) { window.__rec.tones.push(Math.round(this.frequency.value)); return st.apply(this, a) }
}
;(async () => {
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] })
  const ctx = await browser.newContext({ ...devices['Pixel 7'], locale: 'ar-SA' })
  await ctx.addInitScript(INSTRUMENT)
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message))
  await p.goto(BASE + '#/staff', { waitUntil: 'networkidle' }); await p.waitForTimeout(1500)
  const inputs = p.locator('input:visible'); await inputs.nth(0).fill('fahd'); await inputs.nth(1).fill(PW)
  await p.locator('button:visible', { hasText: 'دخول' }).first().click(); await p.waitForTimeout(3000)
  await p.goto(BASE + '#/notifications', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000)
  await p.locator('button', { hasText: 'نغمة واهتزاز هذا الجهاز' }).click()
  check('the technician sees his own device panel', await p.locator('text=اختيارك هنا للجهاز ده بس').isVisible())
  // pick «رنة تليفون» for important, «قوي متكرر» vibration for important, «مزدوج» for normal
  const sec = (t) => p.locator('div', { has: p.locator('p', { hasText: t }) }).last()
  await sec('نغمة الطلبات الجديدة والمهمة').locator('button', { hasText: 'رنة تليفون' }).click()
  await sec('اهتزاز الطلبات الجديدة والمهمة').locator('button', { hasText: 'قوي متكرر' }).click()
  await sec('اهتزاز الإشعارات العادية').locator('button', { hasText: 'مزدوج' }).click()
  const saved = await p.evaluate(() => JSON.parse(localStorage.getItem('mq_sound_device') || '{}'))
  check('the choice is kept on this device', saved.highTone === 'phone' && saved.highVibe === 'strong' && saved.normalVibe === 'double', saved)
  await p.screenshot({ path: path.join(__dirname, 'out/shots/device-sound.png'), fullPage: true })

  await p.evaluate(() => { window.__rec = { tones: [], vibes: [] } })
  await p.evaluate(() => window.dispatchEvent(new CustomEvent('mutqan:push', { detail: { id: 'n1', title: 'تحديث', body: 'x', priority: 'normal' } })))
  await p.waitForTimeout(800)
  let r = await p.evaluate(() => window.__rec)
  check('a normal notification vibrates with HIS pattern (مزدوج)', JSON.stringify(r.vibes[0]) === '[180,100,180]', r)
  check('and rings the company\'s normal tone (he left it)', r.tones.includes(784) && r.tones.includes(988), r.tones)

  await p.evaluate(() => { window.__rec = { tones: [], vibes: [] } })
  await p.goto(BASE + '#/staff', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000)
  await p.evaluate(() => { window.__rec = { tones: [], vibes: [] }; window.dispatchEvent(new CustomEvent('mutqan:push', { detail: { id: 'n2', title: '🔔 مهمة جديدة', body: 'غسيل', priority: 'high' } })) })
  await p.waitForTimeout(1200)
  r = await p.evaluate(() => window.__rec)
  check('a new task rings HIS tone (رنة تليفون 440/480)', r.tones.includes(440) && r.tones.includes(480), r.tones)
  check('and vibrates with HIS pattern (قوي متكرر)', r.vibes.some((v) => JSON.stringify(v) === '[500,150,500,150,800]'), r.vibes)
  await p.locator('button', { hasText: 'إسكات' }).first().click().catch(() => {})

  await p.evaluate(() => { const d = JSON.parse(localStorage.getItem('mq_sound_device')); d.mute = true; localStorage.setItem('mq_sound_device', JSON.stringify(d)) })
  await p.evaluate(() => { window.__rec = { tones: [], vibes: [] }; window.dispatchEvent(new CustomEvent('mutqan:push', { detail: { id: 'n3', title: 'تحديث', body: 'y', priority: 'normal' } })) })
  await p.waitForTimeout(800)
  r = await p.evaluate(() => window.__rec)
  check('muted on this device: no sound, no vibration', !r.tones.length && !r.vibes.length, r)
  check('but the banner still shows', /تحديث/.test(await p.innerText('body')))

  // another device follows the company setting untouched
  const ctx2 = await browser.newContext({ ...devices['Pixel 7'], locale: 'ar-SA' }); await ctx2.addInitScript(INSTRUMENT)
  const q = await ctx2.newPage()
  await q.goto(BASE + '#/', { waitUntil: 'networkidle' }); await q.waitForTimeout(1500)
  await q.evaluate(() => { window.__rec = { tones: [], vibes: [] }; window.dispatchEvent(new CustomEvent('mutqan:push', { detail: { id: 'n4', title: 'تحديث', body: 'z', priority: 'normal' } })) })
  await q.waitForTimeout(800)
  r = await q.evaluate(() => window.__rec)
  check('another phone is not affected (company default: قصير)', JSON.stringify(r.vibes[0]) === '[120]', r)
  check('no script errors', !errs.length, errs)
  await browser.close()
  console.log(fails ? `\n${fails} failed` : '\nall passed'); process.exit(fails ? 1 : 0)
})().catch((e) => { console.error(e); process.exit(1) })
