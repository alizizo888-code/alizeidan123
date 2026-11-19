// Blogger re-layout of EXISTING posts (owner order 2026-11-03): prepend the shop front + «شرح المقال» heading to every old post.
// Paste into a draft.blogger.com tab on /blog/posts/<blogId> (javascript_tool, after a full navigation). Needs localStorage 'bk_fronts'
// = fronts.json from the public branch blogger-queue ({brand: html with @@T@@ for the title}); built by:
//   node -e "const {front}=require('./blogger/storefront');..."  (see PUBLISHING.md). Posts already carrying <!--oxf--> are skipped.
// Keeps all old content; only inserts at the top. Run: window.__relayout('brand', maxEdits) ; poll window.__log. Blogger blocks ~45 writes/hour: keep <=15 per pass.
window.__log = []; window.__stop = 0
const sleep = (ms) => new Promise((r) => setTimeout(r, ms)), vis = (el) => el && el.offsetParent !== null
const clk = (el) => { const b = el.closest('[role=button]') || el; ['mousedown', 'mouseup', 'click'].forEach((ev) => b.dispatchEvent(new MouseEvent(ev, { bubbles: true }))) }
const W = async (f, n = 40) => { for (let i = 0; i < n; i++) { const r = f(); if (r) return r; await sleep(400) } return null }
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const H2 = 'font-size:20px;font-weight:800;color:#075985;margin:22px 0 10px;padding-right:10px;border-right:5px solid #0ea5e9;border-top:3px dashed #bae6fd;padding-top:16px;margin-top:28px'
const links = () => [...document.querySelectorAll('a[href*="/post/edit/"]')]
async function back() { await sleep(1500); const b = [...document.querySelectorAll('[role=button][aria-label="رجوع"]')].find(vis); if (b) clk(b); await W(() => !location.pathname.includes('/edit/') && links().length, 40); await sleep(1500) }
window.__relayout = async (brand, max) => {
  const FR = JSON.parse(localStorage.getItem('bk_fronts'))[brand]; let done = 0
  const ids = links().map((a) => a.getAttribute('href').split('/').pop()); const n = ids.length; __log.push(brand + ' posts=' + n)
  for (let i = 0; i < n && done < max && !__stop; i++) {
    try {
      await sleep(2500); const a = links().find((x) => x.getAttribute('href').endsWith(ids[i])); if (!a) { __log.push(i + ' nolink'); break } a.click()
      await W(() => location.pathname.endsWith(ids[i])); await sleep(2500)
      const t = await W(() => [...document.querySelectorAll('input[aria-label="العنوان"]')].find((x) => vis(x) && x.value))
      const fr = await W(() => [...document.querySelectorAll('iframe')].find((f) => vis(f) && f.contentDocument && f.contentDocument.body && f.contentDocument.body.isContentEditable && f.contentDocument.body.innerHTML.length > 20))
      if (!t || !fr) { __log.push(i + ' NOEDITOR'); __stop = 1; break }
      const d = fr.contentDocument
      if (d.body.innerHTML.includes('oxf')) { __log.push(i + ' has ' + t.value.slice(0, 30)); await back(); continue }
      const title = t.value, before = d.body.innerText.length
      d.body.focus(); d.getSelection().collapse(d.body, 0)
      d.execCommand('insertHTML', false, FR.split('@@T@@').join(esc(title)) + `<h2 style="${H2}">شرح المقال: ${esc(title)}</h2>`); await sleep(900)
      if (!d.body.innerHTML.includes('oxf')) { __log.push(i + ' INSERTFAIL ' + title.slice(0, 30)); await back(); continue }
      const ups = [...document.querySelectorAll('[role=button][aria-label="تعديل"]')].filter(vis).sort((x, y) => x.getBoundingClientRect().left - y.getBoundingClientRect().left)
      clk(ups[0]); await sleep(5000) // Blogger saves in place and stays in the editor
      __log.push(i + ' ' + title.slice(0, 35) + ' | ' + before + '->' + d.body.innerText.length + ' updated'); done++; await back()
    } catch (e) { __log.push(i + ' ERR ' + e.message); await back() }
  }
  __log.push('DONE ' + done)
}
