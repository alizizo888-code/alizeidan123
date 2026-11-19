#!/usr/bin/env python3
"""Longer pages for the original faces (owner order 2026-11-03: no page of two or three lines).
Built only from this repo's service, knowledge and district data: no prices, ratings, licences or invented history.
Refreshes the first four pages in pages.json (about, service, FAQ, areas); the privacy page stays as it is.
  python3 blogger/content/build_pages_face.py <brandId>..."""
import json, random, sys
from pathlib import Path
H = Path(__file__).resolve().parent
NET = {b['id']: b for b in json.loads((H.parent / 'network.json').read_text(encoding='utf-8'))}
SV = json.loads((H / 'services.json').read_text(encoding='utf-8'))
KB = json.loads((H / 'kb.json').read_text(encoding='utf-8'))
DI = json.loads((H / 'districts.json').read_text(encoding='utf-8'))
P = json.loads((H / 'pages.json').read_text(encoding='utf-8'))
HUB = 'https://oxygen11.com'
ul = lambda xs: '<ul>' + ''.join(f'<li>{x}</li>' for x in xs) + '</ul>'
STEPS = ['تطلب الخدمة من التطبيق أو تتصل بنا وتحدد موقعك.', 'يصلك تأكيد الموعد ويُسند الطلب إلى الفني المناسب للحالة.', 'يعاين الفني ويشخّص، ثم يعرض عليك العمل المطلوب والسعر قبل التنفيذ.', 'بعد موافقتك يبدأ التنفيذ، ويختبر الفني النتيجة أمامك.', 'تحصل على ملخص بما تم، ويسري الضمان حسب سياسة الشركة المكتوبة في الطلب.']
for i in sys.argv[1:]:
    b = NET[i]; n, a, s = b['name'], b['angle_label'], b['spec']
    k = KB.get(s, KB['general']); sv = [v for v in SV.values() if v['spec'] == s] or [v for v in SV.values() if v['spec'] == 'general']
    r = random.Random('face-' + i)
    order = f"<p><a href='{HUB}/app/?via={i}#/new'><b>اطلب الخدمة من تطبيق {n}</b></a> — يصل طلبك مباشرة إلى غرفة العمليات، ويتواصل معك الفريق لتأكيد الموعد.</p>"
    about = (f"<p>{n} ضمن مجموعة أكسجين للصيانة والمقاولات العامة، وتركز هذه المدونة على {a} في مكة المكرمة وجدة. نكتب هنا ما يحتاجه صاحب البيت أو المنشأة ليفهم المشكلة قبل أن يطلب الخدمة، ثم نوفر له طريقة مباشرة للطلب والمتابعة.</p>"
             f"<h2>في ماذا نتخصص؟</h2><p>{k['what']}</p>{ul([v['svc'] for v in sv])}"
             f"<h2>كيف نعمل؟</h2><ol>{''.join(f'<li>{x}</li>' for x in STEPS)}</ol>"
             "<h2>ما الذي نلتزم به؟</h2>" + ul(['فحص وتشخيص قبل عرض السعر، ولا يُضاف أي بند دون موافقتك.', 'شرح السبب بلغة بسيطة وعرض الخيارات المتاحة، والقرار الأخير لك.', 'توثيق ما تم في الطلب لتعود إليه وقت الحاجة.', 'ضمان على العمل حسب سياسة الشركة المكتوبة في الطلب.']) + order)
    svc = f"<p>هذه الصفحة تجمع ما تقدمه {n} في مجال {a}، ومتى تحتاج الخدمة، وكيف يعمل الفني، وما الذي يحدد الكلفة.</p>"
    svc += ''.join(f"<h2>{v['svc']}</h2>{ul(v['items'])}<h3>متى تحتاج الخدمة؟</h3>{ul(v['signs'])}" for v in sv)
    svc += '<h2>الأسباب الشائعة للمشكلة</h2>' + ''.join(f'<h3>{t}</h3><p>{x}</p>' for t, x in k['causes'])
    svc += f"<h2>كيف ينفذ الفني العمل؟</h2><ol>{''.join(f'<li>{x}</li>' for x in k['steps'])}</ol>"
    svc += '<h2>ما الذي يحدد الكلفة؟</h2>' + ''.join(f'<h3>{t}</h3><p>{x}</p>' for t, x in k['cost']) + '<p>لا نذكر سعراً ثابتاً قبل المعاينة، ويُعرض عليك السعر كاملاً قبل بدء العمل.</p>' + order
    gen = [('كيف أطلب الخدمة؟', f'من تطبيق {n} مباشرة بلا تسجيل، أو بالاتصال بنا، ثم يصلك تأكيد الموعد.'), ('هل تخدمون مكة المكرمة وجدة؟', 'نعم، نغطي أحياء مكة المكرمة وجدة، ويؤكد فريق الجدولة الموعد المناسب بعد استلام الطلب.'), ('متى أعرف السعر؟', 'يُعرض عليك السعر بعد المعاينة وقبل بدء التنفيذ، ولا يُضاف أي بند دون موافقتك.'), ('هل يوجد ضمان؟', 'نعم، حسب سياسة الشركة المكتوبة في الطلب، ويوضح لك الفني ما يشمله.'), ('ماذا أجهز قبل وصول الفني؟', 'صوّر المشكلة إن أمكن، واكتب متى بدأت، ووفر مساحة آمنة حول مكان العمل، واذكر رقم الدور وأقرب معلم.'), ('هل أستطيع تعديل الموعد؟', 'نعم، تواصل معنا عبر الطلب نفسه ليعيد فريق الجدولة ترتيب الزيارة.')]
    faq = ''.join(f'<h3>{q}</h3><p>{x}</p>' for v in sv for q, x in v['faq']) + ''.join(f'<h3>{q}</h3><p>{x}</p>' for q, x in gen)
    faq += '<h2>أخطاء شائعة يجدر تجنبها</h2>' + ''.join(f'<h3>{t}</h3><p>{x}</p>' for t, x in k['mistakes']) + order
    areas = (f"<p>تصل خدمات {n} إلى أحياء مكة المكرمة وجدة. اختر موقعك على الخريطة في التطبيق أو اذكر الحي وأقرب معلم عند الطلب، فهذا يختصر الوقت ويساعد على إسناد الطلب إلى الفني المناسب.</p>"
             f"<h2>أحياء مكة المكرمة</h2><p>{'، '.join(DI['makkah'])}.</p><h2>أحياء جدة</h2><p>{'، '.join(DI['jeddah'])}.</p>"
             f"<h2>طبيعة المنطقة وأثرها على العمل</h2><p>{k['climate']}</p>"
             "<p>نخدم العمائر السكنية والفلل والشقق المفروشة والمحلات والمنشآت، ولكل منها طبيعة تؤثر على طريقة العمل: المساحات والارتفاعات وأوقات الدخول المسموحة.</p>"
             "<p>إذا لم تجد حيّك في القائمة فأرسل طلبك مع موقعك، وسيؤكد لك فريق الجدولة ما يمكن ترتيبه.</p>" + order)
    old = P[i]
    P[i] = [[old[0][0], about], [old[1][0], svc], [old[2][0], faq], [old[3][0], areas], old[4]]
    print(i, [len(h.replace('<', ' <').split()) for _, h in P[i]])
(H / 'pages.json').write_text(json.dumps(P, ensure_ascii=False, indent=1), encoding='utf-8')
