// One publishing window (morning | noon | evening). Sequential, one article per site per pass, jitter between writes, stops on 403/429.
const { q } = require('./db'), P = require('./planner'), G = require('./generators/commercial'), PE = require('./generators/personas'), D = require('./diversity'), TH = require('./themes'), AD = require('./adapters'), L = require('./layouts'), C = require('./contact'), K = require('./keywords'), LN = require('./lines'), LD = require('./generators/landing')
const NET = (() => { try { return require('../../blogger/network.json') } catch { return [] } })()
const sleep = (ms) => new Promise((r) => setTimeout(r, process.env.HUB_DRY === '1' ? 0 : ms))
exports.runWindow = async (win, { max = 15 } = {}) => {
  const day = P.day(), run = q.run('INSERT INTO runs(day,win,started) VALUES(?,?,?)', day, win, new Date().toISOString()).lastInsertRowid
  const out = { published: [], errors: [], blocked: false }, perAcc = {}
  const netFp = q.all("SELECT fp FROM articles WHERE status='published' ORDER BY id DESC LIMIT 300").map((r) => JSON.parse(r.fp || '[]'))
  for (const site of P.sitesFor(win, day)) {
    if (out.published.length >= max) break
    const acc = q.get('SELECT * FROM accounts WHERE id=?', site.account_id)
    if (acc.status !== 'active') continue
    if (P.writesLastHour(acc.id) >= P.MAX_PER_ACCOUNT_HOUR) { out.errors.push(`${site.slug}: hourly cap reached for ${acc.label}`); continue }
    try {
      const used = new Set(q.all('SELECT topic FROM articles WHERE site_id=? AND topic IS NOT NULL', site.id).map((r) => r.topic))
      const sameFp = q.all("SELECT fp FROM articles WHERE site_id=? AND status='published' ORDER BY id DESC LIMIT 60", site.id).map((r) => JSON.parse(r.fp || '[]'))
      let art = null, entry = null, tries = 0
      while (!art && tries++ < 6) {                                              // a rejected draft burns its topic and we try the next one
        entry = P.pickTopic(G.plan(site.slug), used, P.mix(day), K.recentKw(site.id)); if (!entry) break
        used.add(entry.t)
        const net = NET.find((x) => x.id === site.slug) || { id: site.slug, name: site.title || site.slug, live_url: site.url, spec: site.trade || 'ac' }
        const kind2 = site.platform === 'landing' || (site.platform === 'wordpress' && Math.random() < 0.25) ? 'landing' : 'article'
        let raw = kind2 === 'landing' ? LD.build(site, entry, net.spec) : G.make(site.slug, entry); if (!raw) { out.errors.push(`${site.slug}: no generator`); break }
        const recent = q.all('SELECT recipe FROM articles WHERE recipe IS NOT NULL ORDER BY id DESC LIMIT 120').map((r) => r.recipe); let recipe = kind2 === 'landing' ? 'landing|' + site.slug : L.choose(recent, site.persona || PE.pick(site.id), site.slug + raw.title)
        let html = kind2 === 'landing' ? raw.html : L.compose(raw.html, net, raw.title, recipe, site.slug + raw.title)
        const ref = C.newRef(), line = LN.lineOf(site.id); html = C.inject(html, line, ref, raw.title, site.id)
        const recentTh = q.all("SELECT recipe FROM articles WHERE recipe LIKE '%|th:%' ORDER BY id DESC LIMIT 8").map((r) => (r.recipe.split('|th:')[1] || '').split('|')[0]), pk = TH.pick(site.slug + raw.title + Date.now(), recentTh)
        recipe += '|th:' + pk.theme.id + '|w:' + pk.widget
        const href = line ? C.waLink(line, ref, raw.title) : '', orderUrl = `https://oxygen11.com/app/?via=${site.slug}#/new`
        const page = site.platform === 'landing' ? TH.page(raw.title.replace(/</g, '&lt;'), html, pk, line, href, orderUrl) : null
        if (!page) html = TH.wrap(html, pk, line, href, orderUrl)
        const chk = D.check({ html }, { sameSite: sameFp, network: netFp, minWords: kind2 === 'landing' ? +process.env.MIN_LANDING || 350 : D.MIN_WORDS })
        q.run('INSERT INTO articles(site_id,title,labels,html,words,fp,topic,kind,city,persona,source,status,reject_reason,ref,recipe,kw,kind2) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', site.id, raw.title, K.labels(entry, site), html, chk.words || 0, JSON.stringify(chk.fp || []), entry.t, entry.kind || '', entry.city || '', site.persona || '', 'commercial', chk.ok ? 'queued' : 'rejected', chk.ok ? null : chk.reason, ref, recipe, entry.kw || null, kind2)
        if (chk.ok) art = { ...raw, page, labels: K.labels(entry, site), html, fp: chk.fp, id: q.get('SELECT last_insert_rowid() id').id }
        else out.errors.push(`${site.slug}: rejected «${raw.title.slice(0, 40)}» — ${chk.reason}`)
      }
      if (!art) continue
      const r = await AD[site.platform].publish(site, acc, art)
      q.run("UPDATE articles SET status='published', url=?, remote_id=?, published_at=? WHERE id=?", r.url, r.remote_id, new Date().toISOString(), art.id)
      q.run('UPDATE sites SET last_post_at=? WHERE id=?', new Date().toISOString(), site.id); q.run('UPDATE accounts SET last_ok_at=? WHERE id=?', new Date().toISOString(), acc.id)
      q.run('INSERT INTO writes(account_id,site_id,kind,at,status) VALUES(?,?,?,?,?)', acc.id, site.id, 'post', Date.now(), 200)
      P.addMix(day, entry); netFp.unshift(art.fp); out.published.push({ site: site.slug, title: art.title, url: r.url, cool: !!entry.cool, mk: entry.city === 'mk' })
      await sleep(25000 + Math.random() * 35000)
    } catch (e) {
      if (e.needsLogin) { q.run("UPDATE accounts SET status='needs_login' WHERE id=?", acc.id); q.ev('error', 'needs login: ' + e.message, acc.id, site.id); out.errors.push(`${acc.label}: needs_login`); continue }
      if (e.status === 403 || e.status === 429) { q.run("UPDATE accounts SET status='limited' WHERE id=?", acc.id); q.run('INSERT INTO writes(account_id,site_id,kind,at,status) VALUES(?,?,?,?,?)', acc.id, site.id, 'post', Date.now(), e.status); q.ev('error', 'rate limit ' + e.status, acc.id, site.id); out.blocked = true; out.errors.push(`${acc.label}: ${e.status} — account paused for today`); continue }
      out.errors.push(`${site.slug}: ${e.message}`); q.ev('error', e.message, acc.id, site.id)
    }
  }
  q.run('UPDATE runs SET ended=?, published=?, cooling=?, makkah=?, errors=?, blocked=? WHERE id=?', new Date().toISOString(), out.published.length, out.published.filter((p) => p.cool).length, out.published.filter((p) => p.mk).length, JSON.stringify(out.errors), out.blocked ? 1 : 0, run)
  return out
}
// every morning: accounts held back as 'limited' yesterday get another chance
exports.dailyReset = () => q.run("UPDATE accounts SET status='active' WHERE status='limited'")
