#!/usr/bin/env python3
# Network review (on demand or daily): reads the newest posts of every WordPress site and every live Blogger blog and checks
#   • duplicate / near-duplicate titles and bodies across the whole network   • articles under 1200 words
#   • forbidden claims (prices, phone numbers, percentages, licences, ratings)  • sites that do not answer
# With an AI key it also asks the free AI for a short quality verdict on a sample. Writes network/state/review-<date>.md;
# exit code 3 when something needs the owner (the workflow then opens a GitHub issue).
import json, os, re, sys, itertools, zlib
from concurrent.futures import ThreadPoolExecutor
import requests
from common import ROOT, REPO, STATE, TODAY, jload, jsave, norm, words, text, BAD, ask_json, ai_available

PER_SITE = int(os.getenv("REVIEW_PER_SITE") or 10)
UA = {"user-agent": "oxygen-network-review/1.0"}


def wp_posts(host):
    r = requests.get(f"https://{host}/wp-json/wp/v2/posts", params={"per_page": PER_SITE, "_fields": "title,link,content,date"}, headers=UA, timeout=25)
    r.raise_for_status()
    return [{"title": text(p["title"]["rendered"]), "url": p["link"], "html": p["content"]["rendered"]} for p in r.json()]


def blogger_posts(base):
    r = requests.get(base.rstrip("/") + "/feeds/posts/default", params={"alt": "json", "max-results": PER_SITE}, headers=UA, timeout=25)
    r.raise_for_status()
    out = []
    for e in r.json().get("feed", {}).get("entry", []):
        link = next((l["href"] for l in e.get("link", []) if l.get("rel") == "alternate"), "")
        out.append({"title": e["title"]["$t"], "url": link, "html": e.get("content", {}).get("$t", "")})
    return out


def shingles(t, k=6):
    # as small integers: the pair comparison below runs on every pair of the network's posts, and comparing sets of
    # strings made the daily run outgrow its 30 minutes (audit 2026-11-23)
    w = text(t).split()
    return frozenset(zlib.crc32(" ".join(w[i:i + k]).encode()) for i in range(max(0, len(w) - k)))


def main():
    sites = [("wp", s["id"], s["host"]) for s in jload(os.path.join(ROOT, "wp-sites.json"), [])]
    sites += [("blogger", b["id"], [u for u in (b.get("live_url"), b.get("blogspot"), "https://" + b["host"] + "/" if b.get("host") else None) if u]) for b in jload(os.path.join(REPO, "blogger", "network.json"), []) if b.get("blog_id")]   # a blog not created yet is planned, not down (its domain has no Blogger blog behind it)
    posts, down = [], []

    def read(site):
        kind, sid, where = site
        tries, err, got = ([where] if kind == "wp" else list(dict.fromkeys(where))), None, []
        for w in tries:
            try:
                got = [dict(p, kind=kind, site=sid) for p in (wp_posts(w) if kind == "wp" else blogger_posts(w))]
                err = None; break
            except Exception as e:
                err = f"{kind}:{sid} ({w}): {type(e).__name__} {str(e)[-90:]}"
        return got, err
    # every site at once (8 at a time) instead of one after the other
    with ThreadPoolExecutor(max_workers=8) as ex:
        for got, err in ex.map(read, sites):
            posts += got
            if err:
                down.append(err)
    issues = {"dup_title": [], "near_dup": [], "short": [], "claims": []}
    by = {}
    for p in posts:
        by.setdefault(norm(p["title"]), []).append(p)
        body = p["html"].split("شرح المقال")[-1]
        if p["kind"] == "blogger" and words(body) < 1200:
            issues["short"].append(f"{p['site']}: {words(body)} كلمة — [{p['title'][:70]}]({p['url']})")
        m = BAD.search(text(body))
        if m:
            issues["claims"].append(f"{p['site']}: «{m.group(0)}» — [{p['title'][:70]}]({p['url']})")
        p["sh"] = shingles(body)
    for k, ps in by.items():
        if len({(x['kind'], x['site'], x['url']) for x in ps}) > 1:
            issues["dup_title"].append(" | ".join(f"{x['kind']}:{x['site']} [{x['title'][:50]}]({x['url']})" for x in ps))
    seen_path = os.path.join(STATE, "review-seen-pairs.json")
    seen_pairs = set(jload(seen_path, []))
    all_pairs, near = set(), []
    for a, b in itertools.combinations([p for p in posts if len(p["sh"]) > 50], 2):
        if a["site"] == b["site"] and a["kind"] == b["kind"]:
            continue
        la, lb = len(a["sh"]), len(b["sh"])
        if min(la, lb) / max(la, lb) <= 0.35:
            continue   # the Jaccard index can't pass 0.35 when one set is under 35% of the other's size
        inter = len(a["sh"] & b["sh"])
        j = inter / max(1, la + lb - inter)
        if j > 0.35:
            pk = "|".join(sorted([a["url"], b["url"]]))
            all_pairs.add(pk)
            if pk in seen_pairs:
                continue   # reported before: the uniqueness gate (Mutqan_Fp / Oxygen_Uniq) handles it, the report only shows what is new
            near.append((j, f"{round(100 * j)}% تشابه: {a['kind']}:{a['site']} [{a['title'][:40]}]({a['url']}) ↔ {b['kind']}:{b['site']} [{b['title'][:40]}]({b['url']})"))
    issues["near_dup"] = [x for _, x in sorted(near, key=lambda t: -t[0])]
    jsave(seen_path, sorted(seen_pairs | all_pairs)[-20000:])
    old_dup = len(all_pairs & seen_pairs)
    verdicts = []
    if ai_available():
        for p in posts[:: max(1, len(posts) // 8)][:8]:
            prov, d = ask_json("قيّم هذا المقال العربي لموقع صيانة باختصار. أرجع JSON فقط: {\"score\":1-10,\"problems\":[\"...\"]}. ركز على: التكرار والحشو، المعلومات الخاطئة، الادعاءات غير المثبتة، وضوح الدعوة لطلب الخدمة.\n\nالعنوان: " + p["title"] + "\n\n" + text(p["html"])[:6000])
            if d:
                verdicts.append(f"{p['kind']}:{p['site']} — {d.get('score', '?')}/10 — {'؛ '.join(d.get('problems', [])[:3])} — [{p['title'][:50]}]({p['url']}) ({prov})")
    bad = sum(len(v) for v in issues.values()) + len(down)
    L = [f"# مراجعة الشبكة — {TODAY}", "", f"اتقرا {len(posts)} مقالة من {len(sites) - len(down)} موقع شغال من {len(sites)}.", ""]
    L += [f"التشابه: {len(issues['near_dup'])} زوج جديد النهارده، و{old_dup} زوج اتبلغ قبل كده (مش بيتكرر في التقرير). المقالات الجديدة بتتفحص قبل النشر، والنسخ القديمة بتتشال من فهرس جوجل تلقائيًا.", ""]
    names = {"dup_title": "عناوين مكررة", "near_dup": "محتوى متشابه بين مواقع (جديد)", "short": "مقالات أقل من 1200 كلمة", "claims": "ادعاءات ممنوعة (أسعار/أرقام/تراخيص)"}
    for k, v in issues.items():
        L += [f"## {names[k]} ({len(v)})", ""] + [f"- {x}" for x in v[:40]] + ([""] if v else ["- لا يوجد", ""])
    L += [f"## مواقع ما ردتش ({len(down)})", ""] + ([f"- {x}" for x in down] or ["- لا يوجد"]) + [""]
    if verdicts:
        L += ["## رأي الذكاء الاصطناعي (عينة)", ""] + [f"- {x}" for x in verdicts] + [""]
    md = "\n".join(L) + "\n"
    with open(os.path.join(STATE, f"review-{TODAY}.md"), "w", encoding="utf-8") as f:
        f.write(md)
    jsave(os.path.join(STATE, "review-latest.json"), {"date": TODAY, "posts": len(posts), "down": down, "counts": {k: len(v) for k, v in issues.items()}})
    print(md)
    sys.exit(3 if bad else 0)


if __name__ == "__main__":
    main()
