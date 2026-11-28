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

// Orders from the management screen
{
  const { applyCmd } = await import('../src/core.js')
  const meta = {}; const started = []; const removed = []; let saves = 0
  const ops = { meta, start: async (id) => started.push(id), remove: async (id) => { removed.push(id); delete meta[id] }, save: () => { saves++ } }
  assert.equal(await applyCmd({ cid: 'c1', op: 'add', id: 'dAB-x1', label: 'العملاء', mode: 'qr' }, ops), 'c1')
  assert.deepEqual(meta['dab-x1'], { label: 'العملاء', mode: 'qr', phone: '' }); assert.deepEqual(started, ['dab-x1']); assert.equal(saves, 1)
  await applyCmd({ cid: 'c2', op: 'add', id: 'd2', mode: 'code', phone: '+966 50 000 0000' }, ops)
  assert.equal(meta.d2.phone, '966500000000')
  assert.equal(await applyCmd({ cid: 'c3', op: 'restart', id: 'd2' }, ops), 'c3'); assert.equal(started.at(-1), 'd2')
  assert.equal(await applyCmd({ cid: 'c4', op: 'remove', id: 'd2' }, ops), 'c4'); assert.deepEqual(removed, ['d2']); assert.ok(!meta.d2)
  assert.equal(await applyCmd({ cid: 'c5', op: 'remove', id: 'nothing' }, ops), 'c5')   // acknowledged, nothing to do
  assert.equal(await applyCmd({ op: 'add', id: 'x' }, ops), null)                        // no id from the server: ignored
  assert.equal(await applyCmd({ cid: 'c6', op: 'evil', id: 'x' }, ops), 'c6'); assert.ok(!meta.x)
  console.log('wa-gateway commands: OK')
}
