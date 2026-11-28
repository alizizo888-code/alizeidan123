"""All-day watch: which sites answer, which are slow, which hang.

Every run checks, for each of the 22 WordPress sites and the desk:
  - the home page            (what a visitor from Google opens)
  - the app                  (/app/ — what a customer orders from)
  - the server's data door   (POST /wp-json/mutqan/v1/team {op: config} — what the app talks to)
and appends one line per site to network/state/uptime/<date>.jsonl, then rewrites
network/state/uptime-latest.md: today so far, per site — answered / slow / hung, median and worst time.

A check that takes longer than SLOW seconds is «slow»; longer than HANG (or no answer) is «hung».
Stops by itself after UPTIME_UNTIL (YYYY-MM-DD, Riyadh time) so a one-day test does not run forever.
"""
import datetime as dt
import json
import os
import statistics
import time
from concurrent.futures import ThreadPoolExecutor

import requests

ROOT = os.path.dirname(os.path.abspath(__file__))
STATE = os.path.join(ROOT, "state")
SLOW, HANG = 3.0, 15.0
UA = {"User-Agent": "Mozilla/5.0 (uptime-watch; +https://oxygen11.com)", "Cache-Control": "no-cache"}
RIYADH = dt.timezone(dt.timedelta(hours=3))


def check(method, url, body=None):
    t0 = time.monotonic()
    try:
        r = requests.request(method, url, headers={**UA, **({"Content-Type": "application/json"} if body else {})},
                             data=json.dumps(body) if body else None, timeout=HANG, allow_redirects=True)
        sec = round(time.monotonic() - t0, 2)
        ok = r.status_code < 400 and len(r.content) > 50
        return {"code": r.status_code, "s": sec, "ok": ok}
    except requests.RequestException as e:
        return {"code": 0, "s": round(time.monotonic() - t0, 2), "ok": False, "err": type(e).__name__}


def site(s, at):
    h = s["host"]
    out = {"at": at, "id": s["id"], "host": h}
    if s.get("desk"):
        out["home"] = check("GET", f"https://{h}/ops")
    else:
        out["home"] = check("GET", f"https://{h}/?nocache={int(time.time())}")
        out["app"] = check("GET", f"https://{h}/app/?nocache={int(time.time())}")
        out["api"] = check("POST", f"https://{h}/wp-json/mutqan/v1/team", {"op": "config"})
    return out


def state_of(c):
    if not c["ok"] or c["s"] >= HANG:
        return "hung"
    return "slow" if c["s"] >= SLOW else "ok"


def summary(day, rows):
    by = {}
    for r in rows:
        by.setdefault(r["id"], []).append(r)
    lines = [f"# مراقبة المواقع — {day}", "",
             f"كل ربع ساعة: الصفحة الرئيسية، التطبيق (/app)، والسيرفر. «بطيء» = أكتر من {SLOW:g} ث، «معلّق» = أكتر من {HANG:g} ث أو مفيش رد.", "",
             "| الموقع | فحوصات | تمام | بطيء | معلّق | الوسيط (ث) | الأسوأ (ث) | آخر مشكلة |", "|---|---|---|---|---|---|---|---|"]
    worst_sites = []
    for sid, rs in by.items():
        checks = [(k, r[k], r["at"]) for r in rs for k in ("home", "app", "api") if k in r]
        n = len(checks)
        st = [state_of(c) for _, c, _ in checks]
        times = [c["s"] for _, c, _ in checks if c["ok"]]
        bad = [(k, c, at) for k, c, at in checks if state_of(c) != "ok"]
        last = ""
        if bad:
            k, c, at = bad[-1]
            label = {"home": "الرئيسية", "app": "التطبيق", "api": "السيرفر"}[k]
            last = f"{at[11:16]} {label}: " + (f"HTTP {c['code']}" if c["code"] else c.get("err", "لا رد")) + f" ({c['s']} ث)"
        hung = st.count("hung")
        if hung:
            worst_sites.append((hung, sid))
        lines.append(f"| {sid} | {n} | {st.count('ok')} | {st.count('slow')} | {hung} | {statistics.median(times) if times else '—'} | {max(times) if times else '—'} | {last} |")
    lines += ["", "**الخلاصة:** " + ("كل المواقع ردّت طول اليوم." if not worst_sites else "اتعلّقت: " + "، ".join(f"{s} ({n} مرة)" for n, s in sorted(worst_sites, reverse=True)))]
    return "\n".join(lines) + "\n"


def main():
    now = dt.datetime.now(RIYADH)
    until = os.environ.get("UPTIME_UNTIL", "")
    if until and now.strftime("%Y-%m-%d") > until:
        print("uptime watch ended", until)
        return
    sites = json.load(open(os.path.join(ROOT, "wp-sites.json"), encoding="utf-8"))
    sites = [{"id": s["id"], "host": s["host"]} for s in sites] + [{"id": "desk", "host": "desk.oxygen11.com", "desk": True}]
    at = now.strftime("%Y-%m-%dT%H:%M")
    with ThreadPoolExecutor(max_workers=6) as ex:
        rows = list(ex.map(lambda s: site(s, at), sites))
    day = now.strftime("%Y-%m-%d")
    os.makedirs(os.path.join(STATE, "uptime"), exist_ok=True)
    path = os.path.join(STATE, "uptime", f"{day}.jsonl")
    with open(path, "a", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    all_rows = [json.loads(l) for l in open(path, encoding="utf-8") if l.strip()]
    open(os.path.join(STATE, "uptime-latest.md"), "w", encoding="utf-8").write(summary(day, all_rows))
    bad = [r["id"] for r in rows if any(state_of(r[k]) == "hung" for k in ("home", "app", "api") if k in r)]
    print(at, "hung:", ", ".join(bad) or "none")


if __name__ == "__main__":
    main()
