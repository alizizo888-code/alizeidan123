// Buy-intent topic plan for the 44 Blogger blogs (owner order 2026-11-08, replaces the 70% rule of 2026-11-03):
//   • EVERY new article is about cooling: 60% air-conditioning (wash, repair, install, central, freon) and 40% cold rooms / freezer rooms /
//     refrigeration. Blogs of other trades write the A/C or cold-room angle of their own trade, so each site keeps its specialty.
//   • 70% of every blog's list is located in Makkah (districts of Makkah first), 30% Jeddah
//   • titles are what customers search when they want to call or order, in several title shapes so posts never look alike
//   node blogger/content/build_commercial_plan.js  ->  content/commercial_plan.json  {brandId: [{t, kw, city:'mk'|'jd', cool:true, kind:'ac'|'fridge'}]}
const fs = require('fs'), path = require('path')
const net = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'network.json'), 'utf8'))
const D = JSON.parse(fs.readFileSync(path.join(__dirname, 'districts.json'), 'utf8'))
const N = 400
const ROLES = ['أفضل شركة', 'أفضل فني', 'مهندس', 'معلم', 'فني', 'رقم أفضل شركة', 'شركة', 'احجز فني', 'أسرع فني', 'مكتب', 'أفضل معلم', 'أرقام فنيين', 'أقرب فني', 'فريق', 'خدمة']
const TAIL = ['', ' — اطلب الآن', ' | اتصل واحجز', ' — زيارة في نفس اليوم', ' | معاينة قبل التنفيذ']
const COOL = {
  ac: ['غسيل مكيفات', 'اصلاح تكيفات', 'صيانة مكيفات', 'تركيب مكيفات', 'غسيل تكييف', 'تصليح مكيفات', 'صيانة تكييف مركزي', 'تركيب سبليت', 'تنظيف مكيفات', 'شحن فريون مكيفات'],
  fridge: ['صيانة غرف التبريد والتجميد', 'صيانة غرف التبريد', 'صيانة غرف التجميد', 'تصليح ثلاجات', 'صيانة فريزر', 'صيانة ثلاجات المطاعم', 'غرف تبريد للمطاعم والمحلات', 'صيانة ثلاجات عرض'],
}
// how every other trade touches cooling: [A/C angle, cold-room/freezer angle]
const ANGLE = {
  electric: [['فني كهرباء لخطوط المكيفات والقواطع', 'مهندس كهرباء يحمي المكيف من الأحمال', 'أفضل كهربائي لتأسيس نقطة مكيف', 'معلم كهرباء لفصل القاطع عند تشغيل المكيف'], ['شركة كهرباء لغرف التبريد والتجميد', 'فني كهرباء لكمبروسر غرف التبريد', 'مهندس كهرباء للوحات تحكم غرف التجميد']],
  plumbing: [['فني سباكة لتصريف مياه المكيفات', 'معلم سباكة لعلاج تنقيط المكيف', 'سباك لتمديد تصريف المكيف الجداري', 'مهندس سباكة لتمديد مياه التكييف المركزي'], ['أفضل سباك لصرف غرف التبريد', 'فني سباكة لتصريف ثلج غرف التجميد', 'معلم سباكة لمصارف ثلاجات المطاعم']],
  appliances: [['فني أجهزة منزلية وصيانة مكيفات الشباك', 'معلم صيانة مكيفات منزلية', 'فني تصليح مكيف لا يبرد'], ['فني أجهزة منزلية وتصليح ثلاجات', 'أفضل فني صيانة ثلاجات وفريزرات', 'معلم تصليح فريزر لا يجمد', 'شركة صيانة ثلاجات منزلية', 'فني تصليح مبردات المياه']],
  pest: [['مكافحة الحشرات خلف المكيفات والدكت', 'شركة رش حشرات لمجاري التكييف', 'فني مكافحة صراصير فتحات المكيفات'], ['شركة مكافحة حشرات لغرف التبريد والتخزين', 'فني مكافحة صراصير المطابخ والثلاجات', 'أفضل شركة تعقيم لمخازن التبريد']],
  tanks: [['فني خزانات لمياه التكييف المركزي', 'أفضل شركة عزل حراري لخزانات السطح مع المكيفات', 'معلم خزانات ومضخات أبراج التبريد'], ['شركة تنظيف خزانات وصيانة مبردات المياه', 'معلم خزانات ومضخات تبريد', 'فني مبردات مياه للمساجد والمحلات']],
  locks: [['فني أقفال لغرف المكيفات الخارجية', 'تركيب أقفال حماية للوحدات الخارجية للمكيفات'], ['فني أقفال لأبواب غرف التبريد', 'معلم أقفال وأبواب غرف التجميد', 'أفضل شركة أقفال ذكية للمستودعات المبردة', 'تركيب قفل ذكي لغرف التبريد']],
  cameras: [['فني كاميرات لمراقبة وحدات التكييف على الأسطح', 'شركة حساسات حرارة ومراقبة لغرف المكيفات'], ['شركة كاميرات لمراقبة غرف التبريد والمستودعات', 'فني كاميرات لمخازن الأغذية المبردة', 'أفضل شركة مراقبة لمطاعم ومحلات التبريد', 'تركيب كاميرات داخل غرف التجميد']],
  solar: [['شركة طاقة شمسية لتشغيل المكيفات', 'مهندس طاقة شمسية لتقليل فاتورة التكييف', 'أفضل شركة نظام شمسي للمكيفات'], ['فني ألواح شمسية لغرف التبريد', 'شركة طاقة شمسية لثلاجات المحلات', 'مهندس طاقة شمسية لغرف التجميد']],
  paint: [['شركة دهانات عازلة للحرارة تخفف حمل المكيف', 'معلم دهان بعد تركيب المكيفات', 'فني ديكور لإخفاء مواسير التكييف'], ['أفضل شركة دهانات لغرف التبريد', 'دهانات إيبوكسي لأرضيات غرف التجميد', 'معلم دهان مقاوم للرطوبة لغرف التبريد']],
  tiling: [['شركة عزل حراري لتخفيف حمل المكيفات', 'فني عزل أسطح لخفض الحرارة وحمل التكييف'], ['معلم تبليط أرضيات غرف التبريد', 'أفضل شركة بانلات عزل لغرف التبريد والتجميد', 'فني عزل أرضيات غرف التجميد']],
  cleaning: [['شركة تنظيف وتعقيم بعد غسيل المكيفات', 'فني تنظيف مجاري الدكت والتكييف', 'تعقيم فلاتر ومجاري التكييف المركزي'], ['أفضل شركة تنظيف غرف التبريد', 'تنظيف وتعقيم الثلاجات المركزية', 'شركة تعقيم غرف التجميد للمطاعم']],
  moving: [['شركة نقل أثاث مع فك وتركيب المكيفات', 'معلم فك وتركيب مكيفات عند النقل', 'أفضل شركة نقل وتركيب مكيفات'], ['نقل ثلاجات ومبردات بأمان', 'شركة نقل ثلاجات عرض ومعدات تبريد', 'فك ونقل غرف التبريد الجاهزة']],
  corporate: [['عقود صيانة تكييف للشركات والمنشآت', 'شركة صيانة مكيفات للمكاتب والمدارس', 'فني تكييف مركزي للمنشآت'], ['صيانة غرف التبريد للمطاعم والفنادق', 'عقود صيانة ثلاجات وغرف تجميد للمنشآت', 'صيانة غرف التبريد للمستشفيات والمستودعات']],
  general: [['فني صيانة عامة وتصليح مكيفات', 'معلم صيانة منازل ومكيفات', 'أفضل شركة صيانة عامة تشمل التكييف', 'مهندس صيانة منشآت وتكييف'], ['فني صيانة عامة وثلاجات', 'شركة صيانة عامة لغرف التبريد', 'معلم صيانة مطاعم وثلاجات عرض']],
  contracting: [['مقاول تشطيب مع تمديدات تكييف', 'مهندس مقاولات لتجهيز تمديدات المكيفات', 'مقاول تأسيس تكييف مركزي'], ['شركة مقاولات لإنشاء غرف التبريد', 'أفضل شركة مقاولات للتبريد والتجميد', 'مقاول بناء غرف تجميد للمستودعات']],
  satellite: [['فني ستلايت وتركيب مكيفات', 'معلم دش وتمديدات مكيف', 'أفضل فني تركيبات منزلية ومكيفات'], ['فني تركيبات وثلاجات منزلية', 'معلم تركيب مبردات مياه وثلاجات']],
}
// title shapes: rotate so two neighbouring posts never read the same way ({R}=role, {V}=service, {P}=place)
const SHAPES = ['{R} {V} في {P}', 'تبحث عن {R} {V} في {P}؟', '{R} {V} {P} — معاينة وتشخيص قبل السعر', '{R} {V} في {P}: زيارة سريعة', '{V} في {P} | {R} بخبرة في مناخ المنطقة', 'متى تحتاج {V} في {P}؟ دليل الطلب السريع']
const OWN = { electric: ['كهرباء', 'صيانة كهرباء'], plumbing: ['سباكة', 'كشف تسربات المياه'], appliances: ['صيانة أجهزة منزلية', 'صيانة غسالات'], pest: ['مكافحة حشرات'], tanks: ['تنظيف خزانات'], locks: ['أقفال ومفاتيح'], cameras: ['كاميرات مراقبة'],
  solar: ['طاقة شمسية'], paint: ['دهانات وديكور'], tiling: ['تبليط وعزل'], cleaning: ['تنظيف وتعقيم'], moving: ['نقل أثاث'], corporate: ['صيانة منشآت'], general: ['صيانة عامة'], contracting: ['مقاولات'], satellite: ['تركيب دش'] }
const h = (s) => { let x = 2166136261; for (const c of s) x = Math.imul(x ^ c.charCodeAt(0), 16777619) >>> 0; return x }
const out = {}
net.forEach((b, bi) => {
  const spec = b.spec, cool = !!COOL[spec], list = []
  const off = h(b.id) % 997
  const seen = new Set()
  for (let i = 0; i < N; i++) {
    const mk = ((i * 3 + bi) % 10) < 7                                   // 70% Makkah
    const kind = ((i * 7 + bi * 3) % 10) < 6 ? 'ac' : 'fridge'           // 60% A/C, 40% cold rooms / freezers, on every blog
    const dist = mk ? D.makkah : D.jeddah
    for (let s = 0; s < 60; s++) {                                       // widen the choice until the title is new for this blog
      const P = (i + s) % 9 === 0 ? (mk ? 'مكة المكرمة' : 'جدة') : 'حي ' + dist[(i * 5 + off + s * 7) % dist.length] + (mk ? ' بمكة المكرمة' : ' بجدة')
      const role = ROLES[(i + off + s * 5) % ROLES.length], shape = SHAPES[(i + off + s * 3) % SHAPES.length]
      const pool = cool ? COOL[kind] : (ANGLE[spec] || ANGLE.general)[kind === 'ac' ? 0 : 1]
      const v = pool[(i * 3 + off + s) % pool.length]
      // trade-angle phrases already carry their own role ("فني كهرباء لخطوط المكيفات"), so they skip {R}
      let t = (cool ? shape.replace('{R}', role) : shape.replace(' | {R} بخبرة', ' | خبرة').replace('{R} ', '')).replace('{V}', v).replace('{P}', P)
      t = t.replace(/\s+/g, ' ').replace(/^: /, '').trim()
      if (!/[؟:]|—|\|/.test(t)) t += TAIL[(i + off + s) % TAIL.length]
      const kw = `${cool ? role + ' ' : ''}${v} ${P}`
      if (seen.has(t)) continue
      seen.add(t); list.push({ t, kw: kw.replace(/\s+/g, ' ').trim(), city: mk ? 'mk' : 'jd', cool: true, kind }); break
    }
  }
  // never the same title twice in one blog
  out[b.id] = list
})
fs.writeFileSync(path.join(__dirname, 'commercial_plan.json'), JSON.stringify(out))
const all = Object.values(out).flat(), c = all.filter((x) => x.cool).length, m = all.filter((x) => x.city === 'mk').length
const ac = all.filter((x) => x.kind === 'ac').length
console.log(`blogs=${Object.keys(out).length} topics=${all.length} cooling=${(100 * c / all.length).toFixed(1)}% ac=${(100 * ac / all.length).toFixed(1)}% fridge=${(100 - 100 * ac / all.length).toFixed(1)}% makkah=${(100 * m / all.length).toFixed(1)}%`)
