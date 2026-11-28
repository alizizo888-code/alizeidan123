// One relative-time wording everywhere: orders, messages, steps, notifications, offers.
import { ago } from '../../mutqan-app/src/lib/ago.js'
let pass = 0, fail = 0
const t = (n, c, x) => { if (c) { pass++; console.log('PASS ago: ' + n) } else { fail++; console.log('FAIL ago: ' + n + '  -> ' + JSON.stringify(x)) } }
const now = Date.parse('2026-11-14T12:00:00Z'); const back = (s) => new Date(now - s * 1000).toISOString()
t('moments', ago(back(10), now) === 'من لحظات')
t('one minute', ago(back(60), now) === 'من دقيقة', ago(back(60), now))
t('two minutes', ago(back(120), now) === 'من دقيقتين')
t('five minutes', ago(back(300), now) === 'من 5 دقايق', ago(back(300), now))
t('25 minutes', ago(back(1500), now) === 'من 25 دقيقة')
t('two hours', ago(back(7200), now) === 'من ساعتين')
t('3 days', ago(back(3 * 86400), now) === 'من 3 أيام')
t('future appointment', ago(new Date(now + 3 * 3600e3).toISOString(), now) === 'بعد 3 ساعات')
t('unix seconds', ago(now / 1000 - 3600, now) === 'من ساعة')
t('empty is empty', ago('', now) === '' && ago(null, now) === '')
console.log(`\nago: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
