# منظومة الشبكة على GitHub — الإعداد مرة واحدة

كل حاجة بتشتغل من GitHub Actions في المستودع ده (`mutaqin-oxygen-app`)، من غير كمبيوتر ولا Chrome.
المستودع `alizeidan123` نسخة احتياطية متأخرة ساعة (فرع `mirror-mutqan`) وما بينشرش أي حاجة إلا لو اتفعّل فيه `FAILOVER_ACTIVE=true`.

## الأسرار (Settings ← Secrets and variables ← Actions ← Secrets)
| السر | لازمته | منين |
|---|---|---|
| `BLOGGER_CLIENT_ID` / `BLOGGER_CLIENT_SECRET` / `BLOGGER_REFRESH_TOKEN` | نشر بلوجر للحساب الأول (alizizo888) | `blogger/api/SETUP.md` |
| `GMAIL_1_JSON`, `GMAIL_2_JSON` … | كل حساب جيميل بيملك مدونات (التاني: altakyif) | `{"type":"authorized_user","client_id":"…","client_secret":"…","refresh_token":"…"}` — نفس طريقة OAuth Playground بحساب altakyif |
| `GEMINI_API_KEY` | الكتابة والمراجعة بالذكاء الاصطناعي (مجاني) | aistudio.google.com ← Get API key |
| `GROQ_API_KEY` | بديل مجاني | console.groq.com ← API Keys |
| `OPENROUTER_API_KEY` | بديل مجاني تالت | openrouter.ai ← Keys |
| `WP_AUTH` | نشر مقالات الذكاء الاصطناعي على الـ22 موقع ووردبريس | تحت |
| `HOSTINGER_API_TOKEN` | التحكم في DNS من GitHub | hPanel ← Profile ← API ← Generate token |
| `MIRROR_TOKEN` | نسخ المستودع على alizeidan123 | GitHub ← Settings ← Developer settings ← Fine-grained token، Contents: Read and write على alizeidan123 |

### WP_AUTH
في كل موقع ووردبريس: المستخدمين ← ملفك ← **كلمات مرور التطبيقات** ← اسم «GitHub» ← إضافة. لو نفس المستخدم ونفس كلمة المرور على كل المواقع يكفي `default`:
```json
{"default": {"user": "admin", "app_password": "abcd efgh ijkl mnop qrst uvwx"},
 "mutqan": {"user": "editor-mutqan", "app_password": "…", "authors": [2, 5, 7]}}
```
`authors` اختياري: أرقام مستخدمي الكتّاب في الموقع، والمقالات بتتوزع عليهم بالتناوب.

## المتغيرات (Settings ← Secrets and variables ← Actions ← Variables) — كلها اختيارية
- `WP_AI_PER_DAY` عدد مقالات الذكاء الاصطناعي الزيادة على ووردبريس في اليوم (الافتراضي 44 = 2 لكل موقع). محرك كل موقع بيكمل عدده الطبيعي زي ما هو.
- `AUTO_PER_RUN` مقالات مدونات الحسابات التانية في كل تشغيلة (الافتراضي 6، 12 تشغيلة في اليوم).
- `FAILOVER_ACTIVE` = `true` في alizeidan123 بس لو المستودع ده وقع وعايز الاحتياطي ينشر بداله. ما تفعّلوش في الاتنين مع بعض.

## الـworkflows
| الاسم | بيعمل إيه | إمتى |
|---|---|---|
| Network control | يصحّي محرك نشر الـ22 موقع ووردبريس، ويفحص صحتهم، وDNS هوستنجر عند الطلب | كل 15 دقيقة / كل ساعة / يدوي |
| Blogger API automation | نشر بلوجر للحساب الأول: 60→180 في اليوم، 60% مكيفات و40% تبريد | كل ساعتين |
| Blogger multi-account publish | مدونات باقي الحسابات من الحساب اللي بيملكها + كلماتك من صفحة الكلمات | كل ساعتين |
| WordPress AI articles | مقالات زيادة بكتّاب وأساليب مختلفة على ووردبريس | كل ساعتين |
| Network review | تكرار، تشابه، مقالات قصيرة، ادعاءات ممنوعة، مواقع واقفة + رأي الذكاء الاصطناعي | يوميًا 23:41 / يدوي |
| Network mirror | نسخة على alizeidan123 متأخرة ساعة | كل ساعة |

ولا تكرار في أي مكان: كل عنوان بيتسجّل في `network/state/titles.json` قبل النشر، وأي عنوان قريب منه بيترفض على أي موقع وفي أي يوم.
