# نص المهمة المتكررة: إصلاح وتجهيز مدونات الشبكة
المواعيد: يوميًا 10:07 و2:07 و5:07 و7:07 مساءً، بتوقيت الرياض، بدون إشعارات.

Silent repair pass for the owner's Blogger network (do not message the owner; the daily report task covers it).
Repo: GitHub alizizo888-code/mutaqin-oxygen-app, branch `mutqan` (add_repo, push access). `git pull` first and follow "Order of work + article length" and "Work plan: one job at a time" in `blogger/PUBLISHING.md`, plus the Chrome content pipe described there. If Chrome is not connected, log that and stop.
Use at most 12 Blogger writes in this pass and never more than 15 writes of any kind in the last hour. In order:
1. `state.pages_pending`: create or replace pages from `blogger/content/pages.json[id]`. On a published page the save button is aria-label «تعديل»: wait 14 seconds, then press رجوع. On a new page use نشر then تأكيد. Check themes in معاينة المظهر for blogs not yet checked.
2. `state.sitemaps_pending`: submit `/sitemap.xml` and `/sitemap-pages.xml` in Search Console property `sc-domain:oxygen11.com`, removing each blog when done.
3. `state.expand_pending`: load `blogger/content/expand_queue.json` into localStorage `bk_expand`, open `/blog/posts/<blog_id>`, paste `blogger/expand.js`, run `__expand(brand, n)` and poll `__log`. Move finished posts to `state.expand_done` as `brand|title`, and missing titles to `state.expand_missing`.
If the editor does not open or a save sticks, stop and log it. Never delete anything. Do not publish new articles. Append {date, time, task:"repair", writes, done, errors} to `state.log`, commit (message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`) and push to `mutqan`. When all three lists are empty, log "repair finished" and stop.
