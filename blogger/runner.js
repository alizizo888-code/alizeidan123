// Blogger in-page publisher. Paste into a draft.blogger.com tab (javascript_tool) after every FULL navigation
// to /blog/posts/<blogId> or /blog/pages/<blogId>. Content comes from localStorage 'bk_bundle'
// ({pages:{brand:[[t,html]]}, posts:{brand:[t,l,html]}, long:{brand:[[t,l,html]]}, queue:{brand:[[t,l,html]]}}).
// Runs detached; poll window.__log. Never await the whole run inside one javascript_tool call (45 s CDP limit).
window.__log = []; window.__stop = 0
const sleep = (ms) => new Promise((r) => setTimeout(r, ms)); const vis = (el) => el && el.offsetParent !== null
const clk = (el) => { const b = el.closest('[role=button]') || el; ['mousedown', 'mouseup', 'click'].forEach((ev) => b.dispatchEvent(new MouseEvent(ev, { bubbles: true }))) }
const W = async (f, n = 30) => { for (let i = 0; i < n; i++) { const r = f(); if (r) return r; await sleep(400) } return null }
const BID = location.pathname.split('/').pop()
async function pub() {
  const p = [...document.querySelectorAll('[role=button][aria-label="نشر"]')].filter(vis); clk(p[p.length - 1])
  const ok = await W(() => [...document.querySelectorAll('[role=button],button')].find((b) => vis(b) && b.innerText.trim() == 'تأكيد'), 30); if (ok) clk(ok)
  const back = await W(() => !location.pathname.includes('/edit/'), 40); return (ok ? ' ok' : ' NOCONFIRM') + (back ? '' : ' STUCK')
}
async function mk(KIND, BRAND, IDX) {
  const L = JSON.parse(localStorage.getItem('bk_bundle'))[KIND][BRAND]; const it = Array.isArray(L[0]) ? L[IDX] : L
  const title = it[0], html = it[it.length - 1], labels = it.length == 3 ? it[1] : ''
  const nb = await W(() => [...document.querySelectorAll(KIND == 'pages' ? '[aria-label="إنشاء صفحة جديدة"]' : '[aria-label="إنشاء مشاركة جديدة"]')].find(vis)); if (!nb) return 'nonew'
  await sleep(2500); if (document.body.innerText.includes(title)) return 'skip ' + title.slice(0, 30); clk(nb)
  const t = await W(() => [...document.querySelectorAll('input[aria-label="العنوان"]')].find(vis)); await sleep(1500)
  const fr = await W(() => [...document.querySelectorAll('iframe')].find((f) => vis(f) && f.contentDocument && f.contentDocument.body && f.contentDocument.body.isContentEditable))
  if (!fr) { __stop = 1; return 'BLOCKED (editor did not open: Blogger rate limit) — stop for today' }
  const d = fr.contentDocument; d.body.focus(); d.execCommand('selectAll'); d.execCommand('insertHTML', false, html); await sleep(700)
  t.focus(); t.select(); document.execCommand('insertText', false, title); await sleep(600)
  let lab = ''; if (labels) { const l = [...document.querySelectorAll('textarea,input')].find((x) => vis(x) && /تصنيف/.test(x.getAttribute('aria-label') || '')); if (l) { l.focus(); document.execCommand('insertText', false, labels); lab = ' L'; await sleep(300) } }
  return BRAND + ':' + KIND + IDX + ' ' + title.slice(0, 40) + ' | ' + d.body.innerText.length + lab + await pub()
}
async function go(p) { const a = [...document.querySelectorAll('a[href]')].find((x) => x.getAttribute('href').includes(p + '/' + BID)); if (!a) return 'nolink ' + p; a.click(); await sleep(3500); return 'go ' + location.pathname }
window.__run = async (seq) => { for (const s of seq) { if (__stop) break; try { await sleep(3000); __log.push(s[0] == 'go' ? await go(s[1]) : await mk(...s)) } catch (e) { __log.push('ERR ' + e.message) } } __log.push('DONE') }
// usage: __run([['queue','khabeer',0]]) ; pages: [['pages',brand,i]] ; switch list: ['go','/blog/posts']
