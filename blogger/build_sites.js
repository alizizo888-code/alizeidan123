// Full-site Blogger themes for the 22-brand network, in the same design as the Mutqan/Oxygen app home:
// side menu, dhikr ticker, rotating specialty banner (photo + icon + service lines + counter), search, welcome-offer banner,
// app download card, specialty tiles with the app's gradient icons, call / order / WhatsApp cards, AI assistant box,
// floating buttons; then SEO sections (featured specialty in full, all services, steps, coverage), articles, sister sites.
// The Mutqan app opens inside the site (iframe of oxygen11.com/app/?via=<brand>) so every order lands in the one hub.
//
// ONE builder used in two places:
//   node blogger/build_sites.js            -> blogger/templates/<id>.xml
//   in the Blogger theme editor (browser)  -> BloggerSites.make(D)(id)
// D = { net (network.json), SV (content/services.json), DI (content/districts.json), IC (content/icons.json), SC (content/showcase.json), TPL (site_template.xml) }
(function (root) {
  const HUB = 'https://oxygen11.com'
  const SPECS = [ // id, label, short desc, gradient (same as mutqan-app/src/serviceIcons.js)
    ['ac', 'صيانة وتكييف', 'غسيل، فك وتركيب، فريون', '#38bdf8', '#2563eb'], ['electric', 'كهرباء', 'تمديدات، أعطال، لوحات', '#fbbf24', '#f97316'],
    ['plumbing', 'سباكة', 'تسربات، تمديد، أدوات صحية', '#22d3ee', '#0284c7'], ['locks', 'أقفال ذكية', 'تركيب وصيانة الأقفال الذكية', '#818cf8', '#7c3aed'],
    ['appliances', 'أجهزة منزلية', 'غسالات، أفران، سخانات', '#a78bfa', '#c026d3'], ['satellite', 'ساتلايت ودش', 'تركيب وضبط القنوات', '#64748b', '#1e293b'],
    ['paint', 'دهانات وديكور', 'دهانات، ورق جدران، ديكور', '#fb7185', '#db2777'], ['tiling', 'تبليط وترميم', 'تبليط، ترميم، عزل', '#fb923c', '#ef4444'],
    ['cameras', 'كاميرات مراقبة', 'كاميرات مراقبة وأنظمة أمن', '#2dd4bf', '#059669'], ['fridge', 'غرف التبريد', 'ثلاجات ومبردات وفريزرات', '#7dd3fc', '#0891b2'],
    ['cleaning', 'نظافة وتعقيم', 'تنظيف عميق وتعقيم', '#34d399', '#0d9488'], ['pest', 'مكافحة الحشرات', 'رش وضمان مكافحة', '#a3e635', '#16a34a'],
    ['tanks', 'خزانات مياه', 'تنظيف، عزل، تعقيم', '#60a5fa', '#4f46e5'], ['moving', 'نقل الأثاث', 'فك، نقل، تركيب', '#facc15', '#d97706'],
    ['solar', 'طاقة شمسية', 'تركيب وصيانة الأنظمة الشمسية', '#fde047', '#f97316'], ['corporate', 'خدمات الشركات', 'عقود وصيانة المنشآت', '#3b82f6', '#334155'],
    ['general', 'الصيانة العامة', 'أعمال صيانة متنوعة', '#0ea5e9', '#4f46e5'], ['contracting', 'مقاولات عامة', 'إشراف هندسي وعمالة مشاريع', '#fb923c', '#d97706'],
  ]
  const HOME12 = ['ac', 'plumbing', 'electric', 'cleaning', 'appliances', 'paint', 'fridge', 'tanks', 'locks', 'cameras', 'satellite', 'solar']
  const SP = Object.fromEntries(SPECS.map((s) => [s[0], s]))
  const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  const urlOf = (b) => b.live_url || b.blogspot
  const shade = (hex, k) => '#' + [1, 3, 5].map((i) => Math.max(0, Math.min(255, Math.round(parseInt(hex.slice(i, i + 2), 16) * k))).toString(16).padStart(2, '0')).join('')

  function make(D) {
    const { net, SV, DI, IC, SC, TPL } = D
    const svg = (k) => `<svg><use href="#i${k}"/></svg>` // drawn from the one sprite below
    const sprite = '<svg style="display:none" xmlns="http://www.w3.org/2000/svg">' + Object.entries(IC).map(([k, v]) =>
      `<symbol fill="none" id="i${k}" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">${v}</symbol>`).join('') + '</svg>'
    const app = (b, r) => `data-app='${r}' href='${HUB}/app/?via=${b.id}#${r}'`
    const grad = (id) => `background:linear-gradient(135deg,${SP[id][3]},${SP[id][4]})`
    const tile = (b, id) => `<a class='tile' ${app(b, '/s/' + id)}><span class='ic' style='${grad(id)}'>${svg(id)}</span><b>${e(SP[id][1])}</b></a>`
    const order = (b, list) => [b.spec, ...list.filter((x) => x !== b.spec)]
    const slideHtml = (b, id) => {
      const s = SC.s[id] || { t: SP[id][1], l: [SP[id][2]] }
      return `<div class='t'><span class='ic' style='${grad(id)}'>${svg(id)}</span>${e(s.t)}</div>` +
        `<div class='ls'>${s.l.map((l) => `<span>${svg('_check')}${e(l)}</span>`).join('')}</div>` +
        `<a class='ob' ${app(b, '/s/' + id)}>اطلب الخدمة ${svg('_left')}</a>`
    }
    const slides = (b) => order(b, SC.order.filter((id) => SP[id])).map((id) => ({ t: (SC.s[id] || {}).t || SP[id][1], img: ((SC.s[id] || {}).img || [])[0] || '', h: slideHtml(b, id) })).filter((x) => x.img)
    const featured = (b) => Object.entries(SV).filter(([, v]) => v.spec === b.spec).map(([k, v]) =>
      `<article class='card feat' id='svc-${e(k)}'><h3><span class='ic' style='${grad(v.spec)};width:34px;height:34px;border-radius:12px;display:grid;place-items:center;color:#fff'>${svg(v.spec)}</span>${e(v.svc)}</h3>` +
      `<div class='two'><div><h4>ماذا نقدم</h4><ul class='tk'>${v.items.map((x) => `<li>${svg('_check')}${e(x)}</li>`).join('')}</ul></div>` +
      `<div><h4>متى تحتاج الخدمة؟</h4><ul class='tk wr'>${v.signs.map((x) => `<li>${svg('_check')}${e(x)}</li>`).join('')}</ul></div></div>` +
      `<h4>أسئلة شائعة</h4>${v.faq.map(([q, a]) => `<details><summary>${e(q)}</summary><p>${e(a)}</p></details>`).join('')}` +
      `<a class='btn' ${app(b, '/s/' + v.spec)}>اطلب ${e(v.n)} ${svg('_left')}</a></article>`).join('')
    const others = (b) => Object.entries(SV).filter(([, v]) => v.spec !== b.spec).map(([, v]) =>
      `<details class='svc'><summary>${e(v.n)}</summary><p>${e(v.svc)}</p><ul class='tk'>${v.items.slice(0, 4).map((x) => `<li>${svg('_check')}${e(x)}</li>`).join('')}</ul>` +
      `<a ${app(b, '/s/' + v.spec)}>اطلب الخدمة ←</a></details>`).join('')
    const coverage = (b) => (b.id === 'mutamad' ? ['jeddah', 'makkah'] : ['makkah', 'jeddah']).map((c) =>
      `<div><h4>${c === 'makkah' ? 'مكة المكرمة' : 'جدة'}</h4><div class='chips'>${DI[c].map((d) => `<span>${e(d)}</span>`).join('')}</div></div>`).join('')
    const network = (b) => [`<li><a href='${HUB}/'><i style='background:#38bdf8'></i>الموقع الرئيسي — أكسجين</a></li>`]
      .concat(net.filter((o) => o.id !== b.id && urlOf(o)).map((o) => `<li><a href='${e(urlOf(o))}'><i style='background:${o.color}'></i>${e(o.title)}</a></li>`)).join('')
    const chips = (b) => {
      const v = Object.values(SV).find((x) => x.spec === b.spec)
      const q = [v ? `كم سعر ${v.n}؟` : 'كم سعر غسيل 3 مكيفات؟', 'أريد فني سباكة', 'عندي مشكلة في التبريد']
      return q.map((t) => `<a ${app(b, '/ai')}>${e(t)}</a>`).join('')
    }
    const schema = (b, full) => JSON.stringify({
      '@context': 'https://schema.org', '@type': 'HomeAndConstructionBusiness', name: full, url: urlOf(b) || `https://${b.host}/`,
      areaServed: ['مكة المكرمة', 'جدة'], knowsAbout: SPECS.map((s) => s[1]),
      hasOfferCatalog: { '@type': 'OfferCatalog', name: 'خدمات ' + b.name, itemListElement: Object.values(SV).map((v) => ({ '@type': 'Offer', itemOffered: { '@type': 'Service', name: v.svc } })) },
      parentOrganization: { '@type': 'Organization', name: 'مجموعة أكسجين للصيانة والمقاولات العامة', url: HUB + '/' }, sameAs: [HUB + '/'],
    }).replace(/&/g, '&amp;').replace(/</g, '\\u003c')

    return function build(id) {
      const b = net.find((x) => x.id === id)
      if (!b) throw new Error('unknown brand ' + id)
      const full = b.id === 'oxygen' ? 'مجموعة أكسجين للصيانة والمقاولات العامة' : `${b.name} للصيانة والمقاولات العامة`
      const sl = slides(b)
      const rep = {
        __ID__: b.id, __COLOR2__: shade(b.color, 0.72), __COLOR__: b.color, __NAME__: e(b.name), __FULL__: e(full), __HUB__: HUB,
        __ANGLE__: e(b.angle_label), __SPECLABEL__: e(SP[b.spec] ? SP[b.spec][1] : b.angle_label),
        __PAGES__: (b.page_paths || []).map(([t, u]) => `<li><a href='${e(u)}'>${svg('_file')} ${e(t)}</a></li>`).join(''),
        __TILES12__: order(b, HOME12).slice(0, 12).map((x) => tile(b, x)).join(''),
        __TILESALL__: SPECS.map((s) => tile(b, s[0])).join(''),
        __SLIDES__: e(JSON.stringify(sl)), __FIRSTIMG__: e(sl[0] ? sl[0].img : ''), __FIRSTSLIDE__: sl[0] ? sl[0].h : '',
        __OFFERIMG__: e(((SC.s.ac || {}).img || [])[1] || (sl[0] && sl[0].img) || ''),
        __AICHIPS__: chips(b), __FEATURED__: featured(b), __OTHERS__: others(b), __COVERAGE__: coverage(b), __NETWORK__: network(b), __SCHEMA__: schema(b, full),
        __I_ac__: svg(b.spec), __SPRITE__: sprite,
      }
      let x = TPL
      for (const k of Object.keys(rep)) x = x.split(k).join(rep[k])
      x = x.replace(/__I__([a-z0-9]+)__/g, (_, k) => svg('_' + k))
      return x
    }
  }

  if (typeof module !== 'undefined' && typeof require === 'function' && require.main === module) {
    const fs = require('fs'), path = require('path'), H = __dirname
    const rd = (p) => fs.readFileSync(path.join(H, p), 'utf8')
    const D = { net: JSON.parse(rd('network.json')), SV: JSON.parse(rd('content/services.json')), DI: JSON.parse(rd('content/districts.json')),
      IC: JSON.parse(rd('content/icons.json')), SC: JSON.parse(rd('content/showcase.json')), TPL: rd('site_template.xml') }
    const build = make(D)
    fs.mkdirSync(path.join(H, 'templates'), { recursive: true })
    for (const b of D.net) fs.writeFileSync(path.join(H, 'templates', b.id + '.xml'), build(b.id))
    console.log(`${D.net.length} full-site themes -> ${path.join(H, 'templates')}`)
  } else {
    root.BloggerSites = { make }
  }
})(typeof window !== 'undefined' ? window : globalThis)
