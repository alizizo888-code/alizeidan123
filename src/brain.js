// The hub's brain: who is writing, what they want, and what everyone hears — customer, room, technician.
//
// The websites only *report* («order #1002 was created / assigned / moved») and answer short data questions. Every
// WhatsApp word is decided and sent from here, so a slow website can never hold a WhatsApp conversation up, and a
// WhatsApp burst can never slow a website down.
import * as T from './text.js'

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
  hoursFrom: 8, hoursTo: 24,
  welcome: 'أهلًا وسهلًا بيك في *{site}* 🌷\nإحنا هنا لخدمتك في التكييف والتبريد والصيانة العامة بمكة وجدة.\n\nاكتب طلبك مباشرة (الخدمة + الحي + الموعد)، أو اختار رقم:\n1️⃣ طلب خدمة\n2️⃣ الأسعار والعروض\n3️⃣ متابعة طلبي\n4️⃣ أكلم موظف',
  afterHours: 'إحنا برا مواعيد العمل دلوقتي، وهيرد عليك موظف أول ما نفتح. تقدر تسجل طلبك هنا وهيتنفذ في أقرب موعد.',
  prices: 'أسعارنا بتتحدد حسب نوع الجهاز والعدد، والمعاينة مجانية لحد جهازين.\nابعت نوع الخدمة وعدد الأجهزة والحي، ونبعتلك السعر على طول 🌷',
}

export function createBrain({ store, wp, send: rawSend, now = () => Date.now(), log = () => {} }) {
  // «الفني الفني علي»: a technician whose name already starts with «الفني» is named once.
  const send = (phone, text, o) => rawSend(phone, String(text).replace(/(الفن[يى])\s+(?=الفن[يى]\s)/g, ''), o)
  const cfg = () => ({ ...DEFAULTS, ...(store.get('settings', {}) || {}) })
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
  const say = (phone, line, text) => send(T.intl(phone), text, { line, purpose: 'reply' })

  /* ------------------------------------------------------------------ stage memory: which order a phone is talking about */

  function setStage(phone, orderId, stage) {
    const pk = T.pkey(phone); if (!pk) return
    store.touchChat(pk, { phone: T.intl(phone), stage, orderId: String(orderId || ''), stageAt: now() })
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
        case 'order.created': return created(o)
        case 'order.assigned': return assigned(o)
        case 'order.status': return moved(o, ev.was || '', !!ev.byCustomer)
        case 'order.eta': return eta(o, ev.minutes)
        case 'send': return ev.phone && ev.text ? send(T.intl(ev.phone), String(ev.text), { purpose: ev.purpose || 'site', dedup: ev.dedup }) : 0
        default: return 0
      }
    } catch (e) { log('error', 'event ' + ev.type + ': ' + (e?.message || e)) }
  }

  function created(o) {
    if (!o) return
    // The room hears about every order at once, whatever happens with the customer.
    const head = '🆕 *طلب جديد #' + T.num(o) + '*' + (o.site ? ' — ' + o.site : '')
    toRoom([head, '👤 ' + (o.clientName || '—') + (o.clientPhone ? ' — ' + o.clientPhone : ' — 📵 من غير رقم'), ...T.summary(o), '', o.techName ? '👷 اتسند آليًا لـ ' + o.techName : 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*'].join('\n'), 'new-' + o.id)
    if (!o.clientPhone || o.customerConfirmed || ['test', 'import'].includes(o.source)) return
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

  function moved(o, was, byCustomer) {
    if (!o || o.status === was) return
    const n = T.num(o); const tech = o.techName || ''; const who = o.clientName || o.clientPhone || ''
    const phone = o.clientPhone || ''
    const st = o.status
    if (WA_STATUS[st] && phone) {
      toCustomer(o, WA_STATUS[st].replace('{tech}', tech).replace(/\s{2,}/g, ' ') + '\n\n' + (o.statusText || ''), 'st-' + o.id + '-' + st)
      const j = stageOf(phone)
      if (!j || j.o === o.id) setStage(phone, o.id, j && j.s === 'confirm' && !o.customerConfirmed ? 'confirm' : 'live')
    }
    if (st === 'en_route') toRoom('🚗 الفني ' + tech + ' اتحرك لطلب #' + n + ' — ' + who, 'go-' + o.id)
    if (st === 'arrived') toRoom('🏠 *الفني ' + tech + ' وصل* لطلب #' + n + ' — ' + who + (o.address ? '\n📍 ' + String(o.address).slice(0, 100) : ''), 'arr-' + o.id)
    if (st === 'completed') {
      toRoom('✅ *تمت المهمة* — طلب #' + n + '\n👤 ' + who + (tech ? '\n👷 الفني: ' + tech : '') + (Number(o.price) > 0 ? '\n💰 ' + o.price + ' ر.س' : '') + '\n⭐ تقييم العميل هيوصلك هنا أول ما يرد.', 'done-' + o.id)
      if (phone) {
        setStage(phone, o.id, 'rate')
        toCustomer(o, '🎉 تم إنجاز طلبك، شكرًا لثقتك' + (o.site ? ' في *' + o.site + '*' : '') + '.\nقيّم الخدمة والفني' + (tech ? ' ' + tech : '') + ' برقم من *1* لـ *5* ⭐ (5 = ممتازة)، ورأيك بيوصل للمدير مباشرة.', 'rate-' + o.id)
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
    const hit = who$.get(k)
    if (hit && now() - hit.at < 600000) return hit.who
    let w = { kind: 'customer' }
    try { w = (await wp('who', { phone: T.intl(phone) })) || w } catch (e) { log('warn', 'who: ' + e.message) }
    who$.set(k, { at: now(), who: w })
    return w
  }

  /**
   * Every message that arrives on a linked line. `fromMe` is a person typing on the phone itself: it is kept and the
   * bot steps back from that conversation for a while — nobody wants the bot answering over them.
   */
  async function inbound({ line, phone, text = '', kind = 'text', name = '', fromMe = false, self = false }) {
    const pk = T.pkey(phone); if (!pk) return
    text = String(text || '').trim()
    const shown = text || ({ image: '📷 صورة', video: '🎬 فيديو', voice: '🎤 رسالة صوتية', audio: '🎵 صوت', document: '📄 ملف', sticker: '🙂 ملصق', location: '📍 موقع' }[kind] || '…')
    const c0 = store.chat(pk)
    if (fromMe && !self) {
      store.addMsg({ pk, line, dir: 'out', text: shown, kind, by: 'phone' })
      store.touchChat(pk, { phone: T.intl(phone), line, lastAt: now(), lastText: shown, botOff: now() + PAUSE_AFTER_HUMAN, needsHuman: 0, unread: 0 })
      return
    }
    store.addMsg({ pk, line, dir: 'in', text: shown, kind, by: name })
    const c = store.touchChat(pk, { phone: T.intl(phone), line: c0?.line || line, name: name || c0?.name || '', lastAt: now(), lastText: shown, unread: (c0?.unread || 0) + 1 })
    if (!text && kind !== 'location') return   // a picture or a voice note: a person will look at it

    const w = await who(phone)
    const testing = cfg().testPhone && T.pkey(cfg().testPhone) === pk
    if (w.kind !== 'customer' && !(testing && !T.roomCommand(text))) {
      store.touchChat(pk, { kind: w.kind })
      const reply = w.kind === 'tech' ? await technician(w, text) : await room(w, text)
      if (reply) say(phone, line, reply)
      return
    }
    return customer({ c, line, phone, text, name })
  }

  /* ------------------------------------------------------------------ the customer */

  async function customer({ c, line, phone, text, name }) {
    const j = stageOf(phone)
    if (j && j.o) {
      let o = null
      try { o = await wp('order', { id: j.o }) } catch (e) { log('warn', 'order ' + e.message) }
      if (o) {
        const reply = await journey(j.s, o, text, phone)
        if (reply !== null) { if (reply) toCustomer({ ...o, waLine: line }, reply); return }
      } else dropStage(phone)
    }
    if (j && j.s === 'lead') return lead(c, line, phone, text, name)
    const C = cfg()
    if (c.botOff && c.botOff > now()) return notifyHuman(c, text, false)
    if (!C.autoReply) return notifyHuman(c, text, false)

    const n = T.norm(text); const it = T.intent(text)
    // His order is open: answer about it.
    if (['where', 'urgent', 'status', 'cancel', 'postpone', 'request', 'warranty', 'pay'].includes(it) || n === '3') {
      let o = null
      try { o = await wp('order', { phone: T.intl(phone), open: true }) } catch {}
      if (o) { setStage(phone, o.id, o.customerConfirmed || o.status !== 'new' ? 'live' : 'confirm'); const r = await journey(o.customerConfirmed || o.status !== 'new' ? 'live' : 'confirm', o, text, phone); if (r) toCustomer({ ...o, waLine: line }, r); return }
      if (n === '3') return say(phone, line, 'مفيش طلب مفتوح على رقمك دلوقتي. لو حابب تطلب خدمة اكتب *1* أو ابعت الخدمة والحي على طول 🌷')
    }
    if (n === '4' || it === 'human') return notifyHuman(c, text, true)
    if (n === '2') return say(phone, line, await infoOr('الأسعار', C.prices))
    if (n === '1' || SERVICE_WORDS.some((w) => n.includes(T.norm(w)))) return lead(c, line, phone, n === '1' ? '' : text, name, true)
    // Prices, offers, warranty, payment… the website knows the owner's own answers.
    try { const r = await wp('info', { text }); if (r && r.text) return say(phone, line, r.text) } catch {}
    if (!c.welcomedAt || now() - c.welcomedAt > WELCOME_EVERY) {
      store.touchChat(c.pk, { welcomedAt: now() })
      return say(phone, line, welcome())
    }
    return notifyHuman(c, text, false)
  }

  async function infoOr(text, fallback) { try { const r = await wp('info', { text }); if (r && r.text) return r.text } catch {} return fallback }

  function welcome() {
    const C = cfg()
    const h = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Asia/Riyadh' }).format(new Date(now())))
    const open = C.hoursTo >= 24 ? h >= C.hoursFrom : h >= C.hoursFrom && h < C.hoursTo
    return C.welcome.replace(/\{site\}/g, C.siteName) + (open ? '' : '\n\n' + C.afterHours)
  }

  /** A person is needed: the chat is marked, dealt to the next agent, and the agent hears it on WhatsApp. */
  function notifyHuman(c, text, ack) {
    const C = cfg()
    let agent = (C.agents || []).find((a) => a.name === c.agent)
    if (!agent && (C.agents || []).length) {
      const i = (store.get('rr', 0) || 0) % C.agents.length; store.set('rr', i + 1); agent = C.agents[i]
    }
    const fresh = !c.needsHuman || now() - (c.lastAt || 0) > 1800000
    store.touchChat(c.pk, { needsHuman: 1, agent: agent ? agent.name : c.agent })
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
    if (start || !c.stage || c.stage !== 'lead') L = {}
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
    if (L.lat === undefined && !L.place) return say(phone, line, '📍 ابعت موقعك من واتساب (📎 ← الموقع) أو اكتب الحي والعنوان.')
    if (!L.time) return say(phone, line, '📅 إيه الموعد المناسب ليك؟ (مثال: اليوم بعد العصر، بكرة الساعة 6، أو أقرب وقت)')
    try {
      const o = await wp('create', { phone: T.intl(phone), name: name || c.name || '', service: L.service, address: L.place || '', lat: L.lat ?? null, lng: L.lng ?? null, time: L.time, line })
      store.touchChat(c.pk, { lead: '', stage: 'confirm', orderId: o?.id || '', stageAt: now() })
      // The website reports the new order and the confirmation goes out from created().
    } catch (e) {
      log('error', 'create: ' + e.message)
      notifyHuman(store.chat(c.pk), 'طلب جديد من الشات: ' + L.service + ' — ' + (L.place || 'لوكيشن') + ' — ' + L.time, false)
      return say(phone, line, 'وصلنا طلبك ✅ وموظف هيأكده معاك حالًا.')
    }
  }

  /* ------------------------------------------------------------------ the order's conversation */

  async function journey(stage, o, text, phone) {
    if (stage === 'confirm') return confirmStage(o, text, phone)
    if (stage === 'rate') return rateStage(o, text, phone)
    if (stage === 'winback' || stage === 'winback_time') return winbackStage(o, text, phone, stage)
    return liveStage(o, text, phone)
  }

  async function confirmStage(o, text, phone) {
    if (T.intent(text) === 'cancel') {
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
    toRoom(['✅ *العميل أكد طلب #' + T.num(o) + '* على واتساب', '👤 ' + (o.clientName || '—') + ' — ' + o.clientPhone, ...T.summary(o), '', o.techId ? '👷 مع الفني: ' + (o.techName || '') : 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*'].join('\n'), 'ok-' + o.id)
    return 'تم تأكيد طلبك ✅\nهنبلغك هنا أول ما الفني يستلم ويتحرك، وتقدر تسألني في أي وقت «الفني فين؟» أو تطلب أي حاجة توصل له.'
  }

  async function liveStage(o, text, phone) {
    if (['completed', 'cancelled'].includes(o.status)) return null
    const cc = T.coords(text)
    if (cc && !(o.lat !== null && o.lat !== undefined && o.lat !== '')) {
      await wp('patch', { id: o.id, patch: { lat: cc[0], lng: cc[1] } })
      if (o.techId) await wp('tell_tech', { id: o.id, text: '📍 العميل بعت اللوكيشن: https://maps.google.com/?q=' + cc[0] + ',' + cc[1] })
      return 'وصل اللوكيشن ✅' + (o.techId ? ' وبعته للفني.' : '')
    }
    let it = T.intent(text)
    const n = T.num(o)
    if (it === 'cancel') {
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
    const n = T.num(o)
    if (stars !== null && !r.stars) {
      const comment = /[؀-ۿa-zA-Z]{3,}/.test(text) ? text.slice(0, 300) : ''
      await wp('patch', { id: o.id, patch: { rating: { stars, comment, at: new Date(now()).toISOString(), via: 'whatsapp' }, customerRating: stars }, step: { kind: 'wa_rating', text: 'تقييم العميل: ' + stars + '/5' } })
      let line = '⭐'.repeat(stars) + ' *تقييم العميل لطلب #' + n + ': ' + stars + '/5*\n👤 ' + (o.clientName || o.clientPhone) + (o.techName ? '\n👷 الفني: ' + o.techName : '') + (comment ? '\n💬 «' + comment + '»' : '')
      if (stars <= 3) { line = '⚠️ *عميل مش راضي*\n' + line + '\n\nكلّمه: *رد ' + n + ' نص الرسالة*'; wp('alert', { id: o.id, title: '⚠️ تقييم منخفض', body: stars + '/5 ' + comment }).catch(() => {}) }
      toRoom(line, 'rate-' + o.id)
      if (!comment) return stars >= 4 ? 'شكرًا لتقييمك 🙏 يسعدنا إنك راضي.\nلو حابب تكتب لنا كلمة عن الفني أو الخدمة اكتبها هنا.' : 'شكرًا لصراحتك 🙏 آسفين إن التجربة ما كانتش زي ما تستاهل.\nقولنا إيه اللي حصل وهيكلمك المسؤول بنفسه.'
      dropStage(phone)
      return stars >= 4 ? 'شكرًا لتقييمك وكلامك 🙏 في خدمتك دايمًا.' : 'شكرًا لصراحتك 🙏 وصلت ملاحظتك للمسؤول وهيتواصل معاك.'
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
    room.push(...T.summary(o), '', 'وزّعه: *اعتمد ' + T.num(o) + '* أو *وجّه ' + T.num(o) + ' لمحمود*')
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
    const step = T.stepOf(text)
    if (!step) {
      if (text && jobs.length) {
        const o = jobs[0]
        await wp('step', { id: o.id, kind: 'note', text: w.name + ': ' + text.slice(0, 300) })
        if (T.PROBLEM.test(T.norm(text))) {
          toRoom('⚠️ *الفني ' + String(w.name || '').replace(/^الفن[يى]\s+/, '') + ' — طلب #' + T.num(o) + '*\n💬 «' + text.slice(0, 300) + '»\n👤 ' + (o.clientName || '—') + ' — ' + (o.clientPhone || '') + '\n\nكلّم العميل: *رد ' + T.num(o) + ' نص الرسالة* · تسحبه: *اسحب ' + T.num(o) + '* · تلغيه: *الغي ' + T.num(o) + '*', 'tp-' + o.id + '-' + T.norm(text).slice(0, 20))
          return '📝 سجلتها على طلب ' + T.num(o) + ' وبلّغت غرفة العمليات حالًا ✅\nكمّل مع العميل لحد ما يوصلك ردهم.'
        }
        return '📝 تم تسجيل ملاحظتك على طلب ' + T.num(o) + '.'
      }
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
      if (c) store.touchChat(c.pk, { botOff: now() + PAUSE_AFTER_HUMAN, needsHuman: 0 })
      return '📤 اتبعتت للعميل ' + ph + '.'
    }
    const cmd = T.roomCommand(t)
    if (!cmd || cmd.op === 'help') return T.ROOM_HELP + '\n💬 *عميل 0501234567 النص* — رد على عميل من خطه'
    try {
      if (cmd.op === 'report') { const r = await wp('report', { what: cmd.what }); return r?.text || '—' }
      const o = await wp('order', { number: cmd.num })
      if (!o) return '❌ مالقيتش طلب رقمه ' + cmd.num + '.'
      if (cmd.op === 'show') return ['📋 *طلب #' + T.num(o) + '* — ' + (o.statusLabel || o.status), '👤 ' + (o.clientName || '—') + ' — ' + (o.clientPhone || '📵'), ...T.summary(o), o.techName ? '👷 ' + o.techName : '⚠️ بدون فني', o.staffUrl ? '🔗 ' + o.staffUrl : ''].filter(Boolean).join('\n')
      if (cmd.op === 'reply') {
        if (!o.clientPhone) return '📵 الطلب ده من غير رقم عميل.'
        toCustomer(o, cmd.text)
        const c = chatOf(o.clientPhone); if (c) store.touchChat(c.pk, { botOff: now() + PAUSE_AFTER_HUMAN })
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

  return { onEvent, inbound, cfg, template }
}
