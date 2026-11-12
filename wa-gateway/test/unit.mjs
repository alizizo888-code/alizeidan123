import assert from 'node:assert/strict'
import { sign, safeEq, cleanId, jidToPhone, phoneToJid, isUserJid, extract, Pacer } from '../src/core.js'
assert.equal(sign('k', 'abc').length, 64)
assert.ok(safeEq('a', 'a')); assert.ok(!safeEq('a', 'b')); assert.ok(!safeEq('a', 'ab'))
assert.equal(cleanId('My Phone#1!'), 'myphone1')
assert.equal(jidToPhone('966501234567:12@s.whatsapp.net'), '966501234567')
assert.equal(phoneToJid('+966 50 123 4567'), '966501234567@s.whatsapp.net')
assert.ok(isUserJid('1@s.whatsapp.net')); assert.ok(!isUserJid('1@g.us')); assert.ok(!isUserJid('status@broadcast'))
assert.deepEqual(extract({ conversation: 'hi' }), { text: 'hi', kind: 'text' })
assert.equal(extract({ imageMessage: { caption: 'c' } }).kind, 'image')
assert.equal(extract({ audioMessage: { ptt: true } }).kind, 'voice')
assert.match(extract({ locationMessage: { degreesLatitude: 24.7, degreesLongitude: 46.6 } }).text, /maps\.google\.com\/\?q=24\.7,46\.6/)
assert.equal(extract({ ephemeralMessage: { message: { conversation: 'x' } } }).text, 'x')
let t = Date.parse('2026-01-01T10:00:00Z')
const p = new Pacer({ dailyCap: 2, now: () => t, rand: () => 0.5 })
assert.ok(p.canSend()); p.mark(); p.mark(); assert.ok(!p.canSend())
t += 86400000; assert.ok(p.canSend()); assert.equal(p.used(), 0)
assert.equal(p.delay(), 3250)
console.log('wa-gateway unit: OK')
import { detectSource, parseSessions } from '../src/core.js'
// Click-to-WhatsApp ad beats everything else
assert.deepEqual(detectSource({ extendedTextMessage: { text: 'hi', contextInfo: { externalAdReply: { sourceType: 'ad', sourceId: '120', title: 'تنظيف مكيفات', sourceUrl: 'https://www.instagram.com/p/x' } } } }, 'hi #ماب', 'haraj'),
  { source: 'ads', adNet: 'instagram', adId: '120', adTitle: 'تنظيف مكيفات' })
assert.deepEqual(detectSource({ conversation: 'السلام عليكم #ماب' }, 'السلام عليكم #ماب', 'haraj'), { source: 'map' })
assert.deepEqual(detectSource({ conversation: 'x' }, 'Hello #TikTok', ''), { source: 'tiktok' })
assert.deepEqual(detectSource({ conversation: 'x' }, 'سلام', 'haraj'), { source: 'haraj' })
assert.deepEqual(detectSource({ conversation: 'x' }, 'سلام', ''), {})
assert.deepEqual(parseSessions('sim01:حراج:haraj, sim02:ماب:map:0536554731;;SIM 3'), [
  { id: 'sim01', label: 'حراج', source: 'haraj', phone: '' },
  { id: 'sim02', label: 'ماب', source: 'map', phone: '0536554731' },
  { id: 'sim3', label: 'sim3', source: '', phone: '' }])
console.log('wa-gateway sources: OK')
