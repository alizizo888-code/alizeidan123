// Who is free, what a job is worth, and who owes whom — the three sums the field runs on.
//
// Each of these was wrong in a way nobody saw: a technician whose profile carried no capacity was
// permanently "full", so auto-dispatch skipped him and the room counted 0 available; an AC job's
// price lived in acQuote, which six screens did not read, so it counted as zero; and the technician
// had no view of his own cash at all. Imports the real src modules, so it tests what ships.
// Run: node --import ./tests/mutqan/register-loader.mjs tests/mutqan/money.mjs
import { remainingCapacity, isTechAvailable, capacityOf, DEFAULT_CAPACITY } from '../../mutqan-app/src/assignment.js'
import { custody } from '../../mutqan-app/src/lib/custody.js'
import { orderAmount } from '../../mutqan-app/src/payment.js'
import { payroll } from '../../mutqan-app/src/lib/salary.js'

let pass = 0, fail = 0
const t = (n, c, x) => { c ? pass++ : fail++; console.log((c ? 'PASS ' : 'FAIL ') + 'money: ' + n + (c ? '' : '  -> ' + JSON.stringify(x))) }
const today = new Date().toISOString()

// capacity
t('a profile with no capacity is not "full"', isTechAvailable({ userId: 'a' }, []) === true)
t('it gets the same default the technician\'s own form starts at', capacityOf({}) === DEFAULT_CAPACITY && DEFAULT_CAPACITY === 8)
t('a set capacity is respected', remainingCapacity({ userId: 'a', dailyCapacity: 2 }, [{ technicianId: 'a', assignedAt: today }, { technicianId: 'a', assignedAt: today }]) === 0)
t('an off technician is never available, capacity or not', isTechAvailable({ userId: 'a', active: false }, []) === false)

// what a job is worth
t('the final price wins', orderAmount({ price: 200, acQuote: { total: 120 } }) === 200)
t('an AC job with only its calculator quote counts the quote', orderAmount({ acQuote: { total: 120 } }) === 120)
t('an agreed price counts', orderAmount({ agreedPrice: 90 }) === 90)
t('nothing priced is zero, not NaN', orderAmount({}) === 0)

// the account between the company and one technician
const orders = [
  { technicianId: 'fahd', status: 'completed', price: 200, paymentStatus: 'paid', paymentMethod: 'pm-cash' },
  { technicianId: 'fahd', status: 'completed', price: 100, paymentStatus: 'paid', paymentMethod: 'pm-card' },     // paid by card: not in his pocket
  { technicianId: 'fahd', status: 'in_progress', price: 500, paymentStatus: 'paid', paymentMethod: 'pm-cash' },  // not finished: not counted
  { technicianId: 'other', status: 'completed', price: 999, paymentStatus: 'paid', paymentMethod: 'pm-cash' },   // a colleague's
  { technicianId: 'fahd', status: 'completed', price: 300, paymentStatus: 'paid', paymentMethod: 'pm-cash', dispatch: 'site' }, // in-house
]
const a = custody('fahd', orders, [], 30)
t('only his finished jobs count', a.jobs === 2 && a.sales === 300, a)
t('his share is 30% of what he finished', a.commission === 90, a)
t('cash in his hand is the cash jobs only', a.cash === 200, a)
t('he owes the company cash minus his share', a.balance === 110, a)
const b = custody('fahd', orders, [{ techId: 'fahd', dir: 'in', amount: 110 }, { techId: 'other', dir: 'in', amount: 999 }], 30)
t('after he hands it in, the account is even', b.balance === 0 && b.settledIn === 110, b)
const c = custody('fahd', [{ technicianId: 'fahd', status: 'completed', price: 100, paymentStatus: 'paid', paymentMethod: 'pm-card' }], [], 30)
t('when the customer paid the company, the company owes him his share', c.balance === -30, c)

// «نظام راتب» (owner, 2026-11-24)
{
  const W = { mode: 'salary', salary: 3000, orderBonus: 5, maxBonus: 300, tiers: [{ n: 2, amt: 100 }, { n: 4, amt: 200 }] }
  const mk = (i, x = {}) => ({ id: 'j' + i, technicianId: 'sami', status: 'completed', price: 200, paymentStatus: 'paid', paymentMethod: 'cash', completedAt: today, ...x })
  const jobs = [mk(1), mk(2), mk(3, { complaint: { at: today } })]
  const S = [{ techId: 'sami', kind: 'penalty', amount: 50, at: today }, { techId: 'sami', kind: 'bonus', amount: 20, at: today }, { techId: 'sami', kind: 'in', amount: 400, at: today }]
  const p = payroll('sami', jobs, S, W, today.slice(0, 7))
  t('salary: 3 jobs, 2 clean → 2×5 + the 2-job step 100', p.orderBonus === 10 && p.tier.amt === 100 && p.autoBonus === 110, p)
  t('…due = salary + bonuses − penalties, never a percentage', p.due === 3000 + 110 + 20 - 50, p)
  t('…next step told: 1 more job → +200', p.toNext === 1 && p.next.amt === 200, p)
  const c2 = custody('sami', jobs, S, 30, W)
  t('…all the cash he collected is the company\'s (no share), bonus/penalty stay out of his cash', c2.commission === 0 && c2.balance === 600 - 400, c2)
  const p2 = payroll('sami', Array.from({ length: 80 }, (_, i) => mk(10 + i)), [], { ...W, tiers: [{ n: 70, amt: 300 }] }, today.slice(0, 7))
  t('…the monthly cap holds (80×5 + 300 → 300)', p2.autoBonus === 300, p2)
}

console.log(fail ? `FAILED money (${fail})` : `OK money (${pass})`)
process.exit(fail ? 1 : 0)
