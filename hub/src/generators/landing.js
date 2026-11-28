// Landing-page kind: a conversion page (hero, value points, service list, how it works, areas, FAQ, call to act) built from services.json + districts.json.
// Different structure and length from articles (min 700 words); used for WordPress pages and static landing sites.
const path = require('path'), R = path.join(__dirname, '..', '..', '..', 'blogger', 'content'), SV = require(path.join(R, 'services.json')), DI = require(path.join(R, 'districts.json'))
const E = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const rng = (seed) => { let h = 2166136261; for (const c of String(seed)) h = Math.imul(h ^ c.charCodeAt(0), 16777619); return () => ((h = Math.imul(h ^ (h >>> 15), 2246822519) >>> 0) / 4294967296) }
const PRO = require('./pro')
// Landing page for the priority trades: every system type with what it is and what we do on it, per action, built from the same knowledge base as the articles.
const proLanding = (site, entry, spec) => {
  const group = spec === 'fridge' ? 'cold' : 'ac', types = Object.entries(PRO.KB).filter(([, v]) => v.group === group), r = rng(site.slug + entry.t)
  const order = `https://oxygen11.com/app/?via=${site.slug}#/new`, where = entry.city === 'jd' ? 'جدة' : 'مكة المكرمة', title = entry.t
  const cta = `<p style="text-align:center"><a href="${order}" style="display:inline-block;background:#0ea5e9;color:#fff;font-weight:800;border-radius:99px;padding:11px 26px;text-decoration:none">اطلب الخدمة الآن</a></p>`
  const p = [`<h1 style="font-size:26px">${E(title)}</h1><p><b>${E(group === 'cold' ? 'تحتاج غرفة تبريد أو تجميد أو ماكينة ثلج تعمل بلا توقف؟' : 'تحتاج مكيفاً يبرد فعلاً في حر ' + where + '؟')}</b> نعاين أولاً ونشرح السبب ونعرض البنود قبل أي تنفيذ.</p>` + cta]
  p.push(`<h2>الأنظمة التي نخدمها</h2>` + types.map(([, v]) => `<h3>${E(v.n)}</h3><p>${E(v.what)}</p>`).join(''))
  const act = { maintenance: 'maint', repair: 'faults', wash: 'wash', install: 'install', buy_used: 'used', sell_used: 'sell' }
  for (const [a, k] of Object.entries(act)) p.push(`<h2>${E(PRO.ACTIONS[a])}</h2><ul>` + types.slice().sort(() => r() - 0.5).slice(0, 3).map(([, v]) => { const x = v[k][Math.floor(r() * v[k].length)]; return `<li><b>${E(v.n)}:</b> ${E(Array.isArray(x) ? x[0] + ' — ' + x[1] : x)}</li>` }).join('') + '</ul>')
  const cl = (PRO.CLIMATE[entry.city === 'jd' ? 'jd' : 'mk'] || {})[group === 'cold' ? 'cold' : 'ac'] || [], pick = (a, n) => a.slice().sort(() => r() - 0.5).slice(0, n)
  if (cl.length) p.push(`<h2>لماذا تختلف الخدمة في ${where}؟</h2>` + pick(cl, 2).map((x) => `<p>${E(x)}</p>`).join(''))
  p.push('<h2>كيف نفرّق بين فني وفني؟</h2><ul>' + pick(PRO.CRITERIA, 6).map((x) => `<li>${E(x)}</li>`).join('') + '</ul>' + cta)
  p.push('<h2>شرح مبسط لأهم الأنظمة</h2>' + pick(types, 3).map(([k, v]) => `<h3>${E(v.n)}</h3><p>${E((PRO.DEEP[k] || [v.what])[Math.floor(r() * (PRO.DEEP[k] || [1]).length)])}</p>`).join(''))
  p.push('<h2>أخطاء شائعة نراها كل موسم</h2><ul>' + pick(Object.values(PRO.MISTAKES).flat().filter((x) => typeof x === 'string'), 5).map((x) => `<li>${E(x)}</li>`).join('') + '</ul>')
  p.push('<h2>ما الذي يحدد الكلفة؟</h2><ul>' + pick(Object.values(PRO.FACTORS).flat(), 6).map((x) => `<li>${E(x)}</li>`).join('') + '</ul>')
  p.push('<h2>كيف نعمل؟</h2><ol><li>تطلب الخدمة وتحدد الحي والجهاز.</li><li>نؤكد الموعد.</li><li>تتم المعاينة والقياس وشرح السبب.</li><li>تقرر بعد عرض البنود والسعر، ثم نختبر النتيجة أمامك.</li></ol>' + cta)
  p.push('<h2>قبل أن نصل</h2><ul>' + pick(PRO.PREP, 5).map((x) => `<li>${E(x)}</li>`).join('') + '</ul>')
  p.push(`<h2>الأحياء التي نخدمها في ${where}</h2><p>${(entry.city === 'jd' ? DI.jeddah : DI.makkah).slice().sort(() => r() - 0.5).slice(0, 18).map((d) => 'حي ' + E(d)).join('، ')} وما حولها.</p>`)
  const fq = pick(Object.values(PRO.FAQ).flat(), 5)
  p.push('<h2>أسئلة سريعة</h2>' + fq.map(([q, a]) => `<h3>${E(q)}</h3><p>${E(a)}</p>`).join('') + cta)
  return { title, labels: entry.kw || '', html: p.join('') }
}
exports.build = (site, entry, spec) => {
  if (entry.pro && (spec === 'ac' || spec === 'fridge' || entry.kind)) return proLanding(site, entry, entry.kind === 'fridge' ? 'fridge' : 'ac')
  const KB = require(path.join(R, 'kb.json'))[spec] || {}, r = rng(site.slug + entry.t), svcs = Object.values(SV).filter((s) => s.spec === spec).sort(() => r() - 0.5).slice(0, 6); if (!svcs.length) return null
  const mk = DI.makkah.slice().sort(() => r() - 0.5).slice(0, 10), where = entry.city === 'jd' ? 'جدة' : 'مكة المكرمة', order = `https://oxygen11.com/app/?via=${site.slug}#/new`
  const cta = `<p style="text-align:center"><a href="${order}" style="display:inline-block;background:#0ea5e9;color:#fff;font-weight:800;border-radius:99px;padding:11px 26px;text-decoration:none">اطلب الخدمة الآن</a></p>`
  const title = entry.t, p = []
  p.push(`<h1 style="font-size:26px">${E(title)}</h1><p><b>تحتاج الخدمة اليوم في ${where}؟</b> اطلب الفني من الرابط أو راسلنا واتساب، وتصلك معاينة وتشخيص قبل أي تنفيذ وسعر واضح بعد الفحص.</p>` + cta)
  p.push('<h2>لماذا هذه الصفحة؟</h2><ul>' + ['فني مختص يصل بموعد مؤكد', 'تشخيص السبب قبل اقتراح أي قطعة', 'عرض البنود قبل التنفيذ', 'اختبار النتيجة أمامك قبل المغادرة', 'ضمان حسب سياسة الشركة'].map((x) => `<li>${x}</li>`).join('') + '</ul>')
  for (const s of svcs) p.push(`<h2>${E(s.n)}</h2><p>${E(s.svc)}.</p><ul>${s.items.map((i) => `<li>${E(i)}</li>`).join('')}</ul>` + (s.why ? `<p>${E(s.why)}</p>` : ''))
  if (KB.what) p.push(`<h2>نبذة عن الخدمة</h2><p>${E(KB.what)}</p>` + (KB.climate ? `<p>${E(KB.climate)}</p>` : ''))
  if (KB.causes) p.push('<h2>أكثر الأسباب التي تدفع العملاء للطلب</h2>' + KB.causes.slice(0, 4).map(([a, b]) => `<h3>${E(a)}</h3><p>${E(b)}</p>`).join(''))
  if (KB.cost) p.push('<h2>ما الذي يحدد كلفة الزيارة؟</h2><ul>' + KB.cost.map(([a, b]) => `<li><b>${E(a)}:</b> ${E(b)}</li>`).join('') + '</ul>')
  if (KB.tips) p.push('<h2>نصائح قبل وصول الفني</h2><ul>' + KB.tips.slice(0, 5).map((x) => `<li>${E(x)}</li>`).join('') + '</ul>')
  p.push('<h2>كيف نعمل؟</h2><ol><li>تطلب الخدمة وتحدد الحي والمشكلة.</li><li>نؤكد الموعد المناسب.</li><li>يعاين الفني ويشرح السبب والبنود.</li><li>تقرر وتبدأ التنفيذ، ثم نختبر النتيجة معك.</li></ol>' + cta)
  p.push(`<h2>الأحياء التي نخدمها في ${where}</h2><p>${mk.map((d) => 'حي ' + E(d)).join('، ')} وما حولها.</p>`)
  p.push('<h2>أسئلة سريعة</h2>' + svcs.flatMap((s) => s.faq || []).slice(0, 5).map(([a, b]) => `<h3>${E(a)}</h3><p>${E(b)}</p>`).join('') + cta)
  return { title, labels: entry.kw || '', html: p.join('') }
}
