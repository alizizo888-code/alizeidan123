#!/usr/bin/env python3
# Network control from GitHub: wake every WordPress engine, health + stats, and Hostinger DNS.
#   python network/control.py cron             call wp-cron on all 22 WordPress sites (each site's own engine publishes its quota)
#   python network/control.py health           HTTP + net-stats of every site -> network/state/health.json (+ hourly history)
#   python network/control.py dns-list         Hostinger DNS zone oxygen11.com (secret HOSTINGER_API_TOKEN)
#   python network/control.py dns-set NAME TYPE CONTENT [TTL]   add/replace one record (CNAME/A/TXT); protected names are refused
import json, os, sys, time, concurrent.futures as cf
import requests
from common import ROOT, STATE, TODAY, NOW, jload, jsave, guarded

SITES = jload(os.path.join(ROOT, "wp-sites.json"), [])
ZONE = "oxygen11.com"
PROTECTED = {"@", "www", "ftp", "mail", "autodiscover", "autoconfig", "_dmarc", "mutqan", "desk", "ads"}
UA = {"user-agent": "oxygen-network-control/1.0"}


def one(url, timeout=40):
    t = time.time()
    try:
        r = requests.get(url, headers=UA, timeout=timeout)
        return r.status_code, round(time.time() - t, 1), r
    except Exception as e:
        return 0, round(time.time() - t, 1), str(e)[:120]


def cron():
    if not guarded():
        print("standby repository: not waking the engines"); return 0
    with cf.ThreadPoolExecutor(8) as ex:
        res = list(ex.map(lambda s: (s["id"],) + one(f"https://{s['host']}/wp-cron.php?doing_wp_cron={int(time.time())}", 60)[:2], SITES))
    bad = [r for r in res if not (200 <= r[1] < 400)]
    print(json.dumps({"woken": len(res) - len(bad), "failed": bad}, ensure_ascii=False))
    return 0


def health():
    def chk(s):
        code, sec, r = one(f"https://{s['host']}/")
        scode, _, sr = one(f"https://{s['host']}/wp-json/oxygen/v1/net-stats")
        stats = None
        if scode == 200:
            try:
                stats = sr.json()
            except Exception:
                pass
        return {"id": s["id"], "host": s["host"], "http": code, "seconds": sec, "stats": stats}
    with cf.ThreadPoolExecutor(8) as ex:
        res = list(ex.map(chk, SITES))
    doc = {"date": TODAY, "time": NOW.strftime("%H:%M"), "sites": res, "down": [r["id"] for r in res if not (200 <= r["http"] < 400)]}
    jsave(os.path.join(STATE, "health.json"), doc)
    hist = jload(os.path.join(STATE, "health-history.json"), [])
    hist = (hist + [{"date": doc["date"], "time": doc["time"], "down": doc["down"]}])[-24 * 14:]
    jsave(os.path.join(STATE, "health-history.json"), hist)
    print(json.dumps({"up": len(res) - len(doc["down"]), "down": doc["down"]}, ensure_ascii=False))
    return 3 if doc["down"] else 0


def probe():
    """Blogger custom domains: DNS, TLS and HTTP of blog-*.oxygen11.com and of the blogspot address -> network/state/blogger-probe.json"""
    import socket, ssl
    net = jload(os.path.join(os.path.dirname(ROOT), "blogger", "network.json"), [])
    def chk(b):
        host = b.get("host") or ""
        row = {"id": b["id"], "host": host, "blog_id": bool(b.get("blog_id"))}
        try:
            row["dns"] = sorted({a[4][0] for a in socket.getaddrinfo(host, 443)})
        except Exception as e:
            row["dns"] = "FAIL " + str(e)[:60]
        try:
            with socket.create_connection((host, 443), timeout=15) as sk:
                with ssl.create_default_context().wrap_socket(sk, server_hostname=host) as t:
                    row["tls"] = "ok " + dict(x[0] for x in t.getpeercert()["subject"]).get("commonName", "")
        except Exception as e:
            row["tls"] = "FAIL " + str(e)[:90]
        row["http"] = one(f"https://{host}/", 25)[0]
        row["http_plain"] = one(f"http://{host}/", 25)[0]
        if b.get("blogspot"):
            row["blogspot"] = one(b["blogspot"], 25)[0]
        return row
    with cf.ThreadPoolExecutor(8) as ex:
        rows = list(ex.map(chk, [b for b in net if b.get("host")]))
    jsave(os.path.join(STATE, "blogger-probe.json"), {"date": TODAY, "time": NOW.strftime("%H:%M"), "rows": rows})
    for r in rows:
        print(r)
    return 0


def seo():
    """What blocks Google on every WordPress site: robots.txt, sitemap, noindex, canonical, http->https, slow pages, broken posts.
    -> network/state/seo-audit.json + seo-audit.md ; exit 3 when something is wrong."""
    import re
    def chk(s):
        h, out = s["host"], {"id": s["id"], "host": s["host"], "problems": []}
        P = out["problems"]
        c, sec, r = one(f"https://{h}/robots.txt")
        txt = r.text if c == 200 else ""
        if c != 200: P.append(f"robots.txt {c}")
        elif re.search(r"(?im)^\s*disallow:\s*/\s*$", txt): P.append("robots.txt يمنع الموقع كله (Disallow: /)")
        if c == 200 and "sitemap" not in txt.lower(): P.append("robots.txt مفيهوش Sitemap")
        c, sec, r = one(f"https://{h}/wp-sitemap.xml")
        if c != 200: P.append(f"wp-sitemap.xml {c}")
        else:
            subs = re.findall(r"<loc>([^<]+)</loc>", r.text); out["sitemaps"] = len(subs)
            if not subs: P.append("خريطة الموقع فاضية")
        c, sec, r = one(f"https://{h}/", 40)
        out["home"] = [c, sec]
        if c != 200: P.append(f"الصفحة الرئيسية {c}")
        else:
            html = r.text
            if re.search(r"<meta[^>]+name=.robots.[^>]+noindex", html, re.I) or "noindex" in r.headers.get("x-robots-tag", "").lower(): P.append("الصفحة الرئيسية عليها noindex (جوجل ممنوع يفهرسها)")
            can = re.search(r"<link[^>]+rel=.canonical.[^>]+href=.([^\"']+)", html, re.I)
            if can and h not in can.group(1): P.append("canonical بيشاور على دومين تاني: " + can.group(1))
            if sec > 6: P.append(f"الصفحة الرئيسية بطيئة ({sec} ثانية)")
        c2, _, r2 = one(f"http://{h}/", 25)
        if isinstance(r2, requests.Response) and r2.url.startswith("http://"): P.append("http مش بيحوّل على https")
        c, _, r = one(f"https://{h}/wp-json/wp/v2/posts?per_page=5&_fields=link")
        links = [x["link"] for x in r.json()] if c == 200 else []
        if c != 200: P.append(f"REST posts {c}")
        bad = []
        for u in links:
            pc, ps, pr = one(u, 40)
            if pc != 200: bad.append(f"{pc} {u}")
            elif re.search(r"<meta[^>]+name=.robots.[^>]+noindex", pr.text, re.I): bad.append(f"noindex {u}")
        if bad: P.append("مقالات فيها مشكلة: " + "، ".join(bad[:3]))
        return out
    with cf.ThreadPoolExecutor(6) as ex:
        res = list(ex.map(chk, SITES))
    jsave(os.path.join(STATE, "seo-audit.json"), {"date": TODAY, "time": NOW.strftime("%H:%M"), "sites": res})
    L = [f"# فحص السيو لمواقع ووردبريس — {TODAY} {NOW.strftime('%H:%M')}", ""]
    for x in res:
        L.append(f"- **{x['id']}** ({x['host']}): " + ("سليم" if not x["problems"] else "؛ ".join(x["problems"])))
    open(os.path.join(STATE, "seo-audit.md"), "w", encoding="utf-8").write("\n".join(L) + "\n")
    print("\n".join(L))
    return 3 if any(x["problems"] for x in res) else 0


def cities(since=""):
    """Newest posts of every WordPress site: which ones name a city other than Makkah/Jeddah (to see whether the
    Makkah/Jeddah-only engine is live). since = ISO time (UTC), e.g. 2026-11-09T13:00:00 -> network/state/cities.json"""
    import re
    src = open(os.path.join(ROOT, "..", "oxygen-ac-automation", "includes", "seo", "data-cities.php"), encoding="utf-8").read()
    names = [c for c in re.findall(r'"([^"]+)"', src) if "مكة" not in c]
    def chk(s):
        params = {"per_page": 30, "_fields": "title,link,date_gmt"}
        if since:
            params["after"] = since
        c, _, r = one(f"https://{s['host']}/wp-json/wp/v2/posts?" + "&".join(f"{k}={v}" for k, v in params.items()))
        posts = r.json() if c == 200 else []
        bad = [p["title"]["rendered"] for p in posts if any(n in p["title"]["rendered"] for n in names)]
        return {"id": s["id"], "posts": len(posts), "other_city": len(bad), "examples": bad[:2]}
    with cf.ThreadPoolExecutor(6) as ex:
        res = list(ex.map(chk, SITES))
    jsave(os.path.join(STATE, "cities.json"), {"date": TODAY, "time": NOW.strftime("%H:%M"), "since": since, "sites": res})
    for x in res:
        print(x)
    print("TOTAL posts", sum(x["posts"] for x in res), "other-city", sum(x["other_city"] for x in res))
    return 0


def aitest():
    """One tiny request to each free AI provider: prints the model it picked and whether it answered."""
    import common
    for name, fn in common.WRITERS:
        try:
            out = common.ai_call(fn, 'أرجع JSON فقط: {"ok": true, "word": "تكييف"}')
            print(f"{name}: OK {out.strip()[:80]}")
        except Exception as e:
            print(f"{name}: FAIL {str(e)[:200]}")
    print("models:", common._models)
    return 0


def hostinger(method, body=None):
    tok = os.getenv("HOSTINGER_API_TOKEN")
    if not tok:
        print("HOSTINGER_API_TOKEN not set yet: nothing to do"); sys.exit(0)
    r = requests.request(method, f"https://developers.hostinger.com/api/dns/v1/zones/{ZONE}", timeout=30,
                         headers={"authorization": "Bearer " + tok, "accept": "application/json", "content-type": "application/json"},
                         data=json.dumps(body) if body is not None else None)
    if not r.ok:
        print(f"Hostinger {r.status_code}: {r.text[:300]}"); sys.exit(1)
    return r.json() if r.text else {}


def dns_list():
    z = hostinger("GET")
    rows = [f"{x.get('name')}\t{x.get('type')}\t{x.get('ttl')}\t" + ", ".join(y.get("content", "") for y in x.get("records", [])) for x in (z if isinstance(z, list) else z.get("data", z.get("zone", [])))]
    print("\n".join(rows))
    jsave(os.path.join(STATE, "dns.json"), z)
    return 0


def dns_set(name, typ, content, ttl="3600"):
    if name in PROTECTED or typ not in ("CNAME", "A", "TXT"):
        print(f"refused: {name} {typ} is protected or not allowed"); return 2
    hostinger("PUT", {"overwrite": True, "zone": [{"name": name, "type": typ, "ttl": max(300, min(86400, int(ttl))), "records": [{"content": content}]}]})
    print(f"ok: {name}.{ZONE} {typ} -> {content}")
    return 0


if __name__ == "__main__":
    a = sys.argv[1:] or ["health"]
    if a[0] == "cities":
        sys.exit(cities(*a[1:2]))
    cmd = {"cron": cron, "health": health, "dns-list": dns_list, "probe": probe, "seo": seo, "aitest": aitest}.get(a[0])
    sys.exit(cmd() if cmd else dns_set(*a[1:]) if a[0] == "dns-set" else 2)
