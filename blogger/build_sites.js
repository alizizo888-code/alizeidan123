// Full-site Blogger themes for the 22-brand network — ONE builder used in two places:
//   node blogger/build_sites.js            -> blogger/templates/<id>.xml   (the files kept in the repo)
//   in the Blogger theme editor (browser)  -> BloggerSites.build(id) returns the same XML to paste/apply
// Data: network.json (brands), content/services.json (from the plugin's data-services.php), content/districts.json, site_template.xml.
// Each theme is a complete site: hero, all 18 app specialties, the brand's featured specialty in full, every other service,
// how ordering works, Makkah + Jeddah coverage, latest articles, sister sites, and the Mutqan app opened inside the site
// (iframe of oxygen11.com/app/?via=<brand>) so every order and message lands in the one hub tagged with the brand.
(function (root) {
  const HUB = 'https://oxygen11.com'
  const SPECS = [ // same list and order as mutqan-app/src/constants.js
    ['ac', 'صيانة وتكييف', '❄️', 'غسيل، فك وتركيب، فريون'], ['electric', 'كهرباء', '⚡', 'تمديدات، أعطال، لوحات'],
    ['plumbing', 'سباكة', '🚿', 'تسربات، تمديد، أدوات صحية'], ['locks', 'أقفال ذكية', '🔐', 'تركيب وصيانة الأقفال الذكية'],
    ['appliances', 'أجهزة منزلية', '🧺', 'غسالات، أفران، سخانات'], ['satellite', 'ساتلايت ودش', '📡', 'تركيب وضبط القنوات'],
    ['paint', 'دهان وديكور', '🎨', 'دهانات، ورق جدران، ديكور'], ['tiling', 'تبليط وترميم', '🧱', 'تبليط، ترميم، عزل'],
    ['cameras', 'كاميرات ومراقبة', '📹', 'كاميرات مراقبة وأنظمة أمن'], ['fridge', 'التبريد', '🧊', 'ثلاجات ومبردات وفريزرات'],
    ['cleaning', 'تنظيف وتعقيم', '🧽', 'تنظيف عميق وتعقيم'], ['pest', 'مكافحة الحشرات', '🐜', 'رش وضمان مكافحة'],
    ['tanks', 'خزانات المياه', '🚰', 'تنظيف، عزل، تعقيم'], ['moving', 'نقل الأثاث', '📦', 'فك، نقل، تركيب'],
    ['solar', 'طاقة شمسية', '☀️', 'تركيب وصيانة الأنظمة الشمسية'], ['corporate', 'خدمات الشركات', '🏢', 'عقود وصيانة المنشآت'],
    ['general', 'الصيانة العامة', '🛠️', 'أعمال صيانة متنوعة'], ['contracting', 'مقاولات عامة', '🏗️', 'إشراف هندسي وعمالة مشاريع'],
  ]
  const LABEL = Object.fromEntries(SPECS.map((s) => [s[0], s[1]]))
  const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  const urlOf = (b) => b.live_url || b.blogspot

  function make(D) {
    const { net, SV, DI, TPL } = D
    const appLink = (b, route) => `data-app='${route}' href='${HUB}/app/?via=${b.id}#${route}'`
    const featured = (b) => {
      let rows = Object.entries(SV).filter(([, v]) => v.spec === b.spec)
      return rows.map(([k, v]) => `<article class='feat' id='svc-${e(k)}'><h3>${e(v.svc)}</h3>` +
        `<div class='two'><div><h4>ماذا نقدم</h4><ul class='ticks'>${v.items.map((x) => `<li>${e(x)}</li>`).join('')}</ul></div>` +
        `<div><h4>متى تحتاج الخدمة؟</h4><ul class='warn'>${v.signs.map((x) => `<li>${e(x)}</li>`).join('')}</ul></div></div>` +
        `<h4>أسئلة شائعة</h4>${v.faq.map(([q, a]) => `<details><summary>${e(q)}</summary><p>${e(a)}</p></details>`).join('')}` +
        `<a class='btn' ${appLink(b, '/s/' + v.spec)}>اطلب ${e(v.n)}</a></article>`).join('')
    }
    const others = (b) => Object.entries(SV).filter(([, v]) => v.spec !== b.spec).map(([, v]) =>
      `<details class='svc'><summary>${e(v.n)}</summary><p>${e(v.svc)}</p><ul class='ticks'>${v.items.slice(0, 4).map((x) => `<li>${e(x)}</li>`).join('')}</ul>` +
      `<a ${appLink(b, '/s/' + v.spec)}>اطلب الخدمة ←</a></details>`).join('')
    const tiles = (b) => [...SPECS].sort((x, y) => (x[0] !== b.spec) - (y[0] !== b.spec))
      .map((s) => `<a class='tile' ${appLink(b, '/s/' + s[0])}><i>${s[2]}</i><b>${e(s[1])}</b><small>${e(s[3])}</small></a>`).join('')
    const coverage = (b) => {
      const order = b.id === 'mutamad' ? ['jeddah', 'makkah'] : ['makkah', 'jeddah']
      const name = { makkah: 'مكة المكرمة', jeddah: 'جدة' }
      return order.map((c) => `<div><h4>${name[c]}</h4><p class='chips'>${DI[c].map((d) => `<span>${e(d)}</span>`).join('')}</p></div>`).join('')
    }
    const network = (b) => [`<li><a href='${HUB}/'><i style='background:#38bdf8'></i>الموقع الرئيسي — أكسجين</a></li>`]
      .concat(net.filter((o) => o.id !== b.id && urlOf(o)).map((o) => `<li><a href='${e(urlOf(o))}'><i style='background:${o.color}'></i>${e(o.title)}</a></li>`)).join('')
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
      const rep = {
        __ID__: b.id, __COLOR__: b.color, __NAME__: e(b.name), __FULL__: e(full), __HUB__: HUB,
        __ANGLE__: e(b.angle_label), __SPECLABEL__: e(LABEL[b.spec] || b.angle_label), __INITIAL__: e(b.name.replace('ال', '').slice(0, 1)),
        __PAGES__: (b.page_paths || []).map(([t, u]) => `<li><a href='${e(u)}'>${e(t)}</a></li>`).join(''),
        __TILES__: tiles(b), __FEATURED__: featured(b), __OTHERS__: others(b), __COVERAGE__: coverage(b), __NETWORK__: network(b), __SCHEMA__: schema(b, full),
      }
      let x = TPL
      for (const k of Object.keys(rep)) x = x.split(k).join(rep[k])
      return x
    }
  }

  if (typeof module !== 'undefined' && module.exports && typeof require === 'function' && require.main === module) {
    const fs = require('fs'), path = require('path'), H = __dirname
    const rd = (p) => fs.readFileSync(path.join(H, p), 'utf8')
    const D = { net: JSON.parse(rd('network.json')), SV: JSON.parse(rd('content/services.json')), DI: JSON.parse(rd('content/districts.json')), TPL: rd('site_template.xml') }
    const build = make(D)
    fs.mkdirSync(path.join(H, 'templates'), { recursive: true })
    for (const b of D.net) fs.writeFileSync(path.join(H, 'templates', b.id + '.xml'), build(b.id))
    console.log(`${D.net.length} full-site themes -> ${path.join(H, 'templates')}`)
  } else {
    root.BloggerSites = { make }
  }
})(typeof window !== 'undefined' ? window : globalThis)
