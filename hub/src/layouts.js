// Recipes = persona (7 thinking styles) × layout (6 page orders) × register (3 tones) × length (2) = 252 distinct ways to present one topic.
// The layouts decide what the visitor sees FIRST: the site (shop front), the article, the Q&A, the checklist, a compact hero... — never the same for two neighbours.
const SF = require('../../blogger/storefront'), PE = require('./generators/personas'), D = require('./diversity')
const E = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const H2 = 'font-size:20px;font-weight:800;color:#075985;margin:22px 0 10px;padding-right:10px;border-right:5px solid #0ea5e9'
const LAYOUTS = ['front_first', 'article_first', 'explain_first', 'hero_only', 'qa_first', 'checklist_first']
const REGISTERS = ['formal', 'systematic', 'friendly'], LENGTHS = ['full', 'compact']
const ORD = ['أولاً', 'ثانياً', 'ثالثاً', 'رابعاً', 'خامساً', 'سادساً', 'سابعاً', 'ثامناً', 'تاسعاً', 'عاشراً', 'حادي عشر', 'ثاني عشر', 'ثالث عشر', 'رابع عشر', 'خامس عشر', 'سادس عشر', 'سابع عشر', 'ثامن عشر', 'تاسع عشر', 'العشرون', 'الحادي والعشرون', 'الثاني والعشرون']
const OPEN = {
  formal: [(n, t) => `<p>يسرّنا في ${E(n)} أن نضع بين يديكم هذا العرض المنظّم حول: ${E(t)}، بما يساعدكم على اتخاذ قرار مدروس قبل التنفيذ.</p>`, (n, t) => `<p>تقدّم ${E(n)} هذه الإفادة المختصرة بشأن ${E(t)}، مع بيان الخطوات المعتمدة في الزيارة والمتابعة.</p>`],
  systematic: [(n, t) => `<p><b>موضوع الدليل:</b> ${E(t)}.<br><b>الجهة:</b> ${E(n)}.<br><b>الغرض:</b> بيان الحالات والإجراءات والبنود التي يجب التحقق منها قبل التنفيذ وبعده.</p>`],
  friendly: [(n, t) => `<p>خلّينا نختصرها لك: إذا كان موضوعك ${E(t)}، فهذه الصفحة تشرح لك بالترتيب ماذا يحدث ومتى تتصل بنا.</p>`, (n, t) => `<p>مو لازم تقرأ كل شيء بسرعة؛ ابدأ بما يهمك من القائمة تحت، وإذا الوضع عاجل تواصل معنا مباشرة.</p>`],
}
const CLOSE = { formal: '<p>وتفضلوا بقبول فائق الاحترام والتقدير.</p>', systematic: '<p><b>انتهى الدليل.</b> يُراجَع البند المناسب لحالتك قبل الطلب.</p>', friendly: '<p>نخدمك بكل سرور، وأي سؤال ما تلقاه هنا اسألنا فيه مباشرة.</p>' }
const split = (html) => { const parts = html.split(/(?=<h2)/), head = parts.shift(), isTail = (s) => /<h2>(أسئلة شائعة|ما الذي يسأله|قبل أن تقرر|أسئلة|أجوبة|أين نقدم|اطلب الآن)/.test(s); return { head, mid: parts.filter((s) => !isTail(s)), tail: parts.filter(isTail) } }
const hOf = (s) => (s.match(/<h2>(.*?)<\/h2>/) || [, ''])[1], isFaq = (s) => /<h2>(أسئلة|ما الذي يسأله|قبل أن تقرر|أجوبة)/.test(s)
function hero(b, title) {
  const app = `https://oxygen11.com/app/?via=${b.id}#/`
  return `<!--oxf--><div dir="rtl" style="background:#0b3b73;color:#fff;border-radius:14px;padding:16px;text-align:center"><p style="font-size:20px;font-weight:900;margin:0 0 6px;color:#fff">${E(title)}</p><p style="margin:0 0 10px;color:#e0f2fe;font-size:14px">${E(b.name)} · مكة وجدة</p><a href="${app}new" style="display:inline-block;background:#fbbf24;color:#4a2c00;font-weight:800;border-radius:99px;padding:9px 22px;text-decoration:none">اطلب الفني الآن</a></div>`
}
exports.recipes = () => { const r = []; for (const p of Object.keys(PE.PERSONAS)) for (const l of LAYOUTS) for (const g of REGISTERS) for (const n of LENGTHS) r.push([p, l, g, n].join('|')); return r }
// least-recently-used recipe across the network (recent = list of recipe ids), seeded tie-break; the site's persona is preferred but not forced
exports.choose = (recent, personaId, seed) => {
  const all = exports.recipes(), cnt = new Map(); recent.forEach((r, i) => cnt.set(r, (cnt.get(r) || 0) + 1 + (recent.length - i) / 1000))
  const pick = all.map((r) => [r, (cnt.get(r) || 0) + (r.startsWith(personaId + '|') ? -0.5 : 0) + [...(seed + r)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 997, 7) / 10000]).sort((a, b) => a[1] - b[1])
  return pick[0][0]
}
exports.compose = (raw, b, title, recipe, seed) => {
  const [persona, layout, register, len] = recipe.split('|')
  let html = PE.shape(raw, persona, seed, D.bodyWords, D.MIN_WORDS)
  const { head, mid, tail } = split(html)
  let m = mid.slice()
  if (len === 'compact') { while (m.length > 6 && D.bodyWords(head + m.slice(0, -1).join('') + tail.join('')) >= 1250) m.pop() }   // shorter variant, never below the minimum
  if (register === 'systematic') { let i = 0; m = m.map((s) => s.replace(/<h2>/, `<h2>${ORD[i++ % ORD.length]}: `)) }
  const op = OPEN[register][Math.floor(seed.length % OPEN[register].length)](b.name, title), cl = CLOSE[register]
  const faq = tail.filter(isFaq), rest = tail.filter((s) => !isFaq(s)), front = SF.front(b, title)
  const sign = (t) => `<h2 style="${H2};border-top:3px dashed #bae6fd;padding-top:16px;margin-top:28px">${t}</h2>`
  const first = (re) => m.filter((s) => re.test(hOf(s))), others = (re) => m.filter((s) => !re.test(hOf(s)))
  let out
  switch (layout) {
    case 'front_first': out = front + sign('شرح المقال: ' + E(title)) + op + head + m.join('') + faq.join('') + rest.join('') + cl; break
    case 'article_first': out = op + head + m.slice(0, 2).join('') + front.replace('<!--oxf-->', '<!--oxf-->') + sign('تابع الشرح') + m.slice(2).join('') + faq.join('') + rest.join('') + cl; break
    case 'explain_first': out = '<!--oxf-->' + op + head + m.join('') + faq.join('') + rest.join('') + cl + sign('خدماتنا ووجهاتنا في لمحة') + front.replace('<!--oxf-->', ''); break
    case 'hero_only': out = hero(b, title) + op + head + m.join('') + faq.join('') + rest.join('') + cl; break
    case 'qa_first': out = op + head + faq.join('') + front + sign('شرح المقال') + m.join('') + rest.join('') + cl; break
    case 'checklist_first': { const c = first(/علامات|جهّز|ماذا تفعل/); out = hero(b, title) + op + head + c.join('') + front.replace('<!--oxf-->', '') + sign('شرح المقال') + others(/علامات|جهّز|ماذا تفعل/).join('') + faq.join('') + rest.join('') + cl; break }
  }
  return out
}
exports.LAYOUTS = LAYOUTS
