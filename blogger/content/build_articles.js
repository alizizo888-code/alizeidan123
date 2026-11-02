// Long-form articles for the 22-brand Blogger network (single generator, Node + browser).
// Each brand gets 6 long articles: 4 on its own specialty in different formats + 2 on neighbouring specialties,
// with brand voice, a field case, local climate, districts, sister-site links and an in-app order button (?via=<brand>).
//   node blogger/content/build_articles.js   -> articles_long.json  {brand: [[title, labels, html], ...]}
//   browser: BloggerArticles.make({KB, SV, DI, NET})() -> same object
(function (root) {
  const HUB = 'https://oxygen11.com'
  const LBL = { ac: 'التكييف', plumbing: 'السباكة', electric: 'الكهرباء', fridge: 'التبريد', appliances: 'الأجهزة المنزلية', pest: 'مكافحة الحشرات', tanks: 'خزانات المياه', locks: 'الأقفال الذكية', cameras: 'كاميرات المراقبة', satellite: 'الدش', paint: 'الدهانات', tiling: 'التبليط والعزل', cleaning: 'التنظيف', moving: 'نقل الأثاث', solar: 'الطاقة الشمسية', corporate: 'صيانة المنشآت', general: 'الصيانة العامة', contracting: 'المقاولات' }
  const NEIGH = { ac: ['electric', 'solar', 'cleaning'], fridge: ['electric', 'appliances', 'corporate'], electric: ['ac', 'solar', 'cameras'], plumbing: ['tanks', 'tiling', 'appliances'], pest: ['cleaning', 'plumbing', 'tanks'], tanks: ['plumbing', 'pest', 'tiling'], appliances: ['electric', 'plumbing', 'fridge'], general: ['plumbing', 'electric', 'paint', 'ac', 'locks', 'moving'], cameras: ['locks', 'electric', 'satellite'], solar: ['electric', 'ac', 'tiling'], paint: ['tiling', 'cleaning', 'contracting'], cleaning: ['pest', 'ac', 'tanks'], tiling: ['paint', 'plumbing', 'contracting'], satellite: ['cameras', 'electric', 'general'], moving: ['cleaning', 'appliances', 'general'], locks: ['cameras', 'general', 'electric'], corporate: ['ac', 'electric', 'fridge'], contracting: ['tiling', 'paint', 'electric'] }
  const T = {
    guide: ['الدليل الشامل إلى {L} في البيوت: كل ما تحتاج معرفته', '{L} من الألف إلى الياء: دليل عملي لأصحاب البيوت في مكة وجدة', 'كل شيء عن {L}: الأعطال والصيانة والاختيار الصحيح', 'مرجعك الكامل في {L}: من الفحص إلى التسليم', 'دليل {N} الشامل إلى {L}', 'افهم {L} قبل أن تدفع ريالاً واحداً'],
    causes: ['أشهر أعطال {L} وأسبابها الحقيقية وكيف تتصرف', 'لماذا تتكرر مشاكل {L}؟ تشخيص عملي للأسباب', 'قبل أن تتصل بالفني: افهم أعطال {L} الشائعة', 'خمسة أسباب وراء أغلب مشكلات {L}', 'تشخيص أعطال {L}: ما يراه فنيو {N} يومياً', 'من العرض إلى السبب: قراءة أعطال {L}'],
    cost: ['ما الذي يحدد تكلفة {L}؟ دليل المقارنة بين العروض', 'تكلفة {L} بوضوح: العوامل التي ترفع السعر وتخفضه', 'كيف تدفع السعر العادل في {L} دون مفاجآت', 'عروض {L}: كيف تقارن بينها وتختار الأنسب', 'ميزانية {L}: دليل {N} للتسعير الشفاف', 'لماذا يختلف سعر {L} من فني لآخر؟'],
    mistakes: ['أخطاء شائعة في {L} تكلفك أكثر مما تتوقع', 'تجنب هذه الأخطاء في {L} ووفر مالك ووقتك', 'عادات خاطئة في {L} يقع فيها أغلب الناس', 'ما لا يخبرك به أحد عن أخطاء {L}', 'أخطاء {L} التي يصلحها فنيو {N} كل أسبوع', 'دروس من الميدان: أخطاء {L} وكيف تتجنبها'],
    season: ['جدول صيانة {L} على مدار السنة: متى وماذا تفحص', 'قائمة فحص موسمية لـ{L} تناسب مناخ مكة وجدة', 'تقويم {L}: خطة صيانة وقائية خطوة بخطوة', 'برنامج سنوي لـ{L} يمنع الأعطال قبل حدوثها', 'خطة {N} الوقائية لـ{L} شهراً بشهر', 'الصيانة الدورية لـ{L}: متى تكون ضرورية؟'],
    local: ['{L} في مناخ مكة وجدة: ما الذي يختلف وكيف تستعد', 'الحرارة والرطوبة و{L}: دليل محلي للمنطقة الغربية', '{L} بين غبار مكة ورطوبة جدة', 'دليل {L} المحلي: نصائح من فرقنا الميدانية', '{L} في أحياء مكة وجدة: تجربة {N}', 'كيف يؤثر مناخ المنطقة الغربية على {L}؟'],
  }
  const FMT = ['guide', 'causes', 'cost', 'mistakes', 'season', 'local']
  const FMT_LABEL = { guide: 'أدلة شاملة', causes: 'الأعطال', cost: 'الأسعار والتكلفة', mistakes: 'نصائح', season: 'الصيانة الدورية', local: 'مكة وجدة' }
  const OPEN = ['في {N} نستقبل يومياً طلبات تبدأ بجملة واحدة: "المشكلة بدأت صغيرة". لهذا كتبنا هذا الدليل.', 'كتبنا هذا المقال من واقع زيارات فرق {N} الميدانية في أحياء مكة وجدة، لا من الكتب.', 'هذا المقال جزء من سلسلة {N} التي تشرح لأصحاب البيوت ما يحدث خلف الجدران والأجهزة.', 'سؤال يتكرر على فريق خدمة العملاء في {N} كل أسبوع، والإجابة تستحق مقالاً كاملاً.', 'قبل أن تطلب أي فني، خذ عشر دقائق مع هذا الدليل من {N}؛ سيوفر عليك كثيراً.', 'نؤمن في {N} أن العميل الذي يفهم المشكلة يحصل على خدمة أفضل وسعر أعدل.']
  const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  const ul = (xs) => '<ul>' + xs.map((x) => `<li>${e(x)}</li>`).join('') + '</ul>'
  const fill = (s, L, N) => s.split('{L}').join(L).split('{N}').join(N).replace(/لـال/g, 'لل')
  const rng = (seed) => { let h = 2166136261; for (const c of seed) h = Math.imul(h ^ c.charCodeAt(0), 16777619) >>> 0; return () => { h = (h + 0x6D2B79F5) >>> 0; let t = h; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296 } }
  const pick = (r, a) => a[Math.floor(r() * a.length)]
  const sample = (r, a, n) => { const c = a.slice(); for (let i = c.length - 1; i > 0; i--) { const j = Math.floor(r() * (i + 1)); [c[i], c[j]] = [c[j], c[i]] } return c.slice(0, n) }

  function make({ KB, SV, DI, NET }) {
    const svcs = (spec) => Object.values(SV).filter((v) => v.spec === spec)
    const urlOf = (b) => b.live_url || b.blogspot
    const sister = (spec, me) => NET.find((b) => b.spec === spec && b.id !== me && urlOf(b))
    const USED = new Set()
    function build(b, bi, spec, fmt, idx, r) {
      const k = KB[spec], L = LBL[spec], N = b.name, via = b.id
      let title = null
      for (let j = 0; j < T[fmt].length; j++) { const c = fill(T[fmt][(idx + bi + j) % T[fmt].length], L, N); if (!USED.has(c)) { title = c; break } }
      if (!title) title = fill(T[fmt][(idx + bi) % T[fmt].length], L, N) + ` — دليل ${N}`
      USED.add(title)
      const sv = svcs(spec)
      const p = [`<p><b>${e(fill(pick(r, OPEN), L, N))}</b></p>`, `<p>${e(k.what)}</p>`,
        `<p>تعمل ${e(N)} ضمن مجموعة أكسجين للصيانة والمقاولات العامة، وتغطي مكة المكرمة وجدة بفرق ميدانية وتطبيق واحد لاستقبال الطلبات ومتابعتها، مع تركيز خاص على ${e(b.angle_label)}. في هذا المقال نتناول ${e(L)} من زاوية ${['guide', 'season'].includes(fmt) ? 'عملية' : 'تحليلية'} تساعدك على اتخاذ القرار الصحيح.</p>`, '<h2>محتويات المقال</h2>']
      const secs = [], sec = (h, body) => secs.push([h, body])
      const causes = k.causes.map(([t, x]) => `<h3>${e(t)}</h3><p>${e(x)} وفي الزيارات الميدانية نلاحظ أن هذا السبب يظهر غالباً مع علامات أخرى، لذلك يفحص الفني الصورة كاملة ولا يكتفي بأول عطل يجده.</p>`).join('')
      const steps = '<ol>' + k.steps.map((s) => `<li>${e(s)}</li>`).join('') + '</ol>'
      const mist = k.mistakes.map(([t, x]) => `<h3>${e(t)}</h3><p>${e(x)}</p>`).join('')
      const sched = '<table border="1" cellpadding="8" style="border-collapse:collapse;width:100%"><tr><th>التوقيت</th><th>ماذا تفعل</th></tr>' + k.schedule.map(([w, x]) => `<tr><td>${e(w)}</td><td>${e(x)}</td></tr>`).join('') + '</table>'
      const cost = k.cost.map(([t, x]) => `<h3>${e(t)}</h3><p>${e(x)} ولهذا نعرض السعر بعد المعاينة وقبل البدء، ولا نضيف أي قطعة دون موافقتك المكتوبة في التطبيق.</p>`).join('')
      const items = sv.map((v) => `<h3>${e(v.svc)}</h3>${ul(v.items)}`).join('')
      const signs = ul([...new Set(sv.flatMap((v) => v.signs))])
      const tips = ul(k.tips)
      if (fmt === 'guide') {
        sec(`ما الذي تشمله خدمات ${L}؟`, items + '<p>كل بند من هذه البنود يُنفذ وفق قائمة فحص موحدة في المجموعة، ويُوثق في الطلب داخل التطبيق لتعود إليه وقت الحاجة.</p>')
        sec('علامات تقول إنك تحتاج الخدمة الآن', signs); sec('الأسباب الأكثر شيوعاً', causes)
        sec('كيف ينفذ الفني الخدمة خطوة بخطوة', steps + '<p>التزام الفني بهذه الخطوات هو ما يصنع الفرق بين إصلاح يدوم وإصلاح يتكرر بعد أسابيع.</p>')
        sec('جدول الصيانة الوقائية', sched); sec('نصائح للحفاظ على النتيجة', tips)
      } else if (fmt === 'causes') {
        sec('الأسباب بالتفصيل', causes); sec('علامات مبكرة لا تتجاهلها', signs)
        sec('ما الذي يمكنك فعله بنفسك وما الذي يحتاج فنياً؟', '<p>القاعدة البسيطة: التنظيف الخارجي والملاحظة وتسجيل الأعراض عليك، وأي شيء يتطلب فتح الجهاز أو التعامل مع الكهرباء أو الغاز أو الضغط يحتاج فنياً مؤهلاً. التدخل غير المتخصص قد يحول عطلاً بسيطاً إلى استبدال كامل.</p>' + tips)
        sec('كيف نشخّص العطل في الزيارة', steps); sec('أخطاء تزيد العطل سوءاً', mist)
      } else if (fmt === 'cost') {
        sec('العوامل التي تحدد السعر', cost)
        sec('ماذا يجب أن يشمل العرض الجيد؟', items + '<p>اطلب أن يكون العرض مكتوباً ببنود واضحة: ما يشمله، وما لا يشمله، ومدة الضمان، وسعر القطع إن وجدت.</p>')
        sec('كيف تقارن بين عرضين؟', ul(['قارن نطاق العمل لا الرقم النهائي فقط.', 'اسأل عن الضمان ومدته وما يغطيه.', 'تأكد من أن الفني سيعرض العطل قبل استبدال أي قطعة.', 'اسأل عن زمن الوصول وإمكانية المتابعة.', 'العرض الأرخص بلا ضمان غالباً الأغلى على المدى البعيد.']))
        sec('أخطاء ترفع التكلفة دون داعٍ', mist); sec('كيف توفر على المدى الطويل', sched + tips)
      } else if (fmt === 'mistakes') {
        sec('الأخطاء الأكثر تكراراً', mist); sec('ما الذي يحدث عندما تتراكم هذه الأخطاء؟', causes)
        sec('الطريقة الصحيحة', steps); sec('عادات بسيطة تصنع فرقاً', tips); sec('متى تطلب الفني فوراً؟', signs)
      } else if (fmt === 'season') {
        sec('الجدول السنوي', sched + '<p>ضع هذه المواعيد في تقويم جوالك، أو اطلب من التطبيق تذكيراً بالزيارة الدورية.</p>')
        sec('قبل الصيف', `<p>${e(k.climate)}</p>` + tips); sec('علامات تستدعي زيارة خارج الجدول', signs)
        sec('ماذا يحدث في الزيارة الدورية؟', steps); sec('ما الذي تشمله الخدمة', items)
      } else {
        sec('المناخ المحلي وأثره', `<p>${e(k.climate)}</p><p>هذه الفروق ليست نظرية؛ فرقنا ترى في جدة أعطالاً مرتبطة بالرطوبة والتآكل، وفي مكة أعطالاً مرتبطة بالغبار والحرارة والأحمال الموسمية في رمضان والحج.</p>`)
        sec('الأعطال الأكثر انتشاراً في المنطقة', causes); sec('خطة وقائية تناسب منطقتك', sched)
        sec('نصائح من الميدان', tips); sec('أخطاء نراها كثيراً', mist)
      }
      const c0 = k.causes[(idx + bi) % k.causes.length]
      const who = pick(r, ['عميل في حي ' + pick(r, DI.jeddah) + ' بجدة', 'عميلة في حي ' + pick(r, DI.makkah) + ' بمكة', 'صاحب محل في ' + pick(r, DI.jeddah), 'أسرة في حي ' + pick(r, DI.makkah)])
      const seen = sv.length ? sv[0].signs[idx % sv[0].signs.length] : c0[0]
      sec('حالة من الميدان', `<p>تواصل معنا ${e(who)} بعد أن لاحظ: ${e(seen)}. ظن في البداية أن المشكلة كبيرة ومكلفة، لكن الفحص المنهجي كشف أن السبب الرئيسي هو «${e(c0[0])}». ${e(c0[1])}</p><p>نفّذ الفني الخطوات المعتادة: ${e(k.steps.slice(0, 4).join('، ثم '))}. وانتهت الزيارة بتجربة كاملة أمام العميل، مع نصيحة مكتوبة في الطلب: ${e(k.tips[idx % k.tips.length])}. الدرس هنا أن التشخيص الصحيح قبل الإصلاح يوفر المال والوقت، وأن الصيانة الوقائية كانت ستمنع العطل من الأساس.</p>`)
      sec('قائمة تحضير قبل وصول الفني', ul(['صوّر المشكلة أو رسالة الخطأ وأرفقها بالطلب', 'اكتب متى بدأت المشكلة وهل تتكرر في وقت معين', 'وفّر مساحة عمل آمنة حول مكان العطل', 'جهّز فاتورة أو ضمان الجهاز أو العمل السابق إن وجد', 'حدد إن كانت هناك أعمال أخرى تريد إنجازها في نفس الزيارة لتوفير التكلفة', 'تأكد من إمكانية الوصول للمكان (السطح، الخزان، اللوحة، الوحدة الخارجية)']))
      sec('كيف تطلب من التطبيق وماذا تتوقع', `<ol><li>افتح تطبيق ${e(N)} واختر تخصص ${e(L)}.</li><li>اختر الخدمة المحددة وأضف وصفاً وصوراً.</li><li>حدد موقعك على الخريطة والوقت المناسب.</li><li>يصلك تأكيد الطلب ورقمه، ثم اسم الفني عند إسناده.</li><li>تابع حالة الطلب لحظة بلحظة من «طلباتي».</li><li>بعد المعاينة يظهر السعر لتوافق عليه قبل البدء.</li><li>بعد الانتهاء قيّم الخدمة، ويبقى الضمان مسجلاً في الطلب.</li></ol><p>كل الطلبات من مواقع الشبكة تصل إلى مركز عمليات واحد، لذلك تحصل على نفس الجودة ونفس الضمان أينما طلبت.</p>`)
      sec('الضمان والجودة', `<p>تخضع أعمال ${e(L)} في ${e(N)} لسياسة ضمان المجموعة: الضمان مكتوب في الطلب، ويغطي العمل المنفذ والقطع المركبة حسب نوعها. إذا تكرر العطل خلال مدة الضمان يعود الفني دون رسوم زيارة. ونراجع تقييمات العملاء أسبوعياً لنضمن أن كل فني يلتزم بقائمة الفحص والخطوات الموحدة.</p>`)
      p.push(ul(secs.map((s) => s[0])))
      for (const [h, body] of secs) p.push(`<h2>${e(h)}</h2>${body}`)
      const faq = sv.flatMap((v) => v.faq)
      if (faq.length) p.push('<h2>أسئلة شائعة</h2>' + faq.map(([q, a]) => `<h3>${e(q)}</h3><p>${e(a)}</p>`).join(''))
      const mk = sample(r, DI.makkah, 10), jd = sample(r, DI.jeddah, 10)
      p.push(`<h2>أين نقدم الخدمة؟</h2><p>تصل فرق ${e(N)} إلى أحياء مكة المكرمة مثل: ${e(mk.join('، '))}، وإلى أحياء جدة مثل: ${e(jd.join('، '))}، وغيرها. اختر موقعك على الخريطة داخل التطبيق ليصلك أقرب فني.</p>`)
      const links = (NEIGH[spec] || []).filter((s) => s !== spec).slice(0, 3).map((s) => [s, sister(s, via)]).filter((x) => x[1])
        .map(([s, sb]) => `<li><a href="${e(urlOf(sb))}">${e(sb.title)}</a> — دليل ${e(LBL[s])}</li>`)
      links.push(`<li><a href="${HUB}/">الموقع الرئيسي لمجموعة أكسجين</a></li>`)
      p.push('<h2>اقرأ أيضاً في شبكتنا</h2><ul>' + links.join('') + '</ul>')
      p.push(`<h2>اطلب الخدمة الآن</h2><p>اطلب ${e(L)} من <a href="${HUB}/app/?via=${via}#/s/${spec}"><b>تطبيق ${e(N)}</b></a> في أقل من دقيقة: اختر الخدمة، حدد موقعك، ووقت الزيارة المناسب، وتابع الفني حتى يصل. يمكنك أيضاً سؤال <a href="${HUB}/app/?via=${via}#/ai">المساعد الذكي</a> عن السعر التقريبي قبل الطلب.</p>`)
      return [title, `${L},${FMT_LABEL[fmt]}`, p.join('')]
    }
    function plan(b, i) {
      const own = FMT.slice(i % 6).concat(FMT.slice(0, i % 6))
      const ns = (NEIGH[b.spec] || []).filter((s) => KB[s])
      const nb = [ns[i % ns.length], ns[(i + 1) % ns.length]]
      return [[b.spec, own[0]], [b.spec, own[1]], [b.spec, own[2]], [b.spec, own[3]], [nb[0], own[4]], [nb[1], own[5]]]
    }
    return function all() {
      USED.clear()
      const out = {}
      NET.forEach((b, i) => { const r = rng(b.id); out[b.id] = plan(b, i).map(([s, f], j) => build(b, i, s, f, j, r)) })
      return out
    }
  }

  if (typeof module !== 'undefined' && typeof require === 'function' && require.main === module) {
    const fs = require('fs'), path = require('path'), H = __dirname
    const rd = (p) => JSON.parse(fs.readFileSync(path.join(H, p), 'utf8'))
    const out = make({ KB: rd('kb.json'), SV: rd('services.json'), DI: rd('districts.json'), NET: rd('../network.json') })()
    fs.writeFileSync(path.join(H, 'articles_long.json'), JSON.stringify(out, null, 0))
    const w = Object.values(out).flat().map((a) => a[2].replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length)
    const t = Object.values(out).flat().map((a) => a[0])
    console.log(w.length, 'articles; words min/avg/max', Math.min(...w), Math.round(w.reduce((a, b) => a + b) / w.length), Math.max(...w), '; dup titles', t.length - new Set(t).size)
  } else root.BloggerArticles = { make }
})(typeof window !== 'undefined' ? window : globalThis)
