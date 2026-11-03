// Buy-intent topic plan for the 44 Blogger blogs (owner order 2026-11-03):
//   • about 70% of all daily posts are about A/C + cold rooms / freezer rooms / refrigeration  (ac & fridge blogs: 100%; every other blog: its trade's cooling angle ~65%, its own trade ~35%)
//   • 70% of every blog's list is located in Makkah (districts of Makkah first), 30% Jeddah
//   • titles are what customers search when they want to call or order: "أفضل شركة غسيل مكيفات", "فني اصلاح تكيفات", "معلم مكيفات", "مهندس صيانة"...
//   node blogger/content/build_commercial_plan.js  ->  content/commercial_plan.json  {brandId: [{t, kw, city:'mk'|'jd', cool:true|false}]}
const fs = require('fs'), path = require('path')
const net = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'network.json'), 'utf8'))
const D = JSON.parse(fs.readFileSync(path.join(__dirname, 'districts.json'), 'utf8'))
const N = 120
const ROLES = ['أفضل شركة', 'أفضل فني', 'مهندس', 'معلم', 'فني', 'رقم أفضل شركة', 'شركة', 'احجز فني', 'أسرع فني', 'مكتب', 'أفضل معلم', 'أرقام فنيين']
const TAIL = ['', ' — اطلب الآن', ' | اتصل واحجز']
const COOL = {
  ac: ['غسيل مكيفات', 'اصلاح تكيفات', 'صيانة مكيفات', 'تركيب مكيفات', 'غسيل تكييف', 'تصليح مكيفات', 'صيانة تكييف مركزي', 'تركيب سبليت', 'تنظيف مكيفات', 'شحن فريون مكيفات'],
  fridge: ['صيانة غرف التبريد والتجميد', 'صيانة غرف التبريد', 'صيانة غرف التجميد', 'تصليح ثلاجات', 'صيانة فريزر', 'صيانة ثلاجات المطاعم', 'غرف تبريد للمطاعم والمحلات', 'صيانة ثلاجات عرض'],
}
// how every other trade touches cooling (the "cooling angle")
const ANGLE = {
  electric: ['فني كهرباء لخطوط المكيفات والقواطع', 'مهندس كهرباء يحمي المكيف من الأحمال', 'أفضل كهربائي لتأسيس نقطة مكيف', 'معلم كهرباء لفصل القاطع عند تشغيل المكيف', 'شركة كهرباء لغرف التبريد والتجميد'],
  plumbing: ['فني سباكة لتصريف مياه المكيفات', 'معلم سباكة لعلاج تنقيط المكيف', 'أفضل سباك لصرف غرف التبريد', 'مهندس سباكة لتمديد مياه التبريد', 'سباك لتمديد تصريف المكيف الجداري'],
  appliances: ['فني أجهزة منزلية وتصليح ثلاجات', 'أفضل فني صيانة ثلاجات وفريزرات', 'معلم تصليح فريزر لا يجمد', 'شركة صيانة ثلاجات منزلية', 'فني تصليح مبردات المياه'],
  pest: ['شركة مكافحة حشرات لغرف التبريد والتخزين', 'مكافحة الحشرات خلف المكيفات والدكت', 'فني مكافحة صراصير المطابخ والثلاجات', 'أفضل شركة تعقيم لمخازن التبريد'],
  tanks: ['شركة تنظيف خزانات وصيانة مبردات المياه', 'فني خزانات لمياه التكييف المركزي', 'أفضل شركة عزل حراري لخزانات السطح', 'معلم خزانات ومضخات تبريد'],
  locks: ['فني أقفال لأبواب غرف التبريد', 'معلم أقفال وأبواب غرف التجميد', 'أفضل شركة أقفال ذكية للمستودعات المبردة', 'تركيب قفل ذكي لغرف التبريد'],
  cameras: ['شركة كاميرات لمراقبة غرف التبريد والمستودعات', 'فني كاميرات لمخازن الأغذية المبردة', 'أفضل شركة مراقبة لمطاعم ومحلات التبريد', 'تركيب كاميرات داخل غرف التجميد'],
  solar: ['شركة طاقة شمسية لتشغيل المكيفات', 'مهندس طاقة شمسية لتقليل فاتورة التكييف', 'فني ألواح شمسية لغرف التبريد', 'أفضل شركة نظام شمسي للمكيفات'],
  paint: ['شركة دهانات عازلة للحرارة تخفف حمل المكيف', 'معلم دهان بعد تركيب المكيفات', 'فني ديكور لإخفاء مواسير التكييف', 'أفضل شركة دهانات لغرف التبريد'],
  tiling: ['شركة عزل حراري لتخفيف حمل المكيفات', 'معلم تبليط أرضيات غرف التبريد', 'فني عزل أسطح لخفض الحرارة', 'أفضل شركة بانلات عزل لغرف التبريد والتجميد'],
  cleaning: ['شركة تنظيف وتعقيم بعد غسيل المكيفات', 'فني تنظيف مجاري الدكت والتكييف', 'أفضل شركة تنظيف غرف التبريد', 'تنظيف وتعقيم الثلاجات المركزية'],
  moving: ['شركة نقل أثاث مع فك وتركيب المكيفات', 'معلم فك وتركيب مكيفات عند النقل', 'نقل ثلاجات ومبردات بأمان', 'أفضل شركة نقل وتركيب مكيفات'],
  corporate: ['عقود صيانة تكييف للشركات والمنشآت', 'شركة صيانة مكيفات للمكاتب والمدارس', 'فني تكييف مركزي للمنشآت', 'صيانة غرف التبريد للمطاعم والفنادق'],
  general: ['فني صيانة عامة وتصليح مكيفات', 'معلم صيانة منازل ومكيفات', 'أفضل شركة صيانة عامة تشمل التكييف', 'مهندس صيانة منشآت وتكييف'],
  contracting: ['مقاول تشطيب مع تمديدات تكييف', 'شركة مقاولات لإنشاء غرف التبريد', 'مهندس مقاولات لتجهيز تمديدات المكيفات', 'أفضل شركة مقاولات للتبريد والتجميد'],
  satellite: ['فني ستلايت وتركيب مكيفات', 'معلم دش وتمديدات مكيف', 'أفضل فني تركيبات منزلية ومكيفات'],
}
const OWN = { electric: ['كهرباء', 'صيانة كهرباء'], plumbing: ['سباكة', 'كشف تسربات المياه'], appliances: ['صيانة أجهزة منزلية', 'صيانة غسالات'], pest: ['مكافحة حشرات'], tanks: ['تنظيف خزانات'], locks: ['أقفال ومفاتيح'], cameras: ['كاميرات مراقبة'],
  solar: ['طاقة شمسية'], paint: ['دهانات وديكور'], tiling: ['تبليط وعزل'], cleaning: ['تنظيف وتعقيم'], moving: ['نقل أثاث'], corporate: ['صيانة منشآت'], general: ['صيانة عامة'], contracting: ['مقاولات'], satellite: ['تركيب دش'] }
const h = (s) => { let x = 2166136261; for (const c of s) x = Math.imul(x ^ c.charCodeAt(0), 16777619) >>> 0; return x }
const out = {}
net.forEach((b, bi) => {
  const spec = b.spec, own = !COOL[spec], list = []
  const off = h(b.id) % 997
  const seen = new Set()
  for (let i = 0; i < N; i++) {
    const mk = ((i * 3 + bi) % 10) < 7                                   // 70% Makkah
    const cool = !own || ((i * 7 + bi * 3) % 20) < 13                    // ac/fridge blogs: 100%; the others ~65% cooling angle
    const dist = mk ? D.makkah : D.jeddah
    for (let s = 0; s < 40; s++) {                                       // widen the choice until the title is new for this blog
      const P = (i + s) % 9 === 0 ? (mk ? 'مكة المكرمة' : 'جدة') : 'حي ' + dist[(i * 5 + off + s * 7) % dist.length] + (mk ? ' بمكة المكرمة' : ' بجدة')
      const role = ROLES[(i + off + s * 5) % ROLES.length]
      let t, kw
      if (cool && !own) { const v = COOL[spec][(i * 3 + off + s) % COOL[spec].length]; t = `${role} ${v} في ${P}`; kw = `${role} ${v} ${P}` }
      else if (cool) { const a = ANGLE[spec] || ANGLE.general; const v = a[(i + off + s) % a.length]; t = `${v} في ${P}`; kw = `${v} ${P}` }
      else { const o = OWN[spec] || ['صيانة عامة']; const v = o[(i + off + s) % o.length]; t = `${role} ${v} في ${P}`; kw = `${role} ${v} ${P}` }
      t += TAIL[(i + off + s) % 3]
      if (seen.has(t)) continue
      seen.add(t); list.push({ t, kw: kw.replace(/\s+/g, ' ').trim(), city: mk ? 'mk' : 'jd', cool }); break
    }
  }
  // never the same title twice in one blog
  out[b.id] = list
})
fs.writeFileSync(path.join(__dirname, 'commercial_plan.json'), JSON.stringify(out))
const all = Object.values(out).flat(), c = all.filter((x) => x.cool).length, m = all.filter((x) => x.city === 'mk').length
console.log(`blogs=${Object.keys(out).length} topics=${all.length} cooling=${(100 * c / all.length).toFixed(1)}% makkah=${(100 * m / all.length).toFixed(1)}%`)
