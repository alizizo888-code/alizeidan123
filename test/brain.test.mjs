// The whole conversation, end to end, with a pretend website and a pretend WhatsApp: what each person receives.
import { openStore } from '../src/store.js'
import { createBrain } from '../src/brain.js'
import * as T from '../src/text.js'
import fs from 'node:fs'

let pass = 0; let fail = 0
const t = (name, ok, extra = '') => { if (ok) { pass++; console.log('PASS wa-hub: ' + name) } else { fail++; console.log('FAIL wa-hub: ' + name + (extra ? '\n   ' + String(extra).slice(0, 600) : '')) } }

const ROOM = '966536554731'; const CUST = '966500000001'; const TECH = '966549648890'; const NEW = '966500000009'; const OLDC = '966500000020'; const FRIEND = '966500000030'; const LATE = '966500000040'; const AIC = '966500000050'
const AI = { on: false }
const store = openStore(':memory:')
store.set('settings', { roomPhone: ROOM, agents: [{ name: 'سارة', phone: '0511111111' }], humanFirstSec: 0.3, hoursFrom: 0, hoursTo: 24 })
const out = []
const send = (phone, text, o = {}) => { out.push({ phone, text, purpose: o.purpose, dedup: o.dedup }); return out.length }
const transcript = []
const toWho = (p) => (T.pkey(p) === T.pkey(ROOM) ? 'الغرفة' : T.pkey(p) === T.pkey(TECH) ? 'الفني' : T.pkey(p) === T.pkey(CUST) ? 'العميل' : T.pkey(p) === T.pkey(NEW) ? 'عميل جديد' : p)
const take = () => { const x = out.splice(0); for (const m of x) transcript.push('  → ' + toWho(m.phone) + ': ' + m.text.replace(/\n/g, '\n      ')); return x }
const to = (msgs, phone) => msgs.filter((m) => T.pkey(m.phone) === T.pkey(phone)).map((m) => m.text).join('\n---\n')

// ---- a pretend website ----
const orders = {}
const calls = []
const SL = { new: 'جديد', assigned: 'مُسند', accepted: 'قَبِل الفني', en_route: 'في الطريق', arrived: 'وصل', completed: 'تم الإنجاز', cancelled: 'ملغي' }
const view = (o) => ({ ...o, statusLabel: SL[o.status] || o.status, facts: { stepLabel: SL[o.status] }, statusText: '📋 طلب #' + o.number + ' — ' + (SL[o.status] || o.status), techPhone: o.techId ? TECH : '', jobCard: '*🔧 طلب جديد ليك*\n📋 رقم الطلب: *' + o.number + '*\nرد بكلمة: *قبلت*' })
let brain
const SITE = { on: false }   // the site's shared brain (cancel / answer ops) — switched on for section X
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
  if (op === 'create') { const o = { id: 'o' + (Object.keys(orders).length + 1), number: 1000 + Object.keys(orders).length + 1, status: 'new', clientPhone: b.phone, clientName: b.name, service: b.service, address: b.address, preferredTime: b.time, site: 'رعد السماء', agreedPrice: b.price }; orders[o.id] = o; emit('order.created', o); return view(o) }
  if (op === 'claim') { const o = b.seal ? Object.values(orders).find((x) => b.seal.startsWith(x.seal || '#')) : byNum(b.number); if (!o || o.claimCode !== b.code) return null; const first = !o.customerConfirmed; Object.assign(o, { clientPhone: b.phone, customerConfirmed: true }); return { first, card: first ? '🔔 *طلب جديد — رعد السماء*\nرقم الطلب: #' + o.number : '', ...view(o) } }
  if (op === 'report') return { text: '📋 تقرير' }
  if (op === 'info') return /سعر|اسعار/.test(b.text) ? { text: 'أسعار المالك' } : null
  if (op === 'quote') { const n = T.norm(b.text); if (!/مكيف|سبليت|تسريب|سباك/.test(n)) return null; const q = /مكيفين|2/.test(n) ? 2 : 1; return /غسيل|اغسل/.test(n) ? { spec: 'ac', fixed: true, label: 'غسيل ' + q + ' مكيف سبليت', qty: q, total: q === 2 ? 140 : 100, floor: q === 2 ? 125 : 89, acQuote: { mode: 'wash', type: 'سبليت', qty: q } } : { spec: 'plumbing', fixed: false, label: 'سباكة', from: 120, total: 120, floor: 100 } }
  if (op === 'greet') return T.pkey(b.phone) === T.pkey(OLDC) ? { site: 'رعد السماء', kind: 'returning', name: 'أبو فهد', lastService: 'غسيل مكيفين', lastDays: 40, fromUs: false } : { site: 'رعد السماء', kind: 'new', fromUs: /#raad|من موقع/.test(b.text) }
  if (op === 'cancel' && SITE.on) { const o = orders[b.id]; if (['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(o.status)) { o.cancelRequest = { text: b.text }; return { done: 'requested', reply: 'وصلني طلب الإلغاء ✅ الفني كان متحرك لك، فبلّغته يستنى' } } o.status = 'cancelled'; return { done: 'cancelled', reply: 'تم إلغاء طلبك #' + o.number + ' ✅' } }
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
t('address is recorded and the summary asks for تأكيد', orders.o1.address && /اكتب \*تأكيد\*/.test(to(m, CUST)), to(m, CUST))
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
m = await say(ROOM, 'اعتمد 1002', 'الغرفة'); await say(TECH, 'قبلت', 'الفني'); await say(TECH, 'وصلت', 'الفني')
m = await say(TECH, 'العميل مش موجود والباب مقفول', 'الفني')
t('technician problem → room alerted at once', /الفني علي — طلب #1002/.test(to(m, ROOM)) && /بلّغت غرفة العمليات/.test(to(m, TECH)), JSON.stringify(m))
m = await say(TECH, 'تم', 'الفني')
t('bare «تم» completes; customer asked to rate; room told', orders.o1.status === 'completed' && /قيّم الخدمة/.test(to(m, CUST)) && /تمت المهمة/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(CUST, '5 ممتاز تسلموا')
t('rating saved and sent to the room', orders.o1.customerRating === 5 && /تقييم العميل لطلب #1002: 5\/5/.test(to(m, ROOM)), JSON.stringify(m))

// ===== E. a stranger writes: welcome, a person, an order from the chat =====
transcript.push('\n### هـ) عميل جديد بيكلم الخط: ترحيب، موظف، وطلب من الشات')
m = await say(NEW, 'السلام عليكم')
t('welcome with the etiquette: the company and what we do', /مرحبًا بكم في \*رعد السماء\*/.test(to(m, NEW)) && /غرف التبريد والتجميد/.test(to(m, NEW)) && /السباكة والنجارة والكهرباء/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'بكام الاسعار')
t('prices from the owner\'s own answers', /أسعار المالك/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, '4')
t('«4» → agent told on WhatsApp, customer acknowledged', /عميل محتاج موظف/.test(to(m, '0511111111')) && /بلّغت موظف/.test(to(m, NEW)), JSON.stringify(m))
m = await say(NEW, 'عايز غسيل 2 مكيف سبليت')
t('service → asks for the place', /ابعت موقعك/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'حي العوالي')
t('place → asks for the time', /الموعد المناسب/.test(to(m, NEW)), to(m, NEW))
m = await say(NEW, 'بكرة الساعة 5')
const created = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(NEW))
t('order created from the chat and confirmation sent', created && created.address === 'حي العوالي' && /وصلنا طلبك رقم/.test(to(m, NEW)) && /طلب جديد #/.test(to(m, ROOM)), JSON.stringify(m))

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
t('an old customer is welcomed back by name with his last job', /أهلًا بعودتك يا أبو فهد/.test(to(m, OLDC)) && /غسيل مكيفين/.test(to(m, OLDC)), to(m, OLDC))
brain.contact(FRIEND, 'خالي محمد')
m = await say(FRIEND, 'إزيك يا علي عامل ايه')
t('a name saved in the phone, not from our sites: left alone', !to(m, FRIEND) && store.chat(T.pkey(FRIEND)).kind === 'personal', JSON.stringify(m))
m = await say(NEW.replace(/9$/, '8'), 'عايز اغسل مكيف سبليت')
t('a full request: welcome at once, the rest waits for a person', /مرحبًا بكم/.test(m.map((x) => x.text).join()) && !/ابعت موقعك/.test(m.map((x) => x.text).join()), JSON.stringify(m))
await brain.inbound({ line: 'l1', phone: NEW.replace(/9$/, '8'), text: 'أهلا بيك يا فندم، في أي حي؟', fromMe: true })
await sleep(450); m = take()
t('…a person answered within the two minutes: the bot keeps out', !m.length, JSON.stringify(m))
m = await say(NEW.replace(/9$/, '7'), 'عايز اغسل مكيف سبليت')
await sleep(450); m = m.concat(take())
t('…nobody answered: the bot takes the customer', /ابعت موقعك/.test(m.map((x) => x.text).join()), JSON.stringify(m))
m = await say(TECH, 'صباح الخير يا جماعة', 'الفني')
t('a colleague saying hello gets no bot reply', !to(m, TECH), JSON.stringify(m))

// ===== H. after hours: morning booking, emergencies to the phone =====
transcript.push('\n### ز) برا مواعيد العمل')
store.set('settings', { ...store.get('settings'), hoursFrom: 0, hoursTo: 0, emergencyPhone: '0536554731' })
m = await say(LATE, 'عندي تسريب مياه من المكيف وماس كهربا')
await sleep(450); m = m.concat(take())
t('after hours an emergency is told to call the mobile, and the room is alerted', /اتصل بينا حالًا على \*0536554731\*/.test(to(m, LATE)) && /طارئ برا مواعيد العمل/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(LATE.replace(/0$/, '1'), 'محتاج غسيل مكيف')
await sleep(450); m = m.concat(take())
m = await say(LATE.replace(/0$/, '1'), 'حي النسيم')
t('after hours an ordinary job is booked for the morning', /بكرة الصبح من الساعة 0/.test(to(m, LATE.replace(/0$/, '1'))), JSON.stringify(m))
m = await say(LATE.replace(/0$/, '1'), 'تمام')
t('…«تمام» books it', Object.values(orders).some((o) => T.pkey(o.clientPhone) === T.pkey(LATE.replace(/0$/, '1')) && /بكرة الصبح/.test(o.preferredTime)), JSON.stringify(m))
store.set('settings', { ...store.get('settings'), hoursFrom: 0, hoursTo: 24 })

// ===== I. the site's AI talks, prices, and books =====
AI.on = true
m = await say(AIC, 'السلام عليكم'); m = await say(AIC, 'بكم غسيل 2 مكيف سبليت؟')
t('the site\'s AI answers the price question', /120 ر\.س/.test(to(m, AIC)), JSON.stringify(m))
AI.area = 'الزاهر'; AI.time = 'بكرة العصر'
m = await say(AIC, 'في الزاهر بكرة العصر')
t('…and when it has everything, the order is created and confirmed', Object.values(orders).some((o) => T.pkey(o.clientPhone) === T.pkey(AIC) && o.address === 'الزاهر') && /وصلنا طلبك رقم/.test(to(m, AIC)), JSON.stringify(m))
AI.on = false


// ===== J. prices and bargaining, from the owner's lists, down to his floor and no further =====
transcript.push('\n### ح) الأسعار والفصال')
const HAG = '966500000060'
m = await say(HAG, 'السلام عليكم')
m = await say(HAG, 'بكام غسيل 2 مكيف سبليت؟')
t('price: the owner\'s list price and the next question', /غسيل 2 مكيف سبليت: \*140 ر\.س\*/.test(to(m, HAG)) && /الحي أو اللوكيشن/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'غالي شوية')
t('first «غالي»: one step down', /\*130 ر\.س\* بدل 140/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'لا كتير نزل كمان')
t('second: the floor, as the last word', /آخر كلام يا غالي: \*125 ر\.س\*/.test(to(m, HAG)), to(m, HAG))
m = await say(HAG, 'خليها 100')
t('below the floor: apology, support, room told', /أقل سعر عندنا/.test(to(m, HAG)) && /بيفاصل تحت أقل سعر/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(HAG, 'تمام')
t('«تمام» settles on the floor and the room hears the deal', /اتفقنا على \*125 ر\.س\*/.test(to(m, HAG)) && /اتفاق سعر/.test(to(m, ROOM)), JSON.stringify(m))
m = await say(HAG, 'حي الشوقية'); m = await say(HAG, 'بكرة العصر')
const hagOrder = Object.values(orders).find((o) => T.pkey(o.clientPhone) === T.pkey(HAG))
t('…the order carries the agreed price', hagOrder && hagOrder.agreedPrice === 125 && hagOrder.service === 'غسيل 2 مكيف سبليت', hagOrder)
const HAG2 = '966500000061'
await say(HAG2, 'السلام عليكم'); await say(HAG2, 'كم سعر غسيل مكيف سبليت')
m = await say(HAG2, 'ب 95 ينفع؟')
t('a price he names below the first step is countered, not taken', /\*95 ر\.س\* بدل 100|\*94|بدل 100/.test(to(m, HAG2)) || /آخر كلام/.test(to(m, HAG2)), to(m, HAG2))
m = await say(HAG2, '90 وخلاص')
t('…after we moved, a price at or above the floor is taken', /اتفقنا على \*90 ر\.س\*/.test(to(m, HAG2)), to(m, HAG2))

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
  r = await say(SX, 'طلب 1002 فين'); await sleep(); r = r.concat(take())
  t('another customer\'s order number is not shown', /مش لاقي/.test(to(r, SX)), JSON.stringify(r))
  r = await say(SX, 'الغي الطلب'); await sleep(); r = r.concat(take())
  t('cancel before the technician accepted → cancelled by the site at once, room told', orders.ox.status === 'cancelled' && /تم إلغاء طلبك/.test(to(r, SX)) && /لغى طلب #1999/.test(to(r, ROOM)), JSON.stringify(r))
  SITE.on = false
}

console.log(`wa-hub brain: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
