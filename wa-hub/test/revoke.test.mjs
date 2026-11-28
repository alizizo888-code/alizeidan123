import { createRevoker } from '../src/revoke.js'
let pass = 0, fail = 0
const t = (n, c, x) => { if (c) { pass++; console.log('PASS revoke: ' + n) } else { fail++; console.log('FAIL revoke: ' + n + ' ' + JSON.stringify(x)) } }
const kv = new Map(); const store = { get: (k, d) => (kv.has(k) ? kv.get(k) : d), set: (k, v) => kv.set(k, v) }
let clock = 1_000_000
const r = createRevoker({ store, now: () => clock, ttlH: 46 })
t('a reply is not kept', !r.remember({ purpose: 'reply', phone: '966500000000' }, 'mwc:0', 'mwc12'))
t('the payment link is kept', r.remember({ purpose: 'paylink', phone: '966500000000' }, 'mwc:0', 'mwc12') && r.pending() === 1)
t('not due before 46 hours', r.due().length === 0)
clock += 46 * 3600000
t('due at 46 hours (inside WhatsApp\'s ~48h)', r.due().length === 1 && r.due()[0].waId === 'mwc12')
r.drop(r.due()[0])
t('once done it is off the list', r.pending() === 0)
console.log(`revoke: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
