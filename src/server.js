// «مركز الواتساب» — the WhatsApp hub on the VPS: lines linked by QR, every chat in one place, auto-replies, the
// order conversation, the technicians' words and the room's commands. The websites only report and answer questions.
import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { fileURLToPath } from 'node:url'
import { openStore } from './store.js'
import { createWp, hmac, safeEq } from './wp.js'
import { createBrain, DEFAULTS } from './brain.js'
import { intl, pkey } from './text.js'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const PORT = Number(process.env.PORT || 8787)
const DATA = path.resolve(process.env.DATA_DIR || './data')
const OLD = process.env.OLD_DIR || ''                 // the old gateway's data: its linked numbers are carried over once
const PAIR_TOKEN = process.env.PAIR_TOKEN || ''
const DAILY_CAP = Number(process.env.SEND_DAILY_CAP || 300)
const EXPIRE_MS = 6 * 3600000                         // a message nobody could send within six hours is dropped, not sent late

const store = openStore(path.join(DATA, 'hub.db'))
const log = (kind, text) => { store.log(kind, text); if (kind === 'error') console.error(text) }
const wp = createWp({ getPair: () => store.get('pair') })

/* ------------------------------------------------------------------ lines */

let lines = null
async function initLines() {
  const { createLines } = await import('./wa.js')
  lines = createLines({
    dataDir: DATA,
    onStatus: (id, v) => {
      const l = store.line(id)
      if (l && v.phone && l.phone !== v.phone) store.putLine({ ...l, phone: v.phone })
      if (v.status === 'open' || v.status === 'logged_out') log('line', (l?.label || id) + ': ' + (v.status === 'open' ? 'متصل ' + v.phone : 'اتفصل من الموبايل'))
    },
    onMessage: (m) => brain.inbound(m).catch((e) => log('error', 'inbound: ' + (e?.message || e))),
    onContact: (line, phone, name) => brain.contact(phone, name),
  })
  // Carry the numbers the old gateway had already linked: nobody has to scan again.
  if (OLD && !store.lines().length) {
    try {
      const meta = JSON.parse(fs.readFileSync(path.join(OLD, 'sessions.json'), 'utf8'))
      for (const [id, m] of Object.entries(meta)) {
        const src = path.join(OLD, 'auth', id)
        if (!fs.existsSync(src)) continue
        fs.cpSync(src, path.join(DATA, 'auth', id), { recursive: true })
        store.putLine({ id, label: m.label || id, phone: m.phone || '', role: 'customers' })
        log('line', 'اتنقل من الوسيط القديم: ' + (m.label || id))
      }
      if (fs.existsSync(path.join(OLD, 'lid-map.json')) && !fs.existsSync(path.join(DATA, 'lid-map.json'))) fs.copyFileSync(path.join(OLD, 'lid-map.json'), path.join(DATA, 'lid-map.json'))
    } catch (e) { log('warn', 'import: ' + e.message) }
  }
  // RELINK=<token>: once per token, every line forgets its keys and shows a fresh QR (keys copied from another
  // gateway can end up out of step — the customer then sees «في انتظار هذه الرسالة» instead of the text).
  const relink = process.env.RELINK || ''
  if (relink && store.get('relinkDone') !== relink) {
    for (const l of store.lines()) { fs.rmSync(path.join(DATA, 'auth', l.id), { recursive: true, force: true }); store.putLine({ ...l, phone: '' }) }
    store.set('relinkDone', relink); log('line', 'كل الأرقام اتصفّرت عشان تتربط من جديد بالـQR')
  }
  for (const l of store.lines()) lines.start(l.id).catch((e) => log('error', 'start ' + l.id + ': ' + e.message))
}

/* ------------------------------------------------------------------ sending: one queue, paced per line */

function pickLine(o) {
  const open = store.lines().filter((l) => lines?.isOpen(l.id))
  if (!open.length) return null
  if (o.line && open.some((l) => l.id === o.line)) return o.line
  const set = { ...DEFAULTS, ...(store.get('settings', {}) || {}) }
  const notSelf = open.filter((l) => pkey(l.phone) !== pkey(o.phone))
  const pool = notSelf.length ? notSelf : open
  if (o.brand && set.brandLines && set.brandLines[o.brand] && pool.some((l) => l.id === set.brandLines[o.brand])) return set.brandLines[o.brand]
  const want = ['room', 'tech', 'agent'].includes(o.purpose) ? 'team' : 'customers'
  return (pool.find((l) => l.role === want) || pool.find((l) => l.role === 'customers') || pool[0]).id
}

function send(phone, text, o = {}) {
  if (!phone || !String(text || '').trim()) return 0
  const id = store.queue({ line: o.line || '', phone: intl(phone), text: String(text).slice(0, 4000), purpose: o.purpose || '', dedup: o.dedup ? String(o.dedup).slice(0, 120) : null })
  if (id) { meta.set(id, { brand: o.brand || '' }); setImmediate(pump) }
  return id
}
const meta = new Map()
const busy = new Set()
let pumping = false
async function pump() {
  if (pumping || !lines) return
  pumping = true
  try {
    for (const row of store.nextOut(30)) {
      if (Date.now() - row.at > EXPIRE_MS) { store.outDone(row.id, 'expired', 'فات عليها أكتر من 6 ساعات'); continue }
      const lineId = pickLine({ line: row.line, phone: row.phone, purpose: row.purpose, brand: meta.get(row.id)?.brand })
      if (!lineId || busy.has(lineId)) continue
      if (store.sentToday(lineId) >= DAILY_CAP) { store.outRetry(row.id, 'الحد اليومي للخط'); continue }
      busy.add(lineId)
      lines.sendText(lineId, row.phone, row.text).then((r) => {
        store.outDone(row.id, 'sent'); meta.delete(row.id)
        const pk = pkey(row.phone)
        store.addMsg({ pk, line: lineId, dir: 'out', text: row.text, by: row.purpose || 'bot', status: 'sent', waId: r.waId })
        const c = store.chat(pk)
        store.touchChat(pk, { phone: row.phone, line: c?.line || lineId, lastAt: Date.now(), lastText: row.text.slice(0, 200), kind: c?.kind || (['room', 'tech', 'agent'].includes(row.purpose) ? 'team' : 'customer') })
      }, (e) => {
        if (row.tries >= 4) store.outDone(row.id, 'failed', e.message); else store.outRetry(row.id, e.message)
        log('warn', 'send ' + row.phone + ': ' + e.message)
      }).finally(() => { busy.delete(lineId); setTimeout(pump, 300) })
    }
  } finally { pumping = false }
}
setInterval(pump, 2000)

const brain = createBrain({ store, wp, send, log })

// The site's own settings the hub follows (the test phone), refreshed every ten minutes.
const syncConfig = async () => { if (!store.get('pair')?.secret) return; try { const c = await wp('config', {}); if (c) store.set('wpConfig', c) } catch (e) { log('warn', 'config: ' + e.message) } try { const a = await wp('ai', {}); if (a) store.set('wpAi', a) } catch {} }
setTimeout(syncConfig, 3000); setInterval(syncConfig, 600000).unref?.()

setInterval(() => brain.daily().catch(() => {}), 60000).unref?.()

// A report the website could not deliver (the hub was restarting) waits there; collected every half minute.
setInterval(async () => {
  if (!store.get('pair')?.secret) return
  try { const r = await wp('events', {}); for (const ev of r?.events || []) await brain.onEvent(ev) } catch (e) { log('warn', 'events: ' + e.message) }
}, 30000).unref?.()

/* ------------------------------------------------------------------ the panel's door */

// RESET_PANEL=<token>: once per token, the panel's password is cleared and the next visit chooses a new one.
if (process.env.RESET_PANEL && store.get('resetDone') !== process.env.RESET_PANEL) { store.set('pw', null); store.set('resetDone', process.env.RESET_PANEL); store.log('panel', 'كلمة سر اللوحة اتصفّرت') }
const sessKey = (() => { let k = store.get('sessKey'); if (!k) { k = crypto.randomBytes(32).toString('hex'); store.set('sessKey', k) } return k })()
const cookieOf = (req) => Object.fromEntries(String(req.headers.cookie || '').split(';').map((c) => c.trim().split('=')).filter((p) => p.length === 2))
function signedIn(req) {
  const v = decodeURIComponent(cookieOf(req).mqhub || ''); const [exp, sig] = v.split('.')
  return !!exp && Number(exp) > Date.now() && safeEq(sig, hmac(sessKey, 'u:' + exp))
}
const issue = () => { const exp = Date.now() + 30 * 86400000; return 'mqhub=' + encodeURIComponent(exp + '.' + hmac(sessKey, 'u:' + exp)) + '; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=' + 30 * 86400 }
const hashPw = (pw, salt = crypto.randomBytes(16).toString('hex')) => salt + ':' + crypto.scryptSync(pw, salt, 32).toString('hex')
const checkPw = (pw, h) => { const [salt] = String(h || '').split(':'); return !!salt && safeEq(hashPw(pw, salt), h) }
const tries = new Map()

function body(req, max = 256 * 1024) {
  return new Promise((resolve, reject) => {
    let n = 0; const chunks = []
    req.on('data', (c) => { n += c.length; if (n > max) { reject(new Error('too big')); req.destroy() } else chunks.push(c) })
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    req.on('error', reject)
  })
}
const json = (res, code, obj, headers = {}) => { res.writeHead(code, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', ...headers }); res.end(JSON.stringify(obj)) }

function lineViews() {
  return store.lines().map((l) => ({ ...l, ...(lines ? lines.view(l.id) : {}), label: l.label, role: l.role, brand: l.brand, sentToday: store.sentToday(l.id), cap: DAILY_CAP }))
}

const api = {
  'GET /api/state': () => {
    const p = store.get('pair')
    return { lines: lineViews(), settings: { ...DEFAULTS, ...(store.get('settings', {}) || {}) }, paired: !!(p && p.secret), site: p?.url || '', waiting: store.nextOut(500).length, human: store.chats({ filter: 'human', limit: 500 }).length, unread: store.chats({ filter: 'unread', limit: 500 }).length }
  },
  'GET /api/chats': (q) => ({ chats: store.chats({ filter: q.get('filter') || '', limit: 200 }) }),
  'GET /api/chat': (q) => { const pk = String(q.get('pk') || ''); store.touchChat(pk, { unread: 0 }); return { chat: store.chat(pk), msgs: store.msgs(pk, 120) } },
  'POST /api/send': (b) => {
    const c = b.pk ? store.chat(String(b.pk)) : null
    const phone = intl(c?.phone || b.phone || '')
    if (phone.length < 9 || !String(b.text || '').trim()) throw new Error('اكتب الرقم والرسالة')
    send(phone, String(b.text), { line: b.line || c?.line || '', purpose: 'agent' })
    store.touchChat(pkey(phone), { phone, humanAt: Date.now(), needsHuman: 0, unread: 0 })
    return { ok: true }
  },
  'POST /api/chat': (b) => {
    const f = {}
    if ('bot' in b) f.botOff = b.bot ? 0 : Date.now() + 365 * 86400000
    if ('agent' in b) f.agent = String(b.agent || '')
    if ('done' in b) f.needsHuman = b.done ? 0 : 1
    if ('personal' in b) f.kind = b.personal ? 'personal' : 'customer'
    return { chat: store.touchChat(String(b.pk), f) }
  },
  'POST /api/lines/add': async (b) => {
    const id = 'l' + Date.now().toString(36)
    store.putLine({ id, label: String(b.label || 'رقم جديد').slice(0, 60), role: b.role === 'team' ? 'team' : 'customers', brand: String(b.brand || '') })
    await lines.start(id)
    return { id }
  },
  'POST /api/lines/update': (b) => { const l = store.line(String(b.id)); if (!l) throw new Error('مش موجود'); store.putLine({ ...l, label: b.label ?? l.label, role: b.role ?? l.role, brand: b.brand ?? l.brand }); return { ok: true } },
  'POST /api/lines/restart': async (b) => { await lines.start(String(b.id)); return { ok: true } },
  'POST /api/lines/remove': async (b) => { await lines.stop(String(b.id), true); store.dropLine(String(b.id)); return { ok: true } },
  'POST /api/settings': (b) => {
    const cur = store.get('settings', {}) || {}
    const keep = ['roomPhone', 'testPhone', 'agents', 'notifyAgents', 'autoReply', 'siteName', 'hoursFrom', 'hoursTo', 'welcome', 'welcomeBack', 'afterHours', 'prices', 'brandLines', 'emergencyPhone', 'humanFirstSec', 'ignoreContacts', 'dailyReport']
    for (const k of keep) if (k in b) cur[k] = b[k]
    if (cur.roomPhone) cur.roomPhone = intl(cur.roomPhone)
    if (cur.testPhone) cur.testPhone = intl(cur.testPhone)
    if (cur.emergencyPhone) cur.emergencyPhone = String(cur.emergencyPhone).replace(/[^\d+]/g, '')
    if (Array.isArray(cur.agents)) cur.agents = cur.agents.filter((a) => a && a.name).map((a) => ({ name: String(a.name).slice(0, 40), phone: intl(a.phone || '') }))
    store.set('settings', cur)
    return { settings: { ...DEFAULTS, ...cur } }
  },
  'GET /api/log': () => ({ log: store.logs(200), outbox: store.outbox(100) }),
  'POST /api/password': (b) => { if (String(b.password || '').length < 6) throw new Error('كلمة السر 6 حروف على الأقل'); store.set('pw', hashPw(String(b.password))); return { ok: true } },
}

const server = http.createServer(async (req, res) => {
  try {
    const u = new URL(req.url, 'http://x')
    const route = req.method + ' ' + u.pathname
    if (route === 'GET /health') { const ai = store.get('wpAi') || {}; return json(res, 200, { ok: true, lines: lineViews().map((l) => ({ label: l.label, status: l.status })), ai: { live: ai.live || [], claude: !!ai.claude } }) }
    if (route === 'GET /' || route === 'GET /index.html') {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store', 'x-frame-options': 'DENY', 'referrer-policy': 'no-referrer' })
      return res.end(fs.readFileSync(path.join(HERE, 'panel.html')))
    }
    // The websites report here, signed with the secret they share with the hub.
    if (route === 'POST /wp/events') {
      const raw = await body(req, 1024 * 1024)
      const p = store.get('pair')
      if (!p?.secret || !safeEq(req.headers['x-mq-hub'], hmac(p.secret, raw))) return json(res, 401, { error: 'bad signature' })
      const j = JSON.parse(raw || '{}')
      if (Math.abs(Date.now() / 1000 - Number(j.ts || 0)) > 600) return json(res, 401, { error: 'stale' })
      json(res, 200, { ok: true, n: (j.events || []).length })
      for (const ev of j.events || []) await brain.onEvent(ev)
      return
    }
    // Pairing with the website: once, with the token only the server's owner has. The secret then lives in both places.
    if (route === 'POST /pair') {
      const b = JSON.parse(await body(req) || '{}')
      if (!PAIR_TOKEN || !safeEq(String(b.pairToken || ''), PAIR_TOKEN)) return json(res, 401, { error: 'bad token' })
      if (!/^https:\/\//.test(String(b.url || '')) || String(b.secret || '').length < 32) return json(res, 400, { error: 'bad pair' })
      store.set('pair', { url: String(b.url), secret: String(b.secret), at: Date.now() })
      log('pair', 'اترابط مع ' + b.url); setTimeout(syncConfig, 2000)
      return json(res, 200, { ok: true })
    }
    if (route === 'POST /api/login') {
      const ip = String(req.headers['x-forwarded-for'] || req.socket.remoteAddress || '').split(',')[0]
      const t = tries.get(ip) || { n: 0, at: Date.now() }
      if (Date.now() - t.at > 900000) { t.n = 0; t.at = Date.now() }
      if (t.n >= 10) return json(res, 429, { error: 'محاولات كتير — استنى ربع ساعة' })
      const b = JSON.parse(await body(req) || '{}'); const pw = String(b.password || '')
      const h = store.get('pw')
      if (!h) { if (pw.length < 6) return json(res, 400, { error: 'اختار كلمة سر 6 حروف على الأقل' }); store.set('pw', hashPw(pw)); return json(res, 200, { ok: true, first: true }, { 'set-cookie': issue() }) }
      if (!checkPw(pw, h)) { t.n++; tries.set(ip, t); return json(res, 401, { error: 'كلمة السر غلط' }) }
      return json(res, 200, { ok: true }, { 'set-cookie': issue() })
    }
    if (route === 'GET /api/me') return json(res, 200, { in: signedIn(req), first: !store.get('pw') })
    if (route === 'POST /api/logout') return json(res, 200, { ok: true }, { 'set-cookie': 'mqhub=; Path=/; Max-Age=0' })
    const fn = api[route]
    if (!fn) return json(res, 404, { error: 'not found' })
    if (!signedIn(req)) return json(res, 401, { error: 'سجّل الدخول' })
    const b = req.method === 'POST' ? JSON.parse(await body(req) || '{}') : null
    return json(res, 200, await fn(b || u.searchParams, req))
  } catch (e) { json(res, 400, { error: e?.message || 'error' }) }
})

if (process.env.HUB_NO_LISTEN !== '1') {
  server.listen(PORT, () => console.log('mutqan wa-hub on :' + PORT))
  initLines().catch((e) => log('error', 'lines: ' + e.message))
}
export { server, store, brain, send, pump }
