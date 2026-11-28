// The wake call and voice commands of the staff assistant.
import { wakeSplit, STOP_RE, YES_RE, NO_RE, speakable } from '../../mutqan-app/src/lib/voice.js'
let bad = 0; const c = (n, ok) => { if (!ok) bad++; console.log((ok ? 'PASS ' : 'FAIL ') + 'voice: ' + n) }
c('wake plain', wakeSplit('يا مساعد').woke && wakeSplit('يا مساعد').rest === '')
c('wake + command', wakeSplit('يا متقن فين الفني محمود').rest === 'فين الفني محمود')
c('wake mid-sentence', wakeSplit('طيب يا أكسجين، الطلبات الجديدة').rest === 'الطلبات الجديدة')
c('no wake on normal talk', !wakeSplit('المساعد الفني راح').woke)
c('stop words', STOP_RE.test('خلاص') && STOP_RE.test('شكرا') && !STOP_RE.test('خلاص اسند الطلب'))
c('yes/no', YES_RE.test('نفذ') && YES_RE.test('تمام نفذ') && NO_RE.test('الغي') && !YES_RE.test('الغي'))
c('speakable strips links', !speakable('شوف https://maps.google.com/?q=1,2 هنا').includes('http'))
process.exit(bad ? 1 : 0)
