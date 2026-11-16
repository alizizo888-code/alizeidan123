// Words and sentences: everything the hub needs to understand a message and to answer it, with no AI and no cost.
// Ported from the plugin's WhatsApp classes (journey, desk, order bot) so customers hear exactly what they heard before.

export const digits = (s) => String(s || '').replace(/\D/g, '')

/** Saudi numbers in every shape people write them (05…, 5…, 9665…, +966 5…) → 9665XXXXXXXX. */
export function intl(p) {
  let d = digits(p)
  if (/^05\d{8}$/.test(d)) d = '966' + d.slice(1)
  else if (/^5\d{8}$/.test(d)) d = '966' + d
  else if (/^00/.test(d)) d = d.slice(2)
  return d
}
/** The last nine digits: one key for one person, however the number was typed. */
export const pkey = (p) => { const d = digits(p); return d.length >= 8 ? d.slice(-9) : '' }

export function norm(s) {
  let t = String(s || '').toLowerCase()
  t = t.replace(/[ً-ْـؗ-ؚ]/g, '')
  t = t.replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
  t = t.replace(/[٠-٩]/g, (c) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)))
  return t.replace(/\s+/g, ' ').trim()
}
const has = (n, w) => n.includes(norm(w))

/* ------------------------------------------------------------------ the customer */

const YES = ['تاكيد', 'تأكيد', 'اكد', 'أكد', 'مؤكد', 'موكد', 'اكدت', 'أكدت', 'تمام', 'موافق', 'اوكي', 'اوك', 'ok', 'okay', 'نعم', 'ايوه', 'أيوه', 'ايوا', 'اه', 'صح', 'كله صح', 'مظبوط', 'yes', '1', '👍', '✅']
export function isYes(text) {
  const n = norm(String(text || '').replace(/[!.،,؟?]+/g, ' '))
  if (!n) return false
  return YES.some((w) => { const x = norm(w); return n === x || (n.length <= 30 && (' ' + n + ' ').includes(' ' + x + ' ')) })
}

export function coords(text) {
  const m = String(text || '').match(/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/)
  if (!m) return null
  const lat = Number(m[1]); const lng = Number(m[2])
  return Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? [lat, lng] : null
}

const TIME_WORDS = ['اليوم', 'النهارده', 'النهاردة', 'الليلة', 'بكرة', 'بكره', 'باكر', 'بعد بكرة', 'الصبح', 'الصباح', 'الظهر', 'العصر', 'المغرب', 'العشاء', 'العشا', 'بالليل', 'الساعة', 'الساعه', 'دلوقتي', 'الحين', 'حالا', 'الان', 'الآن', 'السبت', 'الاحد', 'الأحد', 'الاثنين', 'الإثنين', 'الثلاثاء', 'الاربعاء', 'الأربعاء', 'الخميس', 'الجمعة', 'صباحا', 'مساء', 'اقرب وقت', 'أقرب وقت']
export function looksLikeTime(text) {
  const n = ' ' + norm(text) + ' '
  if (/\d{1,2}\s*(:\d{2})?/.test(n) && /(ساعه|ص |م |am|pm|:|بعد|قبل|يوم|بكره|اليوم)/.test(n)) return true
  return TIME_WORDS.some((w) => { const x = norm(w); return x.length > 3 && n.includes(x) })
}

const INTENTS = [
  ['human', ['موظف', 'انسان', 'بشري', 'مسؤول', 'كلمني حد', 'اكلم حد', 'خدمه العملاء']],
  ['cancel', ['الغاء', 'الغي', 'كنسل', 'مش عايز الطلب', 'ما ابغى الطلب']],
  ['postpone', ['اجل', 'تاجيل', 'اغير الموعد', 'غير الموعد', 'موعد ثاني', 'ميعاد تاني', 'يوم ثاني', 'يوم تاني', 'مش فاضي', 'مو فاضي', 'بعدين تعال']],
  ['warranty', ['ضمان', 'الضمان']],
  ['pay', ['ادفع', 'دفع', 'فاتوره', 'كم المبلغ', 'كم الحساب', 'تحويل']],
  ['urgent', ['استعجل', 'مستعجل', 'بسرعه', 'اتاخر', 'تاخر', 'متاخر', 'طولتو', 'لسه ما جا', 'لسا ما جا', 'ليش ما وصل', 'ما وصل', 'مجاش', 'منتظر من']],
  ['where', ['فين', 'وين', 'فاضل', 'باقي له', 'باقي كم', 'كم باقي', 'متى يوصل', 'امتى يوصل', 'امتي', 'يوصل', 'مكانه', 'قرب', 'قريب']],
  ['request', ['هات', 'جيب', 'يجيب', 'معاه', 'معه', 'خليه', 'قول للفني', 'قل للفني', 'بلغ الفني', 'يحضر', 'يركب', 'يشيل', 'يتصل بي', 'يكلمني']],
  ['status', ['حاله', 'اخبار', 'وصل لحد فين', 'وش صار', 'ايه الاخبار', 'الطلب', 'خطوات']],
]
export function intent(text) {
  const n = norm(text)
  for (const [k, words] of INTENTS) if (words.some((w) => has(n, w))) return k
  return 'general'
}

/* ------------------------------------------------------------------ the technician */

export const STEPS = {
  accepted: ['قبلت', 'موافق', 'تمام ماشي', 'استلمت الطلب', 'استلمت'],
  en_route: ['في الطريق', 'فى الطريق', 'طالع', 'متوجه', 'خرجت', 'راكب'],
  nearby: ['قربت', 'قريب', 'وصلت الحي', 'هوصل'],
  arrived: ['وصلت', 'انا عند العميل', 'عند العميل', 'نزلت'],
  in_progress: ['بدأت العمل', 'بديت', 'بدأت', 'شغال', 'تحت الشغل'],
  completed: ['تم إنجاز المهمة', 'تم الانجاز', 'خلصت', 'انتهيت', 'تم التنفيذ', 'المهمة تمت'],
}
export const SAID = { accepted: '✅ تم استلام الطلب', en_route: '🚗 سجّلنا إنك في الطريق', nearby: '📍 قرّبت — تمام', arrived: '✅ وصولك مسجّل', in_progress: '🔧 الشغل بدأ', completed: '✅ تم إنجاز المهمة — شكرًا' }
const DONE_ALONE = ['تم', 'خلاص', 'تم الشغل', 'تم العمل', 'خلصنا', 'تمت', 'تمت المهمه', 'الشغل خلص', 'انتهي']
export const PROBLEM = /(مشكل|عطل|محتاج|محتاجين|قطع|غيار|مش هقدر|مقدرتش|ماقدرتش|متاخر|هتاخر|زحمه|مش موجود|مش بيرد|ما بيردش|رافض|رفض|السعر|غالي|اتخانق|زعلان|مش راضي|حادث|خطر|ضرر|كسر|اتكسر|ريحه|حريق|ماس|كهربا)/

/** Which step a technician's message means: the whole message, or the sentence it opens with — never a buried word. */
export function stepOf(text) {
  const t = norm(text)
  if (!t) return ''
  const bare = t.replace(/[!.،,؟?✅👍🙏\s]+/g, ' ').replace(/\d+/g, '').trim()
  if (DONE_ALONE.some((w) => bare === norm(w))) return 'completed'
  let best = ''; let len = 0
  for (const [st, words] of Object.entries(STEPS)) {
    for (const w of words) { const x = norm(w); if ((t === x || t.startsWith(x + ' ')) && x.length > len) { best = st; len = x.length } }
  }
  return best
}

/* ------------------------------------------------------------------ cancelled: why, and can we keep him */

const WB = {
  price: ['1', 'سعر', 'السعر', 'غالي', 'غاليه', 'مكلف', 'فلوس', 'الفلوس'],
  time: ['2', 'موعد', 'الموعد', 'الوقت', 'مش فاضي', 'مسافر', 'مشغول', 'وقت تاني', 'بعدين', 'تاجيل', 'اجل'],
  late: ['3', 'اتاخر', 'تاخير', 'متاخر', 'محدش رد', 'ما حد رد', 'ماحد رد', 'محدش جه', 'ما جا', 'مجاش', 'بطيء'],
  other_co: ['4', 'حد تاني', 'شركه تانيه', 'غيركم', 'لقيت', 'اتصلح', 'اشتغل', 'مش محتاج', 'ما احتاج', 'خلاص', 'استغنيت'],
}
export const WB_LABEL = { price: 'السعر', time: 'الموعد مش مناسب', late: 'التأخير / محدش رد', other_co: 'لقى بديل / مش محتاج', other: 'سبب تاني' }
export function wbReason(text) {
  const n = norm(text)
  for (const [k, words] of Object.entries(WB)) {
    for (const w of words) {
      const x = norm(w)
      if (/^\d$/.test(x)) { if (new RegExp('(^|\\s)' + x + '(\\s|$)').test(n)) return k; continue }
      if (n.includes(x)) return k
    }
  }
  return /(^|\s)5(\s|$)/.test(n) ? 'other' : ''
}
const BACK = ['رجعه', 'رجّعه', 'رجعو', 'ارجعه', 'رجع الطلب', 'ارجع الطلب', 'كمل', 'كمّل', 'كملوا', 'موافق', 'تمام', 'ماشي', 'اوكي', 'ok', 'نعم', 'ايوه', 'ابغاه', 'ابغى', 'عايزه', 'خلاص رجعه']
export function wantsBack(text) {
  const n = norm(String(text || '').replace(/[!.،,؟?]+/g, ' '))
  return BACK.some((w) => { const x = norm(w); return n === x || (n.length <= 25 && n.includes(x)) })
}

/* ------------------------------------------------------------------ the operations room */

// What the room (or any staff phone) can type. Everything else from a staff phone gets the help card.
export function roomCommand(text) {
  const t = String(text || '').trim()
  let m
  if (!t || /^(مساعدة|الاوامر|الأوامر|help)$/i.test(t)) return { op: 'help' }
  if ((m = t.match(/^(?:رد|ابعت)\s*#?(\d{3,})\s+([\s\S]+)$/))) return { op: 'reply', num: m[1], text: m[2].trim() }
  if ((m = t.match(/^(?:اعتمد|وزع|وزّع)\s*(?:ال)?(?:طلب\s*)?#?(\d{3,})/))) return { op: 'approve', num: m[1] }
  if ((m = t.match(/^(?:وجه|وجّه|اسند|أسند|حوّل|حول)\s*(?:ال)?(?:طلب\s*)?(?:رقم\s*)?#?(\d{3,})\s*(?:الى|إلى|ل|لـ)?\s*(.+)$/))) return { op: 'assign', num: m[1], name: m[2].replace(/^ل\s*/, '').trim() }
  if ((m = t.match(/^(?:اسحب|فك)\s*(?:ال)?(?:طلب\s*)?#?(\d{3,})/))) return { op: 'unassign', num: m[1] }
  if ((m = t.match(/^(?:الغي|ألغي|إلغي|الغى|ألغى|الغاء|إلغاء|كنسل)\s*(?:ال)?(?:طلب\s*)?(?:رقم\s*)?#?(\d{3,})\s*([\s\S]*)$/))) return { op: 'cancel', num: m[1], why: m[2].trim() }
  if ((m = t.match(/^(?:رجع|رجّع|ارجع)\s*(?:ال)?(?:طلب\s*)?#?(\d{3,})/))) return { op: 'reopen', num: m[1] }
  if ((m = t.match(/^طلب\s*#?(\d{3,})$/))) return { op: 'show', num: m[1] }
  if (/^(تقرير|الحالة|الوضع|ملخص)/.test(t)) return { op: 'report', what: 'report' }
  if (/^(الفنيين|حالة الفنيين|فين الفنيين)/.test(t)) return { op: 'report', what: 'techs' }
  if (/^(الطلبات|المعلقة|المعلقه|المفتوحة|المفتوحه)/.test(t)) return { op: 'report', what: 'orders' }
  if (/^(الملغي|الملغيه|الملغية)/.test(t)) return { op: 'report', what: 'cancelled' }
  return null
}
export const ROOM_HELP = '*أوامر غرفة العمليات*\n'
  + '📋 *تقرير* — ملخص اليوم · 👷 *الفنيين* · 🗂 *الطلبات* · ❌ *الملغي*\n'
  + '✅ *اعتمد 1002* — لأنسب فني · ➡️ *وجّه 1002 لمحمود* — لفني بالاسم\n'
  + '↩️ *اسحب 1002* — يرجع للعمليات · ❌ *الغي 1002 السبب* · ♻️ *رجع 1002*\n'
  + '📤 *رد 1002 النص* — يوصل للعميل من خطه · 🔎 *طلب 1002* — بيانات الطلب'

/* ------------------------------------------------------------------ small text helpers */

export const sign = (site, text) => (!site || text.includes('— *' + site + '*')) ? text : text.replace(/\s+$/, '') + '\n\n— *' + site + '* 🌷'
export function missing(o) {
  const miss = []
  if (!o.appointment && !String(o.preferredTime || '').trim()) miss.push('time')
  if (!(Number.isFinite(Number(o.lat)) && o.lat !== null && o.lat !== '') && !String(o.address || '').trim()) miss.push('place')
  return miss
}
export const missingText = (miss) => [
  ...(miss.includes('time') ? ['📅 الموعد المناسب ليك (مثال: بكرة العصر، أو اليوم الساعة 6)'] : []),
  ...(miss.includes('place') ? ['📍 موقعك: ابعت اللوكيشن من واتساب (📎 ← الموقع) أو اكتب الحي والعنوان'] : []),
]
export function summary(o) {
  const L = []
  if (o.service) L.push('🛠 الخدمة: ' + String(o.service).slice(0, 120))
  if (o.address) L.push('🏠 العنوان: ' + String(o.address).slice(0, 140))
  if (o.lat !== null && o.lat !== undefined && o.lat !== '' && Number.isFinite(Number(o.lat))) L.push('📍 اللوكيشن: https://maps.google.com/?q=' + o.lat + ',' + o.lng)
  if (o.appointment || o.preferredTime) L.push('📅 الموعد: ' + String(o.appointment || o.preferredTime).slice(0, 80))
  if (Number(o.price) > 0) L.push('💰 السعر: ' + o.price + ' ر.س')
  return L
}
export const num = (o) => String(o.number || String(o.id || '').slice(-5))
