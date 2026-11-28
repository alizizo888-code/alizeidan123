#!/usr/bin/env python3
# Extra AI articles for the 22 WordPress sites, published from GitHub through the WordPress REST API.
# They come ON TOP of what each site's own engine (oxygen-ac-automation) publishes, to add different writers, voices and shapes.
#   secret WP_AUTH = {"default": {"user": "...", "app_password": "xxxx xxxx ..."}, "<site id>": {"user": "...", "app_password": "...", "authors": [2, 5]}}
#   var    WP_AI_PER_DAY (default 44 = 2 per site), spread over the 12 daily runs; 60% A/C, 40% cold rooms; 70% Makkah.
# Never posts a title the network ledger (network/state/titles.json) or the site itself already has.
import base64, json, math, os, random, sys, time
import requests
from common import (REPO, ROOT, STATE, TODAY, NOW, Ledger, jload, jsave, norm, write_article, ai_available, guarded)

SITES = jload(os.path.join(ROOT, "wp-sites.json"), [])
DIST = jload(os.path.join(REPO, "blogger", "content", "districts.json"), {"makkah": ["العزيزية"], "jeddah": ["الروضة"]})
LOG = os.path.join(STATE, "wp-ai.json")
PER_DAY = int(os.getenv("WP_AI_PER_DAY") or 44)
RUN_HOURS = [3, 15]   # Riyadh hours of the 2 scheduled runs (cron 0 */12 UTC)
DRY = os.getenv("DRY") == "1"
UA = {"user-agent": "oxygen-network-publisher/1.0"}

AC_SVC = ["غسيل مكيفات", "صيانة مكيفات سبليت", "تركيب مكيفات", "شحن فريون", "صيانة تكييف مركزي", "تنظيف دكت التكييف", "اصلاح مكيف لا يبرد", "فك وتركيب مكيفات", "صيانة مكيفات شباك", "تصليح تسريب مياه المكيف"]
FR_SVC = ["صيانة غرف التبريد", "صيانة غرف التجميد", "تصليح ثلاجات المطاعم", "صيانة ثلاجات العرض", "تركيب غرف تبريد", "صيانة كمبروسر غرفة التجميد", "عزل غرف التبريد", "صيانة فريزر تجاري"]
INTENT = ["أفضل شركة", "فني", "رقم فني", "أسرع فني", "مهندس", "معلم", "شركة موثوقة", "خدمة", "كيف تختار", "متى تحتاج", "دليل"]


def auth(site):
    a = json.loads(os.getenv("WP_AUTH") or "{}")
    c = a.get(site["id"]) or a.get("default")
    if not c:
        return None, []
    tok = base64.b64encode(f"{c['user']}:{c['app_password']}".encode()).decode()
    return {"authorization": "Basic " + tok, **UA}, c.get("authors") or []


def keyword(site, kind, city, led):
    svc = AC_SVC if kind == "ac" else FR_SVC
    ds = DIST["makkah" if city == "mk" else "jeddah"]
    for _ in range(40):
        kw = f"{random.choice(INTENT)} {random.choice(svc)} في حي {random.choice(ds)} {'بمكة المكرمة' if city == 'mk' else 'بجدة'}"
        if not led.seen(kw + " " + site["id"]):
            return kw
    return None


def exists_on_site(host, title, hdr):
    try:
        r = requests.get(f"https://{host}/wp-json/wp/v2/search", params={"search": title[:60], "per_page": 5}, headers=hdr, timeout=30)
        return any(norm(x.get("title", "")) == norm(title) for x in (r.json() if r.ok else []))
    except Exception:
        return False


def main():
    if not guarded():
        print("standby repository: not publishing"); return
    if not ai_available() and not DRY:
        print("no AI key (GEMINI_API_KEY / GROQ_API_KEY / OPENROUTER_API_KEY): nothing to write"); return
    st = jload(LOG, {"days": {}, "log": []})
    day = st["days"].setdefault(TODAY, {"n": 0, "ac": 0, "fridge": 0, "mk": 0, "per_site": {}})
    runs_left = max(1, len([h for h in RUN_HOURS if h >= NOW.hour]))
    n = min(int(os.getenv("MAX_PER_RUN") or 15), math.ceil(max(0, PER_DAY - day["n"]) / runs_left))
    if n <= 0:
        print(f"today's WordPress AI quota reached ({day['n']}/{PER_DAY})"); return
    led, done, errors = Ledger(), [], []
    sites = sorted([s for s in SITES], key=lambda s: day["per_site"].get(s["id"], 0))
    for site in sites:
        if len(done) >= n:
            break
        hdr, authors = auth(site)
        if not hdr and not DRY:
            errors.append(f"{site['id']}: no WP_AUTH entry"); continue
        kind = "ac" if day["ac"] + 1 <= 0.6 * (day["n"] + 1) or day["n"] == 0 else "fridge"
        city = "mk" if day["mk"] + 1 <= 0.7 * (day["n"] + 1) or day["n"] == 0 else "jd"
        kw = keyword(site, kind, city, led)
        if not kw:
            continue
        link = f"https://oxygen11.com/app/?via={site['id']}#/new"
        art = {"title": kw, "html": "<p>dry</p>", "excerpt": "", "ai": "dry", "voice": "dry"} if DRY else write_article(kw, kind, city, site["name"], link)
        if not art:
            errors.append(f"{site['id']}: AI failed for «{kw}»"); continue
        if led.seen(art["title"]) or (not DRY and exists_on_site(site["host"], art["title"], hdr)):
            errors.append(f"{site['id']}: duplicate title skipped «{art['title'][:60]}»"); continue
        body = {"title": art["title"], "content": art["html"], "excerpt": art["excerpt"], "status": "publish"}
        if authors:
            body["author"] = random.choice(authors)              # rotate the site's own writers
        try:
            url = "dry://" + site["host"]
            if not DRY:
                r = requests.post(f"https://{site['host']}/wp-json/wp/v2/posts", headers={**hdr, "content-type": "application/json"}, data=json.dumps(body), timeout=60)
                if r.status_code in (401, 403):
                    errors.append(f"{site['id']}: {r.status_code} login refused (check WP_AUTH)"); continue
                if r.status_code == 429:
                    errors.append(f"{site['id']}: 429 rate limited, stopping run"); break
                r.raise_for_status()
                url = r.json().get("link", "")
            led.add(art["title"], site["id"], url); led.add(kw + " " + site["id"], site["id"], url)
            day["n"] += 1; day[kind] += 1; day["mk"] += city == "mk"; day["per_site"][site["id"]] = day["per_site"].get(site["id"], 0) + 1
            done.append({"site": site["id"], "title": art["title"], "url": url, "kind": kind, "city": city, "ai": art["ai"], "voice": art["voice"]})
            led.save(); jsave(LOG, st)
            time.sleep(0 if DRY else 8)
        except Exception as e:
            errors.append(f"{site['id']}: {str(e)[:160]}")
    st["log"] = (st["log"] + [{"date": TODAY, "time": NOW.strftime("%H:%M"), "published": done, "errors": errors}])[-500:]
    st["days"] = dict(sorted(st["days"].items())[-60:])
    jsave(LOG, st); led.save()
    print(json.dumps({"published": len(done), "today": f"{day['n']}/{PER_DAY}", "errors": errors}, ensure_ascii=False))


if __name__ == "__main__":
    sys.path.insert(0, ROOT)
    main()
