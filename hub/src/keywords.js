// Keyword variety: a head keyword is not repeated on the same site within its last 10 posts, and labels mix the head term, the place and related terms.
// Related terms are plain search phrases a customer really uses; they go into labels and the title shapes the generator already rotates — no stuffing in the body.
const { q } = require('./db')
const RELATED = { ac: ['غسيل مكيفات', 'صيانة تكييف', 'تركيب سبليت', 'تعبئة فريون', 'تنظيف مكيف', 'فني تكييف', 'تصليح مكيفات'], fridge: ['غرف تبريد', 'غرف تجميد', 'صيانة ثلاجات', 'فريزر تجاري', 'كمبروسر تبريد', 'بانلات عزل'], other: ['صيانة', 'فني', 'معلم', 'مهندس', 'خدمة منزلية', 'زيارة معاينة'] }
exports.recentKw = (siteId) => new Set(q.all('SELECT kw FROM articles WHERE site_id=? AND kw IS NOT NULL ORDER BY id DESC LIMIT 10', siteId).map((r) => r.kw))
exports.labels = (entry, site, seed = Math.random()) => {
  const pool = RELATED[entry.kind === 'fridge' ? 'fridge' : entry.cool ? 'ac' : 'other'], city = entry.city === 'jd' ? 'جدة' : 'مكة المكرمة', pick = [...pool].sort((a, b) => ((a.length * 7 + seed * 1000) % 13) - ((b.length * 5 + seed * 1000) % 13)).slice(0, 2)
  return [entry.kw, city, ...pick].filter(Boolean).filter((x, i, a) => a.indexOf(x) === i).join(',')
}
