// The whole conversation, end to end, with a pretend website and a pretend WhatsApp: what each person receives.
import { openStore } from '../src/store.js'
import { createBrain } from '../src/brain.js'
import * as T from '../src/text.js'
import * as M from '../src/menu.js'
import fs from 'node:fs'

let pass = 0; let fail = 0
const t = (name, ok, extra = '') => { if (ok) { pass++; console.log('PASS wa-hub: ' + name) } else { fail++; console.log('FAIL wa-hub: ' + name + (extra ? '\n   ' + String(extra).slice(0, 600) : '')) } }

const ROOM = '966536554731'; const CUST = '966500000001'; const TECH = '966549648890'; const NEW = '966500000009'; const OLDC = '966500000020'; const FRIEND = '966500000030'; const LATE = '966500000040'; const AIC = '966500000050'
const AI = { on: false }
const store = openStore(':memory:')
store.set('settings', { roomPhone: ROOM, agents: [{ name: 'سارة', phone: '0511111111' }], humanFirstSec: 0.3, hoursFrom: 0, hoursTo: 24 })
const out = []
const send = (phone, text, o = {}) => { out.push({ phone, text, purpose: o.purpose, dedup: o.dedup, image: o.image || '' }); return out.length }
const transcript = []
const toWho = (p) => (T.pkey(p) === T.pkey(ROOM) ? 'الغرفة' : T.pkey(p) === T.pkey(TECH) ? 'الفني' : T.pkey(p) === T.pkey(CUST) ? 'العميل' : T.pkey(p) === T.pkey(NEW) ? 'عميل جديد' : p)
const take = () => { const x = out.splice(0); for (const m of x) transcript.push('  → ' + toWho(m.phone) + ': ' + m.text.replace(/\n/g, '\n      ')); return x }
const to = (msgs, phone) => msgs.filter((m) => T.pkey(m.phone) === T.pkey(phone)).map((m) => m.text).join('\n---\n')

// ---- a pretend website ----
const orders = {}
const calls = []
const SL = { new: 'جديد', assigned: 'مُسند', accepted: 'قَبِل الفني', en_route: 'في الطريق', arrived: 'وصل', completed: 'تم الإنجاز', cancelled: 'ملغي' }
const view = (o) => ({ url: 'https://x.test/o/' + o.id, ...o, statusLabel: SL[o.status] || o.status, facts: { stepLabel: SL[o.status] }, statusText: '📋 طلب #' + o.number + ' — ' + (SL[o.status] || o.status), techPhone: o.techId ? TECH : '', jobCard: '*🔧 طلب جديد ليك*\n📋 رقم الطلب: *' + o.number + '*\nرد بكلمة: *قبلت*' })
let brain
const SITE = { on: false }
const PAY = { on: false }   // the payment question before the rating (owner, 2026-11-23)   // the site's shared brain (cancel / answer ops) — switched on for section X
const events = []
function emit(type, o, extra = {}) { events.push({ type, order: view(o), ...extra }) }
async function flush() { while (events.length) await brain.onEvent(events.shift()) }
const wp = async (op, b) => {
  calls.push(op + ' ' + JSON.stringify(b).slice(0, 120))
  const byNum = (n) => Object.values(orders).find((o) => String(o.number).includes(String(n)))
  if (op === 'order') { const o = b.id ? orders[b.id] : b.number ? byNum(b.number) : Object.values(orders).find((x) => T.pkey(x.clientPhone) === T.pkey(b.phone) && !['completed', 'cancelled'].includes(x.status)); return o ? view(o) : null }
  if (op === 'patch') {
    const o = orders[b.id]; const was = o.status; Object.assign(o, b.patch)
    if (b.patch.status && b.patch.status !== was) emit('order.status', o, { was, byCustomer: b.patch.status === 'cancelled' && !!o.cancelRequest })
    return view(o)
  }
  if (op === 'who') return T.pkey(b.phone) === T.pkey(TECH) ? { kind: 'tech', name: 'الفني علي', jobs: Object.values(orders).filter((o) => o.techId === 't1' && !['completed', 'cancelled'].includes(o.status)).map(view) } : { kind: 'customer' }
  if (op === 'reopen') { const o = orders[b.id]; Object.assign(o, b.patch, { status: 'new', techId: null, techName: null }); return view(o) }
  if (op === 'approve') { const o = orders[b.id]; Object.assign(o, { techId: 't1', techName: 'الفني علي', status: 'assigned' }); emit('order.assigned', o); return { text: '✅ اتسند للفني علي' } }
  if (op === 'eta_ask') return { result: 'asked' }
  if (op === 'pay_menu') return PAY.on ? { text: '💳 حابب تدفع إزاي؟\n1️⃣ كاش للفني\n2️⃣ تحويل بنكي\n3️⃣ بوابة الدفع الآمنة' } : null
  if (op === 'pay_pick') return b.method === 'transfer' ? { text: '🏦 اختار البنك اللي هتحوّل عليه:\n1️⃣ مصرف الراجحي', purpose: 'reply', banks: 1 } : { text: 'ok ' + b.method, purpose: b.method === 'cash' ? 'reply' : 'paylink' }
  if (op === 'pay_bank') return { messages: [{ text: '🏦 *مصرف الراجحي*\nاسم الحساب: أكسجين', image: '' }, { text: 'SA0000001111', image: '' }, { text: 'بعد التحويل ابعت هنا *صورة الإيصال*', image: '' }] }
  if (op === 'transcribe') return { text: b.url.includes('wash') ? 'السلام عليكم عايز أغسل مكيف سبليت' : '' }
  if (op === 'traits_ask') return b.stars >= 4 ? { text: '🌟 إيه أكتر حاجة عجبتك في الفني؟\n1️⃣ مظهره' } : null
  if (op === 'traits_set') { calls.push('traits:' + b.picks.join(',')); return { labels: b.picks.map((k) => ({ 1: 'مظهره وشكله', 4: 'أسلوبه وكلامه', 6: 'متقن' })[k] || 'x'), tech: 'الفني علي' } }
  if (op === 'pay_proof') { calls.push('pay_proof:' + b.id); return { ok: true } }
  if (op === 'tech_ask') return { reply: 'جرّب تغيّر الكاباستور (سؤال: ' + b.text + ')' }
  if (op === 'create') { const o = { id: 'o' + (Object.keys(orders).length + 1), number: 1000 + Object.keys(orders).length + 1, status: 'new', clientPhone: b.phone, clientName: b.name, service: b.service, address: b.address, preferredTime: b.time, site: 'رعد السماء', agreedPrice: b.price }; orders[o.id] = o; if (b.confirmed) o.customerConfirmed = true; o.acQuote = b.acQuote; o.spec = b.spec; o.mode = b.mode; o.acType = b.acType; o.desc = b.desc; emit('order.created', o); return { ...view(o), details: ['🛠 الخدمة: ' + b.service + ' [' + b.mode + '/' + (b.acType || '-') + ']', '📅 الموعد: ' + b.time] } }
  if (op === 'claim') { const o = b.seal ? Object.values(orders).find((x) => b.seal.startsWith(x.seal || '#')) : byNum(b.number); if (!o || o.claimCode !== b.code) return null; const first = !o.customerConfirmed; Object.assign(o, { clientPhone: b.phone, customerConfirmed: true }); return { first, card: first ? '🔔 *طلب جديد — رعد السماء*\nرقم الطلب: #' + o.number : '', ...view(o) } }
  if (op === 'report') return { text: '📋 تقرير' }
  if (op === 'info') return /سعر|اسعار/.test(b.text) ? { text: 'أسعار المالك' } : null
  if (op === 'quote') { const n = T.norm(b.text); if (!/مكيف|سبليت|تسريب|سباك/.test(n)) return null; const q = /مكيفين|2/.test(n) ? 2 : 1; return /غسيل|اغسل/.test(n) ? { spec: 'ac', fixed: true, label: 'غسيل ' + q + ' مكيف سبليت', qty: q, total: q === 2 ? 140 : 100, floor: q === 2 ? 125 : 89, acQuote: { mode: 'wash', type: 'سبليت', qty: q } } : { spec: 'plumbing', fixed: false, label: 'سباكة', from: 120, total: 120, floor: 100 } }
  if (op === 'greet') return T.pkey(b.phone) === T.pkey(OLDC) ? { site: 'رعد السماء', kind: 'returning', name: 'أبو فهد', lastService: 'غسيل مكيفين', lastDays: 40, fromUs: false } : { site: 'رعد السماء', kind: 'new', fromUs: /#raad|من موقع/.test(b.text) }
  if (op === 'ac_price') { const sp = { 1: 100, 2: 140, 3: 200, 4: 267, 6: 400 }; const wn = { 1: 60, 2: 100, 3: 150 }; let total = 0; const lines = []; const items = []; if (b.split) { total += sp[b.split] || b.split * 66; lines.push(b.split + ' سبليت = ' + (sp[b.split] || b.split * 66)); items.push({ type: 'سبليت', qty: b.split }) } if (b.window) { total += wn[b.window] || b.window * 50; lines.push(b.window + ' شباك = ' + (wn[b.window] || b.window * 50)); items.push({ type: 'شباك', qty: b.window }) } if (b.other) { total += 180 * b.other; items.push({ type: 'دولابي', qty: b.other }) } return { total, floor: Math.round(total * 0.9), units: (b.split || 0) + (b.window || 0) + (b.other || 0), lines, label: 'غسيل ' + items.map((i) => i.qty + ' ' + i.type).join(' + '), acQuote: { mode: 'wash', items } } }
  if (op === 'ac_table') return { text: 'جدول المالك' }
  if (op === 'parts') return /مكثف/.test(b.text) ? { text: '🔧 *تغيير المكثف*: *150 ر.س*', image: 'https://oxygen11.com/cap.jpg', parts: ['capacitor'], strategy: 'إحنا مش محل قطع غيار 🙏 بنبيعلك حل العطل' } : { text: '🔧 *أسعار الإصلاح*', parts: [], strategy: 'إحنا مش محل قطع غيار' }
  if (op === 'cancel' && SITE.on) { const o = orders[b.id]; if (['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(o.status)) { o.cancelRequest = { text: b.text }; return { done: 'requested', reply: 'وصلني طلب الإلغاء ✅ الفني كان متحرك لك، فبلّغته يستنى' } } o.status = 'cancelled'; return { done: 'cancelled', reply: 'تم إلغاء طلبك #' + o.number + ' ✅' } }
  if (op === 'after_rating') return { text: b.stars >= 5 ? '⭐ قيّمنا على خرائط جوجل: https://g.page/r/test' : '' }
  if (op === 'referral') { calls.push('referral:' + b.text); return { code: 'R123ABC' } }
  if (op === 'paylink' && SITE.on) { const o = Object.values(orders).find((x) => T.pkey(x.clientPhone) === T.pkey(b.phone) && ['completed', 'closed'].includes(x.status) && !x.paid); return o ? { text: '💳 *طرق الدفع — طلب #' + o.number + '* https://x.test/app/#/pay?o=' + o.id + '&k=abc' } : null }
  if (op === 'answer' && SITE.on) { const m = T.norm(b.text).match(/طلب\s*#?(\d{3,})/); if (m) { const o = Object.values(orders).find((x) => String(x.number) === m[1]); return { reply: o && T.pkey(o.clientPhone) === T.pkey(b.phone) ? 'طلبك #' + o.number + ' — ' + (SL[o.status] || o.status) : 'مش لاقي طلب رقم ' + m[1] + ' على رقمك' } } if (/طرق الدفع/.test(b.text)) return { reply: '💳 *طرق الدفع* (من الموقع)' }; return { reply: null } }
  if (op === 'converse') return AI.on ? { reply: 'أكيد، غسيل المكيف السبليت بـ 120 ر.س للجهاز. في أي حي؟', lead: { service: 'غسيل 2 سبليت', area: AI.area || '', time: AI.time || '' }, complete: !!(AI.area && AI.time) } : null
  return { ok: true }
}
// Held turns run when the test says so, not on a clock: no timing, no flakiness.
const timers = new Map(); let tid = 0
const later = (fn) => { const id = ++tid; timers.set(id, fn); return id }
const cancel = (id) => timers.delete(id)
const sleep = async () => { const fns = [...timers.values()]; timers.clear(); for (const f of fns) { await f(); } await flush(); await new Promise((r) => setImmediate(r)); await flush() }
brain = createBrain({ store, wp, send, later, cancel })
const say = async (phone, text, label) => { transcript.push((label || toWho(phone)) + ': ' + text); await brain.inbound({ line: 'l1', phone, text }); await flush(); return take() }

// ===== A. the order lands: confirm on WhatsApp =====
transcript.push('\n### أ) طلب من الموقع: تأكيد على واتساب')
orders.o1 = { id: 'o1', number: 1002, status: 'new', clientPhone: '0500000001', clientName: 'علي تجربة', service: 'غسيل مكيف سبليت', preferredTime: '', address: '', lat: null, site: 'رعد السماء' }
emit('order.created', orders.o1); await flush()
let m = take()
t('room hears the new order', /طلب جديد #1002/.test(to(m, ROOM)), to(m, ROOM))
t('customer gets the confirmation with what is missing', /وصلنا طلبك رقم \*1002\*/.test(to(m, CUST)) && /الموعد المناسب/.test(to(m, CUST)) && /رعد السماء/.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'بكرة العصر')
t('time is recorded', orders.o1.preferredTime === 'بكرة العصر' && /سجلت الموعد/.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'العزيزية شارع الحج')
t('a written address is kept, but the pin itself is still asked for', orders.o1.address && /اللوكيشن نفسه/.test(to(m, CUST)) && !/اكتب \*تأكيد\*/.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'تأكيد')
t('…«تأكيد» without the pin does not confirm', !orders.o1.customerConfirmed && /اللوكيشن نفسه/.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'https://maps.google.com/?q=21.4225,39.8262')
t('…the pin arrives and the summary asks for تأكيد', orders.o1.lat === 21.4225 && /اكتب \*تأكيد\*/.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'تأكيد')
t('confirmed: customer told, room told', orders.o1.customerConfirmed && /تم تأكيد طلبك/.test(to(m, CUST)) && /العميل أكد طلب #1002/.test(to(m, ROOM)), JSON.stringify(m))

// ===== B. dispatch: the technician gets the card =====
transcript.push('\n### ب) التوزيع الآلي والفني')
m = await say(ROOM, 'اعتمد 1002', 'الغرفة')
t('room approve → technician card + room note', /طلب جديد ليك/.test(to(m, TECH)) && /اتسند/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(TECH, 'قبلت', 'الفني')
t('technician «قبلت» moves the order and the customer hears', orders.o1.status === 'accepted' && /قبل طلبك/.test(to(m, CUST)) && /تم استلام الطلب/.test(to(m, TECH)), JSON.stringify(m))
m = await say(TECH, 'في الطريق', 'الفني')
t('on the way: customer + room', orders.o1.status === 'en_route' && /في الطريق ليك/.test(to(m, CUST)) && /اتحرك/.test(to(m, ROOM)), JSON.stringify(m))
t('on the way: the customer gets the live tracking link (idea 13)', /تابع مكان الفني لحظة بلحظة: https:\/\/x\.test\/o\//.test(to(m, CUST)), to(m, CUST))
m = await say(CUST, 'الفني فين؟')
t('«الفني فين؟» asks the technician and answers from facts', calls.some((c) => c.startsWith('eta_ask')) && /سألت الفني/.test(to(m, CUST)), to(m, CUST))

// ===== C. cancel while he is on the way, then win back =====
transcript.push('\n### ج) العميل بيلغي والفني في الطريق')
m = await say(CUST, 'معلش عايز الغي الطلب')
t('cancel request: technician holds, room asked to confirm, customer told', orders.o1.cancelRequest && calls.some((c) => c.startsWith('tell_tech') && c.includes('استنى')) && /العميل عايز يلغي طلب #1002/.test(to(m, ROOM)) && /وصلني طلب الإلغاء/.test(to(m, CUST)), JSON.stringify(m))
m = await say(ROOM, 'الغي 1002', 'الغرفة')
t('room cancels: technician told to go back, customer asked why', orders.o1.status === 'cancelled' && /ارجع/.test(to(m, TECH)) && /يهمنا نعرف السبب/.test(to(m, CUST)), JSON.stringify(m))
m = await say(CUST, '1')
t('price reason → discount offer, room told', /خصم 10%/.test(to(m, CUST)) && /سبب إلغاء طلب #1002/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(CUST, 'رجعه')
t('«رجعه» reopens with the discount', orders.o1.status === 'new' && orders.o1.winbackDiscountPct === 10 && /رجّعنا طلبك/.test(to(m, CUST)) && /رجّع طلب #1002/.test(to(m, ROOM)), JSON.stringify(m))

// ===== D. a problem on site, then done and rated =====
transcript.push('\n### د) مشكلة عند العميل، وبعدين التنفيذ والتقييم')
m = await say(ROOM, 'اعتمد 1002', 'الغرفة'); await say(TECH, 'قبلت', 'الفني')
// a new service while his order is open (live test 2026-11-24): a new booking, not a message to the technician
m = await say(CUST, 'عايز أغسل مكيف شباك واحد'); await sleep(); m = m.concat(take())
t('a new service while an order is open → a new booking (price), not relayed to the technician', /طلب جديد/.test(to(m, CUST)) && /ر\.س/.test(to(m, CUST)) && !to(m, TECH), JSON.stringify(m))
await say(CUST, '0')
// «فاضل لك قد إيه؟» answered by a number (owner, 2026-11-23)
m = await say(TECH, '3', 'الفني')
t('technician «3» → the customer hears he is finishing another job', /لسه بيخلّص شغل قبلك/.test(to(m, CUST)) && /بلّغت العميل/.test(to(m, TECH)), JSON.stringify(m))
m = await say(TECH, '2 15', 'الفني')
t('technician «2 15» → the customer hears 15 minutes', /15 دقيقة/.test(to(m, CUST)) && /هتوصل خلال 15/.test(to(m, TECH)), JSON.stringify(m))
await say(TECH, 'وصلت', 'الفني')
m = await say(TECH, 'العميل مش موجود والباب مقفول', 'الفني')
t('technician problem → room alerted at once', /الفني علي — طلب #1002/.test(to(m, ROOM)) && /بلّغت غرفة العمليات/.test(to(m, TECH)), JSON.stringify(m))
PAY.on = true
m = await say(TECH, 'تم', 'الفني'); await sleep(); m = m.concat(take())
t('bare «تم» completes; customer asked HOW HE PAYS first (not the rating yet); room told', orders.o1.status === 'completed' && /حابب تدفع إزاي/.test(to(m, CUST)) && !/قيّم الخدمة/.test(to(m, CUST)) && /تمت المهمة/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(CUST, '2')
t('…«2»: which bank (the list), the room hears his choice', /اختار البنك/.test(to(m, CUST)) && /1️⃣ مصرف الراجحي/.test(to(m, CUST)) && !/رأيك في الخدمة/.test(to(m, CUST)) && /اختار الدفع: \*تحويل بنكي\*/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(CUST, '1')
t('…«1»: that bank — the IBAN alone to copy, «send the receipt», then the rating as choices', /اسم الحساب/.test(to(m, CUST)) && m.some((x) => x.text.trim() === 'SA0000001111' || / SA0000001111$/.test(x.text)) && /صورة الإيصال/.test(to(m, CUST)) && /5️⃣ راضي جدًا/.test(to(m, CUST)) && !/بتتمسح/.test(to(m, CUST)), JSON.stringify(m))
m = await brain.inbound({ line: 'l1', phone: CUST, text: '', kind: 'image', name: 'العميل' }).then(() => take())
t('…his receipt picture: on the order, thanked', calls.some((c) => c.startsWith('pay_proof:')) && /وصلنا الإيصال/.test(to(m, CUST)), JSON.stringify(m))
PAY.on = false
m = await say(CUST, '5')
t('5 → the technician hears his rating; a pleased customer gets the detailed rating in one message', /قيّمك \*5\/5\*/.test(to(m, TECH)) && /أكتر حاجة عجبتك/.test(to(m, CUST)), JSON.stringify(m))
m = await say(CUST, '1 4 6')
t('…«1 4 6»: saved, the technician and operations hear what he liked', calls.includes('traits:1,4,6') && /شكر فيك/.test(to(m, TECH)) && /تقييم مفصّل/.test(to(m, ROOM)) && /شكرًا ليك من القلب/.test(to(m, CUST)), JSON.stringify(m))
orders.o1.rating = null; orders.o1.customerRating = null; store.touchChat(T.pkey(CUST), { stage: 'rate', orderId: 'o1', stageAt: Date.now() })
m = await say(CUST, '5 ممتاز تسلموا')
t('rating saved and sent to the room', orders.o1.customerRating === 5 && /تقييم العميل لطلب #1002: 5\/5/.test(to(m, ROOM)), JSON.stringify(m))
t('5 stars → the Google review link follows the thanks (idea 16, only when switched on)', /قيّمنا على خرائط جوجل: https:\/\/g\.page/.test(to(m, CUST)), to(m, CUST))
m = await say('966500009999', 'السلام عليكم، جاي بترشيح من صاحبي — كود الترشيح R1A2B3C')
t('a referral code in a message is passed to the site (idea 20)', calls.some((c) => c.startsWith('referral:')), calls.slice(-3))

// ===== E. a stranger writes: welcome, a person, an order from the chat =====
transcript.push('\n### هـ) عميل جديد بيكلم الخط: ترحيب، موظف، وطلب من الشات')
m = await say(NEW, 'السلام عليكم')
t('a bare greeting: a short welcome in the name of his site and ONE open question — no list, no AC details (owner, 2026-11-23)', /\*رعد السماء\*/.test(to(m, NEW)) && /(أقدر أساعدك|محتاج إيه|أخدمك)/.test(to(m, NEW)) && !/1️⃣|غسيل كامل|المضخة|سبليت/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '1')
t('«1» (AC) → the AC services only: wash, repair, install…', /1️⃣ غسيل مكيفات/.test(to(m, NEW)) && /3️⃣ تركيب/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '4')
t('«4» → the owner\'s price table', /جدول المالك/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '0')
t('«0» → the main menu (the sections) again', /1️⃣ المكيفات/.test(to(m, NEW)) && /3️⃣ السباكة/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '9')
t('«7» → agent told on WhatsApp, customer acknowledged', /عميل محتاج موظف/.test(to(m, '0511111111')) && /بلّغت موظف/.test(to(m, NEW)), JSON.stringify(m))
m = await say(NEW, 'عايز غسيل 2 مكيف سبليت')
t('«غسيل 2 مكيف سبليت» → priced by the owner\'s table at once, with the split wash video', /\*140 ر\.س\*/.test(to(m, NEW)) && /youtu\.be\/wvtQ_aztCrE/.test(to(m, NEW)) && /1️⃣ تمام، كمّل الحجز/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '1')
t('«1» → asks for the pin itself', /اللوكيشن نفسه/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'حي العوالي')
t('a neighbourhood name alone is kept as a note, and the pin is asked again', /سجلت «حي العوالي»/.test(to(m, NEW)) && /اللوكيشن نفسه/.test(to(m, NEW)) && !/تحب الموعد/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'https://maps.google.com/?q=21.4301,39.8502')
t('pin → the time choices', /تحب الموعد إمتى|بعد 1[01] بالليل/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'بكرة الساعة 5')
t('time → the whole order to confirm', /ملخص طلبك/.test(to(m, NEW)) && /140 ر\.س/.test(to(m, NEW)) && /1️⃣ تأكيد الطلب/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '1')
const created = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(NEW))
t('«1» → order created, confirmed, the room told', created && created.address === 'حي العوالي' && created.agreedPrice === 140 && created.customerConfirmed && /تم تأكيد طلبك رقم/.test(to(m, NEW)) && /طلب جديد #/.test(to(m, ROOM)), JSON.stringify(m))

// ===== F. room commands and the help card =====
m = await say(ROOM, 'تقرير', 'الغرفة')
t('room «تقرير»', /تقرير/.test(to(m, ROOM)))
m = await say(ROOM, 'رد 1002 الفني هيكلمك بعد شوية', 'الغرفة')
t('room «رد 1002 …» reaches the customer', /الفني هيكلمك/.test(to(m, CUST)) && /وصلت للعميل/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(ROOM, 'عميل 0500000009 أهلا بيك', 'الغرفة')
t('room «عميل رقم نص» reaches any customer', /أهلا بيك/.test(to(m, NEW)), JSON.stringify(m))


// ===== G. the etiquette: old customers, people the owner knows, a person answering first =====
transcript.push('\n### و) عميل قديم، ومعارف، وموظف بيرد قبل البوت')
m = await say(OLDC, 'السلام عليكم')
t('an old customer: no welcome at once — operations are told he is back and get their minutes', !to(m, OLDC) && /عميل قديم رجع/.test(to(m, '0511111111') + to(m, ROOM)), JSON.stringify(m))
await sleep(); m = take()
t('…nobody answered: welcomed back by name with his last job, same or new service', /أهلًا بعودتك يا أبو فهد/.test(to(m, OLDC)) && /غسيل مكيفين/.test(to(m, OLDC)) && /1️⃣ نفس الخدمة تاني/.test(to(m, OLDC)) && /2️⃣ خدمة جديدة/.test(to(m, OLDC)), to(m, OLDC))
m = await say(OLDC, '1')
t('…«1» → the same service again, straight into it', /غسيل مكيفات|نوع المكيفات|ر\.س/.test(to(m, OLDC)), to(m, OLDC))
brain.contact(FRIEND, 'خالي محمد')
m = await say(FRIEND, 'إزيك يا علي عامل ايه')
t('a name saved in the phone, not from our sites: left alone', !to(m, FRIEND) && store.chat(T.pkey(FRIEND)).kind === 'personal', JSON.stringify(m))
// live test 2026-11-23: someone in the phone's contacts whom the bot already quoted, writing a bare «1» later, is not «personal»
const KNOWN = '0533333377'
brain.contact(KNOWN, 'عميل معروف')
await say(KNOWN, 'عايز اغسل مكيف سبليت'); await sleep(); take()
store.touchChat(T.pkey(KNOWN), { lead: '{}' })
m = await say(KNOWN, '1')
t('a contact the bot already served: a bare «1» does not make him personal', store.chat(T.pkey(KNOWN)).kind !== 'personal', JSON.stringify(store.chat(T.pkey(KNOWN))))
// a voice note (owner, 2026-11-23): heard and answered like the words in it
const VO = '0533333355'
m = await brain.inbound({ line: 'l1', phone: VO, text: '', kind: 'voice', name: 'صوت', media: 'https://x.test/wp-content/uploads/mwc/wash.ogg', mime: 'audio/ogg' }).then(() => take()); await sleep(); m = m.concat(take())
t('a voice note «عايز أغسل مكيف سبليت» → answered with the price', /ر\.س/.test(to(m, VO)), JSON.stringify(m))
// the price (owner, 2026-11-23): no «عايز سعر أحسن» — an open question; bargaining only when he complains; at the wall a specialist
const HG = '0533333366'
m = await say(HG, 'عايز اغسل 3 مكيف سبليت'); await sleep(); m = m.concat(take())
t('price: no «عايز سعر أحسن», asks if it suits him, 2 = change, 3 = a specialist', !/سعر أحسن\n|عايز سعر أحسن/.test(to(m, HG)) && /مناسب لحضرتك/.test(to(m, HG)) && /2️⃣ غيّر العدد/.test(to(m, HG)) && /3️⃣ كلّم فني مختص/.test(to(m, HG)), to(m, HG))
m = await say(HG, 'غالي'); t('…«غالي» starts the bargaining', /بدل/.test(to(m, HG)), to(m, HG))
m = await say(HG, 'برضه غالي'); m = await say(HG, 'لسه غالي')
t('…at the wall: offered a specialist with today\'s offers', /فني مختص/.test(to(m, HG)) && /العروض اليومية/.test(to(m, HG)), to(m, HG))
m = await say(HG, '3')
t('…«3» hands him to a person with the deal', /حوّلتك لفني مختص/.test(to(m, HG)) && /أقل سعر|آخر عرض/.test(m.map((x) => x.text).join(' ')), JSON.stringify(m))
m = await say(NEW.replace(/9$/, '8'), 'عايز اغسل مكيف سبليت')
t('a full request: welcome and the price at once, no waiting', /مرحبًا بكم/.test(m.map((x) => x.text).join()) && /\*100 ر\.س\*/.test(m.map((x) => x.text).join()), JSON.stringify(m))
await brain.inbound({ line: 'l1', phone: NEW.replace(/9$/, '8'), text: 'أهلا بيك يا فندم، في أي حي؟', fromMe: true })
m = await say(NEW.replace(/9$/, '8'), 'العزيزية'); await sleep(450); m = m.concat(take())
t('…a person took over: the bot keeps out', !to(m, NEW.replace(/9$/, '8')), JSON.stringify(m))
// the technician's two doors (owner, 2026-11-23): «#» → the assistant, «*» → operations
m = await say(TECH, '# الكمبروسر بيفصل بعد دقيقة', 'الفني')
t('technician «# سؤال» → the assistant answers him', /🤖/.test(to(m, TECH)) && /الكمبروسر بيفصل/.test(to(m, TECH)), JSON.stringify(m))
m = await say(TECH, '* العميل مش موجود والباب مقفول', 'الفني')
t('technician «* كلام» → operations hear it, he is told', /وصلت للعمليات/.test(to(m, TECH)) && /الباب مقفول/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(TECH, 'صباح الخير يا جماعة', 'الفني')
t('a colleague saying hello gets no bot reply', !to(m, TECH), JSON.stringify(m))

// ===== H. after hours: morning booking, emergencies to the phone =====
transcript.push('\n### ز) برا مواعيد العمل')
store.set('settings', { ...store.get('settings'), hoursFrom: 0, hoursTo: 0, emergencyPhone: '0536554731' })
m = await say(LATE, 'عندي تسريب مياه من المكيف وماس كهربا')
await sleep(450); m = m.concat(take())
t('after hours an emergency is told to call the mobile, and the room is alerted', /اتصل بينا حالًا على \*0536554731\*/.test(to(m, LATE)) && /طارئ برا مواعيد العمل/.test(to(m, ROOM)), JSON.stringify(m))
const L1 = LATE.replace(/0$/, '1')
m = await say(L1, 'محتاج غسيل مكيف')
t('«محتاج غسيل مكيف» → the AC type to choose', /نوع المكيفات إيه/.test(to(m, L1)) && /3️⃣ سبليت \+ شباك/.test(to(m, L1)), JSON.stringify(m))
m = await say(L1, '3'); m = await say(L1, '2'); m = await say(L1, '1')
t('split + window: counted per type and priced together (2 split + 1 window = 200)', /\*200 ر\.س\*/.test(to(m, L1)) && /2 سبليت = 140/.test(to(m, L1)) && /1 شباك = 60/.test(to(m, L1)), JSON.stringify(m))
store.set('settings', { ...store.get('settings'), hoursFrom: 0, hoursTo: 24 })

// ===== I. the site's AI talks, prices, and books =====
AI.on = true
m = await say(AIC, 'السلام عليكم'); m = await say(AIC, 'عندكم ضمان على الشغل يعني ولا ايه الكلام')
t('a free question goes to the site\'s AI', /أكيد/.test(to(m, AIC)), JSON.stringify(m))
AI.area = 'الزاهر'; AI.time = 'بكرة العصر'
m = await say(AIC, 'في الزاهر بكرة العصر')
t('…when the model thinks it has everything, the menu prices it from the table (the model never books)', !Object.values(orders).some((o) => T.pkey(o.clientPhone) === T.pkey(AIC)) && /\*140 ر\.س\*/.test(to(m, AIC)), JSON.stringify(m))
AI.on = false


// ===== J. prices and bargaining, from the owner's lists, down to his floor and no further =====
transcript.push('\n### ح) الأسعار والفصال')
const HAG = '966500000060'
m = await say(HAG, 'السلام عليكم')
m = await say(HAG, 'غسيل مكيف اسبليت و مكيف شباك بكام')
t('«مكيف اسبليت و مكيف شباك» = one of each, 160 (not 100)', /\*160 ر\.س\*/.test(to(m, HAG)) && /1 سبليت = 100/.test(to(m, HAG)) && /1 شباك = 60/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'غالى ياخى ١٦٠ راعيتا')
t('first «غالي»: one step down, not to the bottom', /\*150 ر\.س\* بدل 160/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'يللة خليها ١٠٠ ريال المكيفين توكل على الله')
t('«خليها 100 المكيفين» is refused — the floor is the last word', /مقدرش أنزل لـ 100/.test(to(m, HAG)) && /\*144 ر\.س\*/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'انا قلت لك ١٠٠ قبل كده واتفقنا')
t('«I told you 100 before» is not believed', !/اتفقنا ✅ \*100|اتفقنا على \*100/.test(to(m, HAG)) && /144/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'لا ابغى غسيل ٢ مكيف و احد شباك و واحد اسبليت')
t('changing the count re-prices it from the table (the old offer gone)', /عدّلت العدد/.test(to(m, HAG)) && /\*160 ر\.س\*/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, '3 سبليت')
t('«3 سبليت» → 200, not the same 100', /\*200 ر\.س\*/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'انتا غبى')
t('cursing: an apology, a person takes over, the bot stops, the room hears', /آسفين جدًا/.test(to(m, HAG)) && /عميل متضايق/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(HAG, 'طيب')
t('…and the bot stays out for half an hour', !to(m, HAG) || /موظف/.test(to(m, HAG)), JSON.stringify(m))
const HAG2 = '966500000061'
await say(HAG2, 'السلام عليكم'); await say(HAG2, 'كم سعر غسيل مكيف سبليت')
m = await say(HAG2, 'ب 95 ينفع؟')
t('a price he names before we moved is countered, not taken', /بدل 100/.test(to(m, HAG2)) && !/اتفقنا/.test(to(m, HAG2)), to(m, HAG2))
m = await say(HAG2, '90 وخلاص')
t('…after we moved, a price at or above the floor is taken', /اتفقنا على \*90 ر\.س\*/.test(to(m, HAG2)), to(m, HAG2))
m = await say(HAG2, 'السبت العصر')
m = await say(HAG2, 'حي الشوقية'); m = await say(HAG2, 'https://maps.google.com/?q=21.4012,39.8033')
m = take().concat(m)
m = await say(HAG2, 'بكره العصر'); m = await say(HAG2, '1')
const h2 = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(HAG2))
t('…the order carries the agreed price and the AC quote', h2 && h2.agreedPrice === 90 && h2.acQuote, JSON.stringify(h2))

// parts: only that repair's price, its photo, and «we sell the repair, not the part»
const PRT = '966500000062'
await say(PRT, 'السلام عليكم')
m = await say(PRT, 'بكام المكثف')
t('«بكام المكثف» → that repair only, with its photo', /تغيير المكثف/.test(to(m, PRT)) && m.some((x) => x.image === 'https://oxygen11.com/cap.jpg') && !/أسعار الإصلاح/.test(to(m, PRT)), JSON.stringify(m))
m = await say(PRT, 'غالي، في المحل ب 40')
t('haggling over a part → «we are a maintenance company, not a parts shop»', /مش محل قطع غيار/.test(to(m, PRT)), to(m, PRT))
m = await say(PRT, '0')
t('«0» → the menu', /1️⃣ المكيفات/.test(to(m, PRT)), to(m, PRT))
await say(PRT, '1')
m = await say(PRT, '5')
t('«5» → the wash videos', /youtu\.be\/qz8gaYjDHmI/.test(to(m, PRT)) && /tiktok/.test(to(m, PRT)), to(m, PRT))

// the same reply a third time → a person
const REP = '966500000063'
await say(REP, 'السلام عليكم'); await say(REP, '1'); await say(REP, '1')
await say(REP, 'مش عارف'); await say(REP, 'مش عارف'); m = await say(REP, 'مش عارف')
t('the same reply a third time → a person is called instead', /مش فاهمك كويس/.test(to(m, REP)) && /البوت كرر/.test(to(m, '0511111111') + to(m, ROOM)), JSON.stringify(m))

// ===== H. sections, old customers by history, our own numbers, campaigns (owner, 2026-11-21) =====
transcript.push('\n### ز) الأقسام، العميل القديم من المحادثة، أرقامنا، الحملات')
const PL = '966500000070'
await say(PL, 'مرحبا')
m = await say(PL, '3')
t('sections: «3» → plumbing\'s own services, nothing about ACs', /السباكة/.test(to(m, PL)) && /تسريب مياه/.test(to(m, PL)) && /تسليك مجاري/.test(to(m, PL)) && !/مكيف/.test(to(m, PL)), to(m, PL))
m = await say(PL, '1')
t('…a service → «write the details» with a plumbing example', /اكتب تفاصيل المطلوب/.test(to(m, PL)) && /المغسلة|مسدود/.test(to(m, PL)), to(m, PL))
m = await say(PL, 'تسريب تحت المغسلة من امبارح')
t('…details → inspection wording without «free for two units», then the pin', /هيعاين/.test(to(m, PL)) && !/لحد جهازين/.test(to(m, PL)) && /اللوكيشن نفسه/.test(to(m, PL)), to(m, PL))
await say(PL, 'https://maps.google.com/?q=21.4012,39.8523'); await say(PL, '1')
m = await say(PL, '1')
const plo = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(PL))
t('…booked as a plumbing order', plo && /السباكة — تسريب مياه/.test(plo.service) && plo.spec === 'plumbing', JSON.stringify(plo))

const EL = '966500000071'
m = await say(EL, 'عندي فيش محروق في المطبخ ومحتاج كهربائي')
t('a request in words for electricity → the electricity section, never the AC wash', /الكهرباء/.test(to(m, EL)) && !/غسيل مكيفات|نوع المكيفات/.test(to(m, EL)), to(m, EL))
const CAM = '966500000072'
m = await say(CAM, 'ابغى اركب كاميرات للبيت')
t('«ابغى اركب كاميرات» → cameras and satellite, not AC install', /تركيب كاميرات مراقبة/.test(to(m, CAM)) && !/نوع المكيف/.test(to(m, CAM)), to(m, CAM))

// an old chat (never ordered) comes back after days: operations first, then the last topic
const HIS = '966500000073'
await say(HIS, 'غسيل مكيف سبليت كم'); take()
store.touchChat(T.pkey(HIS), { welcomedAt: Date.now() - 3 * 86400000, lead: '' })
store.db.prepare('UPDATE msgs SET at = ? WHERE pk = ?').run(Date.now() - 3 * 86400000, T.pkey(HIS))
m = await say(HIS, 'السلام عليكم')
t('a returning chat: quiet at first, operations told', !to(m, HIS) && /عميل قديم رجع/.test(to(m, '0511111111') + to(m, ROOM)), JSON.stringify(m))
await brain.inbound({ line: 'l1', phone: HIS, text: 'أهلين، نورتنا تاني — تحب نحجزلك؟', fromMe: true })
await sleep(); m = take()
t('…operations answered in time → the bot stays out', !to(m, HIS), to(m, HIS))
const HIS2 = '966500000074'
await say(HIS2, 'عايز اغسل مكيف شباك'); take()
store.touchChat(T.pkey(HIS2), { welcomedAt: Date.now() - 2 * 86400000, lead: '' })
store.db.prepare('UPDATE msgs SET at = ? WHERE pk = ?').run(Date.now() - 2 * 86400000, T.pkey(HIS2))
await say(HIS2, 'مساء الخير'); await sleep(); m = take()
t('…nobody answered → welcome back from the last contact', /أهلًا بعودتك/.test(to(m, HIS2)) && /آخر مرة كنا بنتكلم عن/.test(to(m, HIS2)) && /شباك/.test(to(m, HIS2)), to(m, HIS2))

// our own numbers
const OUT1 = '966546619205'; const MGR = '966549247636'
store.set('wpConfig', { ourNumbers: { [T.pkey(OUT1)]: { role: 'outreach', label: 'استقطاب ١', active: true }, [T.pkey(MGR)]: { role: 'manager', label: 'المدير', active: false } } })
m = await say(OUT1, 'السلام عليكم')
t('a message from one of our outreach numbers → no welcome, no reply (lines never answer each other)', !to(m, OUT1) && store.chat(T.pkey(OUT1)).kind === 'team', JSON.stringify(m))
m = await say(MGR, 'تقرير')
t('the manager number before it is active is ours but not the room', !/تقرير/.test(to(m, MGR)), JSON.stringify(m))
store.set('wpConfig', { managerPhone: MGR, ourNumbers: { [T.pkey(MGR)]: { role: 'manager', label: 'المدير', active: true } } })
m = await say(MGR, 'تقرير')
t('…once active it IS the room: its commands run, reports go to it', /تقرير/.test(to(m, MGR)) && brain.cfg().roomPhone === MGR, JSON.stringify(m))
store.set('wpConfig', {})

// campaigns: «إيقاف», and a reply to a campaign message
const PROS = '966500000075'
store.addMsg({ pk: T.pkey(PROS), line: 'l1', dir: 'out', text: 'عرض الصيانة', by: 'outreach' })
m = await say(PROS, 'إيقاف')
t('«إيقاف» → out of the campaigns for good, told once', calls.some((c) => c.startsWith('outreach_optout')) && /مش هنبعتلك رسايل تاني/.test(to(m, PROS)), JSON.stringify(m))
const PROS2 = '966500000076'
store.addMsg({ pk: T.pkey(PROS2), line: 'l1', dir: 'out', text: 'عرض الصيانة', by: 'outreach' })
await say(PROS2, 'حجز')
t('a reply to a campaign → marked as replied (once) and answered like any customer', calls.filter((c) => c.startsWith('outreach_reply')).length === 1, calls.filter((c) => c.startsWith('outreach')).join(' | '))
t('isStop never takes «وقف الطلب» for an unsubscribe', !M.isStop('وقف الطلب') && M.isStop('ايقاف') && M.isStop('Stop'))
t('isGreeting: «السلام عليكم» yes, «السلام عليكم عندي مكيف خربان» no', M.isGreeting('السلام عليكم') && !M.isGreeting('السلام عليكم عندي مكيف خربان'))

// stopped services (owner, 2026-11-21): hidden from the sections, and asking for one gets a person
store.set('wpConfig', { offSections: [5, 6] })
const OFF = '966500000077'
m = await say(OFF, 'السلام عليكم'); m = await say(OFF, '0')
t('a stopped service disappears from the sections (numbers stay: 7 is still «other»)', /4️⃣ الكهرباء/.test(to(m, OFF)) && !/5️⃣ النجارة/.test(to(m, OFF)) && !/6️⃣ الكاميرات/.test(to(m, OFF)) && /7️⃣ خدمات تانية/.test(to(m, OFF)), to(m, OFF))
m = await say(OFF, '5')
t('…picking it anyway → «stopped for now», and a person is called', /متوقفة عندنا مؤقتًا/.test(to(m, OFF)) && /عميل محتاج موظف/.test(to(m, '0511111111') + to(m, ROOM)), JSON.stringify(m))
const OFF2 = '966500000078'
m = await say(OFF2, 'ابغى اركب كاميرات')
t('…asking for it in words → the same', /متوقفة عندنا مؤقتًا/.test(to(m, OFF2)) && !/الكاميرات والدش\* —/.test(to(m, OFF2)), to(m, OFF2))
store.set('wpConfig', {})

// ===== text helpers =====
t('stepOf: «وصلت 1002»', T.stepOf('وصلت 1002') === 'arrived')
t('stepOf: «تم استلام…» is not done', T.stepOf('تم استلام الطلب') !== 'completed')
t('intl: 05… → 9665…', T.intl('0536554731') === '966536554731')
t('moneyIn: «2 مكيف» is not a price', T.moneyIn('عندي 20 مكيف') === null && T.moneyIn('خليها 150') === 150)
t('roomCommand: وجّه 1002 لمحمود', T.roomCommand('وجّه 1002 لمحمود')?.name === 'محمود')

if (process.env.SCEN_OUT) fs.writeFileSync(process.env.SCEN_OUT, transcript.join('\n'))
// ===== Y. an order from the site's button: the site sends the full card itself; the hub does not ask «تأكيد» =====
{
  const SC = '966500000088'
  orders.os = { id: 'os', number: 1888, status: 'new', clientPhone: SC, clientName: 'علي', service: 'غسيل مكيف', site: 'متقن', source: 'site', lat: 21.4, lng: 39.8, preferredTime: 'أقرب وقت' }
  emit('order.created', orders.os); await flush(); const m = take()
  t('site order: room hears it, the customer gets no «اكتب تأكيد» from the hub', /طلب جديد #1888/.test(to(m, ROOM)) && !to(m, SC), JSON.stringify(m))
  const r = await say(SC, 'الفني هيوصل امتى؟')
  t('…and his reply is about that order', !/مرحبًا بكم/.test(to(r, SC)), JSON.stringify(r))
}

// ===== X. the room gets the site's full card for a new order =====
{
  orders.ox = { id: 'ox', number: 1999, status: 'new', clientPhone: '', service: 'غسيل مكيف', site: 'متقن', source: 'site' }
  brain.onEvent({ type: 'order.created', order: view(orders.ox), card: '🔔 *طلب جديد — متقن*\nرقم الطلب: #1999\n🌐 جاي من: متقن (mutqan.oxygen11.com)\n🔐 كود التوثيق: ABCD2345' }); await flush(); const m = take()
  t('room gets the full card (site, seal)', /جاي من: متقن/.test(to(m, ROOM)) && /كود التوثيق/.test(to(m, ROOM)) && /اعتمد 1999/.test(to(m, ROOM)), JSON.stringify(m))
}

// ===== Z. the site's confirm button opens WhatsApp with the order: sending it is the confirmation =====
{
  const CL = '966500000077'
  orders.oc = { id: 'oc', number: 1777, status: 'new', clientPhone: '', service: 'غسيل مكيف', site: 'رعد السماء', claimCode: '4821' }
  const bad = await say(CL, 'أكدت طلبي رقم #1777 ✅\nرمز الطلب: 1111')
  t('a wrong order code does not take the order', orders.oc.clientPhone === '' && !/تم تأكيد طلبك/.test(to(bad, CL)), JSON.stringify(bad))
  await sleep(); take(); store.touchChat(T.pkey(CL), { welcomedAt: 0 })
  const m = await say(CL, 'السلام عليكم، أكدت طلبي رقم #1777 ✅\nالخدمة: غسيل مكيف\n📍 الموقع: https://maps.google.com/?q=21.4,39.8\nرمز الطلب: 4821')
  t('the WhatsApp message from the confirm button claims and confirms the order', T.pkey(orders.oc.clientPhone) === T.pkey(CL) && orders.oc.customerConfirmed && to(m, CL).includes('طلب جديد — رعد السماء') && to(m, CL).includes('#1777') && /أكد طلب #1777 من واتساب/.test(to(m, ROOM)) && !/مرحبًا بكم/.test(to(m, CL)), JSON.stringify(m))
  const again = await say(CL, 'أكدت طلبي رقم #1777 ✅\nرمز الطلب: 4821')
  t('sending it twice only says it is already confirmed', /واصلنا ومأكد/.test(to(again, CL)) && !to(again, ROOM), JSON.stringify(again))
}

// ===== W. the site's own order message (with its verification link) arrives from the customer =====
{
  const SW = '966500000066'
  orders.ow = { id: 'xx5bfb9', number: 1555, status: 'new', clientPhone: '', service: 'غسيل مكيف', site: 'متقن', seal: '6KHZCYHR' }
  const m = await say(SW, '🔔 *طلب جديد — متقن*\nرقم الطلب: #5bfb9\n🛠️ الخدمة: صيانة وتكييف\n📍 الموقع: https://maps.google.com/?q=21.4,39.8\n✅ للتحقق من صحة الطلب (توثيق): https://mutqan.oxygen11.com/v/6KHZCYHR-f4ab50')
  t('the site order message binds his number, gets a receipt (no welcome), the room is told', T.pkey(orders.ow.clientPhone) === T.pkey(SW) && /تم استلام طلبك رقم \*1555\*/.test(to(m, SW)) && !/مرحبًا بكم/.test(to(m, SW)) && /أكد طلب #1555 من واتساب/.test(to(m, ROOM)), JSON.stringify(m))
}

// ===== Y. every A/C type and the exact service reach the site; the confirmation carries the whole order =====
{
  const SY = '966500000093'
  let r = await say(SY, 'عايز غسيل 2 مكيف كونسيلد'); await sleep(); r = r.concat(take())
  t('«غسيل 2 كونسيلد» → no split-table price, survey first, asks for the pin', !/ر\.س\*/.test(to(r, SY)) && /بعد المعاينة/.test(to(r, SY)) && /اللوكيشن نفسه/.test(to(r, SY)), JSON.stringify(r))
  await say(SY, 'https://maps.google.com/?q=21.4011,39.8122'); await sleep(); take()
  await say(SY, '1'); await sleep(); take()
  r = await say(SY, '1'); await sleep(); r = r.concat(take())
  const oy = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(SY))
  t('…booked as wash / concealed, no price, the label says what it is', oy && oy.mode === 'wash' && oy.acType === 'concealed' && !oy.agreedPrice && /غسيل 2 مكيف كونسيلد/.test(oy.service), JSON.stringify(oy))
  t('…the confirmation shows the whole order (service, time)', /تم تأكيد طلبك رقم/.test(to(r, SY)) && /🛠 الخدمة: غسيل 2 مكيف كونسيلد/.test(to(r, SY)) && /📅 الموعد/.test(to(r, SY)), JSON.stringify(r))

  const SZ = '966500000094'
  await say(SZ, 'السلام عليكم'); await sleep(); take()
  await say(SZ, '1'); await sleep(); take()
  r = await say(SZ, '2'); await sleep(); r = r.concat(take())
  t('repair menu lists every type (دولابي، كاسيت، كونسيلد/داكت، باكيدج/مركزي)', /دولابي/.test(to(r, SZ)) && /كاسيت/.test(to(r, SZ)) && /داكت/.test(to(r, SZ)) && /مركزي/.test(to(r, SZ)), JSON.stringify(r))
  await say(SZ, '6'); await sleep(); take()
  await say(SZ, 'مش بيبرد'); await sleep(); take()
  await say(SZ, 'https://maps.google.com/?q=21.4021,39.8133'); await sleep(); take()
  await say(SZ, '1'); await sleep(); take()
  await say(SZ, '1'); await sleep(); take()
  const oz = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(SZ))
  t('…repair on a package unit reaches the site as repair / package with the problem', oz && oz.mode === 'repair' && oz.acType === 'package' && oz.desc === 'مش بيبرد', JSON.stringify(oz))

  const SW = '966500000095'
  await say(SW, 'السلام عليكم'); await sleep(); take()
  await say(SW, '1'); await sleep(); take()
  await say(SW, '3'); await sleep(); take()
  await say(SW, '3'); await sleep(); take()
  await say(SW, '2'); await sleep(); take()
  await say(SW, '1'); await sleep(); take()
  await say(SW, 'https://maps.google.com/?q=21.4031,39.8144'); await sleep(); take()
  await say(SW, '1'); await sleep(); take()
  await say(SW, '1'); await sleep(); take()
  const ow = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(SW))
  t('install menu → «فك وتركيب» of a standing unit reaches the site as move / standing', ow && ow.mode === 'move' && ow.acType === 'standing' && /فك وتركيب 1 مكيف دولابي/.test(ow.service), JSON.stringify(ow))
}

// ===== X. one brain with the site: same cancel procedure, same answers =====
{
  SITE.on = true
  const SX = '966500000091'
  orders.ox = { id: 'ox', number: 1999, status: 'assigned', techId: 't1', techName: 'الفني علي', clientPhone: SX, clientName: 'سامي', service: 'غسيل مكيف', customerConfirmed: true }
  await say(SX, 'السلام عليكم'); await sleep(); take()
  let r = await say(SX, 'طلب 1999 وصل لفين؟'); await sleep(); r = r.concat(take())
  t('«طلب 1999 وصل لفين؟» → the site\'s answer (same as the site chat)', /طلبك #1999/.test(to(r, SX)), JSON.stringify(r))
  r = await say(SX, 'طرق الدفع'); await sleep(); r = r.concat(take())
  t('«طرق الدفع» → the site\'s text', /من الموقع/.test(to(r, SX)), JSON.stringify(r))
  orders.oy = { id: 'oy', number: 1998, status: 'completed', techId: 't1', techName: 'الفني علي', clientPhone: SX, clientName: 'سامي', service: 'غسيل مكيف', customerConfirmed: true }
  r = await say(SX, 'طرق الدفع'); await sleep(); r = r.concat(take())
  t('«طرق الدفع» with a finished unpaid order → the payment link is sent again, as a «paylink» (deleted in two days)', /#\/pay\?o=oy/.test(to(r, SX)) && r.some((x) => x.purpose === 'paylink'), JSON.stringify(r))
  orders.oy.paid = true
  r = await say(SX, 'طلب 1002 فين'); await sleep(); r = r.concat(take())
  t('another customer\'s order number is not shown', /مش لاقي/.test(to(r, SX)), JSON.stringify(r))
  r = await say(SX, 'الغي الطلب'); await sleep(); r = r.concat(take())
  t('cancel before the technician accepted → cancelled by the site at once, room told', orders.ox.status === 'cancelled' && /تم إلغاء طلبك/.test(to(r, SX)) && /لغى طلب #1999/.test(to(r, ROOM)), JSON.stringify(r))
  SITE.on = false
}

console.log(`wa-hub brain: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
