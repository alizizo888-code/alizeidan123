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
import { createChatwoot } from './chatwoot.js'
import * as OR from './outreach.js'
import { createRevoker } from './revoke.js'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const PORT = Number(process.env.PORT || 8787)
const DATA = path.resolve(process.env.DATA_DIR || './data')
const OLD = process.env.OLD_DIR || ''                 // the old gateway's data: its linked numbers are carried over once
const PAIR_TOKEN = process.env.PAIR_TOKEN || ''
const DAILY_CAP = Number(process.env.SEND_DAILY_CAP || 300)
// «مركز الواتساب» الجديد على الموقع (أرقام Evolution): رسايل عملائه بتيجي هنا للدماغ، والرد بيرجع يتبعت من نفس الرقم هناك.
const CENTER_URL = (process.env.CENTER_URL || '').replace(/\/$/, '')
const CENTER_KEY = process.env.CENTER_KEY || ''
const isCenter = (line) => /^mwc:\d+$/.test(String(line || ''))
async function centerSend(row) {
  const account = isCenter(row.line) ? Number(String(row.line).slice(4)) : 0   // 0 = رقم المدير في المركز
  const r = await fetch(CENTER_URL + '/ext/send', { method: 'POST', headers: { 'content-type': 'application/json', 'x-mwc-key': CENTER_KEY },
    body: JSON.stringify({ account, phone: row.phone, text: row.text, image: row.media || '', force: ['room', 'tech', 'agent'].includes(row.purpose) ? 1 : 0 }), signal: AbortSignal.timeout(45000) })
  const j = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error('center ' + r.status + ' ' + (j.message || ''))
  if (j.skipped) return { skipped: j.skipped }
  if (j.ok === false) throw new Error(j.error || 'center send failed')
  return { waId: 'mwc' + (j.id || '') }
}
// What we just sent, for ten minutes: when the center sends a team alert from one of our numbers to another of ours
// (the room), the receiving number reports it back as an incoming message — it must not be read as a customer writing
// (the owner's test phone is one of our numbers and is treated as a customer, live test 2026-11-23).
const recentOut = new Map()
const outKey = (t) => String(t || '').replace(/\s+/g, ' ').trim().slice(0, 300)
function noteOut(text) {
  const now = Date.now(); recentOut.set(outKey(text), now)
  if (recentOut.size > 500) for (const [k, at] of recentOut) if (now - at > 600000) recentOut.delete(k)
}
const isEcho = (text) => { const at = recentOut.get(outKey(text)); return !!at && Date.now() - at < 600000 }
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
      if (v.status === 'open' && l) cw.link([store.line(id)]).catch(() => {})
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
  // A line linked before starts by itself; one only prepared waits for «ربط بالـQR» on the panel (no QR loop nobody scans).
  for (const l of store.lines()) { if (l.phone || fs.existsSync(path.join(DATA, 'auth', l.id))) lines.start(l.id).catch((e) => log('error', 'start ' + l.id + ': ' + e.message)) }
}

/* ------------------------------------------------------------------ sending: one queue, paced per line */

function pickLine(o) {
  const open = store.lines().filter((l) => lines?.isOpen(l.id))
  return OR.pickLine(o, open, { ...DEFAULTS, ...(store.get('settings', {}) || {}) })
}
const outLast = new Map()   // line id → {at, gap}: the pause between two campaign messages of one line

function send(phone, text, o = {}) {
  if (!phone || !String(text || '').trim()) return 0
  const id = store.queue({ line: o.line || '', phone: intl(phone), text: String(text).slice(0, 4000), purpose: o.purpose || '', dedup: o.dedup ? String(o.dedup).slice(0, 120) : null, media: /^https:\/\//.test(o.image || '') ? o.image : '' })
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
      let lineId = isCenter(row.line) ? row.line : pickLine({ line: row.line, phone: row.phone, purpose: row.purpose, brand: meta.get(row.id)?.brand })
      // مفيش خط هنا متصل (الأرقام اتنقلت للمركز): رسايل الفريق والعملاء بتطلع من رقم المدير في المركز
      if (!lineId && CENTER_URL && CENTER_KEY && row.purpose !== 'outreach') lineId = 'mwc:0'
      if (isCenter(lineId)) {
        if (!CENTER_URL || !CENTER_KEY || busy.has(lineId)) continue
        busy.add(lineId)
        noteOut(row.text)
        centerSend({ ...row, line: lineId }).then((r) => {
          store.outDone(row.id, r.skipped ? 'skipped' : 'sent', r.skipped || ''); meta.delete(row.id)
          if (r.skipped) return
          const pk = pkey(row.phone)
          store.addMsg({ pk, line: lineId, dir: 'out', text: row.text, by: row.purpose || 'bot', status: 'sent', waId: r.waId })
          revoker.remember(row, lineId, r.waId)
          const c = store.chat(pk)
          store.touchChat(pk, { phone: row.phone, line: c?.line || lineId, lastAt: Date.now(), lastText: row.text.slice(0, 200), kind: c?.kind || (['room', 'tech', 'agent'].includes(row.purpose) ? 'team' : 'customer') })
        }, (e) => {
          if (row.tries >= 4) store.outDone(row.id, 'failed', e.message); else store.outRetry(row.id, e.message)
          log('warn', 'center send ' + row.phone + ': ' + e.message)
        }).finally(() => { busy.delete(lineId); setTimeout(pump, 300) })
        continue
      }
      if (!lineId || busy.has(lineId)) continue
      if (row.purpose === 'outreach') { if (!OR.due(lineId, outLast)) continue; outLast.set(lineId, { at: Date.now(), gap: OR.gapSec() }) }
      // The daily cap protects the line from a ban on mass sending to customers; the room and the technicians are ours.
      if (!['room', 'tech', 'agent'].includes(row.purpose) && store.sentToday(lineId) >= DAILY_CAP) {
        store.outRetry(row.id, 'الحد اليومي للخط')
        if (store.get('capWarned') !== new Date().toDateString()) { store.set('capWarned', new Date().toDateString()); log('warn', 'الخط ' + lineId + ' وصل الحد اليومي (' + DAILY_CAP + ' رسالة) — باقي رسايل العملاء مستنية لبكره') }
        continue
      }
      busy.add(lineId)
      noteOut(row.text)
      lines.sendText(lineId, row.phone, row.text, row.media || '').then((r) => {
        store.outDone(row.id, 'sent'); meta.delete(row.id)
        const pk = pkey(row.phone)
        store.addMsg({ pk, line: lineId, dir: 'out', text: row.text, by: row.purpose || 'bot', status: 'sent', waId: r.waId })
        revoker.remember(row, lineId, r.waId)
        const c = store.chat(pk)
        store.touchChat(pk, { phone: row.phone, line: c?.line || lineId, lastAt: Date.now(), lastText: row.text.slice(0, 200), kind: c?.kind || (['room', 'tech', 'agent'].includes(row.purpose) ? 'team' : 'customer') })
        cw.mirror(pk, lineId, 'out', row.text, row.purpose)
      }, (e) => {
        if (row.tries >= 4) store.outDone(row.id, 'failed', e.message); else store.outRetry(row.id, e.message)
        log('warn', 'send ' + row.phone + ': ' + e.message)
      }).finally(() => { busy.delete(lineId); setTimeout(pump, 300) })
    }
  } finally { pumping = false }
}
setInterval(pump, 2000)

// The payment-methods link (and any other message sent to expire) is deleted for everyone before WhatsApp's limit.
const revoker = createRevoker({ store })
async function revokeDue() {
  for (const x of revoker.due()) {
    try {
      if (isCenter(x.line)) {
        if (!CENTER_URL || !CENTER_KEY) continue
        const r = await fetch(CENTER_URL + '/ext/revoke', { method: 'POST', headers: { 'content-type': 'application/json', 'x-mwc-key': CENTER_KEY }, body: JSON.stringify({ id: Number(String(x.waId).replace(/^mwc/, '')) }), signal: AbortSignal.timeout(30000) })
        const j = await r.json().catch(() => ({}))
        if (!j.ok) log('warn', 'revoke ' + x.phone + ': ' + (j.error || r.status))
      } else if (lines?.revoke) {
        await lines.revoke(x.line, x.phone, x.waId)
      }
      log('chat', 'اتمسحت رسالة عند الجميع (' + x.phone + ')')
    } catch (e) { log('warn', 'revoke ' + x.phone + ': ' + (e?.message || e)) }
    revoker.drop(x)   // once: a second try after WhatsApp's limit would fail anyway
  }
}
setInterval(() => revokeDue().catch(() => {}), 300000).unref?.()

const PUBLIC_URL = process.env.PUBLIC_URL || 'https://vps.oxygen11.com'
const cw = createChatwoot({ store, wp, log, hookBase: PUBLIC_URL })
const brain = createBrain({ store, wp, send, log, mirror: (...a) => cw.mirror(...a) })
// The team's app (Chatwoot): linked by itself once the site has its credentials and a line is connected.
setTimeout(() => { const open = store.lines().filter((l) => lines?.isOpen(l.id)); if (open.length) cw.link(open).catch(() => {}) }, 20000).unref?.()

// The site's own settings the hub follows (the test phone), refreshed every ten minutes.
const syncConfig = async () => { if (!store.get('pair')?.secret) return; try { const c = await wp('config', {}); if (c) store.set('wpConfig', c) } catch (e) { log('warn', 'config: ' + e.message) } try { const a = await wp('ai', {}); if (a) store.set('wpAi', a) } catch {} }
setTimeout(syncConfig, 3000); setInterval(syncConfig, 600000).unref?.()

// Each connected line takes the job the desk gave its number (Mutqan_Lines): manager → team, outreach, sales → customers.
function syncRoles() {
  const our = (store.get('wpConfig', {}) || {}).ourNumbers || {}
  for (const l of store.lines()) {
    if (!l.phone) continue
    const r = our[pkey(l.phone)]
    const want = OR.hubRole(r ? r.role : '', l.role, r ? r.status : 'active')
    if (want !== l.role) { store.putLine({ ...l, role: want }); log('line', (l.label || l.id) + ': دوره بقى «' + want + '» من لوحة الأرقام') }
  }
}
setInterval(syncRoles, 60000).unref?.()
// Every number on the desk's list gets its line ready (named, with its role), waiting for the QR scan from the panel.
function prepareLines() {
  const our = (store.get('wpConfig', {}) || {}).ourNumbers || {}
  for (const l of OR.toPrepare(our, store.lines())) { store.putLine(l); log('line', 'اتجهز للربط: ' + l.label + ' — امسح الكود من اللوحة') }
}
setInterval(prepareLines, 120000).unref?.(); setTimeout(prepareLines, 15000).unref?.()

// A number the desk moved to the new WhatsApp Center (Evolution): the hub logs it out and forgets it — otherwise
// the same phone carries two live sessions at once, which is what leaves the customer's WhatsApp stuck on
// «في انتظار هذه الرسالة». A QR-only placeholder (never linked) is just dropped, nothing to log out.
async function retireCenterLines() {
  if (!lines) return
  const our = (store.get('wpConfig', {}) || {}).ourNumbers || {}
  for (const l of OR.toRetire(our, store.lines())) {
    const had = l.phone
    if (had) await lines.stop(l.id, true).catch((e) => log('warn', 'retire ' + l.id + ': ' + e.message))
    store.dropLine(l.id)
    log('line', (l.label || l.id) + ': اتفصل من مركز وا-هب ونُقل لمركز الواتساب الجديد' + (had ? ' (كان متصل بـ' + had + ')' : ' (كان لسه مجرد كود QR مستني)'))
  }
}
setInterval(() => retireCenterLines().catch((e) => log('error', 'retire: ' + e.message)), 120000).unref?.()
setTimeout(() => retireCenterLines().catch((e) => log('error', 'retire: ' + e.message)), 20000).unref?.()

// The campaigns: every five minutes the website says who each connected outreach line invites next (it keeps the plan,
// the hours and each line's daily ceiling); the queue sends them one by one, a random minute or more apart per line.
async function outreachTick() {
  if (!store.get('pair')?.secret || !lines) return
  const ol = store.lines().filter((l) => l.role === 'outreach' && l.phone && lines.isOpen(l.id))
  if (!ol.length) return
  if (store.nextOut(500).some((r) => r.purpose === 'outreach')) return   // the last batch is still going out
  let r = null
  try { r = await wp('outreach_batch', { lines: ol.map((l) => ({ id: l.id, phone: l.phone, sentToday: store.sentToday(l.id) })) }) } catch (e) { log('warn', 'outreach: ' + e.message); return }
  for (const it of (r && r.items) || []) { if (it.phone && it.text && it.line) send(it.phone, it.text, { line: it.line, purpose: 'outreach', dedup: 'out-' + it.id + '-' + new Date().toISOString().slice(0, 10) }) }
  if (r?.items?.length) log('outreach', 'دفعة حملات: ' + r.items.length + ' رسالة')
}
setInterval(() => outreachTick().catch(() => {}), 300000).unref?.()

setInterval(() => brain.daily().catch(() => {}), 60000).unref?.()

// A report the website could not deliver (the hub was restarting) waits there; collected every half minute.
setInterval(async () => {
  if (!store.get('pair')?.secret) return
  try { const r = await wp('events', {}); for (const ev of r?.events || []) await brain.onEvent(ev) } catch (e) { log('warn', 'events: ' + e.message) }
}, 30000).unref?.()

/* ------------------------------------------------------------------ GitHub Actions OIDC */

const OIDC_ISS = 'https://token.actions.githubusercontent.com'
const OIDC_REPO = process.env.OIDC_REPO || 'alizizo888-code/mutaqin-oxygen-app'
let jwks = { at: 0, keys: [] }
async function githubOidc(tok) {
  try {
    const [h, p, sig] = String(tok).split('.')
    if (!h || !p || !sig) return null
    const head = JSON.parse(Buffer.from(h, 'base64url').toString()); const c = JSON.parse(Buffer.from(p, 'base64url').toString())
    if (head.alg !== 'RS256') return null
    if (Date.now() - jwks.at > 3600000 || !jwks.keys.some((k) => k.kid === head.kid)) {
      const r = await fetch(OIDC_ISS + '/.well-known/jwks', { signal: AbortSignal.timeout(10000) }); jwks = { at: Date.now(), keys: (await r.json()).keys || [] }
    }
    const jwk = jwks.keys.find((k) => k.kid === head.kid); if (!jwk) return null
    const ok = crypto.verify('RSA-SHA256', Buffer.from(h + '.' + p), crypto.createPublicKey({ key: jwk, format: 'jwk' }), Buffer.from(sig, 'base64url'))
    const now = Date.now() / 1000
    if (!ok || c.iss !== OIDC_ISS || c.aud !== 'mutqan-hub' || c.repository !== OIDC_REPO || c.ref !== 'refs/heads/mutqan' || !(c.exp > now) || c.nbf > now + 60) return null
    return c
  } catch { return null }
}

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
  'GET /api/state': async () => {
    const p = store.get('pair')
    let chatwoot = null; try { chatwoot = await cw.status() } catch {}
    return { chatwoot, lines: lineViews(), settings: { ...DEFAULTS, ...(store.get('settings', {}) || {}) }, paired: !!(p && p.secret), site: p?.url || '', waiting: store.nextOut(500).length, human: store.chats({ filter: 'human', limit: 500 }).length, unread: store.chats({ filter: 'unread', limit: 500 }).length }
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
  'GET /api/chatwoot': () => cw.status(),
  'POST /api/chatwoot/link': () => cw.link(store.lines().filter((l) => lines?.isOpen(l.id))),
  'POST /api/chatwoot/on': (b) => { cw.setOn(!!b.on); return cw.status() },
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
    // The site (signed with the pairing secret) reads recent customer conversations — the owner reviews the bot's work.
    if (route === 'POST /wp/chats') {
      const raw = await body(req)
      const p = store.get('pair')
      if (!p?.secret || !safeEq(req.headers['x-mq-hub'], hmac(p.secret, raw))) return json(res, 401, { error: 'bad signature' })
      const j = JSON.parse(raw || '{}')
      if (Math.abs(Date.now() / 1000 - Number(j.ts || 0)) > 600) return json(res, 401, { error: 'stale' })
      const want = String(j.phone || '').replace(/\D/g, '').slice(-9)
      const list = store.chats({ filter: '', limit: 60 }).filter((c) => (want || c.kind !== 'personal') && (!want || String(c.phone || c.pk || '').replace(/\D/g, '').endsWith(want)))
      const n = Math.max(1, Math.min(10, Number(j.limit || 3)))
      const out = list.slice(0, n).map((c) => ({ phone: c.phone || c.pk, name: c.name || '', lastAt: c.lastAt, lead: c.lead || '', msgs: store.msgs(c.pk, Math.min(120, Number(j.msgs || 60))).map((m) => ({ dir: m.dir, by: m.by || '', at: m.at, text: String(m.text || '').slice(0, 600) })) }))
      return json(res, 200, { chats: out })
    }
    // The owner checks one number from the site (signed like /wp/chats): how the hub sees it — the chat record, who it
    // thinks this is, the test phone it holds, and the last log lines that mention it. No secrets in the answer.
    if (route === 'POST /wp/diag') {
      const raw = await body(req)
      const p = store.get('pair')
      if (!p?.secret || !safeEq(req.headers['x-mq-hub'], hmac(p.secret, raw))) return json(res, 401, { error: 'bad signature' })
      const j = JSON.parse(raw || '{}')
      if (Math.abs(Date.now() / 1000 - Number(j.ts || 0)) > 600) return json(res, 401, { error: 'stale' })
      const pk = pkey(String(j.phone || ''))
      const c = pk ? store.chat(pk) : null
      const C = brain.cfg()
      let w = null; try { w = pk ? await brain.who(String(j.phone)) : null } catch (e) { w = { error: String(e?.message || e) } }
      const tail = pk.slice(-9)
      const logs = store.logs(400).filter((l) => !tail || JSON.stringify(l).includes(tail)).slice(0, 30)
      const { greet, ...chat } = c || {}
      return json(res, 200, { pk, chat: c ? chat : null, who: w, testPhone: C.testPhone || '', roomPhone: C.roomPhone || '', isOurs: !!(C.ourNumbers || {})[pk], ourNumbers: Object.keys(C.ourNumbers || {}), centerKey: !!CENTER_KEY, msgs: pk ? store.msgs(pk, 12) : [], logs, recent: store.logs(25) })
    }
    // The AI keys kept as GitHub secrets: a workflow of the owner's repository proves itself with GitHub's own signed
    // token (OIDC) — no password to keep anywhere — and the hub hands the keys to the website over the paired channel.
    if (route === 'POST /ai-keys') {
      const raw = await body(req)
      const claims = await githubOidc(String(req.headers.authorization || '').replace(/^Bearer\s+/i, ''))
      if (!claims) return json(res, 401, { error: 'not the owner\'s workflow' })
      const b = JSON.parse(raw || '{}')
      const r = await wp('ai_keys', { providers: b.providers || {} })
      store.set('wpAi', r); log('ai', 'مفاتيح الذكاء الاصطناعي وصلت من GitHub: ' + ((r && r.set) || []).join('، '))
      return json(res, 200, { set: r?.set || [], live: r?.live || [], claude: !!r?.claude })
    }
    // «مركز الواتساب» على الموقع: رسالة عميل (أو رد موظف) على رقم من أرقام المركز → الدماغ نفسه
    if (route === 'POST /center/in') {
      if (!CENTER_KEY || !safeEq(String(req.headers['x-mwc-key'] || ''), CENTER_KEY)) return json(res, 401, { error: 'bad key' })
      const b = JSON.parse(await body(req) || '{}')
      json(res, 200, { ok: true })
      const line = 'mwc:' + Number(b.account || 0)
      const pk = pkey(b.phone)
      if (!pk) return
      // one of our own numbers handing back what we sent it a moment ago (a team alert): not a message to answer
      if (!b.fromMe && isEcho(b.text) && (brain.cfg().ourNumbers || {})[pk]) { log('chat', 'رسالة من رقمنا لرقمنا (تنبيه للفريق) — اتجاهلت'); return }
      if (!b.fromMe) { const c0 = store.chat(pk); if (!c0 || c0.line !== line) store.touchChat(pk, { phone: intl(b.phone), line }) }
      // the center handed this chat to the AI («الذكاء يتولى»): good for the next half hour, renewed by every message
      if (!b.fromMe && b.takeover) store.touchChat(pk, { aiTake: Date.now() + 30 * 60000, kind: 'customer', botOff: 0 })
      if (!b.fromMe && b.takeover === false) { const c1 = store.chat(pk); if (c1 && c1.aiTake) store.touchChat(pk, { aiTake: 0 }) }
      brain.inbound({ line, phone: String(b.phone), text: String(b.text || ''), kind: String(b.kind || 'text'), name: String(b.name || ''), fromMe: !!b.fromMe, media: /^https:\/\//.test(String(b.media || '')) ? String(b.media) : '', mime: String(b.mime || '') })
        .catch((e) => log('error', 'center inbound: ' + (e?.message || e)))
      return
    }
    // Chatwoot calls here when an agent answers in the app.
    if (route === 'POST /cw/hook') {
      const e = JSON.parse(await body(req, 2 * 1024 * 1024) || '{}')
      const r = cw.hook(e, u.searchParams.get('k'), send)
      return json(res, r.status || 200, r)
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
  // turns held before a restart: answered once, a few seconds after start (the lines need a moment to come up)
  setTimeout(() => brain.resumeHeld().catch((e) => log('error', 'resume: ' + e.message)), 8000)
}
export { server, store, brain, send, pump }
