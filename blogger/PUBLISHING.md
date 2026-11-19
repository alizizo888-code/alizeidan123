# Daily publishing runbook — Blogger network (22 brands)

Network: 44 blogs (22 original faces + 22 Blogger-only twins, `tier: blog-only`).
Target: as many as the account allows without bursts — at most **15 posts per pass**, 4 passes/day (≈60/day),
rotating blogs so the ones published longest ago go first; raise the cap only after a week without BLOCKED. Never burst:
Blogger blocked new posts *and* edits for the whole account after ~45 posts in one hour (2026-11-02).

## SERVER ONLY — no browser, no device (owner order 2026-11-17) — overrides everything below
The owner's phone and computer are usually off or asleep. **Forbidden:** publishing articles, posts, pages, edits or themes through Chrome
(Claude in Chrome), the desktop bridge or computer use. All work must run online on the server side (VPS Ubuntu 24.04 + n8n, or GitHub Actions + Blogger API v3 — `api/daily.js`, `multi/`).
Until that server path has its credentials (see `api/SETUP.md`: `BLOGGER_CLIENT_ID`, `BLOGGER_CLIENT_SECRET`, `BLOGGER_REFRESH_TOKEN` or `GMAIL_n_JSON`), a publishing / create / repair pass
may still prepare content in the repo (queue, written/, storefront.js) but must NOT open the browser: it logs `{blocked:true, errors:["server-only order: no Chrome; waiting for server credentials"]}` and stops.

## SERVER ONLY (owner order 2026-11-17) — overrides every Chrome/device step in this file
No publishing, page, theme or blog-creation work through Claude in Chrome, the owner's computer or phone. The owner's devices are usually off or asleep.
Everything runs online: the VPS (Hostinger KVM 1, Ubuntu 24.04 + n8n, Docker project `hub`) and/or GitHub Actions (`blogger-api.yml`), through the official Blogger / Search Console APIs.
- Allowed server-side: posts, pages, post edits/relayout (Blogger API v3), sitemap submit (Search Console API), DNS (Hostinger API), reports.
- Not possible through any API: creating a new blog, applying a theme, setting a custom domain. These stay paused until the owner decides; no Chrome fallback.
- Blockers to go live: Google OAuth (client id/secret + one consent by the owner, see `api/SETUP.md` or the hub `/connect` page), and a GitHub token for the VPS hub to read this private repo (hub container currently exits: "could not read Username for github.com").
- If the server path is not ready, a pass logs "waiting for server setup" and does nothing. Never fall back to Chrome.

## Each run
1. `git pull` branch `mutqan`. Read `state/published.json` and `network.json` (blogs with `blog_id` only).
2. For every live blog pick ONE article, in this order:
   a. a pending generator article: `state.pending_long[brand][0]` → `content/articles_long.json[brand][i]`;
   b. otherwise WRITE a new article from `content/editorial_plan.json` — next unused topic of the brand's
      spec (then its neighbour specs). Hand-written, **1200 words minimum** (see the order rule below), Arabic, specific and useful: real causes,
      numbers, steps, local (Makkah/Jeddah) context, a short FAQ, a "اطلب الخدمة" link to
      `https://oxygen11.com/app/?via=<brand>#/s/<spec>` and 1–2 links to sister blogs (`live_url`).
      No copy-paste between brands; different angle per brand (`angle_label`). Save it to
      `content/written/<brand>/<yyyy-mm-dd>-<n>.json` as `[title, labels, html]`.
   When a spec has < 10 unused topics, append 10 new specific topics (no near-duplicates).
3. Build the run bundle `content/queue.json` = `{brand: [[title, labels, html]]}`, then `node blogger/storefront.js blogger/content/queue.json` (shop front first, article last), commit + push.
4. Chrome: open a github.com tab of the repo, `fetch('/alizizo888-code/mutaqin-oxygen-app/raw/mutqan/blogger/content/queue.json')`
   and `window.opener.postMessage({k:'bk_queue', v:text}, 'https://draft.blogger.com')` — the github tab must be
   opened *from* the Blogger tab (inject a button, click it with the computer tool, it calls window.open) and the
   Blogger tab needs a `message` listener that stores `ev.data.v` under `ev.data.k`. Then merge into
   `bk_bundle.queue`.
5. For each blog: navigate (full load) to `/blog/posts/<blog_id>`, paste `runner.js`, `__run([['queue',brand,0]])`,
   poll `__log` every ~20 s. If a line says `BLOCKED`, stop the whole run (rate limit) and log it.
6. Update `state/published.json` (move titles to published, drop pending index, append log line), commit, push.

## Order of work + article length (owner order 2026-11-03) — overrides everything below
1. **Site first, articles after.** A blog gets articles only after its theme is applied and checked: site, destinations, design, specialties and banners all showing (theme editor → معاينة المظهر, because the owner's connection cannot open the `blog-*` hosts directly).
2. **No article under 1200 words**, counted on the explanation only; the shop front does not count. Never publish anything of two or three lines.
   `storefront.js` (`bodyWords`, `MIN_WORDS`) and `make_queue.js` enforce this: a short article is printed as `REJECTED` and removed from the queue. Log the rejects, do not shorten the check.
   Most of `articles_long.json` / `state.pending_long` is under 1200 and stays held until expanded.
3. Pages (من نحن, service, FAQ, areas) must carry real content too, not a few lines: build them with `content/build_pages_face.py`. The privacy page is the only short one.

## Work plan: one job at a time (owner order 2026-11-03)
Chrome and the Blogger account are shared, so jobs never overlap and the hourly limit counts every kind of write: articles, edits, pages and themes (≤ 15 per hour).
Before starting, check the newest `state.log` entries: if a Chrome job logged a start in the last 30 minutes with no end, skip this run and log it.
Always in this order; a step starts only when the one before is finished for that blog:
1. **Create** the blog, domain and theme, then check it in معاينة المظهر.
2. **Pages** from `state.pages_pending` (content from `content/build_pages_face.py`).
3. **Sitemaps** in Search Console.
4. **Repair** published posts from `state.expand_pending` using `expand.js` (needs `bk_expand` = `content/expand_queue.json`); move finished ones to `state.expand_done` as `brand|title`, and rebuild with `node blogger/content/build_expand.js`.
5. **New articles**, 1200 words minimum.
If `draft.blogger.com` shows ERR_CONNECTION_CLOSED, open the same path on `www.blogger.com` (it redirects back and loads). A post editor can stay on «جارٍ الحفظ» after a good save: reload to verify rather than saving again.
The publishing pass still runs on its hours, but it writes at most `15 − writes in the last hour`.
Never publish an invented customer story. An example must say it is hypothetical.

## Mix + volume (owner order 2026-11-08) — overrides the 70 % rule below
- **New articles: 60 % air-conditioning, 40 % cold rooms / freezer rooms / refrigeration, on every blog.** Blogs of other trades write the A/C or
  cold-room angle of their own trade (see `ANGLE` in `content/build_commercial_plan.js`), so each site keeps its specialty. 70 % Makkah, 30 % Jeddah.
- **Per day: 2/3 new buy-intent articles + 1/3 old generator articles** of the blog's own specialty (`state.pending_long`). An old article under
  1200 words is used only when the AI step brings it over 1200; otherwise the slot takes a new topic.
- **Volume ramps:** 60/day, +20 every 7 days without a Blogger block, up to 180 (120 new + 60 old). Spread over 12 runs, ≤ 15 posts per run.
  A 403/429 stops the day and restarts the ramp one step lower. Settings live in `state.ramp`.
- **Variety:** six title shapes and five tails per topic; the middle sections of every article come in a seeded order per brand + title;
  the optional AI step (`api/ai.js`) adds three unique sections. Tracked per day in `state.api_day` and `state.mix`.
- **Dashboard:** `state/DASHBOARD.md` and `state/reports/<date>.md`, rewritten after every run by `api/report.js`.

## Mix + style rule (owner order 2026-11-03) — applies to every pass
**Mix.** Of all posts published per day: **70 % about air-conditioning + cold rooms / freezer rooms / refrigeration**, and **70 % located in Makkah**
(Makkah districts, Makkah heat/Hajj-Umrah season, Makkah buildings). The two shares are independent (aim ≈ 49 % both).
- Every blog takes part: the 8 `ac`/`fridge` blogs always take cooling + Makkah topics; every other blog writes the *cooling angle of its own trade*
  (electric → A/C circuits & breakers; cleaning → duct cleaning; moving → A/C & fridge relocation; solar → A/C on solar; contracting → cold-room insulation) ~65 % of the time and its own trade otherwise.
- Per pass of N posts: ≥ ceil(0.7·N) cooling/cold/freezer and ≥ ceil(0.7·N) Makkah. Track running counts in `state.mix` = `{date, n, cooling, makkah}`
  (reset when the Asia/Riyadh date changes) and top up whichever share is behind.

**Topics = buy intent first.** Take the next unused topic of the blog from `content/commercial_plan.json[brand]` (each entry `{t, kw, city, cool}`;
`t` = title, `kw` = focus keyword, already 71 % cooling / 70 % Makkah). A topic is "used" when its `t` is in `state.published_topics[brand]`.
These are the searches that make people call or order — «أفضل شركة غسيل مكيفات»، «فني اصلاح تكيفات»، «معلم مكيفات»، «مهندس صيانة»، «تركيب مكيفات»، «أرقام فنيين».
Pending generator articles (`state.pending_long`, guides/Q&A style) and `editorial_plan.json` topics stay valid as *variety*, but at most **30 %** of a day's posts; the rest must come from the commercial plan.
When a blog's list runs low, regenerate with `node blogger/content/build_commercial_plan.js` (raise `N`) or append new Makkah-district topics by hand.

**Body of a buy-intent article** (1200 words minimum, hand-written, different per brand/angle): open with the customer's need in the first two lines and the
call to act; state what the service covers and how a visit works (معاينة، تشخيص، سعر واضح قبل التنفيذ، ضمان حسب سياسة الشركة — never invent prices, ratings or licences);
name the real Makkah district and its building types; add the signs that mean "call now"; a short FAQ; and **repeat the call-to-action** (`https://oxygen11.com/app/?via=<brand>#/new`) at the start, middle and end.
Not a consultation or a how-to-fix-it-yourself guide: tips are fine, but they must lead to "let our technician do it".

**Layout.** Every article uses the same shop front as the app's home page — hero + contact cards, offers banners, specialties, destinations — and the explanation of the article comes LAST under «شرح المقال».
After writing the day's articles into `content/queue.json`, run `node blogger/storefront.js blogger/content/queue.json` (idempotent; marker `<!--oxf-->`) before publishing.

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

## Second Google account (owner order 2026-11-09)
- The second Gmail account (altakyif) gets its **own full set of 44 blogs** (88 on Blogger in total), with different names, addresses and content from the first account's 44; same structure (theme, pages, sitemaps, rules above).
- Not started yet: it needs its own list in `network.json` (account field) and the creation steps run while signed in to that account in Chrome.
- Old WordPress articles that name other cities or look alike stay as they are; only new content follows the Makkah + Jeddah and variety rules.

## Creating the remaining blogs (daily task)
Up to 5 new blogs per day (Blogger's creation cap), from `network.json` entries with `blog_id: null`, in file order.
For each: Blogger → new blog (title = `title`, address = `slug`, fall back to `slug-ksa` if taken) → record `blog_id`/`blogspot`
→ (Hostinger CNAME `blog-<id>` → `ghs.google.com.` already exists for all 44 hosts, `dns: true`) → Blogger custom domain `blog-<id>.oxygen11.com` → theme from
`templates/<id>.xml` (rebuild with `node build_sites.js` after updating network.json) → 5 pages from `content/pages.json`
→ check the theme in معاينة المظهر → submit both sitemaps in Search Console. Article publishing is left to the publishing passes.
`state.pages_pending` lists blogs whose pages still need creating or replacing with the longer version (≤ 15 page writes per hour, never in a burst).
If "تعذَّر إنشاء المدونة" appears, the daily cap is reached: stop and continue next day.

## Re-layout of existing posts (owner order 2026-11-03)
Old posts get the shop front + «شرح المقال» heading prepended (content untouched) with `blogger/relayout.js`:
1. `fronts.json` = `{brand: storefront.front(b,'@@T@@')}` served from the public branch `blogger-queue`; load into localStorage `bk_fronts` with fetch on a draft.blogger.com tab.
2. On `/blog/posts/<blogId>` (full navigation) paste relayout.js, then `window.__relayout('<brand>', <max>)`; poll `window.__log`. Posts already carrying `<!--oxf-->` are skipped; empty drafts are skipped.
3. Blogger saves the update in place (the orange «تعديل» button) and stays in the editor — verify by reloading, not by waiting for navigation. Keep ≤15 updates per hour.

## Device-free path (Blogger API v3)
**Owner order 2026-11-17: no browser / Chrome / device work at all — everything runs online on the VPS (vps.oxygen11.com, Ubuntu 24.04 + n8n). See `blogger/vps/README.md`: `blogger/vps/setup.sh` installs a cron job that runs `blogger/vps/run.sh` (daily.js publish + report + ledger push) every 2 hours. The Chrome runners below are retired.**
`blogger/api/daily.js` + `.github/workflows/blogger-api.yml` publish and re-layout through the API from GitHub Actions (4 runs/day), no Chrome/device. Needs the 3 secrets in `blogger/api/SETUP.md`; until they exist the workflow no-ops. The Chrome runners stay as a fallback.
