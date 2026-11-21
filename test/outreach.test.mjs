// The campaign lanes: which line may carry what, and the pause between two campaign messages.
import * as OR from '../src/outreach.js'
let pass = 0; let fail = 0
const t = (n, ok, x = '') => { if (ok) { pass++; console.log('PASS outreach: ' + n) } else { fail++; console.log('FAIL outreach: ' + n + (x ? '\n   ' + JSON.stringify(x) : '')) } }

const our = { 546619205: { role: 'outreach' }, 549247636: { role: 'manager' }, 549665320: { role: 'sales' } }
t('roleOf by the last nine digits, any spelling', OR.roleOf('+966 54 661 9205', our) === 'outreach' && OR.roleOf('0549247636', our) === 'manager' && OR.roleOf('0500000000', our) === '')
t('hub roles: manager → team, outreach → outreach, sales → customers, unknown keeps its role', OR.hubRole('manager') === 'team' && OR.hubRole('outreach') === 'outreach' && OR.hubRole('sales') === 'customers' && OR.hubRole('', 'team') === 'team')

const S = { id: 's', phone: '966549665320', role: 'customers' }; const O = { id: 'o', phone: '966546619205', role: 'outreach' }; const M = { id: 'm', phone: '966549247636', role: 'team' }
t('a campaign message leaves only from its outreach line', OR.pickLine({ purpose: 'outreach', line: 'o', phone: '966500000001' }, [S, O, M]) === 'o')
t('…never from a sales line, even when the outreach line is down (it waits)', OR.pickLine({ purpose: 'outreach', line: 'o', phone: '966500000001' }, [S, M]) === null)
t('…and never from another outreach line than the one the plan chose', OR.pickLine({ purpose: 'outreach', line: 'x', phone: '966500000001' }, [S, O]) === null)
t('a customer reply with no line chosen never goes out on the outreach line', OR.pickLine({ purpose: 'reply', phone: '966500000001' }, [O, S]) === 's')
t('…but a prospect who answered the campaign keeps talking to the same outreach number', OR.pickLine({ purpose: 'reply', line: 'o', phone: '966500000001' }, [S, O]) === 'o')
t('the room goes to the team line (the manager)', OR.pickLine({ purpose: 'room', phone: '966536554731' }, [S, O, M]) === 'm')
t('only an outreach line connected: a customer still gets an answer rather than none', OR.pickLine({ purpose: 'reply', phone: '966500000001' }, [O]) === 'o')
t('nothing connected → null', OR.pickLine({ purpose: 'reply', phone: '1' }, []) === null)

let lo = 1; let hi = 0
for (let i = 0; i < 200; i++) { const g = OR.gapSec(); lo = Math.min(lo === 1 ? g : lo, g); hi = Math.max(hi, g) }
t('the pause between two campaign messages is 70–200 s and never fixed', lo >= 70 && hi <= 200 && hi > lo, [lo, hi])
const last = new Map([['o', { at: 1000000, gap: 90 }]])
t('due: not before the pause, yes after it, yes for a line that never sent', !OR.due('o', last, 1000000 + 89000) && OR.due('o', last, 1000000 + 90000) && OR.due('n', last, 0))

console.log('wa-hub outreach: ' + pass + ' passed, ' + fail + ' failed')
if (fail) process.exit(1)
