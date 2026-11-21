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
  const out = { split: 0, window: 0, other: 0, any: 0 }
  const kind = (w) => (/سبليت/.test(w) ? 'split' : /شباك/.test(w) ? 'window' : /(كاسيت|مخفي|دولابي|كونسيلد|مركزي|داكت)/.test(w) ? 'other' : '')
  let typed = false
  tok.forEach((w, i) => {
    const k = kind(w); if (!k) return
    typed = true
    let q = null
    for (let j = i - 1; j >= Math.max(0, i - 3); j--) {
      const c = countOf(tok[j]); if (c) { q = c; break }
      if (!FILLER.has(tok[j].replace(/^و/, '')) && !/^مكيف/.test(tok[j])) break
    }
    if (q === null && tok[i + 1]) q = countOf(tok[i + 1])   // «سبليت 2»
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

export const TYPES = '1️⃣ سبليت\n2️⃣ شباك\n3️⃣ سبليت + شباك\n4️⃣ كاسيت / مخفي / دولابي'
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
