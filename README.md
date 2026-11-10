# Mutqan WhatsApp Gateway (مجاني، ذاتي الاستضافة)

وسيط يربط عدة أرقام واتساب (عادي أو أعمال) بصورة QR أو كود اقتران، ويرسل الرسائل الواردة إلى إضافة ووردبريس
(`/wp-json/mutqan/v1/wa-hook`) ويستقبل أوامر الإرسال منها. لا يستخدم WhatsApp Cloud API ولا أي رصيد ذكاء اصطناعي.

> الربط غير رسمي (مكتبة Baileys). التزم بالرد على العملاء الذين راسلوك، وتجنب الإرسال الجماعي.
> الوسيط يضع فاصلًا عشوائيًا بين الرسائل وحدًا يوميًا لكل رقم (`SEND_DAILY_CAP`).

## التشغيل على موبايل أندرويد (Termux) — الطريقة المعتمدة

الموبايل مالوش عنوان ثابت على الإنترنت، فالموقع ميقدرش ينده عليه. عشان كده الوسيط **بيسأل الموقع**
كل شوية: «فيه رسايل أبعتها؟» — فمحتاجش نفق ولا IP ولا سيرفر. الرسايل الواردة بتروح للموقع عادي.

```bash
pkg update && pkg install nodejs-lts git
termux-setup-storage
git clone -b wa-gateway https://github.com/alizizo888-code/alizeidan123.git wa-gateway
cd wa-gateway
npm ci --omit=dev
```

اعمل ملف `.env` جنب المشروع:

```
GATEWAY_TOKEN=<أي 24 حرف عشوائي>
WEBHOOK_URL=https://oxygen11.com/wp-json/mutqan/v1/wa-hook
WEBHOOK_SECRET=<الموجود في التطبيق: الواتساب ← الأرقام ← إعداد الوسيط>
DATA_DIR=./data
SEND_DAILY_CAP=200
```

في التطبيق سيب خانة **رابط الوسيط فاضية** — دي اللي بتقول للنظام إن الوسيط على موبايل، فيحطّ
الرسايل في طابور بدل ما يحاول يتصل.

شغّله وخليه عايش:

```bash
termux-wake-lock                      # عشان أندرويد ما يقفلوش وهو في الخلفية
set -a && . ./.env && set +a && node src/index.js
```

امسح الـQR من الشاشة مرة واحدة وخلاص. وفي إعدادات أندرويد: البطارية ← Termux ← **غير مقيّد**.

لو قفل، شغّله تاني بنفس الأمر — بيانات الجلسة متخزنة في `data/` فمش هيطلب QR تاني.
خد نسخة من فولدر `data/` كل فترة.

### ضبط سرعة السؤال (اختياري)
`PULL_EVERY_MS` (افتراضي 5000) و`PULL_IDLE_MS` (افتراضي 20000): لما مفيش شغل بيبطّأ لوحده
لحد المدة التانية عشان البطارية، وبيرجع يسرّع أول ما تيجي رسالة.

## التشغيل على سيرفر (لو حبيت لاحقًا)
1. ثبّت Docker، ثم:
   ```bash
   git clone -b wa-gateway https://github.com/alizizo888-code/alizeidan123.git wa-gateway && cd wa-gateway
   cp .env.example .env      # عدّل GATEWAY_TOKEN و WEBHOOK_SECRET
   docker build -t mutqan-wa .
   docker run -d --name mutqan-wa --restart always --env-file .env -p 127.0.0.1:8787:8787 -v mutqan-wa-data:/data mutqan-wa
   ```
2. ضع أمامه HTTPS (Caddy أو Cloudflare Tunnel المجاني) ليصبح مثل `https://wa.yourdomain.com`.
3. في التطبيق: الواتساب ← الأرقام ← «إعداد الوسيط»: الصق الرابط والمفتاح (GATEWAY_TOKEN)،
   ثم انسخ `WEBHOOK_URL` و`WEBHOOK_SECRET` الظاهرين هناك إلى ملف `.env` وأعد تشغيل الحاوية.
4. اضغط «ربط رقم جديد» وامسح QR أو أدخل كود الاقتران.

بدون Docker: `npm ci --omit=dev && node src/index.js` (Node 20+) مع المتغيرات نفسها، ويفضّل تشغيله عبر pm2/systemd.
بيانات الجلسات في `DATA_DIR` — احتفظ بنسخة احتياطية منها كي لا تعيد الربط.
