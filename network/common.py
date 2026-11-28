# Shared helpers for the GitHub-run network (WordPress + Blogger): free AI writers, network-wide de-duplication ledger, small I/O.
import datetime, hashlib, json, os, random, re, sys, html as H
import requests

ROOT = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(ROOT)
STATE = os.path.join(ROOT, "state")
os.makedirs(STATE, exist_ok=True)
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


def words(h):
    return len(re.sub(r"<[^>]+>", " ", h or "").split())


def text(h):
    return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", h or ""))).strip()


# ---------- network-wide ledger: no title (or near-same title) twice anywhere, any day ----------
LEDGER = os.path.join(STATE, "titles.json")


def norm(t):
    t = re.sub(r"[ً-ْـ]", "", t or "")                # tashkeel, tatweel
    t = re.sub(r"[أإآ]", "ا", t).replace("ة", "ه").replace("ى", "ي")
    t = re.sub(r"[^\w\s]", " ", t)
    t = re.sub(r"\b(في|من|على|الى|إلى|عن|مع|و|ال)\b", " ", t)
    return " ".join(sorted(set(t.split())))


def key(t):
    return hashlib.sha1(norm(t).encode()).hexdigest()[:16]


class Ledger:
    def __init__(self):
        self.d = jload(LEDGER, {})

    def seen(self, title):
        return key(title) in self.d

    def add(self, title, site, url):
        self.d[key(title)] = [site, TODAY, url]

    def save(self):
        jsave(LEDGER, self.d)


# ---------- free AI writers: Gemini -> Groq -> OpenRouter ----------
# model names change over time (a retired model answers 404), so the live model list picks one unless GEMINI_MODEL / GROQ_MODEL is set
_models = {}


def gemini_model(k):
    if os.getenv("GEMINI_MODEL"):
        return os.getenv("GEMINI_MODEL")
    if "gemini" not in _models:
        try:
            r = requests.get(f"https://generativelanguage.googleapis.com/v1beta/models?key={k}&pageSize=200", timeout=30)
            ms = [m["name"].split("/", 1)[1] for m in r.json().get("models", []) if "generateContent" in m.get("supportedGenerationMethods", [])]
            ok = [m for m in ms if "flash" in m and not re.search(r"image|tts|live|audio|embed|thinking|exp|preview", m)] or \
                 [m for m in ms if "flash" in m] or ms
            ok.sort(key=lambda m: ([int(x) for x in re.findall(r"\d+", m)][:2], "lite" not in m), reverse=True)
            _models["gemini"] = ok[0] if ok else "gemini-2.5-flash"
        except Exception:
            _models["gemini"] = "gemini-2.5-flash"
        print(f"[ai] gemini model: {_models['gemini']}", file=sys.stderr)
    return _models["gemini"]


GROQ_PREFER = ["llama-3.3-70b", "gpt-oss-120b", "llama-4-maverick", "llama-4-scout", "kimi-k2", "qwen3-32b", "gpt-oss-20b", "llama-3.1-8b"]


def groq_model(k):
    if os.getenv("GROQ_MODEL"):
        return os.getenv("GROQ_MODEL")
    if "groq" not in _models:
        try:
            r = requests.get("https://api.groq.com/openai/v1/models", headers={"authorization": "Bearer " + k}, timeout=30)
            ids = [m["id"] for m in r.json().get("data", []) if m.get("active", True) and not re.search(r"whisper|guard|tts|orpheus|playai|distil", m["id"])]
            pick = next((i for p in GROQ_PREFER for i in ids if p in i), ids[0] if ids else "llama-3.3-70b-versatile")
            _models["groq"] = pick
        except Exception:
            _models["groq"] = "llama-3.3-70b-versatile"
        print(f"[ai] groq model: {_models['groq']}", file=sys.stderr)
    return _models["groq"]


def _gemini(p):
    k = os.getenv("GEMINI_API_KEY")
    if not k:
        raise RuntimeError("no key")
    r = requests.post(f"https://generativelanguage.googleapis.com/v1beta/models/{gemini_model(k)}:generateContent?key={k}", timeout=150,
                      json={"contents": [{"role": "user", "parts": [{"text": p}]}], "generationConfig": {"temperature": 0.95, "responseMimeType": "application/json"}})
    r.raise_for_status()
    return "".join(x.get("text", "") for x in r.json()["candidates"][0]["content"]["parts"])


def _oai(url, k, model, p):
    r = requests.post(url, timeout=150, headers={"authorization": "Bearer " + k},
                      json={"model": model, "temperature": 0.95, "response_format": {"type": "json_object"}, "messages": [{"role": "user", "content": p}]})
    r.raise_for_status()
    return r.json()["choices"][0]["message"]["content"]


def _groq(p):
    k = os.getenv("GROQ_API_KEY")
    if not k:
        raise RuntimeError("no key")
    return _oai("https://api.groq.com/openai/v1/chat/completions", k, groq_model(k), p)


def _openrouter(p):
    k = os.getenv("OPENROUTER_API_KEY")
    if not k:
        raise RuntimeError("no key")
    return _oai("https://openrouter.ai/api/v1/chat/completions", k, os.getenv("OPENROUTER_MODEL", "meta-llama/llama-3.3-70b-instruct:free"), p)


WRITERS = [("groq", _groq), ("gemini", _gemini), ("openrouter", _openrouter)]   # Groq answered in tests; Gemini timed out
_dead = set()
AI_DELAY = int(os.getenv("AI_DELAY") or 20)          # seconds between two AI requests (free-tier rate limits)
AI_RETRIES = int(os.getenv("AI_RETRIES") or 3)       # 429 retries per provider: 60s, 120s, 240s
_last = [0.0]


def ai_call(fn, p):
    """One AI request with a fixed pause before it and exponential backoff on 429. Raises after the last retry."""
    import time
    for attempt in range(AI_RETRIES + 1):
        wait = AI_DELAY - (time.time() - _last[0])
        if wait > 0:
            time.sleep(wait)
        _last[0] = time.time()
        try:
            return fn(p)
        except requests.HTTPError as e:
            code = e.response.status_code if e.response is not None else 0
            if code == 429 and attempt < AI_RETRIES:
                pause = 60 * (2 ** attempt)
                print(f"[ai {fn.__name__}] 429 rate limit, retry in {pause}s", file=sys.stderr)
                time.sleep(pause)
                continue
            raise


def ai_available():
    return [n for n, _ in WRITERS if os.getenv({"gemini": "GEMINI_API_KEY", "groq": "GROQ_API_KEY", "openrouter": "OPENROUTER_API_KEY"}[n])]


def ask_json(p, prefer=None):
    order = sorted(WRITERS, key=lambda w: w[0] != prefer) if prefer else WRITERS
    for name, fn in order:
        if name in _dead:
            continue
        try:
            raw = re.sub(r"^```(?:json)?|```$", "", ai_call(fn, p).strip()).strip()
            return name, json.loads(raw)
        except requests.HTTPError as e:
            if e.response is not None and e.response.status_code in (401, 403, 429):
                _dead.add(name)                          # 429 only after all backoff retries: skip it for this run
            print(f"[ai {name}] {e}", file=sys.stderr)
        except Exception as e:
            print(f"[ai {name}] {e}", file=sys.stderr)
    return None, None


# writing voices: each article picks one, so the network reads like many different authors and editors
VOICES = [
    ("مهندس تكييف يشرح بهدوء ودقة", "دليل فني بخطوات وجداول مقارنة"),
    ("فني ميداني يحكي ما يراه يومياً في البيوت والمحلات", "أسلوب قريب من الناس مع أمثلة افتراضية"),
    ("محرر خدمات يكتب للعميل المستعجل", "إجابات مباشرة وقوائم علامات وتحذيرات"),
    ("مستشار صيانة للمنشآت والمطاعم", "تحليل أسباب ثم حلول ثم خطة وقائية"),
    ("كاتب محتوى محلي يعرف أحياء مكة وجدة", "مقال عن الحي ونوع المباني وتأثير المناخ"),
    ("مشرف جودة يراجع أعمال الفنيين", "قائمة فحص قبل وبعد الخدمة وأسئلة يجب طرحها"),
]


def article_prompt(kw, kind, city, brand, voice):
    topic = "غرف التبريد والتجميد والثلاجات التجارية" if kind == "fridge" else "المكيفات: غسيل وصيانة وتركيب وتكييف مركزي"
    place = "جدة" if city == "jd" else "مكة المكرمة"
    who, shape = voice
    return f"""اكتب مقالاً عربياً أصلياً تماماً لموقع خدمات صيانة "{brand}" بالكلمة المفتاحية: «{kw}».
التخصص: {topic}. المدينة: {place} (أحياء حقيقية، مناخ المدينة، أنواع المباني). صوت الكاتب: {who}. شكل المقال: {shape}.
1400 كلمة على الأقل. عنوان رئيسي فريد يحتوي الكلمة المفتاحية بصياغة غير مألوفة، 7-10 أقسام H2 بعناوين غير نمطية، وأسئلة شائعة.
اذكر الكلمة المفتاحية في أول فقرة وفي عنوانين فرعيين، ونوّع المرادفات، وادعُ القارئ لطلب فني في البداية والوسط والنهاية.
ممنوع: الأسعار، أرقام الهواتف، النسب والإحصاءات، التقييمات، التراخيص، سنوات الخبرة، قصص عملاء حقيقيين (أي مثال يُذكر أنه افتراضي).
أرجع JSON فقط: {{"title":"...","excerpt":"وصف ميتا 150 حرفاً","tags":["..."],"sections":[{{"h2":"...","paragraphs":["..."],"h3":[{{"t":"...","p":"..."}}]}}],"faq":[{{"q":"...","a":"..."}}]}}"""


def render(d, link):
    e = H.escape
    cta = f'<p style="text-align:center;margin:18px 0"><a href="{link}" style="display:inline-block;background:#0ea5e9;color:#fff;font-weight:800;text-decoration:none;border-radius:99px;padding:11px 26px">اطلب الفني الآن</a></p>'
    out, secs = [], d.get("sections", [])
    for i, s in enumerate(secs):
        out.append(f"<h2>{e(s.get('h2', ''))}</h2>" + "".join(f"<p>{e(x)}</p>" for x in s.get("paragraphs", [])))
        out += [f"<h3>{e(x.get('t', ''))}</h3><p>{e(x.get('p', ''))}</p>" for x in (s.get("h3") or [])]
        if i in (0, len(secs) // 2):
            out.append(cta)
    if d.get("faq"):
        out.append("<h2>أسئلة شائعة</h2>" + "".join(f"<h3>{e(f.get('q', ''))}</h3><p>{e(f.get('a', ''))}</p>" for f in d["faq"]))
    out.append(cta)
    return '<div dir="rtl">' + "".join(out) + "</div>"


def write_article(kw, kind, city, brand, link, min_words=1200):
    voice = random.choice(VOICES)
    p = article_prompt(kw, kind, city, brand, voice)
    for _ in range(2):
        prov, d = ask_json(p)
        if not d:
            return None
        body = render(d, link)
        if d.get("title") and words(body) >= min_words and not BAD.search(text(body)):
            return {"title": d["title"].strip(), "excerpt": (d.get("excerpt") or "")[:300], "tags": [t for t in d.get("tags", []) if t][:6], "html": body, "ai": prov, "voice": voice[0]}
        p += "\nالنسخة السابقة كانت أقصر من المطلوب أو فيها ممنوعات. التزم بـ1400 كلمة على الأقل وبدون أي أرقام أو أسعار."
    return None


def guarded():
    """Only one repository publishes: the primary (mutaqin-oxygen-app), or the mirror when FAILOVER_ACTIVE=true there."""
    repo = os.getenv("GITHUB_REPOSITORY", "")
    return repo in ("", "alizizo888-code/mutaqin-oxygen-app") or os.getenv("FAILOVER_ACTIVE") == "true"
