// «التنبيه الصوتي» (owner, 2026-11-22): every alert is also said out loud — one loud male voice: «عندك طلب جديد»,
// «العميل رفض الطلب», «الفني قبل الطلب», «تم الدفع»… The clip comes from the site (GET /voice?k=…, made once by the
// free Gemini voice and kept), played louder through the page's audio engine; until it exists the phone's own Arabic
// voice reads the same sentence. Same sentences and keys as Mutqan_Voice (PHP) and the Android apps.
import { API_BASE, headers } from './_api'
import { audioContext, deviceSound } from './alert'

export const VOICE_TEXT = {
  new_order: 'انتباه، عندك طلب جديد', new_task: 'انتباه، عندك مهمة جديدة', auto_assigned: 'تم الإسناد الآلي للطلب', assigned: 'تم إسناد الطلب للفني',
  unassigned: 'تنبيه، في طلب لسه من غير فني', accepted: 'الفني قبل الطلب', tech_rejected: 'تنبيه، الفني رفض الطلب', cust_rejected: 'تنبيه، العميل رفض الطلب',
  cust_cancelled: 'تنبيه، العميل ألغى الطلب', late: 'تنبيه، الفني اتأخر على الطلب', en_route: 'الفني في الطريق للعميل', nearby: 'الفني قرّب من العميل',
  arrived: 'الفني وصل عند العميل', started: 'الفني بدأ الشغل', completed: 'تم تنفيذ الطلب', failed: 'تنبيه، الطلب ما اتنفذش', paid: 'تم الدفع', transfer: 'تم التحويل',
  message: 'وصلتك رسالة جديدة', handoff: 'عميل محتاج موظف، رد عليه', complaint: 'تنبيه، في شكوى من عميل', warranty: 'عندك طلب ضمان جديد', call: 'مكالمة واردة',
  postpone: 'العميل عايز يأجل الموعد', rating: 'العميل قيّم الخدمة', reminder: 'تذكير، موعد الخدمة قرّب', contact: 'الفني محتاج يتواصل مع العميل',
  tech_join: 'في طلب انضمام فني جديد', tech_money: 'في طلب مالي من فني', alert: 'عندك تنبيه جديد',
}
const MAP = {
  new_order: 'new_order', order_new: 'new_order', order_created: 'new_order', order_bot: 'new_order', intake: 'new_order',
  order_assigned: 'assigned', assigned: 'assigned', order_unassigned: 'unassigned', accepted: 'accepted', rejected: 'tech_rejected',
  en_route: 'en_route', nearby: 'nearby', distance: 'nearby', arrived: 'arrived', started: 'started', progress: 'started', completed: 'completed',
  failed: 'failed', order_failed: 'failed', paid: 'paid', payment: 'paid', transfer: 'transfer',
  order_msg: 'message', wa_chat: 'message', wa_message: 'message', message: 'message', msg: 'message', chat: 'message', phone_wa: 'message', support_msg: 'message',
  wa_handoff: 'handoff', order_handoff: 'handoff', wa_complaint: 'complaint', complaint: 'complaint', warranty_claim: 'warranty', order_postpone_req: 'postpone',
  order_rating: 'rating', appointment_reminder: 'reminder', contact: 'contact', tech_application: 'tech_join', payout_request: 'tech_money', tech_topup: 'tech_money', tech_objection: 'tech_money',
}

/** The sentence key for an alert ({event, title, body, voice}) — the same rules as Mutqan_Voice::key on the site. */
export function voiceKey(m = {}) {
  if (m.voice && VOICE_TEXT[m.voice]) return m.voice
  const e = String(m.event || '').toLowerCase(); const t = String(m.title || '') + ' ' + String(m.body || '')
  if (e.includes('call')) return 'call'
  if (/الإسناد الآلي|التوزيع الآلي|اتسند تلقائي|إسناد تلقائي|أسند تلقائي/.test(t)) return 'auto_assigned'
  if (/(العميل|الزبون).{0,12}رفض/.test(t) || e === 'customer_rejected') return 'cust_rejected'
  if (/(العميل|الزبون).{0,12}(لغى|ألغى|الغى|إلغاء|الغاء)/.test(t) || ['cancelled', 'order_cancelled', 'customer_cancel'].includes(e)) return 'cust_cancelled'
  if (/اتأخر|متأخر|تأخر/.test(t) || ['order_nudge', 'late', 'order_late'].includes(e)) return 'late'
  if (MAP[e]) return MAP[e] === 'assigned' && /مهمة جديدة|طلب جديد ليك|مهمة ليك/.test(t) ? 'new_task' : MAP[e]
  if (/طلب جديد/.test(t)) return 'new_order'
  if (/تم الدفع|دفع العميل|اتدفع/.test(t)) return 'paid'
  if (/تحويل/.test(t)) return 'transfer'
  if (/رسالة/.test(t)) return 'message'
  return 'alert'
}

export const voiceOn = () => deviceSound().voice !== false && !deviceSound().mute

const clips = new Map()   // key → Promise<AudioBuffer|null>
function clipOf(key) {
  if (!clips.has(key)) {
    clips.set(key, fetch(API_BASE + '/voice?k=' + encodeURIComponent(key)).then((r) => (r.ok ? r.arrayBuffer() : null))
      .then((b) => (b ? new Promise((res) => { try { audioContext().decodeAudioData(b, res, () => res(null)) } catch { res(null) } }) : null))
      .catch(() => null).then((buf) => { if (!buf) setTimeout(() => clips.delete(key), 60000); return buf }))
  }
  return clips.get(key)
}

// «التنبيه يقول نفسه» (owner, 2026-11-22): the alert says WHAT it is — «الفني أحمد قبل طلب رقم 1004»,
// «رسالة جديدة من العميل: …», «للعلم، تقرير نشر المقالات: 12 مقال اتنشر» — in a voice that matches how
// urgent it is: urgent = a sharper commanding voice, louder, said twice; normal = loud and clear; info (reports,
// summaries) = calm and lower. The site makes that clip (POST /push {op:'say'}); the phone's voice reads the same
// sentence when it can't.
// One voice, once (owner, 2026-11-22: «الأصوات بتتكرر وفي أكتر من شخص بيتكلم وصوت نسائي»): nothing repeats, alerts
// queue one after the other instead of on top of each other, the same alert is said by only one open screen, and
// the phone's own reader is used only if it has a MAN's Arabic voice — otherwise the site's male clip alone.
const TIER = {
  urgent: { gain: 1.9, times: 1, rate: 1.05, pitch: 0.7 },
  normal: { gain: 1.5, times: 1, rate: 0.95, pitch: 0.8 },
  info: { gain: 1.1, times: 1, rate: 0.9, pitch: 0.9 },
}
export function tierOf(m = {}) {
  if (TIER[m.tier]) return m.tier
  const t = String(m.title || '') + ' ' + String(m.event || '')
  if (/تقرير|ملخص|خطة اليوم|كشف حساب|مقالات|report|digest|summary/i.test(t)) return 'info'
  if (m.priority === 'high' || m.hi) return 'urgent'
  return 'normal'
}
const clean = (s) => String(s || '').replace(/https?:\/\/\S+/g, '').replace(/[\u{1F300}-\u{1FAFF}☀-➿️]/gu, '').replace(/#(\d+)/g, 'رقم $1').replace(/\s*[·|•—–]\s*/g, '، ').replace(/ر\.س/g, 'ريال').replace(/\s+/g, ' ').trim()
// names only, no numbers (owner): no phone/order numbers, amounts, codes or distances in what is SAID
export const noNumbers = (t) => String(t || '')
  .replace(/(طلب|الطلب)\s*(رقم\s*)?#?\s*[\d٠-٩]+/g, 'الطلب')
  .replace(/(?:مبلغ|بمبلغ|المبلغ)?\s*[\d٠-٩][\d٠-٩,.\s]*\s*(ريال|ر\.س|SAR)/g, '')
  .replace(/(على\s*بُ?عد|المسافة)\s*[\d٠-٩.,]+\s*(كم|متر)?/g, 'قريب')
  .replace(/(رقم العملية|مرجع|الجوال|جوال|رقم|كود)\s*:?\s*[A-Za-z0-9٠-٩+\-\s]{3,}/g, '')
  .replace(/\b[A-Za-z]*\d[A-Za-z0-9-]*\b/g, '').replace(/[\d٠-٩]+/g, '')
  .replace(/\s*،\s*/g, '، ').replace(/(،\s*){2,}/g, '، ').replace(/\s{2,}/g, ' ').replace(/^[\s،.:]+|[\s،.:]+$/g, '')
/** What to say when the site's sentence isn't there: the alert's own title and text. */
export function sentenceOf(m = {}) {
  if (m.speak) return m.speak
  const title = noNumbers(clean(m.title)); const body = noNumbers(clean(m.body))
  const head = title && !/^(إشعار|تنبيه|متقن|أكسجين)$/.test(title) ? title : VOICE_TEXT[voiceKey(m)]
  return (tierOf(m) === 'urgent' ? 'انتباه! ' : tierOf(m) === 'info' ? 'للعلم، ' : '') + head + (body ? '. ' + body.split(' ').slice(0, 30).join(' ') : '')
}

const MALE = /male|maged|hamed|naayf|tarik|shakir|fahed|majed|hamdan|omar|ahmed|zayd|salman/i
function maleVoice() {
  try { return (window.speechSynthesis.getVoices() || []).find((v) => /^ar/i.test(v.lang) && MALE.test(v.name) && !/female|hoda|salma|zariyah|laila|mona|amira|fatima|hala/i.test(v.name)) || null } catch { return null }
}
/** The phone's reader, only with a man's voice; resolves when it is done (or at once when there is none). */
function sayText(text, tier) {
  return new Promise((done) => {
    try {
      const s = window.speechSynthesis; const v = s && maleVoice()
      if (!v) { done(false); return }
      const T = TIER[tier] || TIER.normal
      const u = new SpeechSynthesisUtterance(text); u.lang = v.lang; u.voice = v; u.volume = 1; u.rate = T.rate; u.pitch = T.pitch
      u.onend = u.onerror = () => done(true); s.cancel(); s.speak(u)
      setTimeout(() => done(true), 20000)
    } catch { done(false) }
  })
}
function playBuf(buf, tier) {
  return new Promise((done) => {
    try {
      const ctx = audioContext(); const T = TIER[tier] || TIER.normal
      const v = typeof deviceSound().volume === 'number' ? deviceSound().volume : 1
      const src = ctx.createBufferSource(); src.buffer = buf
      const g = ctx.createGain(); g.gain.value = T.gain * Math.max(0.3, v)
      src.connect(g).connect(ctx.destination); src.onended = () => done(true); src.start()
      setTimeout(() => done(true), (buf.duration + 1) * 1000)
    } catch { done(false) }
  })
}
/** Only one open screen (tab, desk, app) says a given alert: the first one to claim it. */
function claim(key) {
  try {
    const k = 'mq_said:' + key; const now = Date.now(); const was = Number(localStorage.getItem(k) || 0)
    if (now - was < 120000) return false
    localStorage.setItem(k, String(now))
    for (let i = localStorage.length - 1; i >= 0; i--) { const x = localStorage.key(i); if (x && x.startsWith('mq_said:') && now - Number(localStorage.getItem(x) || 0) > 600000) localStorage.removeItem(x) }
    return true
  } catch { return true }
}
let chain = Promise.resolve()
export const voiceBusy = { now: false }

async function clipFor(m) {
  if (!m.id || String(m.id).startsWith('sch-')) return null
  try {
    const r = await fetch(API_BASE + '/push', { method: 'POST', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify({ op: 'say', id: m.id }), credentials: 'omit' })
    if (!r.ok) return null
    const j = await r.json(); const d = (j && (j.data || j)) || {}
    if (!d.wav) return { text: d.text, tier: d.tier, buf: null }
    const bin = atob(d.wav); const a = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i)
    const buf = await new Promise((res) => { try { audioContext().decodeAudioData(a.buffer, res, () => res(null)) } catch { res(null) } })
    return { text: d.text, tier: d.tier, buf }
  } catch { return null }
}

const said = new Set()
/** Says the alert ONCE, in its urgency's voice, after whatever is being said now. */
export function speakAlert(m = {}, { delayMs = 500 } = {}) {
  if (!voiceOn()) return
  if (typeof document !== 'undefined' && document.hidden && window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) return   // the app's own service says it when the screen is away
  const id = m.id || (m.event || '') + (m.title || '')
  const text0 = sentenceOf(m)
  if (said.has(id) || said.has(text0)) return
  said.add(id); said.add(text0); if (said.size > 300) { said.delete(said.values().next().value); said.delete(said.values().next().value) }
  if (!claim(id) || !claim(text0)) return
  chain = chain.then(async () => {
    voiceBusy.now = true
    try {
      await new Promise((r) => setTimeout(r, delayMs))
      const got = await clipFor(m)
      const tier = (got && TIER[got.tier] && got.tier) || tierOf(m)
      if (got && got.buf) { await playBuf(got.buf, tier); return }
      // the site's sentence isn't there: the phone's man's voice reads it, else the short male clip for its kind
      if (await sayText((got && got.text) || text0, tier)) return
      const generic = await clipOf(voiceKey(m))
      if (generic) await playBuf(generic, tier)
    } finally { voiceBusy.now = false; await new Promise((r) => setTimeout(r, 400)) }
  }).catch(() => {})
}
