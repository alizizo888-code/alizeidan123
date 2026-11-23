// The hub's brain: who is writing, what they want, and what everyone hears — customer, room, technician.
//
// The websites only *report* («order #1002 was created / assigned / moved») and answer short data questions. Every
// WhatsApp word is decided and sent from here, so a slow website can never hold a WhatsApp conversation up, and a
// WhatsApp burst can never slow a website down.
import * as T from './text.js'
import * as M from './menu.js'

const DAY = 86400000
const KEEP = 4 * DAY                        // a conversation about an order nobody answered is forgotten after four days
const PAUSE_AFTER_HUMAN = 2 * 3600000       // a person answered from the phone or the panel: the bot keeps out for two hours
const WELCOME_EVERY = 12 * 3600000
const OPEN = ['new', 'forced', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
const MOVING = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
const LABEL_ST = { accepted: 'قبل الطلب', en_route: 'في الطريق', nearby: 'قرّب', arrived: 'وصل', in_progress: 'بيشتغل' }
const SERVICE_WORDS = ['تكييف', 'مكيف', 'مكيفات', 'سبليت', 'شباك', 'غسيل', 'صيانه', 'صيانة', 'تركيب', 'فك', 'نقل', 'فريون', 'سباك', 'سباكه', 'كهربا', 'كهربائي', 'ثلاجه', 'غساله', 'غرفه تبريد', 'غرف تبريد', 'تجميد', 'تسريب', 'عطل', 'ما يبرد', 'مش بيبرد', 'دهان', 'ترميم', 'مقاولات', 'تنظيف', 'سخان']

export const DEFAULTS = {
  roomPhone: '966536554731',     // «غرفة العمليات»: every report and every command
  testPhone: '',                 // one phone that is both a customer and the room (an owner testing alone)
  agents: [],                    // [{name, phone}] — customer chats are dealt to them in turn
  notifyAgents: true,
  autoReply: true,
  siteName: 'أكسجين للصيانة',
  hoursFrom: 7, hoursTo: 23,     // owner, 2026-11-20: 7 am–10 pm same day; from 11 pm the next morning (the site's config wins)
  welcome: 'مرحبًا بكم في *{site}* 🌷 تحت أمرك — إيش الخدمة اللي تحتاجها؟',
  returningWaitSec: 180,          // an old customer writes: operations get three minutes to answer him before the bot does
  welcomeBack: 'أهلًا بعودتك{name} 🌷 نورتنا تاني في *{site}*.{last}\nتحب نكرر نفس الخدمة، ولا محتاج حاجة جديدة؟',
  afterHours: '🌙 إحنا دلوقتي برا مواعيد العمل؛ نقدر نحجزلك أقرب موعد الصبح. ولو الموضوع طارئ اتصل بينا مباشرة على *{phone}*.',
  emergencyPhone: '0536554731',
  humanFirstSec: 120,             // a person gets two minutes to answer before the bot takes the conversation
  ignoreContacts: true,           // names saved in the line's phone (family, friends) are left alone
  dailyReport: false,             // owner, 2026-11-22: the day's summary stays in the app (notifications), not on WhatsApp
  washVideos: M.WASH_VIDEOS,       // «طريقة الغسيل» (5 in the menu) — the owner's own videos
  prices: 'أسعارنا بتتحدد حسب نوع الجهاز والعدد، والمعاينة مجانية لحد جهازين.\nابعت نوع الخدمة وعدد الأجهزة والحي، ونبعتلك السعر على طول 🌷',
}

export function createBrain({ store, wp, send: rawSend, mirror = () => {}, now = () => Date.now(), log = () => {}, later = (fn, ms) => setTimeout(fn, ms), cancel = (h) => clearTimeout(h) }) {
  // «الفني الفني علي»: a technician whose name already starts with «الفني» is named once.
  const send = (phone, text, o) => rawSend(phone, String(text).replace(/(الفن[يى])\s+(?=الفن[يى]\s)/g, ''), o)
  const cfg = () => { const c = { ...DEFAULTS, ...(store.get('settings', {}) || {}) }; const wc = store.get('wpConfig', {}) || {}; if (!c.testPhone) c.testPhone = wc.testCustomer || ''; if (Number.isFinite(wc.hoursFrom)) c.hoursFrom = wc.hoursFrom; if (Number.isFinite(wc.hoursTo)) c.hoursTo = wc.hoursTo; if (Array.isArray(wc.washVideos)) c.washVideos = wc.washVideos; if (/1️⃣ طلب خدمة|نقدّم خدمات صيانة وإصلاح جميع أنواع المكيفات/.test(c.welcome || '')) c.welcome = DEFAULTS.welcome
    // The desk's numbers (Mutqan_Lines): the manager becomes the room once the owner marked it active.
    if (wc.managerPhone) c.roomPhone = wc.managerPhone
    c.ourNumbers = wc.ourNumbers || {}
    c.offSections = Array.isArray(wc.offSections) ? wc.offSections.map(Number) : []
    return c }
  const who$ = new Map()   // phone → {at, who}: one question to the website per person per ten minutes

  /* ------------------------------------------------------------------ sending */

  const chatOf = (phone) => store.chat(T.pkey(phone))
  function toCustomer(o, text, dedup) {
    if (!o?.clientPhone) return 0
    const c = chatOf(o.clientPhone)
    return send(T.intl(o.clientPhone), T.sign(o.site || cfg().siteName, text), { line: c?.line || o.waLine || '', purpose: 'customer', dedup, brand: o.brand })
  }
  function toRoom(text, dedup) {
    const r = cfg().roomPhone
    return r ? send(T.intl(r), text, { purpose: 'room', dedup }) : 0
  }
  function toTech(o, text, dedup) {
    const ph = o?.techPhone
    return ph ? send(T.intl(ph), text, { purpose: 'tech', dedup }) : 0
  }
  // The same reply a third time in a quarter of an hour means we are not understanding him: a person takes over
  // (owner, 2026-11-21 — the same summary went out three times while the customer was cursing).
  const recentOut = new Map()
  const say = (phone, line, text) => {
    const pk = T.pkey(phone)
    const r = (recentOut.get(pk) || []).filter((x) => now() - x.at < 900000)
    if (r.filter((x) => x.t === text).length >= 2) {
      const c = chatOf(phone); if (c) notifyHuman(c, 'البوت كرر نفس الرد 3 مرات — محتاج موظف', false)
      text = 'حاسس إني مش فاهمك كويس 🙏 بلّغت موظف خدمة العملاء وهيرد عليك هنا حالًا.\nأو اكتب *0* للقائمة.'
    }
    r.push({ t: text, at: now() }); recentOut.set(pk, r.slice(-6))
    return send(T.intl(phone), text, { line, purpose: 'reply' })
  }
  const sayImg = (phone, line, text, image) => (image ? send(T.intl(phone), text, { line, purpose: 'reply', image }) : say(phone, line, text))

  /* ------------------------------------------------------------------ stage memory: which order a phone is talking about */

  function setStage(phone, orderId, stage) {
    const pk = T.pkey(phone); if (!pk) return
    store.touchChat(pk, { phone: T.intl(phone), stage, orderId: String(orderId || ''), stageAt: now() })
    // the order asks him something (how he pays, a rating…): a menu left open must not take his «1 / 2 / 3» (live test 2026-11-24)
    if (['pay', 'paybank', 'rate', 'traits'].includes(stage)) { const c = store.chat(pk); if (c && c.lead) { let L = {}; try { L = JSON.parse(c.lead || '{}') } catch {} if (L.m) { delete L.m; store.touchChat(pk, { lead: JSON.stringify(L) }) } } }
  }
  function stageOf(phone) {
    const c = chatOf(phone)
    if (!c || !c.stage || now() - (c.stageAt || 0) > KEEP) return null
    return { s: c.stage, o: c.orderId, c }
  }
  const dropStage = (phone) => setStage(phone, '', '')

  /* ------------------------------------------------------------------ the website reports */

  async function onEvent(ev) {
    const o = ev.order || null
    try {
      switch (ev.type) {
        case 'order.created': return created(o, ev.card)
        case 'order.assigned': return assigned(o)
        case 'order.status': return moved(o, ev.was || '', !!ev.byCustomer)
        case 'order.eta': return eta(o, ev.minutes)
        case 'send': return ev.phone && ev.text ? send(T.intl(ev.phone), String(ev.text), { purpose: ev.purpose || 'site', dedup: ev.dedup }) : 0
        default: return 0
      }
    } catch (e) { log('error', 'event ' + ev.type + ': ' + (e?.message || e)) }
  }

  function created(o, card) {
    if (!o) return
    // The room hears about every order at once, whatever happens with the customer — the full card when the site
    // sent one (site, service, count, time and date, map, verification), else the short summary.
    const head = '🆕 *طلب جديد #' + T.num(o) + '*' + (o.site ? ' — ' + o.site : '')
    const hint = o.techName ? '👷 اتسند آليًا لـ ' + o.techName : 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*'
    const waiting = [o.originLine ? '🧭 جاي منين: ' + o.originLine : '', o.historyText ? '✅ تم العمل عنده قبل كده\n' + o.historyText : '', o.clientPhone ? '' : '⏳ رقم العميل يوصل أول ما يبعت رسالة الطلب من واتساب'].filter(Boolean).join('\n')
    toRoom((card ? [card, waiting, '', hint] : [head, '👤 ' + (o.clientName || 'بدون اسم') + (o.clientPhone ? ' — ' + o.clientPhone : ' — ⏳ مستني تأكيده من واتساب'), ...T.opsSummary(o), o.historyText ? '✅ تم العمل عنده قبل كده\n' + o.historyText : '', '', hint]).filter((x, i, a) => x !== '' || (i > 0 && a[i - 1] !== '')).join('\n'), 'new-' + o.id)
    // From the site's order button: the site sends him the full order card itself — no «اكتب تأكيد» for him.
    if (o.clientPhone && ['site', 'mutqan_ai'].includes(o.source)) { setStage(o.clientPhone, o.id, 'live'); return }
    if (!o.clientPhone || o.customerConfirmed || o.waClaim || ['test', 'import'].includes(o.source)) return
    const miss = T.missing(o)
    const L = ['أهلًا' + (o.clientName ? ' ' + o.clientName : '') + ' 👋', 'معاك خدمة عملاء' + (o.site ? ' *' + o.site + '*' : '') + '، وصلنا طلبك رقم *' + T.num(o) + '*:', ...T.summary(o)]
    if (miss.length) L.push('', 'عشان نبعتلك الفني محتاجين منك:', ...T.missingText(miss))
    L.push('', miss.length ? 'ابعتهم هنا، وبعدها اكتب *تأكيد* ✅' : 'لو البيانات صح اكتب *تأكيد* ✅ — أو اكتب أي تعديل.')
    if (o.url) L.push('🔗 تابع طلبك: ' + o.url)
    toCustomer(o, L.join('\n'), 'confirm-' + o.id)
    setStage(o.clientPhone, o.id, 'confirm')
    wp('step', { id: o.id, kind: 'wa_confirm_ask', text: 'اتبعت للعميل تأكيد الطلب على واتساب' + (miss.length ? ' (ناقص: ' + miss.map((m) => (m === 'time' ? 'الموعد' : 'الموقع')).join('، ') + ')' : '') }).catch(() => {})
  }

  function assigned(o) {
    if (!o?.techId) return
    const head = '📤 طلب #' + T.num(o) + ' اتسند للفني ' + (o.techName || '')
    if (!o.techPhone || o.techIsSite) {
      const why = o.techIsSite ? '«فني الموقع» حساب عام' : 'الفني ده ما لوش رقم واتساب في ملفه'
      toRoom('⚠️ *راجع وابعت بنفسك* — ' + why + '\n' + head + '\n\n' + String(o.jobCard || '').replace(/\n*رد بكلمة:[\s\S]*$/, '') + '\n\nوزّعه على فني: *وجّه ' + T.num(o) + ' لمحمود*', 'as-' + o.id + '-' + o.techId)
      return
    }
    toTech(o, o.jobCard || ('*🔧 طلب جديد ليك* #' + T.num(o)), 'card-' + o.id + '-' + o.techId)
    toRoom(head + (o.customerConfirmed ? '' : '\n⏳ العميل لسه ما أكدش على واتساب'), 'as-' + o.id + '-' + o.techId)
  }

  const WA_STATUS = {
    accepted: '✅ الفني {tech} قبل طلبك وهيتواصل معاك قبل ما يتحرك.',
    en_route: '🚗 الفني {tech} في الطريق ليك دلوقتي.',
    nearby: '📍 الفني {tech} قرّب من موقعك.',
    arrived: '🏠 الفني {tech} وصل لموقعك.',
  }

  function rateText(o) {
    const tech = o.techName || ''
    // choices, not a free text (owner, 2026-11-23)
    return '🎉 تم إنجاز طلبك، شكرًا لثقتك' + (o.site ? ' في *' + o.site + '*' : '') + '.\nإيه رأيك في الخدمة والفني' + (tech ? ' ' + tech : '') + '؟ اختار رقم 👇\n5️⃣ راضي جدًا ⭐⭐⭐⭐⭐\n4️⃣ راضي ⭐⭐⭐⭐\n3️⃣ تمام ⭐⭐⭐\n2️⃣ مش راضي ⭐⭐\n1️⃣ سيء ⭐\nرأيك بيوصل للمدير مباشرة.'
  }
  function askRate(o, phone) { setStage(phone, o.id, 'rate'); toCustomer(o, rateText(o), 'rate-' + o.id) }

  /** His answer to «حابب تدفع إزاي؟»: recorded on the site, what he needs now, then the rating. */
  async function payStage(o, text, phone) {
    const n = T.norm(String(text).split('\n')[0]).trim()   // held messages come joined: his first answer counts
    const m = /^(1|١)$|كاش|نقد|cash|للفني/.test(n) ? 'cash' : /^(2|٢)$|تحويل|بنك|ايبان|حساب|راجحي|اهلي|stc|برق|رياض/.test(n) ? 'transfer' : /^(3|٣)$|بوابه|فيزا|مدي|اونلاين|بطاقه|كارت|ماستر|باي ?بال|paypal|ابل باي/.test(n) ? 'online' : null
    if (!m) {
      const k = 'payask:' + o.id
      if (store.get(k)) { store.set(k, 0); askRate(o, phone); return '' }   // he wants to talk about something else: move on
      store.set(k, 1)
      return 'اختار رقم من التلاتة 👇\n1️⃣ كاش للفني\n2️⃣ تحويل بنكي\n3️⃣ بوابة الدفع الآمنة (فيزا / مدى / حسابات أونلاين)'
    }
    let r = null
    try { r = await wp('pay_pick', { id: o.id, method: m }) } catch (e) { log('warn', 'pay_pick: ' + e.message) }
    // a transfer: which bank first, then that bank's details (owner, 2026-11-23)
    if (r && r.banks) {
      store.set('paybanks:' + o.id, Number(r.banks)); setStage(phone, o.id, 'paybank')
      toRoom('💳 العميل اختار الدفع: *تحويل بنكي* — طلب #' + T.num(o), 'payc-' + o.id + '-' + m)
      return r.text
    }
    if (r && r.text) { const c = chatOf(o.clientPhone); send(T.intl(o.clientPhone), T.sign(o.site || cfg().siteName, r.text), { line: c?.line || o.waLine || '', purpose: r.purpose === 'paylink' ? 'paylink' : 'customer', brand: o.brand }) }
    toRoom('💳 العميل اختار الدفع: *' + ({ cash: 'كاش للفني', transfer: 'تحويل بنكي', online: 'بوابة الدفع' }[m]) + '* — طلب #' + T.num(o), 'payc-' + o.id + '-' + m)
    askRate(o, phone)
    return ''
  }

  /** The bank he picked: its details (the IBAN alone, to copy), the QR when saved, then «send the receipt», then the rating. */
  async function payBankStage(o, text, phone) {
    text = String(text).split('\n')[0]
    const n = Number((T.norm(text).match(/^\s*([0-9]{1,2})\s*$/) || [])[1] || 0)
    const max = Number(store.get('paybanks:' + o.id) || 0)
    if (!n || n > max) {
      const k = 'paybankask:' + o.id
      if (store.get(k)) { store.set(k, 0); askRate(o, phone); return '' }
      store.set(k, 1)
      return 'اكتب رقم البنك من القايمة 👆'
    }
    let r = null
    try { r = await wp('pay_bank', { id: o.id, idx: n }) } catch (e) { log('warn', 'pay_bank: ' + e.message) }
    const c = chatOf(o.clientPhone)
    for (const x of (r && r.messages) || []) send(T.intl(o.clientPhone), x.text, { line: c?.line || o.waLine || '', purpose: 'paylink', image: x.image || '', brand: o.brand })
    store.set('awaitproof:' + T.pkey(phone), JSON.stringify({ o: o.id, at: now() }))
    askRate(o, phone)
    return ''
  }

  function moved(o, was, byCustomer) {
    if (!o || o.status === was) return
    const n = T.num(o); const tech = o.techName || ''; const who = o.clientName || o.clientPhone || ''
    const phone = o.clientPhone || ''
    const st = o.status
    if (WA_STATUS[st] && phone) {
      // «تتبع الفني» (owner, 2026-11-23 — idea 13): on the way / close by, the customer gets his order page, where the
      // technician's position moves live.
      const track = ['en_route', 'nearby'].includes(st) && o.url ? '\n📍 تابع مكان الفني لحظة بلحظة: ' + o.url : ''
      toCustomer(o, WA_STATUS[st].replace('{tech}', tech).replace(/\s{2,}/g, ' ') + track + '\n\n' + (o.statusText || ''), 'st-' + o.id + '-' + st)
      const j = stageOf(phone)
      if (!j || j.o === o.id) setStage(phone, o.id, j && j.s === 'confirm' && !o.customerConfirmed ? 'confirm' : 'live')
    }
    if (st === 'en_route') toRoom('🚗 الفني ' + tech + ' اتحرك لطلب #' + n + ' — ' + who, 'go-' + o.id)
    if (st === 'arrived') toRoom('🏠 *الفني ' + tech + ' وصل* لطلب #' + n + ' — ' + who + (o.address ? '\n📍 ' + String(o.address).slice(0, 100) : ''), 'arr-' + o.id)
    if (st === 'completed') {
      toRoom('✅ *تمت المهمة* — طلب #' + n + '\n👤 ' + who + (tech ? '\n👷 الفني: ' + tech : '') + (Number(o.price) > 0 ? '\n💰 ' + o.price + ' ر.س' : '') + '\n⭐ تقييم العميل هيوصلك هنا أول ما يرد.', 'done-' + o.id)
      // first how he pays (owner, 2026-11-23): 1 cash · 2 transfer · 3 the secure gateway — then the thanks and the rating
      if (phone) {
        wp('pay_menu', { id: o.id }).catch(() => null).then((pm) => {
          if (pm && pm.text) { setStage(phone, o.id, 'pay'); toCustomer(o, pm.text, 'pay-' + o.id) } else askRate(o, phone)
        })
      }
    }
    if (st === 'cancelled') {
      if (o.techId && ['assigned', ...MOVING].includes(was)) toTech(o, '❌ طلب #' + n + ' اتلغى' + (['en_route', 'nearby'].includes(was) ? ' — ارجع، مش محتاج تكمّل الطريق' : '') + '. شكرًا ليك 🙏', 'x-tech-' + o.id)
      toRoom('❌ اتلغى طلب #' + n + ' — ' + who + (byCustomer && phone ? '\n🔁 العميل لغاه بنفسه — بسأله عن السبب وبحاول أرجّعه، وهبلغك بالنتيجة.' : ''), 'x-' + o.id)
      if (phone && byCustomer) winbackStart(o)
      else if (phone) { toCustomer(o, 'تم إلغاء طلبك رقم *' + n + '*. لو محتاج أي خدمة إحنا موجودين 🌷', 'x-c-' + o.id); const j = stageOf(phone); if (j && j.o === o.id) dropStage(phone) }
    }
  }

  function eta(o, minutes) {
    if (!o?.clientPhone) return
    const j = stageOf(o.clientPhone)
    if (!j || j.o !== o.id) return
    toCustomer(o, '🚗 الفني ' + (o.techName || '') + ' رد: هيوصل خلال حوالي ' + Number(minutes) + ' دقيقة.')
  }

  /* ------------------------------------------------------------------ someone writes */

  async function who(phone) {
    const k = T.pkey(phone); const c = cfg()
    if (k && k === T.pkey(c.roomPhone)) return { kind: 'staff', name: 'غرفة العمليات', role: 'owner' }
    const ag = (c.agents || []).find((a) => T.pkey(a.phone) === k)
    if (ag) return { kind: 'staff', name: ag.name, role: 'operations' }
    // Our own numbers: never a customer, never welcomed — a line writing to a line would answer itself forever.
    const ours = (c.ourNumbers || {})[k]
    if (ours) return ours.role === 'manager' && ours.active ? { kind: 'staff', name: 'المدير', role: 'owner' } : { kind: 'line', name: ours.label || 'رقم الشركة', role: ours.role }
    if ((store.lines?.() || []).some((l) => l.phone && T.pkey(l.phone) === k)) return { kind: 'line', name: 'رقم الشركة', role: 'line' }
    const hit = who$.get(k)
    if (hit && now() - hit.at < 600000) return hit.who
    let w = { kind: 'customer' }
    try { w = (await wp('who', { phone: T.intl(phone) })) || w } catch (e) { log('warn', 'who: ' + e.message) }
    who$.set(k, { at: now(), who: w })
    return w
  }

  /** A name the phone has in its own address book: the owner knows this person. */
  function contact(phone, name) {
    const pk = T.pkey(phone); if (!pk || !name) return
    const c = store.chat(pk)
    if (!c) store.touchChat(pk, { phone: T.intl(phone), contact: name })
    else if (c.contact !== name) store.touchChat(pk, { contact: name })
  }

  // «اترد عليه؟» — a turn the bot holds back while a person may answer. One per chat; a new message joins it.
  const held = new Map()   // pk → {timer, texts:[], at}
  function holdThen(pk, text, ms, fn) {
    const h = held.get(pk) || { texts: [], at: now() }
    if (h.timer) cancel(h.timer)
    h.texts.push(text)
    h.timer = later(() => {
      held.delete(pk)
      const c = store.chat(pk)
      if (c && (c.humanAt || 0) >= h.at) return   // a person answered meanwhile: the bot keeps out
      return Promise.resolve(fn(h.texts.join('\n'))).catch((e) => log('error', 'held: ' + (e?.message || e)))
    }, ms)
    if (h.timer && h.timer.unref) h.timer.unref()
    held.set(pk, h)
  }
  const humanActive = (c, within) => c && c.humanAt && now() - c.humanAt < within

  /**
   * Every message that arrives on a linked line. `fromMe` is a person typing on the phone itself: it is kept, and the
   * bot gives way — it speaks again only if the customer writes and nobody answers him for two minutes.
   */
  async function inbound({ line, phone, text = '', kind = 'text', name = '', fromMe = false, self = false, media = '', mime = '' }) {
    const pk = T.pkey(phone); if (!pk) return
    text = String(text || '').trim()
    // a voice note (owner, 2026-11-23): heard and answered like a written message — the words are kept with it
    let heard = ''
    if (!fromMe && ['voice', 'audio'].includes(kind) && media) {
      try { const r = await wp('transcribe', { url: media, mime }); heard = String((r && r.text) || '').trim() } catch (e) { log('warn', 'transcribe: ' + e.message) }
    }
    const shown = text || ({ image: '📷 صورة', video: '🎬 فيديو', voice: '🎤 رسالة صوتية', audio: '🎵 صوت', document: '📄 ملف', sticker: '🙂 ملصق', location: '📍 موقع' }[kind] || '…')
    const c0 = store.chat(pk)
    if (fromMe && !self) {
      store.addMsg({ pk, line, dir: 'out', text: shown, kind, by: 'phone' })
      store.touchChat(pk, { phone: T.intl(phone), line, lastAt: now(), lastText: shown, humanAt: now(), needsHuman: 0, unread: 0 })
      mirror(pk, line, 'out', shown, 'phone')
      return
    }
    store.addMsg({ pk, line, dir: 'in', text: heard ? '🎤 ' + heard : shown, kind, by: name })
    if (heard) { text = heard; kind = 'text' }
    const c = store.touchChat(pk, { phone: T.intl(phone), line: c0?.line || line, name: name || c0?.name || '', lastAt: now(), lastText: shown, unread: (c0?.unread || 0) + 1 })
    mirror(pk, line, 'in', shown)
    // family, friends: the owner answers them himself — never the owner's own test phone (live tests, 2026-11-23)
    if (c.kind === 'personal' && !(cfg().testPhone && T.pkey(cfg().testPhone) === pk)) return
    // the transfer receipt he was asked for (a picture or a file): on the order, operations told
    if (['image', 'document'].includes(kind)) {
      let ap = null; try { ap = JSON.parse(store.get('awaitproof:' + pk) || 'null') } catch {}
      if (ap && now() - ap.at < 3 * DAY) {
        store.set('awaitproof:' + pk, '')
        wp('pay_proof', { id: ap.o, kind }).catch(() => {})
        return say(phone, line, 'وصلنا الإيصال ✅ شكرًا ليك، والعمليات هتأكد الدفع 🌷')
      }
    }
    if (!text && kind !== 'location') return     // a picture or a voice note: a person will look at it

    const w = await who(phone)
    // the owner's test phone may be one of our own numbers (owner, 2026-11-23: 0536554731 plays the customer in the live
    // tests) — then it is a customer, not a line of ours to stay quiet with
    const testing = cfg().testPhone && T.pkey(cfg().testPhone) === pk
    if (w.kind === 'line' && !testing) { store.touchChat(pk, { kind: 'team', unread: 0 }); return }
    // «إيقاف»: out of every campaign list for good, and told so once.
    if (w.kind === 'customer' && M.isStop(text)) {
      try { await wp('outreach_optout', { phone: T.intl(phone) }) } catch (e) { log('warn', 'optout: ' + e.message) }
      return say(phone, line, 'تمام ✅ مش هنبعتلك رسايل تاني. ولو احتجت أي خدمة في يوم إحنا موجودين 🌷')
    }
    // He answered a campaign message: a conversation now (the base marks him as replied, once).
    if (w.kind === 'customer' && !store.get('or:' + pk) && store.msgs(pk, 6).some((m) => m.by === 'outreach')) {
      store.set('or:' + pk, 1); wp('outreach_reply', { phone: T.intl(phone) }).catch(() => {})
    }
    if (w.kind !== 'customer' && !(testing && !T.roomCommand(text) && !/^عميل\s/.test(text))) {
      // A colleague: no welcome, no small talk — only his work words (a technician's step, the room's commands).
      store.touchChat(pk, { kind: w.kind })
      const reply = w.kind === 'tech' ? await technician({ ...w, phone }, text) : await room(w, text)
      if (reply) say(phone, line, reply)
      return
    }
    return customer({ c, line, phone, text, name })
  }

  /* ------------------------------------------------------------------ the customer */

  const EMERGENCY = /(طوارئ|طارئ|ضروري جدا|مستعجل جدا|حالا حالا|تسريب|تسرب|ماس|شرار|حريق|دخان|ريحه غاز|ريحة غاز|كهربا فصلت|البضاعه هتبوظ|البضاعة هتبوظ|الغرفه سخنت|الغرفة سخنت|خربان كليا)/
  function isOpen() {
    const C = cfg()
    const h = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Asia/Riyadh' }).format(new Date(now())))
    return C.hoursTo >= 24 ? h >= C.hoursFrom : h >= C.hoursFrom && h < C.hoursTo
  }

  async function greetOf(c, text) {
    let g = null; try { g = JSON.parse(c.greet || 'null') } catch {}
    if (g && now() - (g.at || 0) < DAY) return g
    try { g = await wp('greet', { phone: c.phone, text, brand: store.line?.(c.line)?.brand || '' }) } catch (e) { log('warn', 'greet: ' + e.message) }
    g = { ...(g || {}), site: (g && g.site) || cfg().siteName, at: now() }
    store.touchChat(c.pk, { greet: JSON.stringify(g) })
    return g
  }

  /** «مرحبًا بكم في …» — the company he came through, what we do, and a welcome back for an old customer. */
  // (owner, 2026-11-23) a short welcome in the name of the site he came through — or a general one when that is not known —
  // and one open question. No list of sections: the service is asked for, then taken one step at a time.
  const WELCOMES = [
    'مرحبًا بكم في *{site}* 🌷\nأقدر أساعدك إزاي؟',
    'أهلًا بيك في *{site}* 🌷\nتحت أمرك — محتاج إيه النهارده؟',
    'السلام عليكم ومرحبًا بيك في *{site}* 🌷\nقولّي أقدر أخدمك في إيه؟',
    'أهلًا وسهلًا بيك في *{site}* 🌷\nإزاي أقدر أساعدك؟',
  ]
  const OPEN_WELCOMES = ['أهلًا وسهلًا 🌷 وعليكم السلام\nأقدر أساعدك إزاي؟', 'أهلًا بيك 🌷\nتحت أمرك — محتاج إيه؟', 'مرحبًا بيك 🌷\nقولّي أقدر أخدمك في إيه؟']
  const pickOne = (L) => L[Math.floor(now() / 1000) % L.length]
  function welcome(g) {
    const C = cfg()
    if (!C.welcome || C.welcome === DEFAULTS.welcome) {
      let t = g.known === false ? pickOne(OPEN_WELCOMES) : pickOne(WELCOMES).replace(/\{site\}/g, g.site || C.siteName)
      if (g.known !== false && g.source) t += '\nشايفين إنك جاي من «' + g.source + '» — نقدر نساعدك فيها على طول.'
      if (!isOpen()) t += '\n\n' + C.afterHours.replace(/\{phone\}/g, C.emergencyPhone)
      return t
    }
    const back = ['returning', 'vip'].includes(g.kind)
    const last = back && g.lastService ? '\nآخر مرة نفذنالك «' + g.lastService + '»' + (g.lastDays ? ' من ' + g.lastDays + ' يوم' : '') + (g.warrantyLeft ? '، وضمانها لسه ساري ' + g.warrantyLeft + ' يوم 🛡' : '') + '.' : ''
    let t = (back ? C.welcomeBack : C.welcome).replace(/\{site\}/g, g.site || C.siteName).replace(/\{name\}/g, g.name ? ' يا ' + g.name : '')
    t = t.includes('{last}') ? t.replace('{last}', last) : t.replace(/\n/, last + '\n')
    if (!back && g.source) t += '\nشايفين إنك جاي من «' + g.source + '» — نقدر نساعدك فيها على طول.'
    if (!isOpen()) t += '\n\n' + C.afterHours.replace(/\{phone\}/g, C.emergencyPhone)
    return t + '\n\n' + M.sectionsText(C.offSections)
  }
  /** One line before getting straight to what he asked for — no list of everything we do. */
  function hello(g) {
    const C = cfg()
    return 'مرحبًا بكم في *' + (g.site || C.siteName) + '* 🌷' + (isOpen() ? '' : '\n' + C.afterHours.replace(/\{phone\}/g, C.emergencyPhone))
  }

  /** What we last talked about with him: the last order summary or the last service he asked for, before today. */
  function lastTopic(pk, before) {
    const old = store.msgs(pk, 60).filter((m) => m.at < before)
    for (let i = old.length - 1; i >= 0; i--) {
      const t = String(old[i].text || '')
      const s = t.match(/🛠\s*([^\n]{3,60})/); if (s) return s[1].trim()
      if (old[i].dir === 'in' && wantsService(t)) return t.replace(/\s+/g, ' ').slice(0, 60)
    }
    return ''
  }

  /**
   * An old customer writes (owner, 2026-11-21): no welcome as if he were new. Operations are told and get their minutes
   * to answer; nobody did → the bot reads the last contact and welcomes him back from it: the same service again, or a
   * new one.
   */
  async function welcomeBack(c, line, phone, text, name, g, since) {
    if (wantsService(text)) { say(phone, line, 'أهلًا بعودتك' + (g.name ? ' يا ' + g.name : '') + ' 🌷'); return startFrom(store.chat(c.pk), line, phone, text, name) }
    const topic = g.lastService || lastTopic(c.pk, since)
    const L = ['أهلًا بعودتك' + (g.name ? ' يا ' + g.name : '') + ' 🌷 نورتنا تاني في *' + (g.site || cfg().siteName) + '*.']
    if (g.lastService) L.push('آخر مرة نفذنالك «' + g.lastService + '»' + (g.lastDays ? ' من ' + g.lastDays + ' يوم' : '') + (g.warrantyLeft ? '، وضمانها لسه ساري ' + g.warrantyLeft + ' يوم 🛡' : '') + '.')
    else if (topic) L.push('آخر مرة كنا بنتكلم عن «' + topic + '».')
    L.push('', topic ? 'تحب نكمل في نفس الموضوع، ولا محتاج خدمة جديدة؟' : 'تحت أمرك، إيش اللي تحتاجه النهارده؟')
    if (topic) L.push('1️⃣ نفس الخدمة تاني', '2️⃣ خدمة جديدة', '3️⃣ متابعة طلب', '4️⃣ كلّم موظف')
    if (!isOpen()) L.push('', cfg().afterHours.replace(/\{phone\}/g, cfg().emergencyPhone))
    saveFlow(c.pk, topic ? { step: 'back', again: topic } : { step: 'main' })
    return say(phone, line, L.join('\n'))
  }

  /** The site's confirm button sent this from his own WhatsApp: the order is his, confirmed, and on its way. */
  async function claimed(c, line, phone, text, name) {
    const m = T.claimOf(text); if (!m) return false
    let o = null
    try { o = await wp('claim', m.seal ? { seal: m.seal, phone: T.intl(phone), name } : m.num ? { number: m.num, code: m.code, phone: T.intl(phone), name } : { ref: m.ref, phone: T.intl(phone), name }) } catch (e) { log('warn', 'claim: ' + e.message) }
    if (!o) return false
    store.touchChat(c.pk, { welcomedAt: now() })
    setStage(phone, o.id, 'live')
    if (!o.first) { say(phone, line, 'طلبك رقم *' + T.num(o) + '* واصلنا ومأكد ✅ — هنبلغك هنا بكل جديد.'); return true }
    const C = cfg()
    // He just sent us the order text himself: a short receipt, not the same card back.
    const L = (m.seal || m.ref) ? ['تم استلام طلبك رقم *' + T.num(o) + '* ✅ شكرًا لك' + (o.site ? ' في *' + o.site + '*' : '') + '.', ''] : o.card ? [o.card, ''] : ['أهلًا' + (o.clientName || name ? ' ' + (o.clientName || name) : '') + ' 🌷', 'تم تأكيد طلبك رقم *' + T.num(o) + '*' + (o.site ? ' في *' + o.site + '*' : '') + ' ✅', ...T.summary(o), ''];
    L.push(isOpen() ? 'جاري تجهيز الفني، وهنبلغك هنا أول ما يتحرك 🚗' : 'إحنا دلوقتي برا مواعيد العمل، وطلبك متسجل لأقرب موعد الصبح 🌙\nلو الموضوع طارئ اتصل مباشرة على *' + C.emergencyPhone + '*.')
    if (o.url) L.push('🔗 تابع طلبك: ' + o.url)
    toCustomer({ ...o, waLine: line }, L.join('\n'), 'claim-' + o.id)
    toRoom('✅ العميل أكد طلب #' + T.num(o) + ' من واتساب — ' + T.intl(phone) + (name ? ' (' + name + ')' : ''), 'claim-' + o.id)
    return true
  }

  /** The same answers as the site chat (the site's shared brain): an order by its number, payment, warranty, offers. */
  async function sharedAnswer(line, phone, text) {
    const n = T.norm(text); const it = T.intent(text)
    if (!(/(طلب|رقم|#)\s*#?\s*\d{3,7}/.test(n) || ['pay', 'warranty'].includes(it) || /^(العروض|عروض|الخصومات|في عروض|فيه عروض|عندكم عروض|طرق الدفع|الضمان)$/.test(n) || /طرق الدفع|طريقه الدفع|رابط الدفع/.test(n))) return false
    // «طرق الدفع» again (owner, 2026-11-23): the payment-methods link of his unpaid order is sent anew — it is deleted for
    // everyone two days after each send (revoke.js), so asking is how he gets it back.
    if (it === 'pay' || /طرق الدفع|طريقه الدفع|رابط الدفع|حساباتكم|رقم الحساب/.test(n)) {
      let pl = null
      try { pl = await wp('paylink', { phone: T.intl(phone) }) } catch (e) { log('warn', 'paylink: ' + e.message) }
      if (pl && pl.text) { send(T.intl(phone), pl.text, { line, purpose: 'paylink' }); return true }
    }
    let a = null
    try { a = await wp('answer', { phone: T.intl(phone), text, brand: store.line?.(line)?.brand || '' }) } catch (e) { log('warn', 'answer: ' + e.message) }
    if (!a || !a.reply) return false
    say(phone, line, a.reply)
    return true
  }

  async function customer({ c, line, phone, text, name }) {
    const C = cfg()
    // a friend's referral link (idea 20): the code is remembered on the site; his order is marked when it comes
    if (/كود الترشيح\s*R[0-9A-F]{6}/.test(text)) wp('referral', { phone: T.intl(phone), text }).catch(() => {})
    if (await claimed(c, line, phone, text, name)) return
    // «عايز أكلمكم / رقمكم إيه» (owner, 2026-11-23): the hotline link of his own site — a free call straight to operations
    if (/(اكلمكم|اكلم حد منكم|اتصل بيكم|اتصل عليكم|رقمكم|رقم الشركه|رقم التواصل|كلموني|اتصلوا بي|مكالمه|خط ساخن|الخط الساخن|ارن عليكم|ممكن اتصل)/.test(T.norm(text)) && !/رقم الفني|الفني/.test(T.norm(text))) {
      let h = null; try { h = await wp('hotline', { phone: T.intl(phone), brand: store.line?.(line)?.brand || '' }) } catch {}
      if (h && h.url) return say(phone, line, '📞 تقدر تكلمنا مباشرة دلوقتي — مكالمة مجانية عن طريق النت، بتوصلك على طول بالعمليات:\n' + h.url + '\n\nأو اكتب لنا هنا وإحنا معاك 🌷')
    }
    if (M.isAbuse(text) && !humanActive(c, 10 * 60000)) return abuse(c, line, phone, text)
    if (c.welcomedAt && !humanActive(c, 10 * 60000) && await sharedAnswer(line, phone, text)) return
    // The menu: in the middle of a booking it has the floor; at the main menu it takes the numbers — unless an order's
    // own question (a rating, why he cancelled, «تأكيد») is waiting for a digit.
    const fm = flowOf(c)
    if (fm && !humanActive(c, 10 * 60000) && !(c.botOff && c.botOff > now())) {
      const js = stageOf(phone)?.s
      const digit = M.isMenu(text) || /^\d$/.test(T.norm(text).trim())
      if (fm.step !== 'home' || (digit && !['pay', 'paybank', 'rate', 'traits', 'winback', 'winback_time', 'confirm'].includes(js))) return flow(c, line, phone, text, name, fm)
    }
    const j = stageOf(phone)
    // A new service while he has an open order (live test 2026-11-24: «عايز أغسل مكيف شباك» went to the technician as a
    // message): a new booking, said plainly — his open order stays as it is.
    if (j && j.o && ['live', 'confirm'].includes(j.s) && (M.parseAc(text).found || /(عايز|عاوز|ابغي|ابغى|محتاج|حابب)\s*(اغسل|غسيل|اركب|تركيب|اصلح|صيانه|صيانة|افك|فك|انقل|نقل)/.test(T.norm(text)))) {
      say(phone, line, 'تمام 🌷 ده هيبقى *طلب جديد*، وطلبك المفتوح زي ما هو.')
      return startFrom(c, line, phone, text, name)
    }
    if (j && j.o) {
      // The order's own conversation: at once — unless a person is talking to him right now.
      const go = async (t) => {
        let o = null
        try { o = await wp('order', { id: j.o }) } catch (e) { log('warn', 'order ' + e.message) }
        if (!o) { dropStage(phone); return reception(store.chat(c.pk), line, phone, t, name) }
        const reply = await journey(j.s, o, t, phone)
        if (reply !== null) { if (reply) toCustomer({ ...o, waLine: line }, reply); return }
        return reception(store.chat(c.pk), line, phone, t, name)
      }
      // the bot's own question asked AFTER the person spoke (how he pays, a rating…): his answer is to the bot — no wait
      // (live test 2026-11-24: «2» to «حابب تدفع إزاي؟» sat on hold two minutes because a person wrote before the question)
      const askedAfter = (c.stageAt || 0) > (c.humanAt || 0) && ['pay', 'paybank', 'rate', 'traits', 'confirm'].includes(j.s)
      if (humanActive(c, 10 * 60000) && !askedAfter) return holdThen(c.pk, text, C.humanFirstSec * 1000, go)
      return go(text)
    }
    if (c.botOff && c.botOff > now()) return notifyHuman(c, text, false)
    if (!C.autoReply) return notifyHuman(c, text, false)

    const g = await greetOf(c, text)
    // Someone the phone knows by name, who did not come through our sites and never ordered: personal — left alone.
    // (not someone the bot already served — a quote or a booking talk with him — and not the owner's test phone: a bare
    // «1» after an expired menu made a real conversation «personal» and the bot went silent for good, live test 2026-11-23)
    const served = !!c.welcomedAt || store.msgs(c.pk, 40).some((m) => m.dir === 'out' && m.by === 'reply')
    const testing = C.testPhone && T.pkey(C.testPhone) === c.pk
    if (C.ignoreContacts && c.contact && !served && !testing && !g.fromUs && (g.kind || 'new') === 'new' && !SERVICE_WORDS.some((w) => T.norm(text).includes(T.norm(w)))) {
      store.touchChat(c.pk, { kind: 'personal' }); log('chat', 'اتجاهل (من الأسماء المتسجلة): ' + c.contact)
      return
    }
    const first = !c.welcomedAt || now() - c.welcomedAt > WELCOME_EVERY
    const back = ['returning', 'vip'].includes(g.kind) || !!c.welcomedAt
    if (first && back && !(EMERGENCY.test(T.norm(text)) && !isOpen())) {
      // An old customer: operations first. They hear he is back, and get their minutes before the bot says anything.
      const since = now()
      store.touchChat(c.pk, { welcomedAt: since })
      notifyHuman(store.chat(c.pk), '↩️ عميل قديم رجع' + (g.lastService ? ' (آخر خدمة: ' + g.lastService + ')' : '') + ': ' + text, false)
      return holdThen(c.pk, text, Math.max(0, Number(C.returningWaitSec ?? 180)) * 1000, (t) => welcomeBack(store.chat(c.pk), line, phone, t, name, g, since))
    }
    if (first) {
      store.touchChat(c.pk, { welcomedAt: now() })
      // He said what he wants: one line of welcome, then straight to it. A bare greeting: the welcome and the sections.
      if (!isOpen() && EMERGENCY.test(T.norm(text))) { say(phone, line, hello(g)); return reception(store.chat(c.pk), line, phone, text, name) }
      if (wantsService(text)) { say(phone, line, hello(g)); return startFrom(store.chat(c.pk), line, phone, text, name) }
      saveFlow(c.pk, { step: 'main' })
      say(phone, line, welcome(g))
      return
    }
    if (humanActive(c, 30 * 60000)) return holdThen(c.pk, text, C.humanFirstSec * 1000, (t) => reception(store.chat(c.pk), line, phone, t, name))
    return reception(c, line, phone, text, name)
  }

  /** The conversation itself: the site's AI when the owner has one, the hub's own rules otherwise. */
  async function reception(c, line, phone, text, name) {
    const C = cfg()
    const open = isOpen()
    const n = T.norm(text); const it = T.intent(text)
    if (!open && EMERGENCY.test(n)) {
      toRoom('🚨 *طارئ برا مواعيد العمل*\n👤 ' + (c.name || 'بدون اسم') + ' — ' + c.phone + '\n💬 «' + text.slice(0, 200) + '»', 'emg-' + c.pk + '-' + Math.floor(now() / 1800000))
      return say(phone, line, 'سلامتك 🙏 ده يبان طارئ ومحتاج تدخل سريع — اتصل بينا حالًا على *' + C.emergencyPhone + '* وهنتصرف فورًا.\nولو حابب، ابعت الموقع هنا وأنا هسجل البيانات للفني.')
    }
    if (await sharedAnswer(line, phone, text)) return
    // His order is open: answer about it.
    if (['where', 'urgent', 'status', 'cancel', 'postpone', 'request', 'warranty', 'pay'].includes(it) || n === '3') {
      let o = null
      try { o = await wp('order', { phone: T.intl(phone), open: true }) } catch {}
      if (o) { const st = o.customerConfirmed || o.status !== 'new' ? 'live' : 'confirm'; setStage(phone, o.id, st); const r = await journey(st, o, text, phone); if (r) toCustomer({ ...o, waLine: line }, r); return }
      if (n === '3') return say(phone, line, 'مفيش طلب مفتوح على رقمك دلوقتي 🌷 قولّي الخدمة اللي محتاجها والحي، وأنا أجهز طلبك.')
    }
    if (it === 'human') return notifyHuman(c, text, true)
    if (M.isMenu(text) || /^[1-9]$/.test(n)) return flow(c, line, phone, text, name, flowOf(c) || { step: 'main' })
    if (wantsService(text)) return startFrom(c, line, phone, text, name)

    // The site's AI: understands the request, prices and bargains within the owner's floor, books, hands over.
    const g = await greetOf(c, text)
    let L = {}; try { L = JSON.parse(c.lead || '{}') } catch {}
    let ai = null
    try {
      const hist = store.msgs(c.pk, 16).map((m) => (m.dir === 'in' ? 'العميل' : 'الشركة') + ': ' + String(m.text).slice(0, 300))
      ai = await wp('converse', { phone: c.phone, text, history: hist, lead: L, site: g.site, brief: g.brief, source: g.source, afterHours: !open, emergencyPhone: C.emergencyPhone, hoursFrom: C.hoursFrom, hoursTo: C.hoursTo })
    } catch (e) { log('warn', 'converse: ' + e.message) }
    if (ai && ai.reply) {
      store.touchChat(c.pk, { lead: JSON.stringify(ai.lead || {}) })
      say(phone, line, ai.reply)
      if (ai.handoff) notifyHuman(store.chat(c.pk), ai.reason || text, false)
      // The model never books nor prices: what it understood starts the menu, where the price is the owner's table.
      if (ai.complete && ai.lead && ai.lead.service) return startFrom(store.chat(c.pk), line, phone, [ai.lead.service, ai.lead.note].filter(Boolean).join(' '), ai.lead.name || name, { place: ai.lead.area || '' })
      return
    }
    // No AI: the hub's own rules — the price and the bargaining first, from the owner's own price lists.
    const deal = await negotiate(c, line, phone, text)
    if (deal) return
    if (n === '2') return say(phone, line, await infoOr('الأسعار', C.prices))
    if (n === '1' || SERVICE_WORDS.some((w) => n.includes(T.norm(w))) || (c.stage === 'lead')) return lead(c, line, phone, n === '1' ? '' : text, name, c.stage !== 'lead')
    try { const r = await wp('info', { text }); if (r && r.text) return say(phone, line, r.text) } catch {}
    return notifyHuman(c, text, false)
  }


  /* ------------------------------------------------------------------ the menu: the customer picks (owner, 2026-11-21) */

  const FLOW_TTL = 6 * 3600000
  const PIN_ASK = '📍 ابعت *اللوكيشن نفسه* من واتساب: 📎 ← الموقع ← إرسال موقعي الحالي.\nاسم الحي لوحده مش كفاية — الفني بيوصلك باللوكيشن.'
  const REPAIR = /(عطل|عطلان|خربان|ما يبرد|مش بيبرد|ما يشتغل|ما يشغل|صيانه|اصلاح|تصليح|يقطر|ينقط|صوت|ريحه|فريون|كود|بيفصل|يفصل)/
  const INSTALL = /(تركيب|ركب|فك|نقل|اركب)/
  const PARTS = /(قطع غيار|قطع الغيار|سعر القطع|اسعار القطع|سعر القطعه|مكثف|كباستور|كابستور|بطاريه المكيف|ترموستات|ثرموستات|كونتاكتور|كنتاكتور|شريحه|بورده|موتور|ماتور|ريش المروحه|كمبروسر|كمبريسور|كمبرسور|ضاغط|فريون|كونتكتر|كنتكتر|فانه|الفان|دينمو|دنمو|دينامو)/
  const hourNow = () => Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Asia/Riyadh' }).format(new Date(now())))
  function flowOf(c) { let L = {}; try { L = JSON.parse(c?.lead || '{}') } catch {} const m = L.m; return m && m.step && now() - (m.at || 0) < FLOW_TTL ? m : null }
  function saveFlow(pk, m) {
    const c = store.chat(pk); let L = {}; try { L = JSON.parse(c?.lead || '{}') } catch {}
    if (m) { m.at = now(); L.m = m } else delete L.m
    store.touchChat(pk, { lead: JSON.stringify(L) })
  }
  function wantsService(text) {
    const n = T.norm(text)
    return M.parseAc(text).found || T.isPriceAsk(text) || PARTS.test(n) || SERVICE_WORDS.some((w) => n.includes(T.norm(w))) || M.sectionOf(text) > 0
  }

  async function abuse(c, line, phone, text) {
    store.touchChat(c.pk, { botOff: now() + 30 * 60000 })
    saveFlow(c.pk, null)
    toRoom('🔴 *عميل متضايق* — ' + (c.name || '—') + ' — ' + c.phone + '\n💬 «' + String(text).slice(0, 200) + '»\n\nالبوت وقف معاه نص ساعة — كلّمه: *عميل ' + c.phone + ' النص*', 'abuse-' + c.pk + '-' + Math.floor(now() / 1800000))
    notifyHuman(store.chat(c.pk), text, false)
    return say(phone, line, 'آسفين جدًا على اللخبطة 🙏 حقك علينا.\nحوّلت محادثتك لموظف خدمة العملاء وهيكلمك بنفسه حالًا.')
  }

  /** From his own words («غسيل مكيف اسبليت و مكيف شباك بكام»): straight to the right step, nothing guessed. */
  async function startFrom(c, line, phone, text, name, extra = {}) {
    const n = T.norm(text); const pa = M.parseAc(text)
    // Not an AC job (plumbing, electricity, carpentry, cameras…): that section's own services, never the AC wash.
    const sec = M.sectionOf(text)
    if ((cfg().offSections || []).includes(sec || 1)) { saveFlow(c.pk, null); say(phone, line, M.STOPPED); return notifyHuman(c, 'طلب خدمة متوقفة: ' + text, false) }
    if (sec && !pa.found) { saveFlow(c.pk, { step: 'sub', sec, ...extra }); return say(phone, line, M.subMenu(sec) + M.FOOT) }
    if (PARTS.test(n)) return partAnswer(c, line, phone, text)
    if (REPAIR.test(n) && !/(غسيل|غسل|اغسل|تنظيف)/.test(n)) return flow(c, line, phone, '2', name, { step: 'home', ...extra, pre: text })
    if (INSTALL.test(n) && !/(غسيل|غسل|اغسل)/.test(n)) return flow(c, line, phone, '3', name, { step: 'home', ...extra })
    const m = { step: 'wash_type', svc: 'wash', spec: 'ac', items: {}, ...extra }
    // duct / concealed / package / central: surveyed first, never priced from the split table
    if (pa.big > 0) { Object.assign(m, { acType: pa.bigType, count: pa.big }); return bigWash(c, line, phone, m) }
    if (pa.otherType) m.acType = pa.otherType
    if (pa.units > 0) { m.items = { split: pa.split, window: pa.window, other: pa.other }; return priceStep(c, line, phone, m) }
    if (pa.any) m.anyCount = pa.any
    saveFlow(c.pk, m)
    return say(phone, line, '🧽 *غسيل مكيفات* — نوع المكيفات إيه؟\n' + M.TYPES + M.FOOT)
  }

  /** «بكام المكثف؟» — that repair's price only (and its photo), never a parts list, never «price of the part». */
  async function partAnswer(c, line, phone, text) {
    let r = null; try { r = await wp('parts', { text }) } catch {}
    saveFlow(c.pk, { step: 'part', strategy: r?.strategy || '' })
    const body = (r?.text || 'بنأكدلك السعر بعد الكشف 🙏') + '\n\n1️⃣ احجز الإصلاح (كشف وتركيب)\n2️⃣ احجز غسيل' + M.FOOT
    return sayImg(phone, line, body, r?.image || '')
  }

  /** Washing a duct / concealed / package / central unit: the count, then the survey — no table price. */
  async function bigWash(c, line, phone, m) {
    const nm = M.AC_NAMES[m.acType] || 'مخفي'
    if (!m.count) { m.step = 'big_count'; saveFlow(c.pk, m); return say(phone, line, 'كام وحدة ' + nm + '؟ (اكتب الرقم)') }
    Object.assign(m, { label: 'غسيل ' + m.count + ' مكيف ' + nm, priceLater: true })
    // the owner's rough range + inspection fee for this type, when set (owner, 2026-11-23) — instead of a bare «بعد المعاينة»
    let bp = ''; try { const r = await wp('big_price', { type: m.acType || 'concealed', count: m.count }); bp = (r && r.text) || '' } catch {}
    return askPlace(c, line, phone, m, 'تمام ✅ غسيل الـ' + nm + ' ' + (bp ? '— ' + bp : 'سعره بيتحدد بعد المعاينة (حسب الطن وعدد الفتحات)، والفني بيقولك السعر قبل أي شغل.'))
  }

  async function priceStep(c, line, phone, m, prefix = '') {
    let P = null; try { P = await wp('ac_price', { split: m.items.split || 0, window: m.items.window || 0, other: m.items.other || 0, otherType: m.items.other ? (m.acType || '') : '' }) } catch (e) { log('warn', 'ac_price: ' + e.message) }
    if (!P || !P.total) { saveFlow(c.pk, null); notifyHuman(c, 'ما قدرتش أحسب سعر: ' + JSON.stringify(m.items), false); return say(phone, line, 'ثواني 🙏 موظف هيأكدلك السعر حالًا.') }
    Object.assign(m, { step: 'price', price: P, offer: P.total, round: 0, agreed: null, label: P.label })
    saveFlow(c.pk, m)
    const L = [prefix, '💰 *' + P.label + '*']
    if ((P.lines || []).length > 1) L.push(...P.lines.map((x) => '• ' + x))
    L.push('الإجمالي: *' + P.total + ' ر.س* — غسيل كامل داخلي وخارجي بالمضخة + ضمان ✅')
    // His wash only: the video of the type he asked for (owner, 2026-11-21).
    const vids = (cfg().washVideos || [])
    const v = (m.items.split ? vids.find((x) => /سبليت/.test(x.label)) : null) || (m.items.window ? vids.find((x) => /شباك/.test(x.label)) : null)
    if (v && !m.videoSent) { L.push('🎥 شوف طريقة الغسيل عندنا: ' + v.url); m.videoSent = 1; saveFlow(c.pk, m) }
    // no «عايز سعر أحسن» (owner, 2026-11-23): an open question about the price — the bargaining starts only when he says so
    L.push('السعر ده مناسب لحضرتك؟ 🌷', '', '1️⃣ تمام، كمّل الحجز', '2️⃣ غيّر العدد أو النوع', '3️⃣ كلّم فني مختص')
    return say(phone, line, L.filter(Boolean).join('\n') + M.FOOT)
  }

  function askPlace(c, line, phone, m, prefix = '') {
    m.step = 'place'; saveFlow(c.pk, m)
    if (m.lat !== undefined && m.lat !== null) return askTime(c, line, phone, m, prefix)
    return say(phone, line, (prefix ? prefix + '\n\n' : '') + PIN_ASK)
  }
  function askTime(c, line, phone, m, prefix = '') {
    const tc = M.timeChoices(hourNow()); m.step = 'time'; m.tc = tc.opts; saveFlow(c.pk, m)
    return say(phone, line, (prefix ? prefix + '\n\n' : '') + M.timeText(tc))
  }
  function summary(c, line, phone, m) {
    m.step = 'summary'; saveFlow(c.pk, m)
    const price = m.priceLater ? '💰 السعر بعد المعاينة وقبل أي شغل' : m.svc === 'wash' ? '💰 السعر: *' + (m.agreed || m.offer) + ' ر.س*' : '💰 ' + (m.svc === 'repair' ? (['ac', 'fridge'].includes(m.spec || 'ac') ? 'الكشف مجاني لحد جهازين، والسعر بعد الكشف وقبل أي شغل' : 'السعر بعد المعاينة وقبل أي شغل') : 'السعر بعد المعاينة (حسب النوع وطول النحاس) وقبل أي شغل')
    return say(phone, line, ['📝 *ملخص طلبك*', '🛠 ' + m.label, price, '📍 اللوكيشن اتبعت ✅' + (m.place ? ' (' + m.place + ')' : ''), '📅 ' + m.time, '', '1️⃣ تأكيد الطلب ✅', '2️⃣ تعديل', '3️⃣ إلغاء'].join('\n'))
  }

  async function book(c, line, phone, m, name) {
    if (m.lat === undefined || m.lat === null) { m.step = 'place'; saveFlow(c.pk, m); return say(phone, line, PIN_ASK) }
    let g = null; try { g = JSON.parse(c.greet || 'null') } catch {}
    let o = null
    try {
      o = await wp('create', { phone: T.intl(phone), name: name || c.name || '', service: m.label + (m.desc ? ' — ' + m.desc : ''), address: m.place || '', lat: m.lat ?? null, lng: m.lng ?? null, time: m.time, line,
        mode: m.svc === 'install' ? (m.imode || 'install') : m.svc, acType: m.acType || '', desc: m.desc || '',
        price: m.svc === 'wash' && !m.priceLater ? (m.agreed || m.offer) : null, acQuote: m.svc === 'wash' && !m.priceLater ? (m.price?.acQuote || null) : null, spec: m.spec || 'ac', units: m.price?.units || m.count || null, confirmed: true,
        brand: (g && g.brand) || store.line?.(line)?.brand || '', code: (g && g.code) || '', source: (g && g.source) || '', sourceUrl: (g && g.sourceUrl) || '', platform: (g && g.platform) || '' })
    } catch (e) {
      log('error', 'create: ' + e.message)
      // The site refuses an order without the pin: back to the place step, nothing lost.
      if (/need_location|اللوكيشن/.test(String(e.message))) { m.step = 'place'; delete m.lat; saveFlow(c.pk, m); return say(phone, line, PIN_ASK) }
    }
    saveFlow(c.pk, null)
    if (!o) { notifyHuman(store.chat(c.pk), 'طلب من القائمة ما اتسجلش: ' + m.label + ' — ' + (m.place || '') + ' — ' + m.time, false); return say(phone, line, 'وصلنا طلبك ✅ وموظف هيأكده معاك حالًا.') }
    store.touchChat(c.pk, { stage: 'live', orderId: o.id || '', stageAt: now() })
    // The confirmation carries the whole order as the site saved it: service, A/C type and count, details, address, pin, time, price.
    const det = Array.isArray(o.details) && o.details.length ? o.details : T.summary(o)
    return say(phone, line, ['✅ *تم تأكيد طلبك رقم ' + T.num(o) + '*', ...det, '', 'جاري تجهيز الفني، وهنبلغك هنا أول ما يتحرك 🚗', o.url ? '🔗 تابع طلبك: ' + o.url : '', o.seal ? '🔐 توثيق طلبك (كل اللي اتفقنا عليه): ' + o.seal : '', 'تقدر في أي وقت تكتب *6* لمتابعة طلبك أو تأجيله.'].filter((x) => x !== null && x !== undefined && x !== false).filter((x, i, a) => x !== '' || (i > 0 && a[i - 1] !== '')).join('\n'))
  }

  /** One turn of the menu. m: the conversation's step and what was chosen so far. */
  async function flow(c, line, phone, text, name, m) {
    m = m || { step: 'home' }
    const n = T.norm(text).replace(/[!.،,؟?]/g, '').trim()
    const k = /^\d{1,2}$/.test(n) ? Number(n) : null
    const C = cfg()
    const home = (prefix = '') => { saveFlow(c.pk, { step: 'home' }); return say(phone, line, (prefix ? prefix + '\n\n' : '') + M.HOME) }
    const main = (prefix = '') => { saveFlow(c.pk, { step: 'main' }); return say(phone, line, (prefix ? prefix + '\n\n' : '') + M.sectionsText(C.offSections)) }
    if (M.isMenu(text)) return main()
    if (M.isAbuse(text)) return abuse(c, line, phone, text)
    const pa = M.parseAc(text)
    const writing = ['place', 'time_text', 'rep_desc', 'ins_desc', 'tech_msg', 'postpone_when'].includes(m.step)
    // A change of the units at any point of the wash: priced again from the table, the old offer gone.
    if (m.svc === 'wash' && pa.units > 0 && !writing && k === null) { m.items = { split: pa.split, window: pa.window, other: pa.other }; return priceStep(c, line, phone, m, 'تمام، عدّلت العدد ✅') }
    const coords = T.coords(text)

    switch (m.step) {
      case 'main': {
        // A service the owner stopped from the desk: said once, and a person takes it.
        if (k >= 1 && k <= 7 && (C.offSections || []).includes(k)) { saveFlow(c.pk, null); say(phone, line, M.STOPPED); return notifyHuman(c, 'طلب خدمة متوقفة (القسم ' + k + ')', false) }
        if (k === 1) return home('❄️ *المكيفات*')
        if (M.SUBS[k]) { saveFlow(c.pk, { step: 'sub', sec: k }); return say(phone, line, M.subMenu(k) + M.FOOT) }
        if (k === 8) return flow(c, line, phone, '6', name, { step: 'home' })
        if (k === 9) { saveFlow(c.pk, null); return notifyHuman(c, 'طلب موظف من القائمة', true) }
        if (wantsService(text)) return startFrom(c, line, phone, text, name)
        if (M.isGreeting(text)) return main('تحت أمرك 🌷')
        if (k !== null) return main('اختار رقم القسم 👇')
        // A question in his own words: the conversation (the site's AI), not the menu again.
        saveFlow(c.pk, null)
        return reception(store.chat(c.pk), line, phone, text, name)
      }
      case 'sub': {
        const S = M.SUBS[m.sec]; const it = S && k ? S.items[k - 1] : null
        if (!it) return say(phone, line, (S ? M.subMenu(m.sec) : M.sectionsText(C.offSections)) + M.FOOT)
        Object.assign(m, { step: 'rep_desc', svc: 'repair', spec: it[1], label: S.name + ' — ' + it[0] }); saveFlow(c.pk, m)
        return say(phone, line, 'اكتب تفاصيل المطلوب باختصار 👇\n(مثال: ' + S.ex + ')')
      }
      case 'back': {
        if (k === 1) return startFrom(c, line, phone, m.again || 'غسيل', name)
        if (k === 2) return main()
        if (k === 3) return flow(c, line, phone, '6', name, { step: 'home' })
        if (k === 4) { saveFlow(c.pk, null); return notifyHuman(c, 'عميل قديم طلب موظف', true) }
        if (wantsService(text)) return startFrom(c, line, phone, text, name)
        saveFlow(c.pk, null)
        return reception(store.chat(c.pk), line, phone, text, name)
      }
      case 'home': {
        if (k === 1) return startFrom(c, line, phone, 'غسيل', name)
        if (k === 2) { saveFlow(c.pk, { step: 'rep_type', svc: 'repair', pre: m.pre || '', place: m.place || '' }); return say(phone, line, '🔧 *صيانة وإصلاح* — نوع الجهاز إيه؟\n' + M.KINDS + '\n7️⃣ غرفة تبريد / تجميد\n8️⃣ حاجة تانية (سباكة، كهربا، أجهزة…)' + M.FOOT) }
        if (k === 3) { saveFlow(c.pk, { step: 'ins_type', svc: 'install', spec: 'ac', place: m.place || '' }); return say(phone, line, '🛠 *تركيب / فك ونقل* — نوع المكيف؟\n' + M.KINDS + M.FOOT) }
        if (k === 4) {
          let t = null; try { t = await wp('ac_table', {}) } catch {}
          saveFlow(c.pk, { step: 'table' })
          return say(phone, line, (t?.text || C.prices) + '\n\n1️⃣ احجز غسيل\n2️⃣ أسعار الإصلاح (الفريون والقطع)' + M.FOOT)
        }
        if (k === 5) { saveFlow(c.pk, { step: 'table' }); const vs = C.washVideos || []; return say(phone, line, (vs.length ? '🎥 *طريقة الغسيل عندنا*\n' + vs.map((v) => '▶️ ' + v.label + ':\n' + v.url).join('\n') : '🧼 *طريقة الغسيل عندنا*: غسيل كامل داخلي وخارجي بالمضخة، وتنظيف الفلاتر والصينية، + ضمان ✅') + '\n\n1️⃣ احجز غسيل' + M.FOOT) }
        if (k === 6) {
          let o = null; try { o = await wp('order', { phone: T.intl(phone), open: true }) } catch {}
          if (!o) return home('مفيش طلب مفتوح على رقمك دلوقتي 🌷')
          saveFlow(c.pk, { step: 'myorder', oid: o.id })
          return say(phone, line, (o.statusText || ('📋 طلب #' + T.num(o) + ' — ' + (o.statusLabel || o.status))) + (o.techName ? '\n👷 الفني: ' + o.techName : '') + '\n\n1️⃣ الفني فين؟\n2️⃣ تأجيل / تغيير الموعد\n3️⃣ إلغاء الطلب\n4️⃣ رسالة للفني' + M.FOOT)
        }
        if (k === 7) { saveFlow(c.pk, null); return notifyHuman(c, 'طلب الدعم الفني من القائمة', true) }
        if (wantsService(text)) return startFrom(c, line, phone, text, name)
        return home('اختار رقم من القائمة 👇')
      }
      case 'part': {
        if (k === 1) return flow(c, line, phone, '2', name, { step: 'home' })
        if (k === 2) return startFrom(c, line, phone, 'غسيل', name)
        // Haggling over a part, or «I just want the part»: one line — we sell the repair, not the part.
        if (T.isHaggle(text) || T.moneyIn(text) || /(القطعه بس|قطعه بس|ابيع|اشتري|اشتريها|بجيبها|هجيبها|اجيبها|عندي القطعه|في المحل ب|ارخص في المحل|المحل ب)/.test(n)) {
          return say(phone, line, (m.strategy || 'إحنا شركة صيانة مش محل قطع غيار 🙏 بنجيب القطعة ونركّبها ونضمنها.') + '\n\n1️⃣ احجز الإصلاح' + M.FOOT)
        }
        if (PARTS.test(n)) return partAnswer(c, line, phone, text)
        return flow(c, line, phone, text, name, { step: 'home' })
      }
      case 'table': {
        if (k === 1) return startFrom(c, line, phone, 'غسيل', name)
        if (k === 2) { let r = null; try { r = await wp('parts', { text: '' }) } catch {} saveFlow(c.pk, { step: 'part', strategy: r?.strategy || '' }); return say(phone, line, (r?.text || '') + '\n\n1️⃣ احجز الإصلاح' + M.FOOT) }
        return flow(c, line, phone, text, name, { step: 'home' })
      }
      case 'wash_type': {
        if (k === 6 || k === 7) { Object.assign(m, { acType: k === 6 ? 'concealed' : 'package', count: 0 }); return bigWash(c, line, phone, m) }
        const pick = { 1: { split: 1 }, 2: { window: 1 }, 3: { split: 1, window: 1 }, 4: { other: 1 }, 5: { other: 1 } }[k]
        if (!pick) return say(phone, line, 'اختار نوع المكيفات برقم 👇\n' + M.TYPES + M.FOOT)
        m.acType = { 1: 'split', 2: 'window', 4: 'standing', 5: 'cassette' }[k] || ''
        m.want = Object.keys(pick); m.items = {}
        if (m.anyCount && m.want.length === 1) { m.items[m.want[0]] = m.anyCount; return priceStep(c, line, phone, m) }
        return askCount(c, line, phone, m)
      }
      case 'big_count': {
        const q = M.numberIn(text)
        if (!q || q > 50) return say(phone, line, 'اكتب العدد بالأرقام (مثال: 2)')
        m.count = q; return bigWash(c, line, phone, m)
      }
      case 'count': {
        const q = M.numberIn(text)
        if (!q || q > 50) return say(phone, line, 'اكتب العدد بالأرقام (مثال: 2)')
        m.items[m.asking] = q
        return askCount(c, line, phone, m)
      }
      case 'price': {
        // «غالي يا خي 160» names our own price while complaining: that is not a yes.
        let money = T.moneyIn(text)
        if (money && T.isHaggle(text) && money >= m.offer) money = null
        if (k === 1 || (T.isYes(text) && !money)) { m.agreed = m.agreed || m.offer; return askPlace(c, line, phone, m, 'تمام ✅ اتفقنا على *' + m.agreed + ' ر.س*.') }
        if (k === 2) { m.step = 'wash_type'; m.items = {}; saveFlow(c.pk, m); return say(phone, line, 'نوع المكيفات إيه؟\n' + M.TYPES + M.FOOT) }
        // a specialist (owner, 2026-11-23): at the wall of the bargaining, or whenever he asks — with the deal so far
        if (k === 3 || k === 7) {
          saveFlow(c.pk, null)
          notifyHuman(c, (m.wall ? '💸 عميل وصل لأقل سعر وعايز عرض أحسن' : '🙋 عميل عايز يكلم فني مختص وقت السعر') + ' — ' + m.price.label + ': الرسمي ' + m.price.total + '، آخر عرض ' + m.offer + ' ر.س', false)
          return say(phone, line, 'تمام 🌷 حوّلتك لفني مختص هيشوفلك العروض المتاحة النهارده ويكلمك هنا حالًا.')
        }
        if (money || T.isHaggle(text)) {
          const h = M.haggle(m, money)
          if (h.agreed) return askPlace(c, line, phone, m, h.text)
          saveFlow(c.pk, m)
          return say(phone, line, h.text + '\n\n1️⃣ تمام بـ ' + m.offer + ' ر.س، كمّل الحجز\n2️⃣ غيّر العدد أو النوع\n3️⃣ كلّم فني مختص' + M.FOOT)
        }
        return say(phone, line, 'السعر الحالي *' + m.offer + ' ر.س* لـ' + m.price.label + ' — مناسب لحضرتك؟ 🌷\n\n'+'1️⃣ تمام، كمّل الحجز\n2️⃣ غيّر العدد أو النوع\n3️⃣ كلّم فني مختص' + M.FOOT)
      }
      case 'rep_type': {
        const ty = M.KIND_OF[k]
        const t = ty ? ['ac', 'مكيف ' + M.AC_NAMES[ty]] : { 7: ['fridge', 'غرفة تبريد / تجميد'], 8: ['general', 'صيانة عامة'] }[k]
        if (!t) return say(phone, line, 'اختار نوع الجهاز برقم من 1 لـ 8 👇' + M.FOOT)
        Object.assign(m, { spec: t[0], acType: ty || '', label: 'صيانة وإصلاح — ' + t[1], step: 'rep_desc' }); saveFlow(c.pk, m)
        return say(phone, line, 'اكتب المشكلة باختصار 👇\n(مثال: مش بيبرد، بيقطّر مية، صوت عالي، بيفصل، كود E4)')
      }
      case 'rep_desc': {
        m.desc = String(text).slice(0, 200)
        let parts = ''
        if (PARTS.test(T.norm(text)) || /فريون/.test(T.norm(text))) { try { const pr = await wp('parts', { text }); if (pr?.parts?.length) parts = pr.text } catch {} }
        const acLike = ['ac', 'fridge'].includes(m.spec || 'ac')
        return askPlace(c, line, phone, m, (acLike ? 'تمام ✅ الفني هيكشف ويحدد العطل ويقولك السعر قبل أي شغل — والكشف مجاني لحد جهازين.' : 'تمام ✅ الفني هيعاين ويقولك السعر قبل أي شغل.') + (parts ? '\n\n' + parts : ''))
      }
      case 'ins_type': {
        const ty = M.KIND_OF[k]
        if (!ty) return say(phone, line, 'اختار نوع المكيف برقم 👇\n' + M.KINDS + M.FOOT)
        Object.assign(m, { itype: M.AC_NAMES[ty], acType: ty, step: 'ins_kind' }); saveFlow(c.pk, m)
        return say(phone, line, 'المطلوب إيه؟\n1️⃣ تركيب جديد\n2️⃣ فك وتركيب في مكان تاني\n3️⃣ فك بس' + M.FOOT)
      }
      case 'ins_kind': {
        const t = { 1: 'تركيب', 2: 'فك وتركيب', 3: 'فك' }[k]
        if (!t) return say(phone, line, 'اختار برقم 👇\n1️⃣ تركيب جديد\n2️⃣ فك وتركيب في مكان تاني\n3️⃣ فك بس' + M.FOOT)
        Object.assign(m, { ikind: t, imode: { 1: 'install', 2: 'move', 3: 'remove' }[k], step: 'ins_count' }); saveFlow(c.pk, m)
        return say(phone, line, 'كام جهاز؟ (اكتب الرقم)')
      }
      case 'ins_count': {
        const q = M.numberIn(text)
        if (!q || q > 50) return say(phone, line, 'اكتب العدد بالأرقام (مثال: 1)')
        Object.assign(m, { count: q, label: m.ikind + ' ' + q + ' مكيف ' + m.itype })
        return askPlace(c, line, phone, m, 'تمام ✅ السعر بيتحدد حسب النوع وطول النحاس، والفني بيأكده معاك قبل أي شغل. ولو حابب تبعت صورة للمكيف ومكانه هنا نقولك السعر أدق.')
      }
      case 'place': {
        if (coords) { m.lat = coords[0]; m.lng = coords[1]; return askTime(c, line, phone, m, 'وصل اللوكيشن ✅') }
        // A written address is kept as a note, but the order waits for the pin.
        if (String(text).trim().length >= 3 && k === null) { m.place = String(text).slice(0, 160); saveFlow(c.pk, m); return say(phone, line, 'تمام، سجلت «' + m.place + '» كملاحظة ✅\n' + PIN_ASK) }
        return say(phone, line, PIN_ASK)
      }
      case 'time': {
        const opts = m.tc || []
        const o = k ? opts[k - 1] : null
        if (o === '__other') { m.step = 'time_text'; saveFlow(c.pk, m); return say(phone, line, 'اكتب الموعد اللي يناسبك 👇 (مثال: بكره بعد العصر، السبت الساعة 5)') }
        if (o) { m.time = o; return summary(c, line, phone, m) }
        if (T.looksLikeTime(text)) { m.time = String(text).slice(0, 120); return summary(c, line, phone, m) }
        return askTime(c, line, phone, m)
      }
      case 'time_text': { m.time = String(text).slice(0, 120); return summary(c, line, phone, m) }
      case 'summary': {
        if (k === 1 || T.isYes(text) || /تاكيد|اكد/.test(n)) return book(c, line, phone, m, name)
        if (k === 2) return startFrom(c, line, phone, m.svc === 'repair' ? 'صيانه' : m.svc === 'install' ? 'تركيب' : 'غسيل', name, { place: m.place, lat: m.lat, lng: m.lng })
        if (k === 3) { saveFlow(c.pk, null); return say(phone, line, 'تمام، اتلغى الطلب ده 🌷 ولو احتجت أي حاجة اكتب *0* للقائمة.') }
        return say(phone, line, 'اختار برقم 👇\n1️⃣ تأكيد الطلب ✅\n2️⃣ تعديل\n3️⃣ إلغاء')
      }
      case 'myorder': {
        let o = null; try { o = await wp('order', { id: m.oid }) } catch {}
        if (!o) return home('مش لاقي الطلب ده دلوقتي 🙏')
        if (k === 1) { saveFlow(c.pk, { step: 'home' }); const r = await liveStage(o, 'الفني فين', phone); return say(phone, line, (r || 'بنتابع مع الفني حالًا 🙏') + M.FOOT) }
        if (k === 2) { saveFlow(c.pk, { ...m, step: 'postpone_when' }); return say(phone, line, 'اكتب الموعد الجديد اللي يناسبك 👇') }
        if (k === 3) { saveFlow(c.pk, { ...m, step: 'cancel_sure' }); return say(phone, line, 'متأكد إنك عايز تلغي طلب #' + T.num(o) + '؟\n1️⃣ أيوه، الغيه\n2️⃣ لا، خليه') }
        if (k === 4) { saveFlow(c.pk, { ...m, step: 'tech_msg' }); return say(phone, line, 'اكتب رسالتك للفني 👇') }
        return say(phone, line, '1️⃣ الفني فين؟\n2️⃣ تأجيل / تغيير الموعد\n3️⃣ إلغاء الطلب\n4️⃣ رسالة للفني' + M.FOOT)
      }
      case 'postpone_when': case 'cancel_sure': case 'tech_msg': {
        let o = null; try { o = await wp('order', { id: m.oid }) } catch {}
        saveFlow(c.pk, { step: 'home' })
        if (!o) return home('مش لاقي الطلب ده دلوقتي 🙏')
        if (m.step === 'cancel_sure') {
          if (k !== 1) return say(phone, line, 'تمام، طلبك مستمر ✅' + M.FOOT)
          const r = (await siteCancel(o, 'العميل اختار إلغاء الطلب من القائمة')) || (await liveStage(o, 'الغي الطلب', phone))
          return say(phone, line, r || 'وصل طلب الإلغاء ✅')
        }
        const r = await liveStage(o, (m.step === 'postpone_when' ? 'عايز اغير الموعد: ' : 'قول للفني: ') + String(text).slice(0, 300), phone)
        return say(phone, line, (r || 'وصلت ✅') + M.FOOT)
      }
      default: return home()
    }
  }

  function askCount(c, line, phone, m) {
    const next = (m.want || []).find((t) => !m.items[t])
    if (!next) return priceStep(c, line, phone, m)
    m.step = 'count'; m.asking = next; saveFlow(c.pk, m)
    const name = { split: 'سبليت', window: 'شباك', other: M.AC_NAMES[m.acType] || 'دولابي' }[next]
    return say(phone, line, 'كام مكيف ' + name + '؟ (اكتب الرقم)')
  }

  /* ------------------------------------------------------------------ the price and the bargaining */

  const dealOf = (q) => ({ spec: q.spec, label: q.label, fixed: !!q.fixed, total: q.total || q.from, floor: q.floor || q.total || q.from, offer: q.total || q.from, round: 0, acQuote: q.acQuote || null, at: now() })
  function quoteText(q) {
    if (q.fixed) return '💰 ' + q.label + ': *' + q.total + ' ر.س*\nشامل الغسيل الكامل للوحدة الداخلية والخارجية ✅ والمعاينة مجانية.'
    return '💰 ' + q.label + ' تبدأ من *' + (q.from || q.total) + ' ر.س*\n' + (q.inspect || 'والسعر النهائي بعد المعاينة.')
  }
  const r5 = (v) => Math.max(5, Math.round(v / 5) * 5)

  /** The next thing the order still needs, as one short question — or null when it is complete. */
  function nextAsk(L) {
    if (!L.service) return 'اكتب الخدمة اللي محتاجها بالتفصيل (نوع وعدد الأجهزة).'
    if (L.lat === undefined && !L.place) return '📍 ابعتلي الحي أو اللوكيشن ونثبت الحجز.'
    if (!L.time) return isOpen() ? '📅 الموعد المناسب ليك إمتى؟' : '🌙 إحنا دلوقتي برا مواعيد العمل؛ نحجزلك *بكرة الصبح من الساعة ' + cfg().hoursFrom + '*؟'
    return null
  }

  /**
   * «بكام؟» / «غالي» / «خليها 150» — the price from the owner's lists, then down in steps to his floor and no further:
   * first a step, then the floor as the last word, then an apology and the support team (special offers for valued
   * customers). A price he names at or above what is on the table is taken; once we moved, anything at the floor is.
   */
  async function negotiate(c, line, phone, text) {
    let L = {}; try { L = JSON.parse(c.lead || '{}') } catch {}
    let d = L.deal || null
    const ask = T.isPriceAsk(text); const haggle = T.isHaggle(text); const offered = T.moneyIn(text)
    const save = () => store.touchChat(c.pk, { lead: JSON.stringify(L), stage: 'lead', stageAt: now(), orderId: '' })
    const reply = (msg) => { const nx = nextAsk(L); say(phone, line, msg + (nx ? '\n\n' + nx : '\n\nاكتب *تمام* ونسجّل طلبك حالًا ✅')); return true }

    if (ask && (!d || SERVICE_WORDS.some((w) => T.norm(text).includes(T.norm(w))))) {
      let q = null; try { q = await wp('quote', { text: text + ' ' + (L.service || '') }) } catch {}
      if (q && !(q.total || q.from)) q = null
      if (!q) {
        if (!d) { const own = await infoOr(text, ''); if (own) { say(phone, line, own); return true } say(phone, line, 'أسعارنا بتتحدد حسب نوع الخدمة وعدد الأجهزة 🌷 قولّي الخدمة اللي محتاجها بالتفصيل (مثال: غسيل 2 مكيف سبليت) وأقولك السعر على طول.'); return true }
      } else {
        d = L.deal = dealOf(q)
        L.service = q.label   // «غسيل 2 مكيف سبليت», not «بكام…؟»
        save(); return reply(quoteText(q))
      }
    }
    if (!d) return false
    if (d.agreed) {
      if (haggle || offered) { say(phone, line, 'اتفقنا قبل كده على *' + d.agreed + ' ر.س* 🌷 وده أفضل سعر ممكن مع الضمان.'); return true }
      return false
    }
    const agree = (v, why) => {
      d.agreed = v; d.offer = v; L.deal = d; save()
      toRoom('🤝 *اتفاق سعر* — ' + (c.name || 'بدون اسم') + ' — ' + c.phone + '\n' + d.label + ': ' + v + ' ر.س' + (v < d.total ? ' (بدل ' + d.total + ')' : ''), 'deal-' + c.pk + '-' + v)
      return reply((why || 'اتفقنا ✅') + ' *' + v + ' ر.س* لـ' + d.label + '.')
    }
    if (offered) {
      if (offered >= d.offer) return agree(d.offer, 'تمام، نثبّتلك')
      if (offered >= d.floor && d.round >= 1) return agree(r5(offered) > d.offer ? d.offer : Math.max(d.floor, r5(offered)), 'عشان خاطرك 🌷 اتفقنا على')
    }
    if (!haggle && !offered) {
      if (T.isYes(text) && !(L.askedMorning && !L.time) && now() - (d.at || 0) < 6 * 3600000) return agree(d.offer, 'تمام 🌷 اتفقنا على')
      return false
    }
    // He wants less.
    const step = r5((d.total - d.floor) / 2)
    if (d.round === 0 && d.total - d.floor >= 10) {
      d.offer = Math.max(d.floor, d.total - step); d.round = 1; L.deal = d; save()
      return reply('عشانك 🌷 نخليها *' + d.offer + ' ر.س* بدل ' + d.total + '، بنفس الجودة والضمان.')
    }
    if (d.round <= 1) {
      d.offer = d.floor; d.round = 2; L.deal = d; save()
      return reply('آخر كلام يا غالي: *' + d.floor + ' ر.س* — وده أقل سعر نقدر نقدمه مع الحفاظ على جودة الشغل والضمان ✅')
    }
    d.round++; L.deal = d; save()
    toRoom('💬 عميل بيفاصل تحت أقل سعر — ' + (c.name || 'بدون اسم') + ' — ' + c.phone + '\n' + d.label + ': عرضنا ' + d.floor + ' ر.س' + (offered ? '، وهو عايز ' + offered : '') + '\nكلّمه: *عميل ' + c.phone + ' النص*', 'haggle-' + c.pk + '-' + Math.floor(now() / 3600000))
    say(phone, line, 'والله ده أقل سعر عندنا 🙏 جودة الشغل والضمان ما نقدرش نتنازل عنهم.\nبلّغت الدعم الفني، وعندهم أحيانًا عروض خاصة للعملاء المميزين — هيتواصلوا معاك حالًا.\nولو حابب نثبّت على *' + d.floor + ' ر.س* اكتب *تمام* ✅')
    return true
  }

  async function infoOr(text, fallback) { try { const r = await wp('info', { text }); if (r && r.text) return r.text } catch {} return fallback }

  /** A person is needed: the chat is marked, dealt to the next agent, and the agent hears it on WhatsApp. */
  function notifyHuman(c, text, ack) {
    const C = cfg()
    let agent = (C.agents || []).find((a) => a.name === c.agent)
    if (!agent && (C.agents || []).length) {
      const i = (store.get('rr', 0) || 0) % C.agents.length; store.set('rr', i + 1); agent = C.agents[i]
    }
    const fresh = !c.needsHuman || now() - (c.lastAt || 0) > 1800000
    store.touchChat(c.pk, { needsHuman: 1, agent: agent ? agent.name : c.agent })
    // the phones ring for it too (apps' notifications), whatever happens on WhatsApp (owner, 2026-11-22)
    if (fresh) wp('chat_alert', { title: '🙋 عميل محتاج موظف — ' + (c.name || c.phone), body: String(text || '').slice(0, 200) }).catch(() => {})
    if (fresh && C.notifyAgents) {
      const msg = '🙋 *عميل محتاج موظف*\n👤 ' + (c.name || '—') + ' — ' + c.phone + '\n💬 «' + String(text || '').slice(0, 200) + '»\n\nرد عليه: *عميل ' + c.phone + ' النص*'
      if (agent?.phone) send(T.intl(agent.phone), msg, { purpose: 'agent', dedup: 'h-' + c.pk + '-' + Math.floor(now() / 1800000) })
      else toRoom(msg, 'h-' + c.pk + '-' + Math.floor(now() / 1800000))
    }
    if (ack && fresh) return say(c.phone, c.line, 'تمام 🌷 بلّغت موظف خدمة العملاء وهيرد عليك هنا حالًا.')
  }

  /** «عايز أغسل مكيفين في العزيزية بكرة» — the order is built from the chat and handed to the website. */
  async function lead(c, line, phone, text, name, start = false) {
    let L = {}; try { L = JSON.parse(c.lead || '{}') } catch {}
    if (start || !c.stage || c.stage !== 'lead') L = L.deal ? { deal: L.deal, service: L.service } : {}
    if (L.askedMorning && !L.time && T.isYes(text)) { L.time = 'بكرة الصبح من الساعة ' + cfg().hoursFrom; text = '' }
    const cc = T.coords(text)
    if (cc) { L.lat = cc[0]; L.lng = cc[1] }
    else if (text) {
      if (!L.service) L.service = text.slice(0, 200)
      else if (!L.place && !T.looksLikeTime(text)) L.place = text.slice(0, 160)
      else if (!L.time && T.looksLikeTime(text)) L.time = text.slice(0, 120)
      else if (!L.place) L.place = text.slice(0, 160)
      else if (!L.time) L.time = text.slice(0, 120)
    }
    if (L.service && !L.time && T.looksLikeTime(L.service)) L.time = L.service
    store.touchChat(c.pk, { lead: JSON.stringify(L), stage: 'lead', stageAt: now(), orderId: '' })
    if (!L.service) return say(phone, line, 'تمام 🌷 اكتب الخدمة اللي محتاجها (مثال: غسيل 2 مكيف سبليت، أو تسريب مياه).')
    let priced = ''
    if (!L.deal) {
      let q = null; try { q = await wp('quote', { text: L.service }) } catch {}
      if (q && q.fixed && q.total) { L.deal = dealOf(q); store.touchChat(c.pk, { lead: JSON.stringify(L) }); priced = quoteText(q) + '\n\n' }
    }
    if (L.lat === undefined) return say(phone, line, priced + PIN_ASK)
    if (!L.time) {
      if (!isOpen()) {
        if (!L.askedMorning) {
          L.askedMorning = 1; store.touchChat(c.pk, { lead: JSON.stringify(L) })
          return say(phone, line, '🌙 إحنا دلوقتي برا مواعيد العمل، فأقرب موعد *بكرة الصبح من الساعة ' + cfg().hoursFrom + '*. يناسبك؟ اكتب *تمام*، أو اكتب الوقت اللي يناسبك.\nولو الموضوع طارئ اتصل بينا مباشرة على *' + cfg().emergencyPhone + '*.')
        }
      }
      if (!L.time) return say(phone, line, '📅 إيه الموعد المناسب ليك؟ (مثال: اليوم بعد العصر، بكرة الساعة 6، أو أقرب وقت)')
    }
    return createFrom(c, line, phone, L)
  }

  /** The order goes to the website; its «created» report brings the confirmation back to the customer. */
  async function createFrom(c, line, phone, L, fromAi = false) {
    const name = L.name || c.name || ''
    try {
      const d = L.deal || {}
      const price = d.agreed || (d.fixed ? d.offer : null)
      // where he came from travels with the order: the site (#tag or the line's own), the article code and its link
      let g = null; try { g = JSON.parse(c.greet || 'null') } catch {}
      const o = await wp('create', { phone: T.intl(phone), name, service: L.service, address: L.place || '', lat: L.lat ?? null, lng: L.lng ?? null, time: L.time, line, price: price || null, acQuote: d.acQuote || null, spec: d.spec || '',
        brand: (g && g.brand) || store.line?.(line)?.brand || '', code: (g && g.code) || '', source: (g && g.source) || '', sourceUrl: (g && g.sourceUrl) || '', platform: (g && g.platform) || '' })
      store.touchChat(c.pk, { lead: '', stage: 'confirm', orderId: o?.id || '', stageAt: now() })
      // The website reports the new order and the confirmation goes out from created().
    } catch (e) {
      log('error', 'create: ' + e.message)
      notifyHuman(store.chat(c.pk), 'طلب جديد من الشات: ' + L.service + ' — ' + (L.place || 'لوكيشن') + ' — ' + L.time, false)
      return fromAi ? 0 : say(phone, line, 'وصلنا طلبك ✅ وموظف هيأكده معاك حالًا.')
    }
  }

  /* ------------------------------------------------------------------ the order's conversation */

  async function journey(stage, o, text, phone) {
    if (stage === 'confirm') return confirmStage(o, text, phone)
    if (stage === 'pay') return payStage(o, text, phone)
    if (stage === 'paybank') return payBankStage(o, text, phone)
    if (stage === 'rate') return rateStage(o, text, phone)
    if (stage === 'traits') return traitsStage(o, text, phone)
    if (stage === 'winback' || stage === 'winback_time') return winbackStage(o, text, phone, stage)
    return liveStage(o, text, phone)
  }

  /** «الغي» — the site decides by one procedure for every door (cancelled at once before a technician accepted). */
  async function siteCancel(o, text) {
    let r = null
    try { r = await wp('cancel', { id: o.id, text: text.slice(0, 200) }) } catch (e) { log('warn', 'cancel: ' + e.message) }
    if (!r || !r.reply) return null
    const n = T.num(o)
    if (r.done === 'cancelled') toRoom('❌ العميل لغى طلب #' + n + ' على واتساب (قبل ما فني يقبله — اتلغى)\n💬 «' + text.slice(0, 160) + '»\n👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone, 'cancel-' + o.id)
    else if (r.done === 'requested') toRoom('❌ *العميل عايز يلغي طلب #' + n + '* — والفني ' + (o.techName || '') + ' ' + (LABEL_ST[o.status] || o.status) + '\n💬 «' + text.slice(0, 160) + '»\n👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone + '\n\nتأكّد الإلغاء: *الغي ' + n + '* · أو كلّمه: *رد ' + n + ' نص الرسالة*', 'cancel-' + o.id + '-' + T.norm(text).slice(0, 20))
    return r.reply
  }

  async function confirmStage(o, text, phone) {
    if (T.intent(text) === 'cancel') {
      const done = await siteCancel(o, text); if (done) return done
      await wp('patch', { id: o.id, patch: { cancelRequest: { at: new Date(now()).toISOString(), text: text.slice(0, 200), status: o.status } }, step: { kind: 'wa_cancel_request', text: 'العميل طلب الإلغاء قبل التأكيد: ' + text.slice(0, 80) } })
      toRoom('❌ العميل طلب إلغاء طلب #' + T.num(o) + ' قبل التأكيد: «' + text.slice(0, 120) + '»\n\nتأكّد الإلغاء: *الغي ' + T.num(o) + '*', 'cancel-' + o.id)
      return 'وصلني طلب الإلغاء وبلّغت العمليات، وهيتأكد لك حالًا.'
    }
    const patch = {}; const got = []
    const cc = T.coords(text)
    if (cc) { patch.lat = cc[0]; patch.lng = cc[1]; got.push('اللوكيشن') }
    let miss = T.missing({ ...o, ...patch })
    const yes = T.isYes(text)
    if (!cc && !yes) {
      if (miss.includes('time') && T.looksLikeTime(text)) { patch.preferredTime = text.slice(0, 120); got.push('الموعد') }
      else if (miss.includes('place') && text.length >= 4) { patch.address = text.slice(0, 160); got.push('العنوان') }
      else if (text.length >= 2) { patch.note = ((o.note || '') + '\n' + text.slice(0, 200)).trim(); got.push('ملاحظتك') }
    }
    if (Object.keys(patch).length) { o = (await wp('patch', { id: o.id, patch, step: { kind: 'wa_info', text: 'العميل بعت على واتساب: ' + got.join('، ') } })) || { ...o, ...patch } }
    miss = T.missing(o)
    if (yes && !miss.length) return confirm(o, phone)
    const L = []
    if (got.length) L.push('تمام، سجلت ' + got.join(' و') + ' ✅')
    if (miss.length) L.push('لسه ناقصنا:', ...T.missingText(miss))
    else L.push('كده البيانات كاملة:', ...T.summary(o), '', 'اكتب *تأكيد* ✅ ونبدأ نجهز الفني.')
    return L.join('\n')
  }

  async function confirm(o, phone) {
    await wp('patch', { id: o.id, patch: { customerConfirmed: true, confirmedAt: new Date(now()).toISOString(), confirmedVia: 'whatsapp' }, step: { kind: 'wa_confirmed', text: 'العميل أكد الطلب على واتساب' } })
    setStage(phone, o.id, 'live')
    toRoom(['✅ *العميل أكد طلب #' + T.num(o) + '* على واتساب', '👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone, ...T.opsSummary(o), '', o.techId ? '👷 مع الفني: ' + (o.techName || '') : 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*'].join('\n'), 'ok-' + o.id)
    return 'تم تأكيد طلبك ✅\nهنبلغك هنا أول ما الفني يستلم ويتحرك، وتقدر تسألني في أي وقت «الفني فين؟» أو تطلب أي حاجة توصل له.'
  }

  async function liveStage(o, text, phone) {
    if (['completed', 'cancelled'].includes(o.status)) return null
    const cc = T.coords(text)
    if (cc) {
      // Every location he sends is kept on the order (a newer one replaces the old), and goes to the technician and the room.
      const same = Number(o.lat) === cc[0] && Number(o.lng) === cc[1]
      if (!same) await wp('patch', { id: o.id, patch: { lat: cc[0], lng: cc[1] }, step: { kind: 'wa_location', text: 'العميل بعت لوكيشن على واتساب' } })
      const link = 'https://maps.google.com/?q=' + cc[0] + ',' + cc[1]
      if (o.techId && !same) await wp('tell_tech', { id: o.id, text: '📍 العميل بعت اللوكيشن: ' + link })
      if (!same) toRoom('📍 لوكيشن طلب #' + T.num(o) + ' (' + (o.clientName || o.clientPhone || 'العميل') + '): ' + link, 'loc-' + o.id + '-' + cc.join(','))
      return 'وصل اللوكيشن ✅ واتحفظ في طلبك' + (o.techId ? ' وبعته للفني.' : '.')
    }
    let it = T.intent(text)
    const n = T.num(o)
    if (it === 'cancel') {
      const done = await siteCancel(o, text); if (done) return done
      const moving = MOVING.includes(o.status)
      await wp('patch', { id: o.id, patch: { cancelRequest: { at: new Date(now()).toISOString(), text: text.slice(0, 200), status: o.status } }, step: { kind: 'wa_cancel_request', text: 'العميل طلب الإلغاء على واتساب: ' + text.slice(0, 80) } })
      if (o.techId) await wp('tell_tech', { id: o.id, text: '⏸ العميل طلب إلغاء طلب #' + n + (moving ? ' — استنى مكانك' : '') + ' لحد ما العمليات تأكد. هنبلغك حالًا.' })
      toRoom('❌ *العميل عايز يلغي طلب #' + n + '*' + (moving ? ' — والفني ' + (o.techName || '') + ' ' + (LABEL_ST[o.status] || o.status) : '') + '\n💬 «' + text.slice(0, 160) + '»\n👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone + '\n\nتأكّد الإلغاء: *الغي ' + n + '* (هسأله عن السبب وأحاول أرجّعه) · أو كلّمه: *رد ' + n + ' نص الرسالة*', 'cancel-' + o.id + '-' + T.norm(text).slice(0, 20))
      return 'وصلني طلب الإلغاء ✅ وبلّغت العمليات' + (o.techId ? ' والفني' : '') + ' حالًا.\nلو في حاجة نقدر نعملها عشانك (موعد تاني، السعر، فني تاني) قولّي وأنا أوصّلها.'
    }
    if (it === 'general' && o.techId && text.length > 8 && !T.isYes(text)) it = 'request'
    const did = []
    const f = o.facts || {}
    if (it === 'urgent' || it === 'where') {
      if (it === 'urgent') { await wp('alert', { id: o.id, title: '🔔 العميل يستعجل', body: (o.clientName || 'العميل') + ': ' + text.slice(0, 160) }); did.push('بلّغت غرفة العمليات إنك مستعجل'); toRoom('🔔 العميل بيستعجل طلب #' + n + ': «' + text.slice(0, 160) + '»', 'urg-' + o.id + '-' + Math.floor(now() / 600000)) }
      if (!f.eta && o.techId) { const a = await wp('eta_ask', { id: o.id }); if (a?.result) did.push(a.result === 'asked' ? 'سألت الفني دلوقتي فاضل له قد إيه' : 'الفني اتسأل من شوية ومستنيين رده') }
    } else if (it === 'postpone') {
      await wp('alert', { id: o.id, title: '⏳ العميل يطلب تأجيل', body: text.slice(0, 200) })
      if (o.techId) await wp('tell_tech', { id: o.id, text: 'العميل بيطلب تأجيل: «' + text.slice(0, 160) + '» — استنى تأكيد العمليات قبل ما تتحرك.' })
      toRoom('⏳ العميل بيطلب تأجيل طلب #' + n + ': «' + text.slice(0, 160) + '»\nكلّمه: *رد ' + n + ' النص*', 'pp-' + o.id + '-' + Math.floor(now() / 600000))
      did.push('سجلت طلب التأجيل وبلّغت العمليات' + (o.techId ? ' والفني' : '') + '، والموعد الجديد بيتأكد لك من العمليات')
    } else if (it === 'request') {
      if (o.techId) { await wp('tell_tech', { id: o.id, text: '📌 طلب من العميل: «' + text.slice(0, 300) + '»' }); did.push('وصلت طلبك للفني ' + (o.techName || '')) }
      else { toRoom('📌 طلب من عميل طلب #' + n + ': «' + text.slice(0, 300) + '»', 'rq-' + o.id + '-' + T.norm(text).slice(0, 20)); did.push('سجلت طلبك وهيوصل للفني أول ما يتسند') }
    } else if (it === 'human') {
      const c = chatOf(phone); if (c) notifyHuman(c, text, false)
      did.push('بلّغت موظف خدمة العملاء وهيرد عليك هنا')
    }
    wp('step', { id: o.id, kind: 'wa_customer', text: 'العميل على واتساب: ' + text.slice(0, 80) }).catch(() => {})
    let fresh = o; try { fresh = (await wp('order', { id: o.id })) || o } catch {}
    return template(it, fresh, did)
  }

  function template(it, o, did) {
    const f = o.facts || {}
    const L = []
    if (o.status === 'cancelled') L.push('طلبك #' + T.num(o) + ' ملغي حاليًا. لو حابب نرجّعه أو نفتح طلب جديد قول لي.')
    else if (it === 'where' || it === 'urgent') {
      if (f.eta) L.push('الفني ' + (o.techName || '') + ' قال إنه يوصل خلال حوالي ' + f.eta.minutes + ' دقيقة 🚗')
      else if (f.distanceKm !== null && f.distanceKm !== undefined) L.push('الفني ' + (o.techName || '') + ' على بعد حوالي ' + f.distanceKm + ' كم منك (آخر تحديث قبل ' + f.posAgoMin + ' د).')
      else if (!o.techId) L.push('طلبك لسه في التوزيع على أقرب فني.')
      else L.push('طلبك مع الفني ' + (o.techName || '') + ' — المرحلة: ' + (f.stepLabel || o.statusLabel || '') + '.')
      if (f.techMap) L.push('📍 مكان الفني الآن: ' + f.techMap)
      if (o.techId && o.url) L.push('🗺️ تابعه لحظة بلحظة وكلّمه من هنا: ' + o.url)
    } else if (it === 'warranty') {
      const w = f.warranty
      L.push(w ? (w.active ? 'ضمان طلبك ساري، باقي ' + w.left + ' يوم (حتى ' + w.until + ').' : 'ضمان الطلب انتهى في ' + w.until + '.') : 'الضمان بيبدأ من لحظة إنهاء الخدمة، وبيظهر هنا أول ما الطلب يكتمل.')
    } else if (it === 'pay') {
      L.push(Number(o.price) > 0 ? 'مبلغ الطلب ' + o.price + ' ر.س' + (f.paid ? ' ومدفوع ✅' : '، والدفع بعد إنهاء الخدمة بفاتورة.') : 'السعر النهائي بيتحدد مع الفني بعد المعاينة، والدفع بعد الخدمة بفاتورة.')
    } else {
      L.push('طلبك #' + T.num(o) + (o.service ? ' (' + o.service + ')' : '') + ' — المرحلة: ' + (f.stepLabel || o.statusLabel || '') + (o.techName ? ' مع الفني ' + o.techName : '') + (o.appointment ? '، الموعد ' + o.appointment : '') + '.')
    }
    for (const d of did) L.push('✅ ' + d + '.')
    if (!did.length && ['general', 'status'].includes(it)) L.push('تقدر تسألني عن مكان الفني، أو تطلب تأجيل، أو تبعت أي طلب للفني وأنا أوصله.')
    return L.join('\n')
  }

  /** «1 4 6» — what he liked about the technician: on the order, to the technician and operations, and a warm thanks. */
  async function traitsStage(o, text, phone) {
    const picks = [...new Set((T.norm(text).match(/[1-8١-٨]/g) || []).map((d) => Number(String(d).replace(/[١-٨]/, (x) => '١٢٣٤٥٦٧٨'.indexOf(x) + 1))))]
    dropStage(phone)
    if (!picks.length) return null
    let r = null; try { r = await wp('traits_set', { id: o.id, picks }) } catch {}
    if (!r || !r.labels) return null
    const L = r.labels.join('، ')
    toTech(o, '🌟 العميل شكر فيك على طلب #' + T.num(o) + ': *' + L + '*\nكمّل كده يا بطل 💚', 'traits-t-' + o.id)
    toRoom('🌟 تقييم مفصّل للفني ' + String(o.techName || '').replace(/^الفن[يى]\s+/, '') + ' — طلب #' + T.num(o) + ': ' + L, 'traits-r-' + o.id)
    return 'شكرًا ليك من القلب 💚 كلامك وصل للفني وهيفرّحه جدًا.'
  }

  async function rateStage(o, text, phone) {
    const r = o.rating || {}
    let stars = null
    const si = (text.match(/⭐|★/g) || []).length
    if (si >= 1 && si <= 5) stars = si
    else { const m = T.norm(text).match(/(?:^|\D)([1-5])(?!\d)/); if (m) stars = Number(m[1]) }
    if (stars === null && !r.stars) {
      const n = T.norm(text)
      if (/(ممتاز|رائع|جميل|شكرا|حلو|مبدع|تسلم)/.test(n)) stars = 5
      else if (/(سيء|سيئ|وحش|زفت|مش كويس|متاخر|غالي|ما عجبني)/.test(n)) stars = 2
    }
    // Only a rating is a rating: «السلام عليكم» or «محتاج خدمة جديدة» leaves the stage and goes on as a new talk.
    if (stars === null && !r.stars && (T.norm(text).length > 2 || M.isMenu(text))) { dropStage(phone); return null }
    if (r.stars && (M.parseAc(text).found || SERVICE_WORDS.some((w) => T.norm(text).includes(T.norm(w))) || M.isMenu(text) || /(خدمه جديده|طلب جديد|عايز اطلب|ابغي اطلب)/.test(T.norm(text)))) { dropStage(phone); return null }
    const n = T.num(o)
    if (stars !== null && !r.stars) {
      const comment = /[؀-ۿa-zA-Z]{3,}/.test(text) ? text.slice(0, 300) : ''
      await wp('patch', { id: o.id, patch: { rating: { stars, comment, at: new Date(now()).toISOString(), via: 'whatsapp' }, customerRating: stars }, step: { kind: 'wa_rating', text: 'تقييم العميل: ' + stars + '/5' } })
      let line = '⭐'.repeat(stars) + ' *تقييم العميل لطلب #' + n + ': ' + stars + '/5*\n👤 ' + (o.clientName || o.clientPhone) + (o.techName ? '\n👷 الفني: ' + o.techName : '') + (comment ? '\n💬 «' + comment + '»' : '')
      if (stars <= 3) { line = '⚠️ *عميل مش راضي*\n' + line + '\n\nكلّمه: *رد ' + n + ' نص الرسالة*'; wp('alert', { id: o.id, title: '⚠️ تقييم منخفض', body: stars + '/5 ' + comment }).catch(() => {}) }
      toRoom(line, 'rate-' + o.id)
      // the technician hears his own rating (owner, 2026-11-23)
      toTech(o, '⭐'.repeat(stars) + ' العميل قيّمك *' + stars + '/5* على طلب #' + n + (comment ? '\n💬 «' + comment + '»' : '') + (stars >= 4 ? '\nتسلم إيدك 💚' : '\nراجع العمليات عشان نعرف إيه اللي ضايق العميل.'), 'rate-t-' + o.id)
      // «جلب العملاء» (owner, 2026-11-23): after a good rating, the Google review link and the referral link — each only when switched on
      let more = ''
      if (stars >= 4) { try { const g = await wp('after_rating', { id: o.id, stars }); more = g && g.text ? '\n\n' + g.text : '' } catch {} }
      // a pleased customer (no complaint, did not chase the technician): the detailed rating, in one message
      if (stars >= 4 && !comment) {
        let ta = null; try { ta = await wp('traits_ask', { id: o.id, stars }) } catch {}
        if (ta && ta.text) { setStage(phone, o.id, 'traits'); return 'شكرًا لتقييمك 🙏 يسعدنا إنك راضي.\n\n' + ta.text + more }
      }
      if (!comment) return (stars >= 4 ? 'شكرًا لتقييمك 🙏 يسعدنا إنك راضي.\nلو حابب تكتب لنا كلمة عن الفني أو الخدمة اكتبها هنا.' : 'شكرًا لصراحتك 🙏 آسفين إن التجربة ما كانتش زي ما تستاهل.\nقولنا إيه اللي حصل وهيكلمك المسؤول بنفسه.') + more
      dropStage(phone)
      return (stars >= 4 ? 'شكرًا لتقييمك وكلامك 🙏 في خدمتك دايمًا.' : 'شكرًا لصراحتك 🙏 وصلت ملاحظتك للمسؤول وهيتواصل معاك.') + more
    }
    if (r.stars) {
      await wp('patch', { id: o.id, patch: { rating: { ...r, comment: ((r.comment || '') + ' ' + text.slice(0, 300)).trim() } } })
      toRoom('💬 تعليق العميل على طلب #' + n + ' (' + r.stars + '/5): «' + text.slice(0, 300) + '»', 'cmt-' + o.id + '-' + T.norm(text).slice(0, 20))
      dropStage(phone)
      return 'وصل كلامك للإدارة 🙏 شكرًا ليك.'
    }
    return 'قيّم الخدمة برقم من 1 لـ 5 ⭐ (5 = ممتازة).'
  }

  /* ------------------------------------------------------------------ he cancelled: why, and can we keep him */

  function winbackStart(o) {
    const s = T.summary(o)[0]
    toCustomer(o, ['أهلًا' + (o.clientName ? ' ' + o.clientName : '') + ' 🌷', 'لاحظنا إنك لغيت طلبك رقم *' + T.num(o) + '*' + (s ? ' (' + s.replace(/^🛠 الخدمة: /, '') + ')' : '') + '.', 'يهمنا نعرف السبب عشان نخدمك أحسن — اختار رقم:',
      '1️⃣ السعر', '2️⃣ الموعد مش مناسب', '3️⃣ اتأخرنا عليك / محدش رد', '4️⃣ لقيت بديل أو مش محتاج', '5️⃣ سبب تاني (اكتبه)', '', 'ولو غيرت رأيك اكتب *رجّعه* ✅ ونكمّل بنفس البيانات من غير ما تعيد أي حاجة.'].join('\n'), 'wb-' + o.id)
    setStage(o.clientPhone, o.id, 'winback')
    wp('step', { id: o.id, kind: 'wa_winback', text: 'اتسأل العميل عن سبب الإلغاء وعرضنا نرجّع الطلب' }).catch(() => {})
  }

  async function reopen(o, phone, extra, why) {
    const wb = o.winback || {}
    await wp('reopen', { id: o.id, patch: { ...extra, customerConfirmed: true, confirmedAt: new Date(now()).toISOString(), confirmedVia: 'whatsapp', winback: { ...wb, won: true, at: new Date(now()).toISOString() } }, why })
    setStage(phone, o.id, 'live')
    const room = ['♻️ *العميل رجّع طلب #' + T.num(o) + '* بعد ما لغاه', '👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone]
    if (wb.reason) room.push('❓ سبب الإلغاء كان: ' + (T.WB_LABEL[wb.reason] || wb.reason) + (wb.text ? ' — «' + wb.text + '»' : ''))
    if (why) room.push('🎯 ' + why)
    room.push(...T.opsSummary(o), '', 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*')
    toRoom(room.join('\n'), 'wbw-' + o.id + '-' + now())
    return 'رجّعنا طلبك ✅ بنفس البيانات' + (why ? ' (' + why + ')' : '') + '.\nهنبلغك هنا أول ما الفني يستلم ويتحرك. شكرًا لثقتك 🌷'
  }

  async function winbackStage(o, text, phone, stage) {
    const wb = o.winback || {}
    if (stage === 'winback_time') {
      if (T.looksLikeTime(text) || (text.length >= 3 && !T.wantsBack(text))) return reopen(o, phone, { preferredTime: text.slice(0, 120), appointmentAt: null }, 'على موعد جديد: ' + text.slice(0, 60))
      if (T.wantsBack(text)) return 'اكتب الوقت اللي يناسبك (مثال: بكرة العصر) وأرجّع الطلب عليه.'
    }
    if (T.wantsBack(text)) {
      if (wb.offerPct) return reopen(o, phone, { winbackDiscountPct: Number(wb.offerPct), note: ((o.note || '') + '\nخصم استرجاع ' + wb.offerPct + '% (واتساب)').trim() }, 'بخصم ' + wb.offerPct + '%')
      if (wb.reason === 'late') return reopen(o, phone, { priority: 'high' }, 'بأولوية وأقرب فني')
      return reopen(o, phone, {}, '')
    }
    let reason = T.wbReason(text)
    if (!reason && wb.reason) {
      await wp('patch', { id: o.id, patch: { winback: { ...wb, text: ((wb.text || '') + ' ' + text.slice(0, 200)).trim() } } })
      toRoom('💬 العميل (طلب #' + T.num(o) + ' الملغي): «' + text.slice(0, 200) + '»', 'wbt-' + o.id + '-' + T.norm(text).slice(0, 20))
      return 'وصل كلامك للإدارة 🙏 ولو حابب نرجّع الطلب في أي وقت اكتب *رجّعه*.'
    }
    if (!reason) reason = 'other'
    const nw = { ...wb, reason, text: /[؀-ۿa-zA-Z]{3,}/.test(text) ? text.slice(0, 200) : '', askedAt: wb.askedAt || new Date(now()).toISOString() }
    let reply = ''
    if (reason === 'price') {
      const pct = Number(o.winbackPct || 10)
      nw.offerPct = pct
      reply = pct > 0 ? 'مقدّرين ده 🙏 عشانك هنعملك *خصم ' + pct + '%* على نفس الطلب، والسعر النهائي يتأكد لك قبل التنفيذ ومفيش أي حاجة من غير موافقتك.\nاكتب *رجّعه* ✅ ونكمّل فورًا.' : 'مقدّرين ده 🙏 السعر بيتأكد لك قبل التنفيذ والفني بيعاين الأول.\nاكتب *رجّعه* ✅ لو حابب نكمّل.'
    } else if (reason === 'time') {
      reply = 'ولا يهمك 🌷 قولّي الوقت اللي يناسبك (مثال: بكرة العصر، أو الخميس الساعة 6) وأرجّع الطلب عليه على طول من غير أي إجراءات.'
      setStage(phone, o.id, 'winback_time')
    } else if (reason === 'late') reply = 'حقك علينا 🙏 آسفين على التأخير.\nلو رجّعنا الطلب هيكون *بأولوية* مع أقرب فني متاح، وهتتابع مكانه من هنا لحظة بلحظة.\nاكتب *رجّعه* ✅'
    else if (reason === 'other_co') reply = 'تمام، يسعدنا إن مشكلتك اتحلت 🌷\nلو احتجت أي خدمة بعد كده ابعتلنا هنا كلمة واحدة ونرتب لك كل حاجة بسرعة.'
    else reply = 'شكرًا إنك قلتلنا 🙏 وصلت ملاحظتك للمسؤول.\nولو حابب نرجّع الطلب بنفس البيانات اكتب *رجّعه* ✅'
    await wp('patch', { id: o.id, patch: { winback: nw, cancelReason: T.WB_LABEL[reason] + (nw.text ? ' — ' + nw.text : '') }, step: { kind: 'wa_cancel_reason', text: 'سبب الإلغاء: ' + T.WB_LABEL[reason] } })
    toRoom('❓ *سبب إلغاء طلب #' + T.num(o) + '*: ' + T.WB_LABEL[reason] + (nw.text ? '\n💬 «' + nw.text + '»' : '') + (reason === 'price' && nw.offerPct ? '\n🎯 عرضت عليه خصم ' + nw.offerPct + '%' : reason === 'time' ? '\n🎯 بسأله عن موعد جديد' : reason === 'late' ? '\n🎯 عرضت عليه أولوية وأقرب فني' : '') + '\n\nتكلّمه بنفسك: *رد ' + T.num(o) + ' نص الرسالة*', 'wbr-' + o.id + '-' + reason)
    if (reason === 'other_co') dropStage(phone)
    return reply
  }

  /* ------------------------------------------------------------------ the technician */

  async function technician(w, text) {
    const jobs = w.jobs || []
    const tn = String(w.name || '').replace(/^الفن[يى]\s+/, '')
    // The technician's two doors (owner, 2026-11-23): «#سؤال» → the AI assistant answers him; «*كلام» → operations.
    const lead = String(text || '').trim()
    const ask = lead.match(/^(?:[#＃]\s*(?:مساعد|المساعد)?|مساعد\s*[:：])\s*([\s\S]*)$/)
    if (ask) {
      const q = ask[1].trim()
      if (!q) return 'اكتب سؤالك بعد # — مثال: *# الكمبروسر بيفصل بعد دقيقة، أعمل إيه؟*'
      const o = jobs[0]
      let a = null
      try { a = await wp('tech_ask', { phone: T.intl(w.phone || ''), name: tn, text: q, job: o ? (T.num(o) + ' — ' + (o.service || '') + ' — ' + (o.statusLabel || o.status || '')) : '' }) } catch (e) { log('warn', 'tech_ask: ' + e.message) }
      return '🤖 ' + ((a && a.reply) || 'المساعد مش متاح دلوقتي 🙏 ابعت للعمليات بكتابة * في أول الرسالة.')
    }
    const ops = lead.match(/^[*＊٭]\s*([\s\S]+)$/)
    if (ops) {
      const o = jobs[0]
      if (o) await wp('step', { id: o.id, kind: 'note', text: w.name + ' للعمليات: ' + ops[1].slice(0, 300) }).catch(() => {})
      toRoom('📣 *الفني ' + tn + '*' + (o ? ' — طلب #' + T.num(o) : '') + '\n💬 «' + ops[1].slice(0, 400) + '»' + (o ? '\n👤 ' + (o.clientName || '—') + ' — ' + (o.clientPhone || '') : '') + '\n\nرد عليه: *رد ' + (o ? T.num(o) : '') + ' نص الرسالة*', 'tops-' + T.norm(ops[1]).slice(0, 30))
      return '✅ وصلت للعمليات — هيردوا عليك هنا.'
    }
    // «فاضل لك قد إيه؟» answered by a number (owner, 2026-11-23): 1 on my way · 2 + minutes · 3 still on another job
    if (jobs.length) {
      const o = jobs.find((x) => ['assigned', 'accepted', 'en_route', 'nearby'].includes(x.status)) || null
      const tn2 = tn || 'الفني'
      if (o && /^[1١]$/.test(lead)) {
        if (['assigned', 'accepted'].includes(o.status)) await wp('patch', { id: o.id, patch: { status: 'en_route' }, step: { kind: 'en_route', text: T.SAID.en_route, by: w.name } }).catch(() => {})
        else toCustomer(o, '🚗 الفني ' + tn2 + ' في الطريق ليك دلوقتي.', 'eta1-' + o.id + '-' + Math.floor(now() / 600000))
        return '✅ بلّغت العميل إنك في الطريق — طلب ' + T.num(o)
      }
      if (o && /^[2٢]$/.test(lead)) return 'اكتب الدقايق بعد الرقم، مثال: *2 15*'
      const mm = lead.match(/^(?:[2٢]\s*[-:،,]?\s*)?(\d{1,3})\s*(?:د|دق|دقيقه|دقيقة|دقايق|دقائق|min)?$/)
      if (o && mm && Number(mm[1]) >= 5 && Number(mm[1]) <= 240) {
        const n2 = Number(mm[1])
        wp('patch', { id: o.id, patch: { techEta: { minutes: n2, at: new Date(now()).toISOString() } }, step: { kind: 'eta', text: 'الفني: هيوصل خلال ' + n2 + ' دقيقة', by: w.name } }).catch(() => {})
        toCustomer(o, '🚗 الفني ' + tn2 + ' قال إنه هيوصلك خلال حوالي *' + n2 + ' دقيقة*.', 'eta-' + o.id + '-' + n2)
        return '✅ بلّغت العميل: هتوصل خلال ' + n2 + ' دقيقة — طلب ' + T.num(o)
      }
      if (o && /^[3٣]$/.test(lead)) {
        toCustomer(o, 'الفني ' + tn2 + ' لسه بيخلّص شغل قبلك، وهيتحرك ليك أول ما يخلص 🌷 وهنبلّغك أول ما يتحرك.', 'eta3-' + o.id + '-' + Math.floor(now() / 1800000))
        toRoom('⏳ الفني ' + tn2 + ' لسه في شغل تاني — طلب #' + T.num(o) + ' مستني', 'eta3r-' + o.id + '-' + Math.floor(now() / 1800000))
        return '✅ بلّغت العميل إنك لسه بتخلّص شغل — اكتب *1* أول ما تتحرك.'
      }
    }
    const step = T.stepOf(text)
    if (!step) {
      if (text && jobs.length) {
        const o = jobs[0]
        await wp('step', { id: o.id, kind: 'note', text: w.name + ': ' + text.slice(0, 300) })
        if (T.PROBLEM.test(T.norm(text))) {
          toRoom('⚠️ *الفني ' + String(w.name || '').replace(/^الفن[يى]\s+/, '') + ' — طلب #' + T.num(o) + '*\n💬 «' + text.slice(0, 300) + '»\n👤 ' + (o.clientName || '—') + ' — ' + (o.clientPhone || '') + '\n\nكلّم العميل: *رد ' + T.num(o) + ' نص الرسالة* · تسحبه: *اسحب ' + T.num(o) + '* · تلغيه: *الغي ' + T.num(o) + '*', 'tp-' + o.id + '-' + T.norm(text).slice(0, 20))
          return '📝 سجلتها على طلب ' + T.num(o) + ' وبلّغت غرفة العمليات حالًا ✅\nكمّل مع العميل لحد ما يوصلك ردهم.'
        }
        return null   // noted on the order quietly: a colleague is not chatted with
      }
      if (!jobs.length && !/^(مساعدة|طلباتي|الاوامر|الأوامر)$/.test(text.trim())) return null   // a colleague chatting
      const L = ['*أهلًا ' + (w.name || '') + '*']
      if (jobs.length) { L.push('طلباتك المفتوحة:'); for (const o of jobs.slice(0, 6)) L.push('📋 ' + T.num(o) + ' — ' + (o.service || 'صيانة') + ' — _' + (o.statusLabel || o.status) + '_') } else L.push('مفيش طلبات مفتوحة عندك دلوقتي.')
      L.push('', 'اكتب: *قبلت* · *في الطريق* · *قربت* · *وصلت* · *بدأت العمل* · *تم*')
      return L.join('\n')
    }
    if (!jobs.length) return 'مفيش طلب مفتوح عندك دلوقتي. استنى الإسناد من غرفة العمليات.'
    const m = text.match(/(\d{3,})/)
    if (jobs.length > 1 && !m) return 'عندك ' + jobs.length + ' طلبات مفتوحة. ابعت رقم الطلب مع الكلمة، مثال: *وصلت ' + T.num(jobs[0]) + '*\n' + jobs.slice(0, 6).map((o) => '• ' + T.num(o) + ' — ' + (o.service || 'صيانة')).join('\n')
    const o = (m && jobs.find((x) => T.num(x).includes(m[1]))) || jobs[0]
    await wp('patch', { id: o.id, patch: { status: step }, step: { kind: step, text: T.SAID[step], by: w.name } })
    return T.SAID[step] + ' — طلب ' + T.num(o) + (step === 'completed' ? '\nلو فيه قطع غيار أو ملاحظة، ابعتها دلوقتي وهتتسجل على الطلب.' : '')
  }

  /* ------------------------------------------------------------------ the operations room */

  async function room(w, text) {
    const t = String(text || '').trim()
    const say2 = t.match(/^عميل\s*(\+?[\d\s]{9,16})\s+([\s\S]+)$/)
    if (say2) {
      const ph = T.intl(say2[1]); const c = chatOf(ph)
      send(ph, say2[2].trim(), { line: c?.line || '', purpose: 'agent' })
      if (c) store.touchChat(c.pk, { humanAt: now(), needsHuman: 0 })
      return '📤 اتبعتت للعميل ' + ph + '.'
    }
    const cmd = T.roomCommand(t)
    if (!cmd) return null   // a colleague chatting: not the bot's business
    if (cmd.op === 'help') return T.ROOM_HELP + '\n💬 *عميل 0501234567 النص* — رد على عميل من خطه'
    try {
      if (cmd.op === 'report') { const r = await wp('report', { what: cmd.what }); return r?.text || '—' }
      const o = await wp('order', { number: cmd.num })
      if (!o) return '❌ مالقيتش طلب رقمه ' + cmd.num + '.'
      if (cmd.op === 'show') return ['📋 *طلب #' + T.num(o) + '* — ' + (o.statusLabel || o.status), '👤 ' + (o.clientName || '—') + ' — ' + (o.clientPhone || '📵'), ...T.opsSummary(o), o.techName ? '👷 ' + o.techName : '⚠️ بدون فني', o.staffUrl ? '🔗 ' + o.staffUrl : ''].filter(Boolean).join('\n')
      if (cmd.op === 'reply') {
        if (!o.clientPhone) return '📵 الطلب ده من غير رقم عميل.'
        toCustomer(o, cmd.text)
        const c = chatOf(o.clientPhone); if (c) store.touchChat(c.pk, { humanAt: now() })
        wp('step', { id: o.id, kind: 'wa_room_reply', text: 'العمليات للعميل: ' + cmd.text.slice(0, 120), by: w.name }).catch(() => {})
        return '📤 وصلت للعميل ' + (o.clientName || o.clientPhone) + ' (طلب #' + T.num(o) + ').'
      }
      if (cmd.op === 'approve') { const r = await wp('approve', { id: o.id }); return r?.text || '✅ اتبعت للتوزيع.' }
      if (cmd.op === 'assign') { const r = await wp('assign', { id: o.id, name: cmd.name, by: w.name }); return r?.text || '—' }
      if (cmd.op === 'unassign') { await wp('patch', { id: o.id, patch: { technicianId: null, technicianName: null, status: 'new' }, step: { kind: 'unassign', text: 'اتسحب من الفني', by: w.name } }); return '↩️ طلب ' + T.num(o) + ' اتسحب ورجع لغرفة العمليات.' }
      if (cmd.op === 'cancel') {
        if (['completed', 'cancelled'].includes(o.status)) return 'ℹ️ طلب ' + T.num(o) + ' ' + (o.statusLabel || o.status) + ' بالفعل.'
        await wp('patch', { id: o.id, patch: { status: 'cancelled', cancelledAt: new Date(now()).toISOString(), cancelledBy: 'operations', ...(cmd.why ? { cancelNote: cmd.why.slice(0, 200) } : {}) }, step: { kind: 'cancelled', text: 'العمليات لغت الطلب' + (cmd.why ? ': ' + cmd.why : ''), by: w.name } })
        return '❌ طلب ' + T.num(o) + ' اتلغى' + (o.techId ? ' والفني ' + (o.techName || '') + ' اتبلغ' : '') + (o.cancelRequest ? '، والعميل اتسأل عن السبب وبحاول أرجّعه.' : '.')
      }
      if (cmd.op === 'reopen') {
        await wp('reopen', { id: o.id, patch: {}, why: 'من غرفة العمليات' })
        if (o.clientPhone) { setStage(o.clientPhone, o.id, 'live'); toCustomer(o, 'رجّعنا طلبك رقم *' + T.num(o) + '* ✅ بنفس البيانات، وهنبلغك أول ما الفني يتحرك.') }
        return '♻️ الطلب #' + T.num(o) + ' رجع «جديد»' + (o.clientPhone ? ' واتبلغ العميل' : '') + '. وزّعه: *اعتمد ' + T.num(o) + '*'
      }
    } catch (e) { log('error', 'room ' + cmd.op + ': ' + e.message); return '⚠️ ما اتنفذش: ' + (e.message || 'خطأ') }
    return T.ROOM_HELP
  }

  /** At closing time, once a day: the day's summary to the room. */
  async function daily() {
    const C = cfg(); if (C.dailyReport !== 'whatsapp') return   // only if ever asked for in so many words
    const p = new Intl.DateTimeFormat('en-GB', { hour: 'numeric', minute: 'numeric', hour12: false, timeZone: 'Asia/Riyadh', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(now()))
    const v = Object.fromEntries(p.map((x) => [x.type, x.value]))
    const at = Math.min(23, (C.hoursTo >= 24 ? 23 : C.hoursTo))
    if (Number(v.hour) !== at || Number(v.minute) > 10) return
    const day = v.year + '-' + v.month + '-' + v.day
    if (store.get('dailyAt') === day) return
    store.set('dailyAt', day)
    try { const r = await wp('report', { what: 'report' }); if (r?.text) toRoom('🌙 *ملخص اليوم*\n' + r.text, 'daily-' + day) } catch (e) { log('warn', 'daily: ' + e.message) }
  }

  return { onEvent, inbound, contact, cfg, template, daily, who }
}
