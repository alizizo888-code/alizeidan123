// How the appointment reads, in the WhatsApp copy and on every screen.
// Ali: the same day says «اليوم» — no date; any other day says the weekday by name with its date next to it.
// And no «وقت إرسال الطلب» line: WhatsApp already shows when a message arrived.
import { fullWhen } from '../../mutqan-app/src/lib/orderFacts.js'
import { buildOrderMessage } from '../../mutqan-app/src/lib/waOrder.js'
let pass = 0, fail = 0
const t = (n, c, x) => { if (c) { pass++; console.log('PASS when: ' + n) } else { fail++; console.log('FAIL when: ' + n + '  -> ' + JSON.stringify(x)) } }
const now = new Date(2026, 10, 12, 10, 0)            // Thursday 12 November 2026, 10:00
const at = (d, h, m = 0) => new Date(2026, 10, d, h, m).toISOString()
t('same day: «اليوم» and the time, no date', fullWhen(at(12, 17), now) === 'اليوم — 5:00 م', fullWhen(at(12, 17), now))
t('tomorrow: the weekday with its date (not «غدًا»)', fullWhen(at(13, 9, 30), now) === 'الجمعة 13 نوفمبر — 9:30 ص', fullWhen(at(13, 9, 30), now))
t('a Sunday', fullWhen(at(15, 14), now) === 'الأحد 15 نوفمبر — 2:00 م', fullWhen(at(15, 14), now))
t('a Wednesday', fullWhen(at(18, 20), now) === 'الأربعاء 18 نوفمبر — 8:00 م', fullWhen(at(18, 20), now))
t('next year shows the year', fullWhen(new Date(2027, 0, 3, 11).toISOString(), now) === 'الأحد 3 يناير 2027 — 11:00 ص', fullWhen(new Date(2027, 0, 3, 11).toISOString(), now))
// The copy is built against the real clock, so its appointment must be a real future Sunday (never «today»).
const sun = new Date(); sun.setDate(sun.getDate() + ((7 - sun.getDay()) % 7 || 7)); sun.setHours(14, 0, 0, 0)
const msg = buildOrderMessage({ draft: { specialtyId: 'ac', appointmentAt: sun.toISOString() }, who: { name: 'خالد' }, orderId: 'abc12345' })
t('the WhatsApp copy has no «وقت إرسال الطلب» line', !/وقت إرسال الطلب/.test(msg), msg)
t('and carries the appointment as the weekday + date', /📅 الموعد: الأحد \d+ \S+/.test(msg), msg)
console.log(`\nwhen: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
