// Lead-capture & conversion scenarios (multi-turn): hesitation, trust fear, haggling to the floor, callback and business hand-offs.
import { converse } from './sim.mjs'
let pass = 0, fail = 0
const t = (n, ok, i) => { if (ok) pass++; else { fail++; console.log('FAIL', n, i || '') } }
const run = (turns, o) => converse(turns, o)
const noPrice = (r) => !/\d{2,4}\s*(ريال|ر\.س|جنيه)/.test(r)

// 1. trust fear -> reassurance with guarantees, no invented price, flow continues
{ const r = run(['خايف من النصب', 'طيب ابغى غسيل ٣ مكيفات']); t('trust:reassure', /ضمان|فاتوره|معتمد/.test(r.results[0].reply), r.results[0].reply); t('trust:continues', r.last.draft.specialtyId === 'ac', JSON.stringify(r.last.draft)) }
// 2. hesitation -> soft close, then conversion
{ const r = run(['ابغى غسيل ٣ مكيفات', 'بفكر وارجع لكم']); t('think:keeps-draft', r.last.draft.specialtyId === 'ac'); t('think:no-hard-sell', !/عاجل|فوراً الحين/.test(r.last.reply)); }
// 3. haggling never goes below floor
{ const r = run(['ابغى غسيل ٣ مكيفات', 'غالي', 'نزل شوي', 'نزل اكثر', 'نزل اكثر', 'آخر سعر', 'اقل']); const floor = r.results[0].quote?.floor; const offers = r.results.map((x) => x.draft.negotiation?.offer).filter(Boolean); t('haggle:floor', !floor || offers.every((o) => o >= floor), JSON.stringify({ floor, offers })) }
// 4. callback -> human hand-off with escalation flag
{ const r = run(['اتصلوا علي']); t('callback:handoff', r.last.action === 'handoff', r.last.action) }
// 5. business lead -> captured as project/business hand-off, no price list
{ const r = run(['ابغى عرض سعر لعماره ٢٠ شقه']); t('biz:handoff', r.last.action === 'handoff', r.last.action + ' ' + r.last.reply); t('biz:noprice', noPrice(r.last.reply), r.last.reply) }
{ const r = run(['عندكم باقات صيانة سنوية']); t('annual:handoff', r.last.action === 'handoff', r.last.action + ' ' + r.last.reply) }
// 6. part questions never answered with a wash price
{ for (const q of ['كم يكلف تغيير كمبروسر', 'بكم تركيب لمبات']) { const r = run([q]); t('part:' + q, noPrice(r.last.reply) && /القطع|نوع|الفني/.test(r.last.reply), r.last.reply) } }
// 7. technician nationality -> trust answer, no price
{ const r = run(['الفنيين عندكم سعوديين']); t('tech:nat', noPrice(r.last.reply) && /مدرّب|معتمد/.test(r.last.reply), r.last.reply) }
// 8. refund -> escalated
{ const r = run(['ابغى استرجاع المبلغ']); t('refund:escalate', r.last.action === 'handoff', r.last.action) }
// 9. out of scope + spam never become a generic service request
{ const r = run(['عندي طياره الجناح بتاعها في مشكله']); t('oos:plane', /خارج تخصصنا/.test(r.last.reply) && !r.last.draft.specialtyId, r.last.reply)
  const sp = run(['عندي طياره الجناح بتاعها في مشكله ومحتاج مهندس '.repeat(8)]); t('oos:spam', /مكررة|جملة وحدة|خارج تخصصنا/.test(sp.last.reply), sp.last.reply) }
// 10. conduct: insult, vulgar, scam, exaggeration, movies, joke, are-you-human
{ const a = run(['يا حمار انتم']); t('conduct:abuse1', /حقك علينا/.test(a.last.reply) && a.last.action !== 'handoff', a.last.reply)
  const b = run(['يا حمار انتم', 'انتم كلاب']); t('conduct:abuse2', b.last.action === 'handoff', b.last.reply)
  const v = run(['سكس']); t('conduct:vulgar', /بالأدب/.test(v.last.reply), v.last.reply)
  const f = run(['حول لي عربون للفني']); t('conduct:scam', /ما نقبل تحويل/.test(f.last.reply), f.last.reply)
  const e = run(['ابغى الف مكيف في ساعه']); t('conduct:exaggerate', /عمليين/.test(e.last.reply), e.last.reply)
  const m = run(['تشوف مسلسل نتفلكس']); t('conduct:movie', /تخصصي الصيانة/.test(m.last.reply), m.last.reply)
  const j = run(['قول نكته']); t('conduct:joke', /الفني/.test(j.last.reply), j.last.reply)
  const h = run(['انت بشر ولا روبوت']); t('conduct:human', /مساعد ذكي/.test(h.last.reply), h.last.reply)
  const ok = run(['ابغى غسيل مكيف']); t('conduct:normal-ok', ok.last.draft.specialtyId === 'ac', ok.last.reply) }
// 11. long repeated "I owe the tech, send account number" -> payment hand-off, never a random discount
{ const msg = ('دلوقتي انا اشتركت معاكم في خدمه والفني جالي بس انا معيش اللي انا ادفع له كاش فابعت لي رقم حساب احول عليه ').repeat(6)
  const r = run(['ابغى غسيل ٣ مكيفات', 'غالي', msg]); t('pay:after-service', r.last.action === 'handoff' && /رابط دفع|الدفع عندنا/.test(r.last.reply) && !/تخفيض/.test(r.last.reply), r.last.action + ' ' + r.last.reply) }
console.log(`lead: ${pass}/${pass + fail}`); if (fail) process.exit(1)
