# Daily publishing runbook — Blogger network (22 brands)

Network: 44 blogs (22 original faces + 22 Blogger-only twins, `tier: blog-only`).
Target: as many as the account allows without bursts — at most **15 posts per pass**, 4 passes/day (≈60/day),
rotating blogs so the ones published longest ago go first; raise the cap only after a week without BLOCKED. Never burst:
Blogger blocked new posts *and* edits for the whole account after ~45 posts in one hour (2026-11-02).

## Each run
1. `git pull` branch `mutqan`. Read `state/published.json` and `network.json` (blogs with `blog_id` only).
2. For every live blog pick ONE article, in this order:
   a. a pending generator article: `state.pending_long[brand][0]` → `content/articles_long.json[brand][i]`;
   b. otherwise WRITE a new article from `content/editorial_plan.json` — next unused topic of the brand's
      spec (then its neighbour specs). Hand-written, 900–1400 words, Arabic, specific and useful: real causes,
      numbers, steps, local (Makkah/Jeddah) context, a short FAQ, a "اطلب الخدمة" link to
      `https://oxygen11.com/app/?via=<brand>#/s/<spec>` and 1–2 links to sister blogs (`live_url`).
      No copy-paste between brands; different angle per brand (`angle_label`). Save it to
      `content/written/<brand>/<yyyy-mm-dd>-<n>.json` as `[title, labels, html]`.
   When a spec has < 10 unused topics, append 10 new specific topics (no near-duplicates).
3. Build the run bundle `content/queue.json` = `{brand: [[title, labels, html]]}`, commit + push.
4. Chrome: open a github.com tab of the repo, `fetch('/alizizo888-code/mutaqin-oxygen-app/raw/mutqan/blogger/content/queue.json')`
   and `window.opener.postMessage({k:'bk_queue', v:text}, 'https://draft.blogger.com')` — the github tab must be
   opened *from* the Blogger tab (inject a button, click it with the computer tool, it calls window.open) and the
   Blogger tab needs a `message` listener that stores `ev.data.v` under `ev.data.k`. Then merge into
   `bk_bundle.queue`.
5. For each blog: navigate (full load) to `/blog/posts/<blog_id>`, paste `runner.js`, `__run([['queue',brand,0]])`,
   poll `__log` every ~20 s. If a line says `BLOCKED`, stop the whole run (rate limit) and log it.
6. Update `state/published.json` (move titles to published, drop pending index, append log line), commit, push.

## Fixes queue
`state.title_fixes_pending`: open the post editor, replace "لـال" with "لل" in the title, click تحديث.

## Indexing
All blogs live under the Search Console domain property `sc-domain:oxygen11.com` (DNS-verified).
Sitemaps per blog: `https://blog-<id>.oxygen11.com/sitemap.xml` and `/sitemap-pages.xml` (oxygen: `blog.`).
New blogs: add CNAME `blog-<id>` → `ghs.google.com.` (Hostinger), set Blogger custom domain, submit both sitemaps.
URL Inspection "طلب الفهرسة" has a small daily quota: use it for blog homepages and the newest articles only.

## Reporting
Publishing passes are silent. Each pass appends to `state.log`:
`{date, time, pass, published:[{brand,title,url}], blocked, errors:[]}`.
A separate daily task (23:52 Asia/Riyadh) reads the log and sends the owner ONE combined report.
Robustness: if `window.opener` is null in the GitHub tab, close it and reopen it from the Blogger tab;
re-paste runner.js after every full navigation; never await long work in one javascript_tool call.

## Creating the remaining blogs (daily task)
Up to 5 new blogs per day (Blogger's creation cap), from `network.json` entries with `blog_id: null`, in file order.
For each: Blogger → new blog (title = `title`, address = `slug`, fall back to `slug-ksa` if taken) → record `blog_id`/`blogspot`
→ (Hostinger CNAME `blog-<id>` → `ghs.google.com.` already exists for all 44 hosts, `dns: true`) → Blogger custom domain `blog-<id>.oxygen11.com` → theme from
`templates/<id>.xml` (rebuild with `node build_sites.js` after updating network.json) → 5 pages from `content/pages.json`
→ submit both sitemaps in Search Console. Article publishing is left to the publishing passes.
If "تعذَّر إنشاء المدونة" appears, the daily cap is reached: stop and continue next day.
