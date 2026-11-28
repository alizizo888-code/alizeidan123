// A persona = a different way of *thinking about the same customer problem*. Each site gets one (or two) and keeps it, so the sites of the network
// read differently in structure, order, headings, emphasis and call-to-action tone — not just in swapped nouns.
// shape() only re-orders / selects / re-titles sections the generator already produced; it never adds claims.
const rng = (seed) => { let h = 2166136261; for (const c of String(seed)) h = Math.imul(h ^ c.charCodeAt(0), 16777619); return () => ((h = Math.imul(h ^ (h >>> 15), 2246822519) >>> 0) / 4294967296) }
const PERSONAS = {
  diagnostician: { label: 'المشخّص', lead: ['علامات تقول', 'الأسباب الأكثر شيوعاً', 'كيف ينفذ', 'مثال توضيحي'], drop: ['نبذة عن'], cta: 'ابدأ بالتشخيص: اطلب فنياً الآن', rename: { 'أسئلة شائعة': 'ما الذي يسأله العملاء عادةً؟' } },
  buyer_guide: { label: 'دليل المشتري', lead: ['كيف تختار', 'ما الذي يحدد', 'أخطاء تزيد'], drop: ['مثال توضيحي'], cta: 'قارن ثم اطلب عرض الزيارة', rename: { 'أسئلة شائعة': 'قبل أن تقرر: أجوبة سريعة' } },
  urgency: { label: 'الطوارئ', lead: ['ماذا تفعل حتى', 'طلب عاجل', 'علامات تقول'], drop: ['نبذة عن', 'الصيانة الوقائية'], cta: 'الوضع عاجل؟ اطلب الآن', rename: { 'أسئلة شائعة': 'أسئلة قبل وصول الفني' } },
  local_guide: { label: 'ابن الحي', lead: ['ما الذي يميز', 'أين نقدم'], drop: ['مثال توضيحي'], cta: 'من حيّك إلى بابك: اطلب الخدمة', rename: { 'أسئلة شائعة': 'أسئلة سكان الحي' } },
  process: { label: 'خطوة بخطوة', lead: ['كيف تتم الزيارة', 'جهّز هذه', 'كيف ينفذ', 'بعد انتهاء'], drop: ['نبذة عن'], cta: 'احجز زيارتك بخطوات واضحة', rename: { 'أسئلة شائعة': 'أجوبة عن خطوات الزيارة' } },
  prevention: { label: 'الوقاية', lead: ['الصيانة الوقائية', 'الأسباب الأكثر', 'نبذة عن', 'بعد انتهاء'], drop: ['طلب عاجل'], cta: 'احجز صيانة قبل أن يتفاقم العطل', rename: { 'أسئلة شائعة': 'أسئلة عن الوقاية والصيانة' } },
  trust: { label: 'الثقة والشفافية', lead: ['كيف تختار', 'لماذا تختار', 'بعد انتهاء', 'ماذا يشمل'], drop: ['مثال توضيحي', 'طلب عاجل'], cta: 'اطلب بوضوح: معاينة ثم سعر قبل التنفيذ', rename: { 'أسئلة شائعة': 'أسئلة الثقة والضمان حسب سياسة الشركة' } },
}
const ids = Object.keys(PERSONAS)
exports.PERSONAS = PERSONAS; exports.pick = (siteIndex) => ids[siteIndex % ids.length]
// Split generator output into: head (need + first CTA) | middle sections | tail (FAQ, areas, order). Re-order middle by persona, drop 0-2 optional ones, re-title FAQ.
exports.shape = (html, personaId, seed, minWordsFn, minWords) => {
  const P = PERSONAS[personaId] || PERSONAS.diagnostician, r = rng(seed + personaId)
  const parts = html.split(/(?=<h2)/), head = parts.shift()
  const isTail = (s) => /<h2>(أسئلة شائعة|أين نقدم|اطلب الآن)/.test(s), mid = parts.filter((s) => !isTail(s)), tail = parts.filter(isTail)
  const h = (s) => (s.match(/<h2>(.*?)<\/h2>/) || [, ''])[1], rank = (s) => { const i = P.lead.findIndex((k) => h(s).includes(k)); return i < 0 ? 99 + r() : i + r() * 0.5 }
  let m = mid.slice().sort((a, b) => rank(a) - rank(b))
  const droppable = m.filter((s) => P.drop.some((k) => h(s).includes(k)))
  let out = m.filter((s) => !droppable.includes(s))
  const build = (mm) => head + mm.join('') + tail.map((s) => { let x = s; for (const [a, b] of Object.entries(P.rename)) x = x.replace(`<h2>${a}</h2>`, `<h2>${b}</h2>`); return x.replace(/>اتصل بنا أو اطلب من التطبيق</, `>${P.cta}<`) }).join('')
  let res = build(out); if (minWordsFn && minWordsFn(res) < minWords) res = build(m)       // never shrink below the minimum: put the dropped sections back
  return res
}
