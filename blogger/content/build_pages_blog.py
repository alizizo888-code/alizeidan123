#!/usr/bin/env python3
"""Pages for the 22 Blogger-only brands (tier 'blog-only'): 5 pages each, built from their own angle + services data.
Appends/refreshes their entries in pages.json (the hand-written pages of the first 22 are left untouched)."""
import json, random
from pathlib import Path
H = Path(__file__).resolve().parent
NET = json.loads((H.parent / 'network.json').read_text(encoding='utf-8'))
SV = json.loads((H / 'services.json').read_text(encoding='utf-8'))
DI = json.loads((H / 'districts.json').read_text(encoding='utf-8'))
HUB = 'https://oxygen11.com'
P = json.loads((H / 'pages.json').read_text(encoding='utf-8'))
ABOUT = [
 '{n} فريق ميداني في مكة المكرمة وجدة يركز على {a}. أنشأنا هذه المدونة لنشرح لأصحاب البيوت والمنشآت ما نراه يومياً في الزيارات، بلغة بسيطة وخطوات قابلة للتطبيق.',
 'بدأت {n} من سؤال يتكرر علينا: لماذا يتكرر العطل بعد الإصلاح؟ ومن هنا جاء تخصصنا في {a}، مع تشخيص دقيق قبل أي استبدال وضمان مكتوب بعده.',
 'في {n} نؤمن أن الصيانة الجيدة تبدأ بالفهم؛ لذلك نكتب عن {a} من واقع الميدان، ونستقبل الطلبات عبر تطبيق واحد يتابع الطلب حتى إغلاقه.',
]
for b in NET:
    if b.get('tier') != 'blog-only': continue
    i, n, a, s = b['id'], b['name'], b['angle_label'], b['spec']
    r = random.Random('pages-' + i)
    sv = [v for v in SV.values() if v['spec'] == s]
    order = f"<p><a href='{HUB}/app/?via={i}#/s/{s}'><b>اطلب الخدمة من تطبيق {n}</b></a> — يصل طلبك لغرفة العمليات مباشرة ويُتابَع حتى التنفيذ.</p>"
    about = f"<p>{r.choice(ABOUT).format(n=n, a=a)}</p><h2>ما يميزنا</h2><ul><li>فحص وتشخيص قبل عرض السعر.</li><li>لا قطع ولا أعمال إضافية دون موافقتك.</li><li>ضمان مكتوب داخل الطلب.</li><li>متابعة الطلب لحظة بلحظة من التطبيق.</li></ul>" + order
    svc = ''.join(f"<h2>{v['svc']}</h2><ul>" + ''.join(f'<li>{x}</li>' for x in v['items']) + "</ul><h3>متى تحتاجها؟</h3><ul>" + ''.join(f'<li>{x}</li>' for x in v['signs']) + '</ul>' for v in sv) + order
    faq = ''.join(f'<h3>{q}</h3><p>{ans}</p>' for v in sv for q, ans in v['faq']) + f'<h3>هل تخدمون مكة وجدة معاً؟</h3><p>نعم، فرق {n} تغطي أحياء المدينتين، ويحدد التطبيق أقرب فني لموقعك.</p>' + order
    mk, jd = sorted(r.sample(DI['makkah'], 18)), sorted(r.sample(DI['jeddah'], 18))
    areas = f"<h2>مكة المكرمة</h2><p>{'، '.join(mk)}، وغيرها.</p><h2>جدة</h2><p>{'، '.join(jd)}، وغيرها.</p><p>إذا لم تجد حيّك، حدّد موقعك على الخريطة في التطبيق وسنخبرك بأقرب موعد.</p>" + order
    priv = (f"<p>توضح هذه الصفحة كيف تتعامل {n} مع بيانات الزوار.</p><h2>ما نجمعه</h2><p>لا نطلب بيانات لقراءة المقالات. عند الطلب تُدخل اسمك وجوالك وموقعك في صفحة الطلب، وتُستخدم لتنفيذ الطلب والتواصل فقط.</p>"
            "<h2>ملفات تعريف الارتباط</h2><p>تستخدم منصة Blogger التابعة لـ Google ملفات تعريف ارتباط لقياس الزيارات، ويمكنك التحكم بها من متصفحك.</p>"
            f"<h2>المشاركة</h2><p>لا نبيع بياناتك ولا نشاركها لأغراض تسويقية.</p><h2>للتواصل</h2><p>عبر <a href='{HUB}/'>الموقع الرئيسي</a>.</p>")
    P[i] = [[r.choice(['من نحن', 'تعرّف على ' + n, 'قصة ' + n]), about], [a, svc], ['أسئلة شائعة', faq], ['مناطق الخدمة', areas], ['سياسة الخصوصية', priv]]
(H / 'pages.json').write_text(json.dumps(P, ensure_ascii=False, indent=1), encoding='utf-8')
print(len(P), 'brands with pages')
