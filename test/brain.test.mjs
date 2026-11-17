// The whole conversation, end to end, with a pretend website and a pretend WhatsApp: what each person receives.
import { openStore } from '../src/store.js'
import { createBrain } from '../src/brain.js'
import * as T from '../src/text.js'
import fs from 'node:fs'

let pass = 0; let fail = 0
const t = (name, ok, extra = '') => { if (ok) pass++; else { fail++; console.log('✗ ' + name + (extra ? '\n   ' + String(extra).slice(0, 600) : '')) } }

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
  if (op === 'create') { const o = { id: 'o' + (Object.keys(orders).length + 1), number: 1000 + Object.keys(orders).length + 1, status: 'new', clientPhone: b.phone, clientName: b.name, service: b.service, address: b.address, preferredTime: b.time, site: 'رعد السماء' }; orders[o.id] = o; emit('order.created', o); return view(o) }
  if (op === 'report') return { text: '📋 تقرير' }
  if (op === 'info') return /سعر|اسعار/.test(b.text) ? { text: 'أسعار المالك' } : null
  if (op === 'greet') return T.pkey(b.phone) === T.pkey(OLDC) ? { site: 'رعد السماء', kind: 'returning', name: 'أبو فهد', lastService: 'غسيل مكيفين', lastDays: 40, fromUs: false } : { site: 'رعد السماء', kind: 'new', fromUs: /#raad|من موقع/.test(b.text) }
  if (op === 'converse') return AI.on ? { reply: 'أكيد، غسيل المكيف السبليت بـ 120 ر.س للجهاز. في أي حي؟', lead: { service: 'غسيل 2 سبليت', area: AI.area || '', time: AI.time || '' }, complete: !!(AI.area && AI.time) } : null
  return { ok: true }
}
brain = createBrain({ store, wp, send })
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
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
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

// ===== text helpers =====
t('stepOf: «وصلت 1002»', T.stepOf('وصلت 1002') === 'arrived')
t('stepOf: «تم استلام…» is not done', T.stepOf('تم استلام الطلب') !== 'completed')
t('intl: 05… → 9665…', T.intl('0536554731') === '966536554731')
t('roomCommand: وجّه 1002 لمحمود', T.roomCommand('وجّه 1002 لمحمود')?.name === 'محمود')

if (process.env.SCEN_OUT) fs.writeFileSync(process.env.SCEN_OUT, transcript.join('\n'))
console.log(`wa-hub brain: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
