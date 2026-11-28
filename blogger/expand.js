// Fix posts already published: relabel the invented "field case" as a hypothetical example and add sections until the explanation reaches 1200 words.
// Paste into draft.blogger.com on /blog/posts/<blogId> after a full navigation. Needs localStorage 'bk_expand' = content/expand_queue.json (Chrome pipe in PUBLISHING.md).
// Run: __expand('brand', maxWrites, firstIndexNotDone) ; poll __log, each line ends ok / skip / STUCK. Idempotent: a finished post carries <!--oxx-->. Never deletes anything.
window.__log = []; window.__stop = 0
const sleep = (ms) => new Promise((r) => setTimeout(r, ms)), vis = (el) => el && el.offsetParent !== null
const clk = (el) => { const b = el.closest('[role=button]') || el; ['mousedown', 'mouseup', 'click'].forEach((ev) => b.dispatchEvent(new MouseEvent(ev, { bubbles: true }))) }
const W = async (f, n = 40) => { for (let i = 0; i < n; i++) { const r = f(); if (r) return r; await sleep(400) } return null }
const words = (h) => { const i = h.lastIndexOf('شرح المقال'); return (i >= 0 ? h.slice(i) : h).replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length }
const fix = (h) => h.split('حالة من الميدان').join('مثال توضيحي').replace(/تواصل معنا ([^<]{2,60}?) بعد أن لاحظ:/g, 'هذا مثال افتراضي وليس حالة عميل حقيقية: لنفترض أن $1 لاحظ:').replace(/تواصل معنا عميل في ([^<]{2,60}?) بعد أن لاحظ:/g, 'هذا مثال افتراضي وليس حالة عميل حقيقية: لنفترض أن عميلاً في $1 لاحظ:')
async function back() { await sleep(1500); const b = [...document.querySelectorAll('[role=button][aria-label="رجوع"]')].find(vis); if (b) clk(b); await W(() => !location.pathname.includes('/edit/')); await sleep(1500); if (location.pathname.includes('/edit/')) { __stop = 1; __log.push('STUCK in editor: reload the list and run again') } }
const row = (t) => [...document.querySelectorAll('span,div,a')].filter((x) => x.children.length === 0 && vis(x) && x.textContent.trim() === t)[0]
window.__expand = async (brand, max, from = 0) => {
  const Q = JSON.parse(localStorage.getItem('bk_expand'))[brand] || {}; let writes = 0; const res = { ok: [], skip: [], missing: [] }; window.__res = res
  for (const title of Object.keys(Q).slice(from)) {
    if (__stop || writes >= max) break
    try {
      await sleep(2500); let r = row(title)
      for (let i = 0; i < 12 && !r; i++) { window.scrollTo(0, document.body.scrollHeight); document.querySelectorAll('*').forEach((e) => { if (e.scrollHeight > e.clientHeight + 200 && e.clientHeight > 200) e.scrollTop = e.scrollHeight }); await sleep(900); r = row(title) }
      if (!r) { res.missing.push(title); __log.push('missing ' + title.slice(0, 30)); continue }
      r.closest('[role=listitem]').querySelector('a[href*="/post/edit/"]').click()
      const t = await W(() => [...document.querySelectorAll('input[aria-label="العنوان"]')].find((x) => vis(x) && x.value), 60); await sleep(2500)
      const fr = await W(() => [...document.querySelectorAll('iframe')].find((f) => { try { return vis(f) && f.contentDocument && f.contentDocument.body && f.contentDocument.body.isContentEditable } catch (e) { return false } }))
      if (!t || !fr) { __stop = 1; __log.push('BLOCKED no editor ' + title.slice(0, 30)); break }
      if (t.value.trim() !== title) { __stop = 1; __log.push('WRONGPOST ' + title.slice(0, 30)); await back(); break }
      const d = fr.contentDocument, old = d.body.innerHTML
      if (old.includes('oxx') ) { res.skip.push(title); __log.push('skip ' + title.slice(0, 30)); await back(); continue }
      let h = fix(old); const need = words(h) < 1200
      if (need) { const i = h.lastIndexOf('<h2>اطلب الآن'), x = Q[title]; h = i > 0 ? h.slice(0, i) + x + h.slice(i) : h + x }
      if (h === old) { res.skip.push(title); __log.push('skip fine ' + title.slice(0, 30)); await back(); continue }
      h = '<!--oxx-->' + h; d.body.focus(); d.execCommand('selectAll'); d.execCommand('insertHTML', false, h); await sleep(1000)
      const now = d.body.innerHTML; if (!now.includes('oxf') && old.includes('oxf')) { __stop = 1; __log.push('LOSTFRONT ' + title.slice(0, 30)); break }
      const ups = [...document.querySelectorAll('[role=button][aria-label="تعديل"]')].filter(vis); if (!ups.length) { __stop = 1; __log.push('NOBUTTON'); break }
      clk(ups[0]); await sleep(14000); writes++; const w = words(d.body.innerHTML); res.ok.push(title); __log.push(brand + ' ok ' + w + ' ' + title.slice(0, 30)); await back()
    } catch (e) { __stop = 1; __log.push('ERR ' + e.message) }
  }
  __log.push('DONE writes=' + writes)
}
