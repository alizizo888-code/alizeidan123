// Quality + uniqueness gate. Purpose: every published article must be long enough, say something real, and not be a near-copy of another one
// (same site or any site of the network). Nothing here hides content from anyone — it only blocks weak or repeated posts.
const crypto = require('crypto')
const text = (h) => h.replace(/<(script|style)[\s\S]*?<\/\1>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim()
const MIN_WORDS = +process.env.MIN_WORDS || 1200
const words = (h) => text(h).split(' ').filter(Boolean)
// Word count of the explanation only (the shop front is marked <!--oxf--> … «شرح المقال»)
function bodyWords(h) { const i = h.lastIndexOf('شرح المقال'); return words(i >= 0 ? h.slice(i) : h).length }
const K = 4                                                        // shingle = 4 consecutive words
function fingerprint(h) {                                         // 64 smallest 32-bit hashes of the shingles (min-hash sketch)
  const w = words(h), s = new Set()
  for (let i = 0; i + K <= w.length; i++) s.add(crypto.createHash('md5').update(w.slice(i, i + K).join(' ')).digest().readUInt32BE(0))
  return [...s].sort((a, b) => a - b).slice(0, 64)
}
function sim(a, b) { if (!a.length || !b.length) return 0; const A = new Set(a); let i = 0; for (const x of b) if (A.has(x)) i++; return i / (a.length + b.length - i) }
const BAD = [/\d+\s*(ريال|رس|sar)/i, /\d+\s*%/, /★|⭐|تقييم\s*\d/, /مرخص|ترخيص رقم|رخصة رقم/, /ضمان\s*(مدى الحياة|\d+\s*(سنة|سنوات))/, /خبرة\s*\d+\s*(سنة|عام)/, /\bأرخص\b|\bالأرخص\b/]
function check(art, { sameSite = [], network = [], minWords = MIN_WORDS, simSite = 0.4, simNet = 0.5 } = {}) {
  const w = bodyWords(art.html); if (w < minWords) return { ok: false, reason: `short: ${w} < ${minWords}` }
  const t = text(art.html); for (const re of BAD) if (re.test(t)) return { ok: false, reason: 'unverifiable claim: ' + re }
  const links = (art.html.match(/oxygen11\.com\/app\/\?via=[a-z0-9_-]+#\/new/g) || []).length; if (links < 3) return { ok: false, reason: 'order link < 3' }
  const fp = fingerprint(art.html)
  for (const o of sameSite) { const s = sim(fp, o); if (s > simSite) return { ok: false, reason: `too similar to this site's post (${s.toFixed(2)})` } }
  for (const o of network) { const s = sim(fp, o); if (s > simNet) return { ok: false, reason: `too similar to another site's post (${s.toFixed(2)})` } }
  return { ok: true, words: w, fp }
}
module.exports = { text, words, bodyWords, fingerprint, sim, check, MIN_WORDS }
