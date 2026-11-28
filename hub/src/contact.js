// Ref codes + WhatsApp buttons. The ref travels inside the customer's first message so the bot knows the origin (site, article, platform, line).
const { q } = require('./db')
const A = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'                    // no 0/O/1/I/L confusion
exports.newRef = () => { for (;;) { let r = 'R'; for (let i = 0; i < 5; i++) r += A[Math.floor(Math.random() * A.length)]; if (!q.get('SELECT 1 x FROM articles WHERE ref=?', r)) return r } }
const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;')
exports.waLink = (line, ref, title) => `https://wa.me/${line.number}?text=${encodeURIComponent(`مرحباً، أحتاج الخدمة المذكورة في: ${title.slice(0, 80)} (مرجع: ${ref})`)}`
exports.telLink = (line) => 'tel:+' + String(line.number).replace(/\D/g, '')
exports.waBlock = (line, ref, title, style = 0) => {
  const href = exports.waLink(line, ref, title), name = line.display_name ? e(line.display_name) : 'فريق الخدمة'
  const S = [`background:#16a34a;color:#fff;border-radius:99px;padding:11px 24px`, `background:#fff;color:#15803d;border:2px solid #16a34a;border-radius:12px;padding:10px 22px`, `background:#0f172a;color:#fff;border-radius:10px;padding:11px 24px`][style % 3]
  return `<p style="text-align:center;margin:16px 0"><a href="${href}" style="display:inline-block;${S};font-weight:800;text-decoration:none">راسل ${name} على واتساب</a>${style % 2 ? '' : ` <a href="${exports.telLink(line)}" style="display:inline-block;background:#0f172a;color:#fff;border-radius:99px;padding:11px 22px;font-weight:800;text-decoration:none">اتصل الآن</a>`}</p>`
}
// inject one button after the opening paragraph and one before the closing «اطلب الآن» heading (or at the end)
exports.inject = (html, line, ref, title, style) => {
  if (!line) return html
  const b = exports.waBlock(line, ref, title, style), i = html.indexOf('</p>'), j = html.lastIndexOf('<h2')
  let out = i >= 0 ? html.slice(0, i + 4) + b + html.slice(i + 4) : b + html
  const k = out.lastIndexOf('<h2'); return k >= 0 ? out.slice(0, k) + b + out.slice(k) : out + b
}
exports.context = (ref) => {                                  // what the AI bot receives
  const a = q.get("SELECT a.*, s.slug, s.title stitle, s.platform, s.url surl, s.trade, s.city_focus, s.line_id FROM articles a JOIN sites s ON s.id=a.site_id WHERE a.ref=?", ref); if (!a) return null
  const l = a.line_id ? q.get('SELECT number, display_name FROM lines WHERE id=?', a.line_id) : null
  return { ref, site: { slug: a.slug, title: a.stitle, platform: a.platform, url: a.surl, trade: a.trade, city_focus: a.city_focus }, article: { title: a.title, url: a.url, topic: a.topic, kind: a.kind, city: a.city, type: a.kind2, published_at: a.published_at }, line: l, assistant_note: `العميل قادم من «${a.stitle || a.slug}» (${a.platform}) عبر المقال «${a.title}». أجبه باسم ${l && l.display_name ? l.display_name : 'الفريق'} وركّز على ${a.kind === 'fridge' ? 'التبريد والتجميد' : a.kind === 'ac' ? 'التكييف' : 'تخصص الموقع'} ${a.city === 'mk' ? 'في مكة' : a.city === 'jd' ? 'في جدة' : ''}.` }
}
exports.byLine = (number) => ({ number, sites: q.all('SELECT s.slug, s.title, s.platform, s.url, s.trade FROM sites s JOIN lines l ON l.id=s.line_id WHERE l.number=?', number), display_name: (q.get('SELECT display_name d FROM lines WHERE number=?', number) || {}).d })
