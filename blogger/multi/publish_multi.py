#!/usr/bin/env python3
# Blogger multi-account publisher: free AI article generation (Gemini -> Groq) + Blogger API v3 through the account that owns each blog.
# credentials: blogger/multi/creds/gmail_*.json  (authorized-user JSON: client_id, client_secret, refresh_token)
# queue:       blogger/multi/keywords.json  [{"keyword": "...", "blog_id": "optional", "kind": "ac|fridge|optional", "city": "mk|jd|optional"}]
# also:        env KEYWORDS (lines: keyword | blog_id | kind)
# state:       blogger/multi/state.json ; every run is also appended to blogger/state/published.json "log" for the daily report.
import glob, json, os, re, sys, time, random, datetime, html as H
import requests
from google.oauth2.credentials import Credentials
from google.auth.transport.requests import Request
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), "network"))
from common import Ledger, guarded, ai_call, gemini_model, groq_model                                   # network-wide title ledger (WordPress + Blogger)

ROOT = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(ROOT))
CREDS = os.path.join(ROOT, "creds")
QUEUE = os.path.join(ROOT, "keywords.json")
STATE = os.path.join(ROOT, "state.json")
LEDGER = os.path.join(REPO, "blogger", "state", "published.json")
NETWORK = os.path.join(REPO, "blogger", "network.json")
SCOPES = ["https://www.googleapis.com/auth/blogger"]
MAX_RUN = int(os.getenv("MAX_PER_RUN", "15"))
MAX_ACCOUNT = int(os.getenv("MAX_PER_ACCOUNT", "15"))
MIN_WORDS = int(os.getenv("MIN_WORDS", "1200"))
SLEEP = int(os.getenv("SLEEP_SECONDS", "20"))
DRY = os.getenv("DRY") == "1"
NOW = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=3)
TODAY = NOW.strftime("%Y-%m-%d")
FRIDGE = re.compile(r"غرف|ثلاج|فريزر|تجميد|تبريد")
AC = re.compile(r"مكيف|تكييف|سبليت|فريون|دكت")
BAD = re.compile(r"ريال|ر\.س|\bSAR\b|\+?966|05\d{8}|\d+ ?%|نجوم|مرخص|ترخيص|سنوات خبرة|سنة خبرة")


def jload(p, d):
    try:
        with open(p, encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return d


def jsave(p, o):
    with open(p, "w", encoding="utf-8") as f:
        json.dump(o, f, ensure_ascii=False, indent=1)


# ---------- accounts ----------
def accounts():
    out = []
    for path in sorted(glob.glob(os.path.join(CREDS, "gmail_*.json"))):
        name = os.path.splitext(os.path.basename(path))[0]
        if DRY:
            out.append({"name": name, "svc": None, "blogs": {}, "sent": 0, "blocked": False})
            continue
        try:
            c = Credentials.from_authorized_user_file(path, SCOPES)
            if not c.valid:
                c.refresh(Request())
            svc = build("blogger", "v3", credentials=c, cache_discovery=False)
            items = svc.blogs().listByUser(userId="self").execute().get("items", [])
            out.append({"name": name, "svc": svc, "blogs": {b["id"]: b for b in items}, "sent": 0, "blocked": False})
        except Exception as e:
            print(f"[{name}] auth failed: {e}", file=sys.stderr)
    return out


def owner(accs, blog_id):
    for a in accs:
        if blog_id in a["blogs"]:
            return a
    return None


# ---------- AI ----------
def prompt(kw, kind, city, brand):
    topic = "غرف التبريد والتجميد والثلاجات التجارية" if kind == "fridge" else "المكيفات: غسيل وصيانة وتركيب وتكييف مركزي"
    place = "جدة" if city == "jd" else "مكة المكرمة"
    style = random.choice(["دليل عملي بخطوات", "أسئلة وأجوبة موسعة", "مقارنة بين الخيارات", "قائمة علامات وتحذيرات", "شرح الأسباب ثم الحلول"])
    return f"""اكتب مقالاً عربياً أصلياً لموقع خدمات صيانة "{brand}" بالكلمة المفتاحية: «{kw}».
التخصص: {topic}. المدينة: {place} (اذكر أحياء حقيقية ومناخ المدينة ونوع المباني). نمط المقال: {style}.
الطول: 1400 كلمة على الأقل. عنوان H1 جذاب يحتوي الكلمة المفتاحية، ثم 7 إلى 10 أقسام H2 مع H3 عند الحاجة، وقسم أسئلة شائعة.
اذكر الكلمة المفتاحية في أول فقرة وفي عنوانين فرعيين، ونوّع المرادفات. ادعُ القارئ لطلب فني في البداية والوسط والنهاية.
ممنوع: الأسعار، أرقام الهواتف، النسب والإحصاءات، التقييمات، التراخيص، سنوات الخبرة، قصص عملاء حقيقيين (أي مثال يُذكر أنه افتراضي).
أرجع JSON فقط: {{"title": "...", "labels": ["...","..."], "sections": [{{"h2": "...", "paragraphs": ["..."], "h3": [{{"t": "...", "p": "..."}}]}}], "faq": [{{"q": "...", "a": "..."}}]}}"""


def ask_gemini(p):
    key = os.getenv("GEMINI_API_KEY")
    if not key:
        raise RuntimeError("no gemini key")
    model = gemini_model(key)
    r = requests.post(f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}", timeout=120,
                      json={"contents": [{"role": "user", "parts": [{"text": p}]}],
                            "generationConfig": {"temperature": 0.9, "responseMimeType": "application/json"}})
    r.raise_for_status()
    return "".join(x.get("text", "") for x in r.json()["candidates"][0]["content"]["parts"])


def ask_groq(p):
    key = os.getenv("GROQ_API_KEY")
    if not key:
        raise RuntimeError("no groq key")
    r = requests.post("https://api.groq.com/openai/v1/chat/completions", timeout=120,
                      headers={"authorization": "Bearer " + key},
                      json={"model": groq_model(key), "temperature": 0.9,
                            "response_format": {"type": "json_object"}, "messages": [{"role": "user", "content": p}]})
    r.raise_for_status()
    return r.json()["choices"][0]["message"]["content"]


def to_html(d, kw, link):
    e = H.escape
    cta = f'<p style="text-align:center;margin:18px 0"><a href="{link}" style="display:inline-block;background:#0ea5e9;color:#fff;font-weight:800;text-decoration:none;border-radius:99px;padding:11px 26px">اطلب الفني الآن</a></p>'
    parts, secs = [], d.get("sections", [])
    for i, s in enumerate(secs):
        parts.append(f"<h2>{e(s.get('h2', ''))}</h2>" + "".join(f"<p>{e(x)}</p>" for x in s.get("paragraphs", [])))
        parts += [f"<h3>{e(x.get('t', ''))}</h3><p>{e(x.get('p', ''))}</p>" for x in s.get("h3", []) or []]
        if i in (0, len(secs) // 2):
            parts.append(cta)
    if d.get("faq"):
        parts.append("<h2>أسئلة شائعة</h2>" + "".join(f"<h3>{e(f.get('q', ''))}</h3><p>{e(f.get('a', ''))}</p>" for f in d["faq"]))
    parts.append(cta)
    return '<div dir="rtl">' + "".join(parts) + "</div>"


def words(h):
    return len(re.sub(r"<[^>]+>", " ", h).split())


def generate(kw, kind, city, brand, link):
    p = prompt(kw, kind, city, brand)
    for fn in (ask_groq, ask_gemini):
        for _ in range(2):
            try:
                raw = re.sub(r"^```(?:json)?|```$", "", ai_call(fn, p).strip()).strip()
                d = json.loads(raw)
                body = to_html(d, kw, link)
                text = re.sub(r"<[^>]+>", " ", body)
                if words(body) >= MIN_WORDS and not BAD.search(text) and d.get("title"):
                    return d["title"].strip(), [l for l in d.get("labels", []) if l][:5], body, fn.__name__[4:]
                p += "\nالنسخة السابقة كانت قصيرة أو فيها ممنوعات. التزم بـ1400 كلمة وبدون أي أرقام أو أسعار."
            except Exception as ex:
                print(f"[ai {fn.__name__}] {ex}", file=sys.stderr)
                break
    return None


# ---------- queue ----------
def load_queue():
    q = jload(QUEUE, [])
    for line in (os.getenv("KEYWORDS") or "").splitlines():
        f = [x.strip() for x in line.split("|")]
        if f and f[0]:
            q.append({"keyword": f[0], "blog_id": f[1] if len(f) > 1 and f[1] else None, "kind": f[2] if len(f) > 2 and f[2] else None})
    for it in q:
        it.setdefault("kind", None)
        if not it["kind"]:
            it["kind"] = "fridge" if FRIDGE.search(it["keyword"]) else "ac" if AC.search(it["keyword"]) else None
    return q


def pick_kind(st):
    m = st.setdefault("mix", {})
    if m.get("date") != TODAY:
        m.clear(); m.update({"date": TODAY, "ac": 0, "fridge": 0})
    return "ac" if m["ac"] + 1 <= 0.6 * (m["ac"] + m["fridge"] + 1) or m["ac"] == m["fridge"] == 0 else "fridge"


def main():
    if not guarded():
        print("standby repository: not publishing"); return
    net = {b["blog_id"]: b for b in jload(NETWORK, []) if b.get("blog_id")}
    st = jload(STATE, {"done": [], "log": []})
    done_kw = {(x["blog_id"], x["keyword"]) for x in st["done"]}
    accs = accounts()
    if not accs:
        print("no credentials in blogger/multi/creds"); return
    all_blogs = [bid for a in accs for bid in a["blogs"]] or list(net)
    queue = [x for x in load_queue() if (x.get("blog_id"), x["keyword"]) not in done_kw]
    # blogs of the other Gmail accounts (not handled by blogger/api/daily.js, which serves blogger/network.json) get their own
    # automatic keywords: AUTO_PER_RUN per run, 60% A/C / 40% cold rooms, 70% Makkah, never a keyword the network already used
    led = Ledger()
    others = [bid for a in accs for bid in a["blogs"] if bid not in net]
    auto = int(os.getenv("AUTO_PER_RUN") or 6)
    dist = jload(os.path.join(REPO, "blogger", "content", "districts.json"), {"makkah": ["العزيزية"], "jeddah": ["الروضة"]})
    svc = {"ac": ["غسيل مكيفات", "صيانة مكيفات", "تركيب مكيفات سبليت", "شحن فريون", "صيانة تكييف مركزي", "اصلاح مكيف لا يبرد", "تنظيف دكت"],
           "fridge": ["صيانة غرف التبريد", "صيانة غرف التجميد", "تصليح ثلاجات المطاعم", "صيانة ثلاجات العرض", "تركيب غرف تبريد"]}
    intent = ["أفضل شركة", "فني", "أسرع فني", "مهندس", "معلم", "رقم فني", "خدمة", "دليل", "متى تحتاج"]
    for k in range(min(auto, len(others)) if others and len(queue) < MAX_RUN else 0):
        bid = others[(len(st["done"]) + k) % len(others)]
        kind = "ac" if (k % 5) < 3 else "fridge"; city = "mk" if (k % 10) < 7 else "jd"
        for _ in range(30):
            kw = f"{random.choice(intent)} {random.choice(svc[kind])} في حي {random.choice(dist['makkah' if city == 'mk' else 'jeddah'])} {'بمكة المكرمة' if city == 'mk' else 'بجدة'}"
            if not led.seen(kw + " " + bid):
                queue.append({"keyword": kw, "blog_id": bid, "kind": kind, "city": city, "auto": True}); break
    want = pick_kind(st)
    queue.sort(key=lambda x: (x["kind"] != want, ))      # keep the day near 60% A/C / 40% cold rooms
    published, errors, rr = [], [], 0
    for it in queue:
        if len(published) >= MAX_RUN:
            break
        bid = it.get("blog_id")
        if not bid:
            bid = all_blogs[rr % len(all_blogs)]; rr += 1
        acc = owner(accs, bid) if not DRY else accs[0]
        if not acc:
            errors.append(f"{bid}: no gmail_*.json owns this blog"); continue
        if acc["blocked"] or acc["sent"] >= MAX_ACCOUNT:
            continue
        b = net.get(bid, {})
        brand = b.get("name") or (acc["blogs"].get(bid) or {}).get("name") or "أكسجين"
        link = f"https://oxygen11.com/app/?via={b.get('id', 'blog')}#/new"
        kind = it["kind"] or want
        art = generate(it["keyword"], kind, it.get("city") or "mk", brand, link) if not DRY else (it["keyword"], ["التكييف"], "<p>dry</p>", "dry")
        if not art:
            errors.append(f"{bid}: AI failed for «{it['keyword']}»"); continue
        title, labels, body, prov = art
        if led.seen(title):
            errors.append(f"{bid}: duplicate title skipped «{title[:60]}»"); continue
        try:
            url = "dry://" + bid
            if not DRY:
                r = acc["svc"].posts().insert(blogId=bid, isDraft=False, body={"kind": "blogger#post", "title": title, "content": body, "labels": labels}).execute()
                url = r.get("url", "")
            acc["sent"] += 1
            st["mix"][kind] = st["mix"].get(kind, 0) + 1
            rec = {"brand": b.get("id", bid), "title": title, "url": url, "kind": kind, "account": acc["name"], "ai": prov, "words": words(body)}
            published.append(rec)
            led.add(title, bid, url); led.add(it["keyword"] + " " + bid, bid, url); led.save()
            st["done"].append({"blog_id": it.get("blog_id"), "keyword": it["keyword"], "date": TODAY, "url": url})
            jsave(STATE, st)
            time.sleep(0 if DRY else SLEEP)
        except HttpError as ex:
            code = ex.resp.status
            errors.append(f"{acc['name']} {bid}: {code} {str(ex)[:160]}")
            if code in (403, 429):
                acc["blocked"] = True                           # this account rests until the next run; its blogs are not moved elsewhere
    entry = {"date": TODAY, "time": NOW.strftime("%H:%M"), "task": "multi-publish", "published": published,
             "blocked": any(a["blocked"] for a in accs), "errors": errors,
             "accounts": {a["name"]: {"blogs": len(a["blogs"]), "sent": a["sent"], "blocked": a["blocked"]} for a in accs}}
    st["log"] = (st.get("log", []) + [entry])[-200:]
    jsave(STATE, st)
    led = jload(LEDGER, None)
    if isinstance(led, dict):
        led.setdefault("log", []).append(entry); jsave(LEDGER, led)
    rest = [x for x in jload(QUEUE, []) if (x.get("blog_id"), x["keyword"]) not in {(d["blog_id"], d["keyword"]) for d in st["done"]}]
    if os.path.exists(QUEUE):
        jsave(QUEUE, rest)
    print(json.dumps({"published": len(published), "errors": errors}, ensure_ascii=False))


if __name__ == "__main__":
    main()
