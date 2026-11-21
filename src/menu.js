// «القائمة» — the customer picks, the bot does not guess (owner, 2026-11-21).
//
// The conversation that went wrong: «مكيف اسبليت و مكيف شباك» was read as one split unit, «خليها 100 المكيفين» was taken
// as the agreed price, «أنا ابغى 2 مكيف» was answered three times with the same summary, and the customer cursing
// got «تمام، سجلت ملاحظتك». So: the customer chooses from numbered options, the counts are read per type, the price is
// the owner's table computed on the website, haggling moves in fixed steps to the owner's floor and never below, and
// nothing the customer claims («انت قلت 100») is believed unless it is at or above what the owner allows.
//
// Pure helpers here (tested on their own); the conversation itself lives in brain.js.
import { norm } from './text.js'

const NUMW = { واحد: 1, واحده: 1, وحده: 1, احد: 1, اتنين: 2, اثنين: 2, ثنين: 2, تنين: 2, تلاته: 3, ثلاثه: 3, تلات: 3, ثلاث: 3, اربعه: 4, اربع: 4, خمسه: 5, خمس: 5, سته: 6, ست: 6, سبعه: 7, سبع: 7, تمنيه: 8, ثمانيه: 8, تمن: 8, تسعه: 9, تسع: 9, عشره: 10, عشر: 10 }
const FILLER = new Set(['مكيف', 'مكيفات', 'جهاز', 'اجهزه', 'وحده', 'و', 'نوع', 'غسيل', 'عدد'])

/** A count in a word or digits: «3», «٣», «تلاته», «مكيفين» → 2. */
export function countOf(tok) {
  let t = norm(tok)
  if (NUMW[t] === undefined && /^و/.test(t) && (NUMW[t.slice(1)] !== undefined || /^\d{1,2}$/.test(t.slice(1)))) t = t.slice(1)   // «واتنين», «و3»
  if (/^\d{1,2}$/.test(t)) return Number(t)
  if (NUMW[t] !== undefined) return NUMW[t]
  if (/^(مكيفين|جهازين|وحدتين)$/.test(t)) return 2
  return null
}

/** The first number in a reply («2», «٢ مكيف», «اتنين») or null. */
export function numberIn(text) {
  const n = norm(text)
  const m = n.match(/(?:^|\s)(\d{1,2})(?:\s|$)/)
  if (m) return Number(m[1])
  for (const w of n.split(' ')) { const c = countOf(w); if (c) return c }
  return null
}

/**
 * The AC units in his words, per type: «مكيف اسبليت و مكيف شباك» → {split:1, window:1}; «2 مكيف واحد شباك وواحد
 * اسبليت» → {split:1, window:1}; «3 سبليت» → {split:3}; «مكيفين» alone → {any:2} (type still to ask).
 */
export function parseAc(text) {
  const n = norm(text).replace(/اسبليت|سبلت|اسبلت|سبيلت/g, 'سبليت').replace(/شبابيك/g, 'شباك')
  const tok = n.split(' ').filter(Boolean)
  const out = { split: 0, window: 0, other: 0, any: 0, big: 0, otherType: '', bigType: '' }
  // standing and cassette are priced from the table («other»); duct, concealed, package and central are surveyed first («big»)
  const kind = (w) => (/سبليت/.test(w) ? 'split' : /شباك/.test(w) ? 'window' : /(دولابي|عمودي|فريستاند)/.test(w) ? 'other:standing' : /(كاسيت|سقفي)/.test(w) ? 'other:cassette'
    : /(داكت|دكت)/.test(w) ? 'big:duct' : /(كونسيلد|كونسلد|مخفي)/.test(w) ? 'big:concealed' : /(باكيدج|باكج|بكج|باكدج)/.test(w) ? 'big:package' : /(مركزي|تشيلر)/.test(w) ? 'big:central' : '')
  let typed = false
  tok.forEach((w, i) => {
    let k = kind(w); if (!k) return
    typed = true
    if (k.includes(':')) { const [a, b] = k.split(':'); k = a; out[a + 'Type'] = out[a + 'Type'] || b }
    let q = null
    for (let j = i - 1; j >= Math.max(0, i - 3); j--) {
      const c = countOf(tok[j]); if (c) { q = c; break }
      if (!FILLER.has(tok[j].replace(/^و/, '')) && !/^مكيف/.test(tok[j])) break
    }
    if (q === null && tok[i + 1]) q = countOf(tok[i + 1])   // «سبليت 2»
    // «سبليت دولابي»: one standing unit, not a split plus a standing
    if (k === 'other' && i > 0 && /سبليت/.test(tok[i - 1]) && out.split) { out.other += out.split; out.split = 0; return }
    out[k] += q || 1
  })
  if (!typed) {
    const m = n.match(/(\d{1,2})\s*(مكيف|جهاز|وحده)/)
    if (m) out.any = Number(m[1])
    else if (/(مكيفين|جهازين)/.test(n)) out.any = 2
    else { for (let i = 0; i < tok.length - 1; i++) { const c = countOf(tok[i]); if (c && /^مكيف/.test(tok[i + 1])) { out.any = c; break } } }
  }
  out.units = out.split + out.window + out.other
  out.found = out.units > 0 || out.any > 0
  return out
}

export const ABUSE = /(غبي|اغبي|حمار|زفت|تافه|اهبل|هبل|اعمي|يلعن|لعنه|كلب|حيوان|خرا|زباله|نصاب|حرامي|كذاب|غشاش|تخلف|متخلف)/
export const isAbuse = (t) => ABUSE.test(norm(t))
/** «إيقاف» and its kin: take me off your lists. Only the whole message counts («وقف الطلب» is not a stop). */
export const isStop = (t) => /^(ايقاف|وقف|توقف|الغاء الاشتراك|لا ترسل|لاترسل|stop|unsubscribe|لا تراسلني|ما ابغي رسايل|ما ابي رسايل|ابغي اوقف الرسايل)$/.test(norm(t).replace(/[!.،,؟?]/g, '').trim())
/** A request that names a non-AC section: its section number in SECTIONS, or 0. AC words always win (the AC menu). */
export function sectionOf(t) {
  const n = norm(t)
  if (/(مكيف|تكييف|سبليت|اسبليت|شباك|كاسيت|دولابي|مخفي)/.test(n)) return 0
  if (/(غرفه تبريد|غرف تبريد|غرفه تجميد|غرف تجميد|تجميد|فريزر|ثلاجه عرض|ثلاجات عرض|براد)/.test(n)) return 2
  if (/(سباك|سباكه|تسريب|تسرب|مويه|مياه|مجاري|مجرى|بلاعه|حمام|مغسله|سيفون|خلاط|سخان|شطاف|انسداد|مسدود)/.test(n)) return 3
  if (/(كهربا|كهرباء|كهربائي|فيش|افياش|اناره|لمبه|لمبات|قاطع|طبلون|لوحه كهربا|تمديدات كهربا|شورت|ماس)/.test(n)) return 4
  if (/(نجار|نجاره|باب|ابواب|دولاب|دواليب|خشب|اثاث|غرفه نوم|مطبخ|مطابخ|قفل|اقفال|كالون)/.test(n)) return 5
  if (/(كاميرا|كاميرات|مراقبه|دش|رسيفر|ستلايت|ساتلايت|قنوات)/.test(n)) return 6
  if (/(دهان|دهانات|بويه|ديكور|ترميم|بلاط|تبليط|سيراميك|غساله|غسالات|ثلاجه|ثلاجات|نشافه|فرن)/.test(n)) return 7
  return 0
}
export const isMenu = (t) => /^(0|٠|القائمه|قائمه|القائمة|قائمة|منيو|menu|الرئيسيه|ابدا من جديد|من الاول|رجوع للقائمه)$/.test(norm(t).replace(/[!.،,؟?]/g, '').trim())

/** The owner's three videos (2026-11-21), kept in the hub's settings so he can change them. */
export const WASH_VIDEOS = [
  { label: 'غسيل المكيف السبليت', url: 'https://youtu.be/wvtQ_aztCrE' },
  { label: 'غسيل المكيف السبليت (تيك توك)', url: 'https://vt.tiktok.com/ZSCTdsF4N/' },
  { label: 'غسيل المكيف الشباك', url: 'https://youtu.be/qz8gaYjDHmI' },
]

export const HOME = [
  'اختار رقم الخدمة 👇',
  '1️⃣ غسيل مكيفات',
  '2️⃣ صيانة وإصلاح أعطال',
  '3️⃣ تركيب أو فك ونقل مكيف',
  '4️⃣ جدول الأسعار',
  '5️⃣ طريقة الغسيل (فيديو)',
  '6️⃣ طلبي: متابعة / تأجيل / إلغاء',
  '7️⃣ كلّم الدعم الفني (موظف)',
].join('\n')

/**
 * «إيش تحتاج؟» (owner, 2026-11-21): a bare «السلام عليكم» gets the company's welcome and the sections — not every AC
 * detail at once. Picking a section shows only its own services; the AC section opens the AC menu (HOME).
 */
export const SECTIONS = [
  'اختار القسم 👇',
  '1️⃣ المكيفات (غسيل • صيانة • تركيب)',
  '2️⃣ غرف التبريد والتجميد',
  '3️⃣ السباكة',
  '4️⃣ الكهرباء',
  '5️⃣ النجارة',
  '6️⃣ الكاميرات والدش',
  '7️⃣ خدمات تانية (دهان، ترميم، أجهزة منزلية…)',
  '8️⃣ طلبي: متابعة / تأجيل / إلغاء',
  '9️⃣ كلّم موظف',
].join('\n')
/** Each section's own services: [label, spec on the website]. */
export const SUBS = {
  2: { name: 'غرف التبريد والتجميد', icon: '❄️', items: [['صيانة وإصلاح عطل', 'fridge'], ['تركيب غرفة جديدة', 'fridge'], ['فحص وشحن فريون', 'fridge'], ['عقد صيانة دورية', 'fridge']], ex: 'الغرفة مش بتبرد، الكمبروسر بيفصل، تلج على المبخر' },
  3: { name: 'السباكة', icon: '🚰', items: [['تسريب مياه', 'plumbing'], ['تسليك مجاري', 'plumbing'], ['تركيب أدوات صحية (خلاط، مغسلة، سيفون)', 'plumbing'], ['سخانات', 'plumbing'], ['تمديدات جديدة', 'plumbing']], ex: 'تسريب تحت المغسلة، الحمام مسدود' },
  4: { name: 'الكهرباء', icon: '⚡', items: [['عطل أو فصل كهربا', 'electric'], ['تركيب إنارة وأفياش', 'electric'], ['تمديدات جديدة', 'electric'], ['لوحة كهربا', 'electric']], ex: 'الكهربا بتفصل في غرفة، فيش محروق' },
  5: { name: 'النجارة', icon: '🪚', items: [['تصليح أبواب ودواليب', 'general'], ['تركيب غرف نوم ومطابخ', 'general'], ['فك وتركيب أثاث', 'general'], ['أقفال', 'locks']], ex: 'باب الغرفة مش بيقفل، دولاب محتاج تركيب' },
  6: { name: 'الكاميرات والدش', icon: '📹', items: [['تركيب كاميرات مراقبة', 'cameras'], ['صيانة كاميرات', 'cameras'], ['تركيب دش ورسيفر', 'satellite'], ['ضبط القنوات', 'satellite']], ex: '4 كاميرات للبيت، الدش مفيهوش إشارة' },
  7: { name: 'خدمات تانية', icon: '🛠', items: [['دهان وديكور', 'paint'], ['ترميم وتبليط', 'tiling'], ['أجهزة منزلية (غسالات، ثلاجات)', 'appliances'], ['حاجة تانية', 'general']], ex: 'غسالة مش بتصرف، دهان شقة 3 غرف' },
}
export const subMenu = (k) => { const s = SUBS[k]; return s ? s.icon + ' *' + s.name + '* — إيش المطلوب؟\n' + s.items.map((x, i) => ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣'][i] + ' ' + x[0]).join('\n') : '' }
/** A message that is only a greeting («السلام عليكم», «مرحبا», «هلا») — nothing asked yet. */
export const isGreeting = (t) => /^(السلام عليكم( ورحمه الله( وبركاته)?)?|سلام عليكم|سلام|السلام|مرحبا|مرحبًا|مرحبتين|اهلا|أهلا|اهلين|هلا|هلا والله|هاي|hi|hello|صباح الخير|مساء الخير|صباح النور|مساء النور|يعطيكم العافيه|كيف الحال|السلام عليكم كيف الحال)$/.test(norm(t).replace(/[!.،,؟?🌷👋🙏]/gu, '').trim())

export const TYPES = '1️⃣ سبليت\n2️⃣ شباك\n3️⃣ سبليت + شباك\n4️⃣ دولابي\n5️⃣ كاسيت\n6️⃣ كونسيلد / داكت (مخفي)\n7️⃣ باكيدج / مركزي'
/** Every A/C type by its key: what the site's order and every message call it. */
export const AC_NAMES = { split: 'سبليت', window: 'شباك', standing: 'دولابي', cassette: 'كاسيت', concealed: 'كونسيلد', duct: 'داكت', package: 'باكيدج', central: 'مركزي' }
/** The type question for repair / install: number → key. */
export const KINDS = '1️⃣ سبليت\n2️⃣ شباك\n3️⃣ دولابي\n4️⃣ كاسيت\n5️⃣ كونسيلد / داكت (مخفي)\n6️⃣ باكيدج / مركزي'
export const KIND_OF = { 1: 'split', 2: 'window', 3: 'standing', 4: 'cassette', 5: 'concealed', 6: 'package' }
export const FOOT = '\n\n0️⃣ القائمة الرئيسية'

const r5 = (v) => Math.max(5, Math.round(v / 5) * 5)

/**
 * One haggling turn, by the owner's rules only: m = {price:{total,floor}, offer, round, agreed}; money = what he named
 * (or null for «عايز سعر أقل»). Returns {agreed?, offer, text}. Nothing under the floor, whatever he says he was told.
 */
export function haggle(m, money) {
  const P = m.price; const floor = Math.min(P.floor, P.total)
  if (!m.offer) m.offer = P.total
  if (money && money >= m.offer) { m.agreed = m.offer; return { agreed: m.offer, text: 'اتفقنا ✅ *' + m.offer + ' ر.س* لـ' + P.label + '.' } }
  if (money && money >= floor && (m.round || 0) >= 1) { const v = Math.min(m.offer, Math.max(floor, r5(money))); m.agreed = v; m.offer = v; return { agreed: v, text: 'عشان خاطرك 🌷 اتفقنا على *' + v + ' ر.س* لـ' + P.label + '.' } }
  m.round = (m.round || 0) + 1
  if (floor >= P.total) return { offer: m.offer, text: 'آسفين جدًا 🙏 السعر ده هو السعر الرسمي النهارده ومفيش عليه خصم، وشامل الغسيل الكامل بالمضخة والضمان.' }
  if (m.round === 1) { m.offer = Math.max(floor, r5(P.total - (P.total - floor) / 2)); return { offer: m.offer, text: (money ? 'للأسف ' + money + ' ر.س أقل من تكلفة الشغل 🙏 ' : '') + 'عشانك نقدر نخليها *' + m.offer + ' ر.س* بدل ' + P.total + '.' } }
  if (m.round === 2) { m.offer = floor; return { offer: floor, text: (money ? 'مقدرش أنزل لـ ' + money + ' 🙏 ' : '') + 'ده آخر سعر نقدر عليه: *' + floor + ' ر.س* — وشامل الضمان والغسيل الكامل.' } }
  return { offer: m.offer, text: 'آسفين جدًا 🙏 ' + m.offer + ' ر.س هو أقل سعر ممكن، ومقدرش أنزل عنه. لو حابب تكلّم الدعم الفني اكتب *7*.' }
}

/** The appointment choices for the hour it is now (the owner's rule: 7 am–10 pm today, from 11 pm tomorrow). */
export function timeChoices(hour) {
  if (hour >= 7 && hour < 22) return { mode: 'today', opts: ['اليوم — أقرب وقت ممكن', 'بكره الصبح', '__other'] }
  if (hour === 22) return { mode: 'late', opts: ['بكره الصبح بدري', 'الليلة لو فيه فني متاح (العمليات هتأكد)', '__other'] }
  return { mode: 'tomorrow', opts: [(hour < 7 ? 'النهارده الصبح' : 'بكره الصبح') + ' بدري', '__other'] }
}
export function timeText(tc) {
  const head = tc.mode === 'today' ? '📅 تحب الموعد إمتى؟' : tc.mode === 'late' ? '🌙 الساعة دلوقتي بعد 10 بالليل — تحب الموعد إمتى؟' : '🌙 إحنا دلوقتي بعد 11 بالليل، والفنيين بيبدأوا من 7 الصبح — تحب الموعد إمتى؟'
  return head + '\n' + tc.opts.map((o, i) => ['1️⃣', '2️⃣', '3️⃣'][i] + ' ' + (o === '__other' ? 'وقت تاني (اكتبه)' : o)).join('\n')
}
