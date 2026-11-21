// The words customers really wrote (owner's test, 2026-11-21) → the units per type, and the haggling steps.
import * as M from '../src/menu.js'
let pass = 0; let fail = 0
const t = (name, ok, x = '') => { if (ok) { pass++; console.log('PASS menu: ' + name) } else { fail++; console.log('FAIL menu: ' + name + '  -> ' + JSON.stringify(x)) } }
const eq = (a, b) => a.split === (b.split || 0) && a.window === (b.window || 0) && a.other === (b.other || 0)
const cases = [
  ['مكيف اسبليت و مكيف شباك', { split: 1, window: 1 }],
  ['لا ابغى غسيل ٢ مكيف و احد شباك و واحد اسبليت', { split: 1, window: 1 }],
  ['غسيل مكيف اسبليت بكام', { split: 1 }],
  ['3 سبليت', { split: 3 }],
  ['عندي تلاته سبليت واتنين شباك', { split: 3, window: 2 }],
  ['مكيفين سبليت', { split: 2 }],
  ['سبليت 4', { split: 4 }],
  ['6 مكيفات سبليت و كاسيت', { split: 6, other: 1 }],
  ['غسيل ٥ شبابيك', { window: 5 }],
]
for (const [txt, want] of cases) { const p = M.parseAc(txt); t('«' + txt + '»', eq(p, want), p) }
t('«مكيفين» with no type → a count to keep, the type still asked', M.parseAc('عايز اغسل مكيفين').any === 2 && M.parseAc('عايز اغسل مكيفين').units === 0)
t('«السلام عليكم» is no AC', !M.parseAc('السلام عليكم').found)
t('numbers: «٢» «اتنين» «3 مكيف»', M.numberIn('٢') === 2 && M.numberIn('اتنين') === 2 && M.numberIn('3 مكيف') === 3)
t('abuse is seen', M.isAbuse('انتا غبى') && M.isAbuse('يا حمار') && !M.isAbuse('شكرا يا غالي'))
// haggling: list 160, floor 144
const m = { price: { total: 160, floor: 144, label: 'غسيل 1 سبليت + 1 شباك' } }
let h = M.haggle(m, null); t('1st: one step (150)', h.offer === 150 && !h.agreed, h)
h = M.haggle(m, 100); t('100 under the floor → refused, the floor offered', !h.agreed && h.offer === 144, h)
h = M.haggle(m, 100); t('100 again → still refused', !h.agreed && h.offer === 144, h)
h = M.haggle(m, 145); t('145 (above our last offer 144) → agreed at 144', h.agreed === 144, h)
const m2 = { price: { total: 100, floor: 100, label: 'غسيل 1 سبليت' } }
h = M.haggle(m2, null); t('«ممنوع فصال» (floor = list) → a polite no', !h.agreed && /مفيش عليه خصم/.test(h.text), h)
t('time: 9 am → today options', M.timeChoices(9).mode === 'today' && M.timeChoices(9).opts[0].startsWith('اليوم'))
t('time: 22 → late, 23 and 2 am → tomorrow', M.timeChoices(22).mode === 'late' && M.timeChoices(23).mode === 'tomorrow' && M.timeChoices(2).opts[0].startsWith('النهارده الصبح'))
console.log(`wa-hub menu: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
