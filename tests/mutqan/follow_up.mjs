// Follow-up rules, order clock, coverage areas, working hours, standby technician, performance (owner, 2026-11-23).
globalThis.localStorage = { getItem: () => null, setItem: () => {} }
const { flagsOf, attentionOf, orderTimes, watchCfg, minsText } = await import('../../mutqan-app/src/lib/orderWatch.js')
const { inZone, onShift, canTake, pickTechnician } = await import('../../mutqan-app/src/assignment.js')
const { techPerformance } = await import('../../mutqan-app/src/lib/techPerf.js')
let pass = 0, fail = 0
const t = (n, c, x) => { if (c) { pass++; console.log('PASS follow-up: ' + n) } else { fail++; console.log('FAIL follow-up: ' + n + (x !== undefined ? '  -> ' + JSON.stringify(x) : '')) } }
const now = Date.parse('2026-11-23T10:00:00Z')
const ago = (m) => new Date(now - m * 60000).toISOString()

t('1 · unconfirmed after 12 minutes', !!flagsOf({ status: 'new', createdAt: ago(12) }, now).unconfirmed)
t('1 · not after 4', !flagsOf({ status: 'new', createdAt: ago(4) }, now).unconfirmed)
t('3 · not accepted after 6 minutes', !!flagsOf({ status: 'assigned', technicianId: 'a', assignedAt: ago(6), createdAt: ago(7), customerConfirmed: true }, now).not_accepted)
t('3 · stalled on the road an hour', !!flagsOf({ status: 'en_route', technicianId: 'a', statusHistory: [{ status: 'en_route', at: ago(65) }], createdAt: ago(80), customerConfirmed: true }, now).stalled)
t('3 · late 35 minutes past the appointment', !!flagsOf({ status: 'accepted', technicianId: 'a', appointmentAt: ago(35), statusHistory: [{ status: 'accepted', at: ago(5) }], createdAt: ago(90), customerConfirmed: true }, now).late)
t('the owner\'s minutes are used', !flagsOf({ status: 'new', createdAt: ago(12) }, now, watchCfg({ unconfirmedMinutes: 20 })).unconfirmed)
const at = attentionOf({ status: 'new', createdAt: ago(15), escalation: { reason: 'السعر' } }, now).map((x) => x.key)
t('4 · «محتاج تدخل» lists no technician + not confirmed + objection', at.includes('unassigned') && at.includes('unconfirmed') && at.includes('objection'), at)
t('4 · a finished order needs nobody', attentionOf({ status: 'completed', createdAt: ago(300) }, now).length === 0)

const clock = orderTimes({ status: 'accepted', createdAt: ago(60), confirmedAt: ago(57), assignedAt: ago(55), statusHistory: [{ status: 'assigned', at: ago(55) }, { status: 'accepted', at: ago(50) }] }, now)
const m = Object.fromEntries(clock.map((c) => [c.key, c]))
t('2 · confirm 3 min, assign 5, accept 5', m.confirm.mins === 3 && m.assign.mins === 5 && m.accept.mins === 5, clock)
t('2 · the arrival step is counting (50 min, open)', m.arrive.open && m.arrive.mins === 50, m.arrive)
t('2 · minutes read as hours past 60', minsText(95) === '1س 35د' && minsText(5) === '5د')

t('6 · Makkah order inside «مكة»', inZone({ zones: ['مكة'] }, { address: 'مكة المكرمة، العزيزية' }))
t('6 · Jeddah order outside «مكة»', !inZone({ zones: ['مكة'] }, { address: 'جدة' }))
t('6 · «عزيزيه» matches «العزيزية»', inZone({ zones: ['العزيزية'] }, { address: 'حي عزيزيه' }))
const riyadh = (h, m = 0, day = 1) => new Date(Date.UTC(2026, 10, 22 + day, h - 3, m))   // 2026-11-23 is a Monday (day 1)
t('7 · on shift 10:00 Makkah', onShift({ shift: { from: '08:00', to: '22:00' } }, riyadh(10)))
t('7 · off shift 23:00 Makkah', !onShift({ shift: { from: '08:00', to: '22:00' } }, riyadh(23)))
t('7 · night shift covers 01:00', onShift({ shift: { from: '18:00', to: '02:00' } }, riyadh(1)))
t('7 · day off', !onShift({ shift: { from: '08:00', to: '22:00', off: [1] } }, riyadh(10)))
t('8 · a technician who let it pass is not offered it again', !canTake({ userId: 'a' }, { skipTechIds: ['a'] }, []))
const pick = pickTechnician({ address: 'مكة', lat: 21.4, lng: 39.8 }, [{ userId: 'j', zones: ['جدة'] }, { userId: 'm', zones: ['مكة'] }], [], { strategy: 'capacity' })
t('6 · distribution picks the technician whose area it is', pick?.userId === 'm', pick)

const orders = [
  { technicianId: 'a', status: 'completed', createdAt: ago(300), assignedAt: ago(300), statusHistory: [{ status: 'accepted', at: ago(296) }, { status: 'arrived', at: ago(260) }], rating: 5, finalPrice: 200 },
  { technicianId: 'a', status: 'completed', createdAt: ago(200), assignedAt: ago(200), statusHistory: [{ status: 'accepted', at: ago(194) }, { status: 'arrived', at: ago(160) }], rating: 4, finalPrice: 100 },
  { technicianId: 'b', status: 'accepted', createdAt: ago(100), skipTechIds: ['a'] },
]
const p = techPerformance('a', orders, { now })
t('10 · received 3 (2 his + 1 he let pass), finished 2', p.received === 3 && p.done === 2 && p.passed === 1, p)
t('10 · accept average 5 min, arrive 35 min', p.acceptAvg === 5 && p.arriveAvg === 35, p)
t('10 · rating 4.5, revenue 300', p.rating === 4.5 && p.revenue === 300, p)

console.log(`\nfollow-up: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
