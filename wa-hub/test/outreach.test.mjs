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

// prepared lines and paused numbers (owner, 2026-11-21)
const ours = { 549247636: { role: 'manager', label: 'المدير', status: 'pending', local: '0549247636' }, 546619205: { role: 'outreach', label: 'استقطاب ١', status: 'active', local: '0546619205' }, 549715646: { role: 'sales', label: 'مبيعات ٤', status: 'paused', local: '0549715646' }, 549648890: { role: 'reserve', label: 'فني', status: 'active' } }
const prep = OR.toPrepare(ours, [{ id: 'l1', phone: '966546619205', role: 'outreach' }])
t('every number of ours without a line gets one, named, with its role (a linked one and a reserve one are skipped)', prep.length === 2 && prep.some((l) => l.id === 'n549247636' && l.role === 'team' && /المدير — 0549247636/.test(l.label)) && prep.some((l) => l.id === 'n549715646' && l.role === 'paused'), prep)
t('…and never twice: a prepared line (still without a phone) is not prepared again', OR.toPrepare(ours, [{ id: 'l1', phone: '966546619205' }, ...prep]).length === 0)
t('a paused number: hub role «paused»', OR.hubRole('sales', 'customers', 'paused') === 'paused' && OR.hubRole('outreach', 'customers', 'active') === 'outreach')
const P = { id: 'p', phone: '966549715646', role: 'paused' }
t('a paused line takes no new conversation', OR.pickLine({ purpose: 'reply', phone: '966500000001' }, [P, S]) === 's')
t('…but a chat already on it is answered from it (nothing is cut mid-conversation)', OR.pickLine({ purpose: 'reply', line: 'p', phone: '966500000001' }, [P, S]) === 'p')
t('…and it never carries a campaign', OR.pickLine({ purpose: 'outreach', line: 'p', phone: '966500000001' }, [P, O]) === null)

// a number moved to the new WhatsApp Center (owner, 2026-11-22): never offered a QR here, and any line it already
// has on this hub (linked or still a QR placeholder) gets logged out and dropped — one live session, not two.
const oursCenter = { 536554731: { role: 'sales', label: 'مبيعات ٢ (الطوارئ)', status: 'active', local: '0536554731', center: true }, 549423050: { role: 'sales', label: 'مبيعات ١', status: 'active', local: '0549423050' } }
t('a center-moved number never gets prepared for QR here', OR.toPrepare(oursCenter, []).length === 1 && OR.toPrepare(oursCenter, [])[0].id === 'n549423050')
const linkedCenter = { id: 'l9', phone: '966536554731', role: 'customers' }
const placeholderCenter = { id: 'n536554731', phone: '', role: 'customers' }
const other = { id: 'l1', phone: '966549423050', role: 'customers' }
t('a center-moved number already linked here is retired', OR.toRetire(oursCenter, [linkedCenter, other]).length === 1 && OR.toRetire(oursCenter, [linkedCenter, other])[0].id === 'l9')
t('…and so is a bare QR placeholder for it', OR.toRetire(oursCenter, [placeholderCenter, other]).length === 1 && OR.toRetire(oursCenter, [placeholderCenter, other])[0].id === 'n536554731')
t('…nothing to retire once it is already gone', OR.toRetire(oursCenter, [other]).length === 0)

console.log('wa-hub outreach: ' + pass + ' passed, ' + fail + ' failed')
if (fail) process.exit(1)
