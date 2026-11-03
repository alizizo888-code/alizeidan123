// Blogger article layout (owner order 2026-11-03): every article opens with the same shop front as the app's home page
// (hero + direct contact, offers banners, specialties, destinations) and the article explanation comes LAST under «شرح المقال».
// Inline styles only (Blogger's editor keeps them; <style>/<script> are stripped). Idempotent: a wrapped article carries <!--oxf-->.
//   module:  const { wrap } = require('./storefront'); wrap(blogEntry, title, html) -> html
//   CLI:     node blogger/storefront.js blogger/content/queue.json   (wraps every article of {brand:[[title,labels,html],...]} in place)
const fs = require('fs'), path = require('path')
const HUB = 'https://oxygen11.com'
const net = JSON.parse(fs.readFileSync(path.join(__dirname, 'network.json'), 'utf8'))
const D = JSON.parse(fs.readFileSync(path.join(__dirname, 'content', 'districts.json'), 'utf8'))
const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const TILES = [['ac', '❄️', 'تكييف: غسيل وصيانة وتركيب', 1], ['fridge', '🧊', 'غرف التبريد والتجميد والثلاجات', 1], ['electric', '⚡', 'كهرباء'], ['plumbing', '🚿', 'سباكة'], ['appliances', '🧺', 'أجهزة منزلية'], ['cleaning', '🧽', 'تنظيف وتعقيم'],
  ['pest', '🐜', 'مكافحة الحشرات'], ['tanks', '🚰', 'خزانات المياه'], ['locks', '🔐', 'أقفال ذكية'], ['cameras', '📹', 'كاميرات مراقبة'], ['paint', '🎨', 'دهان وديكور'], ['tiling', '🧱', 'تبليط وترميم'],
  ['moving', '📦', 'نقل الأثاث'], ['solar', '☀️', 'طاقة شمسية'], ['satellite', '📡', 'دش وساتلايت'], ['corporate', '🏢', 'خدمات الشركات']]
const btn = (href, bg, big, small) => `<a href="${href}" style="flex:1 1 30%;min-width:96px;background:${bg};color:#fff;text-decoration:none;border-radius:14px;padding:11px 6px;text-align:center;font-weight:800;font-size:13px;line-height:1.5"><span style="display:block;font-size:22px">${big[0]}</span>${e(big[1])}<span style="display:block;font-weight:600;font-size:10px;opacity:.9">${e(small)}</span></a>`
const H2 = 'font-size:20px;font-weight:800;color:#075985;margin:22px 0 10px;padding-right:10px;border-right:5px solid #0ea5e9'

function front(b, title) {
  const app = `${HUB}/app/?via=${b.id}#/`, blog = (b.live_url || b.blogspot || '/').replace(/\/?$/, '/')
  let h = '<!--oxf--><div dir="rtl" style="font-family:inherit;line-height:1.9">'
  h += `<div style="background:linear-gradient(135deg,#0b3b73,#0c4a6e 60%,#0ea5e9);color:#fff;border-radius:18px;padding:20px 16px;text-align:center">`
    + `<span style="display:inline-block;background:#fbbf24;color:#4a2c00;font-weight:800;font-size:12px;border-radius:99px;padding:2px 12px">${e(b.name)} · مكة وجدة</span>`
    + `<p style="font-size:22px;font-weight:900;line-height:1.5;margin:8px 0 6px;color:#fff">${e(title)}</p>`
    + `<p style="font-size:14px;margin:0 0 12px;color:#e0f2fe">فنيون ومهندسون مختصون، حجز مباشر بلا تسجيل، وتأكيد فوري. اتصل الآن أو اطلب فنيًا لموقعك.</p>`
    + `<div style="display:flex;gap:8px;flex-wrap:wrap">${btn(app + 'contact', 'linear-gradient(135deg,#f43f5e,#db2777)', ['📞', 'اتصال مباشر'], 'تحدث مع خدمة العملاء')}${btn(app + 'new', 'linear-gradient(135deg,#0ea5e9,#2563eb)', ['🛠️', 'اطلب فني الآن'], 'خدمة سريعة 24 ساعة')}${btn(app + 'ai', 'linear-gradient(135deg,#10b981,#16a34a)', ['💬', 'اسأل المساعد'], 'رد سريع وفوري')}</div></div>`
  // offers banners
  const offers = [['حجز سريع', 'اطلب فني صيانة الآن بدون تسجيل', app + 'new'], ['ضمان', 'غسيل وصيانة المكيفات وغرف التبريد بضمان', app + 'services'], ['عروض', 'تصفح عروض الأسبوع واحجز بخصم', app + 'offers']]
  h += `<div style="display:flex;gap:8px;overflow-x:auto;margin:12px 0">` + offers.map((o) => `<a href="${o[2]}" style="flex:0 0 78%;background:linear-gradient(120deg,#0b3b73,#071a33);color:#fff;text-decoration:none;border-radius:16px;padding:14px"><span style="background:#fbbf24;color:#4a2c00;font-size:11px;font-weight:800;border-radius:99px;padding:1px 10px">${o[0]}</span><br><b style="font-size:16px">${e(o[1])}</b><br><span style="background:#0ea5e9;border-radius:99px;padding:3px 14px;font-size:13px;font-weight:800;display:inline-block;margin-top:6px">اطلب الآن</span></a>`).join('') + '</div>'
  // specialties — cooling first
  h += `<h2 style="${H2}">تخصصاتنا</h2><div style="display:flex;flex-wrap:wrap;gap:8px">` + TILES.map((t) => `<a href="${app}new?specialty=${t[0]}" style="flex:1 1 22%;min-width:92px;background:${t[3] ? 'linear-gradient(180deg,#e0f2fe,#fff)' : '#fff'};border:1px solid ${t[3] ? '#38bdf8' : '#bae6fd'};border-radius:12px;padding:8px 4px;text-align:center;text-decoration:none;color:#0f172a;font-weight:800;font-size:12px;line-height:1.4"><span style="display:block;font-size:22px">${t[1]}</span>${e(t[2])}</a>`).join('') + '</div>'
  // destinations — Makkah first (70% of the chips)
  const chip = (txt, q, mk) => `<a href="${blog}search?q=${encodeURIComponent(q)}" style="display:inline-block;margin:3px;padding:5px 13px;border-radius:99px;font-size:13px;font-weight:700;text-decoration:none;${mk ? 'background:#0ea5e9;color:#fff' : 'background:#f0f9ff;border:1px solid #bae6fd;color:#075985'}">${e(txt)}</a>`
  h += `<h2 style="${H2}">وجهاتنا: نصلك أينما كنت</h2><div>` + chip('مكة المكرمة', 'مكة', 1) + D.makkah.slice(0, 14).map((d) => chip('حي ' + d, d, 1)).join('') + chip('جدة', 'جدة', 0) + D.jeddah.slice(0, 6).map((d) => chip('حي ' + d, d, 0)).join('') + '</div>'
  return h + '</div>'
}

/** wrap one article: shop front first, the explanation (the article itself) last */
function wrap(b, title, html) {
  if (String(html).includes('<!--oxf-->')) return html
  return front(b, title) + `<h2 style="${H2};border-top:3px dashed #bae6fd;padding-top:16px;margin-top:28px">شرح المقال: ${e(title)}</h2>` + html
}
module.exports = { wrap, front }

if (require.main === module) {
  const f = process.argv[2]; if (!f) { console.error('usage: node blogger/storefront.js <queue.json>'); process.exit(1) }
  const q = JSON.parse(fs.readFileSync(f, 'utf8')); let n = 0
  for (const [brand, list] of Object.entries(q)) {
    const b = net.find((x) => x.id === brand); if (!b) continue
    const arr = Array.isArray(list[0]) ? list : [list]
    for (const it of arr) { const w = wrap(b, it[0], it[it.length - 1]); if (w !== it[it.length - 1]) { it[it.length - 1] = w; n++ } }
  }
  fs.writeFileSync(f, JSON.stringify(q)); console.log('wrapped', n, 'articles in', f)
}
