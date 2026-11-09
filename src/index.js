import fs from 'node:fs'
import path from 'node:path'
import express from 'express'
import pino from 'pino'
import QRCode from 'qrcode'
import makeWASocket, { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } from '@whiskeysockets/baileys'
import { sign, safeEq, cleanId, digits, jidToPhone, phoneToJid, isUserJid, extract, Pacer } from './core.js'

const PORT = Number(process.env.PORT || 8787)
const TOKEN = process.env.GATEWAY_TOKEN || ''          // WordPress -> gateway
const HOOK_URL = process.env.WEBHOOK_URL || ''          // gateway -> WordPress
const HOOK_SECRET = process.env.WEBHOOK_SECRET || ''
// Asking for work instead of waiting to be called. A gateway on a phone has no address anyone can
// reach, so WordPress cannot dial it; the phone collects what is waiting for it instead. Leave
// PULL_URL empty on a machine that can be dialled and nothing changes.
const PULL_URL = process.env.PULL_URL || (HOOK_URL ? HOOK_URL.replace(/\/wa-hook$/, '/wa-pull') : '')
const PULL_EVERY = Math.max(2000, Number(process.env.PULL_EVERY_MS || 5000))
const PULL_IDLE = Math.max(PULL_EVERY, Number(process.env.PULL_IDLE_MS || 20000))
const DATA = path.resolve(process.env.DATA_DIR || './data')
const DAILY_CAP = Number(process.env.SEND_DAILY_CAP || 200)
const MAX_SESSIONS = Number(process.env.MAX_SESSIONS || 20)
if (!TOKEN || TOKEN.length < 24) { console.error('GATEWAY_TOKEN (>=24 chars) is required'); process.exit(1) }
if (!HOOK_URL || !HOOK_SECRET) console.warn('WEBHOOK_URL / WEBHOOK_SECRET missing: events will not reach WordPress')

const log = pino({ level: process.env.LOG_LEVEL || 'warn' })
fs.mkdirSync(path.join(DATA, 'auth'), { recursive: true })
const META = path.join(DATA, 'sessions.json')
const meta = (() => { try { return JSON.parse(fs.readFileSync(META, 'utf8')) } catch { return {} } })()
const saveMeta = () => fs.writeFileSync(META, JSON.stringify(meta, null, 1))

/** id -> runtime state */
const S = new Map()

// ---- events -> WordPress (HMAC signed, retried) ----
const outbox = []
let flushing = false
async function emit(event) {
  outbox.push({ event, tries: 0 })
  if (outbox.length > 2000) outbox.shift()
  flush()
}
async function flush() {
  if (flushing || !HOOK_URL) return
  flushing = true
  while (outbox.length) {
    const item = outbox[0]
    const body = JSON.stringify(item.event)
    try {
      const r = await fetch(HOOK_URL, { method: 'POST', headers: { 'content-type': 'application/json', 'x-mq-signature': sign(HOOK_SECRET, body) }, body, signal: AbortSignal.timeout(15000) })
      if (!r.ok && r.status >= 500) throw new Error('hook ' + r.status)
      outbox.shift()
    } catch (e) {
      if (++item.tries >= 6) { outbox.shift(); continue }
      await new Promise((r) => setTimeout(r, 2000 * item.tries))
    }
  }
  flushing = false
}

// ---- sessions ----
function view(id) {
  const s = S.get(id); const m = meta[id] || {}
  return { id, label: m.label || id, mode: m.mode || 'qr', phone: s?.phone || m.phone || '', status: s?.status || 'stopped', qr: s?.status === 'qr' ? s.qr : null, pairingCode: s?.status === 'code' ? s.code : null, sentToday: s?.pacer.used() || 0, dailyCap: DAILY_CAP, name: s?.name || '' }
}

async function start(id) {
  const m = meta[id]
  if (!m) throw new Error('unknown session')
  const old = S.get(id)
  if (old?.sock) { try { old.sock.end(undefined) } catch {} }
  const st = old || { status: 'connecting', pacer: new Pacer({ dailyCap: DAILY_CAP }), queue: [], busy: false, retry: 0 }
  st.status = 'connecting'; st.qr = null; st.code = null
  S.set(id, st)
  const { state, saveCreds } = await useMultiFileAuthState(path.join(DATA, 'auth', id))
  const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }))
  const sock = makeWASocket({ version, auth: state, logger: log, browser: Browsers.ubuntu('Chrome'), printQRInTerminal: false, markOnlineOnConnect: false, syncFullHistory: false })
  st.sock = sock
  sock.ev.on('creds.update', saveCreds)
  sock.ev.on('connection.update', async (u) => {
    if (u.qr) {
      if (m.mode === 'code' && m.phone && !state.creds.registered) {
        if (!st.code) {
          try { st.code = (await sock.requestPairingCode(digits(m.phone))).replace(/(.{4})(.{4})/, '$1-$2'); st.status = 'code' } catch (e) { log.warn(e) }
        }
      } else { st.qr = await QRCode.toDataURL(u.qr, { margin: 1, width: 300 }); st.status = 'qr' }
      emit({ type: 'status', accountId: id, status: st.status })
    }
    if (u.connection === 'open') {
      st.status = 'open'; st.retry = 0; st.qr = null; st.code = null
      st.phone = jidToPhone(sock.user?.id); st.name = sock.user?.name || ''
      meta[id].phone = st.phone; saveMeta()
      emit({ type: 'status', accountId: id, status: 'open', phone: st.phone, name: st.name })
    }
    if (u.connection === 'close') {
      const code = u.lastDisconnect?.error?.output?.statusCode
      if (code === DisconnectReason.loggedOut) {
        st.status = 'logged_out'; fs.rmSync(path.join(DATA, 'auth', id), { recursive: true, force: true })
        emit({ type: 'status', accountId: id, status: 'logged_out' })
        return
      }
      st.status = 'closed'
      emit({ type: 'status', accountId: id, status: 'closed' })
      const wait = Math.min(60000, 2000 * 2 ** Math.min(st.retry++, 5))
      setTimeout(() => { if (S.get(id) === st && meta[id]) start(id).catch((e) => log.error(e)) }, wait)
    }
  })
  sock.ev.on('messages.upsert', ({ messages, type }) => {
    if (type !== 'notify' && type !== 'append') return
    for (const msg of messages) {
      const jid = msg.key?.remoteJid
      if (!isUserJid(jid) || !msg.message) continue
      const x = extract(msg.message)
      if (x.kind === 'reaction') continue
      emit({ type: 'message', accountId: id, msgId: msg.key.id, direction: msg.key.fromMe ? 'out' : 'in', phone: jidToPhone(jid), name: msg.pushName || '', text: x.text, kind: x.kind, ts: Number(msg.messageTimestamp) || Math.floor(Date.now() / 1000), fromPhone: !!msg.key.fromMe })
    }
  })
  return st
}

// Paced outbound queue: random delay, typing indicator, daily cap.
function enqueueSend(id, to, text) {
  const st = S.get(id)
  if (!st || st.status !== 'open') throw Object.assign(new Error('الرقم غير متصل'), { code: 409 })
  if (!st.pacer.canSend()) throw Object.assign(new Error('تم بلوغ الحد اليومي للإرسال من هذا الرقم (حماية من الحظر)'), { code: 429 })
  st.pacer.mark()
  return new Promise((resolve, reject) => { st.queue.push({ to, text, resolve, reject }); drain(id) })
}
async function drain(id) {
  const st = S.get(id)
  if (!st || st.busy) return
  st.busy = true
  while (st.queue.length) {
    const job = st.queue.shift()
    try {
      const jid = phoneToJid(job.to)
      await st.sock.presenceSubscribe(jid).catch(() => {})
      await st.sock.sendPresenceUpdate('composing', jid).catch(() => {})
      await new Promise((r) => setTimeout(r, st.pacer.delay()))
      const sent = await st.sock.sendMessage(jid, { text: job.text })
      await st.sock.sendPresenceUpdate('paused', jid).catch(() => {})
      job.resolve({ msgId: sent?.key?.id })
    } catch (e) { job.reject(e) }
  }
  st.busy = false
}

// ---- HTTP API (called only by the WordPress plugin) ----
const app = express()
app.disable('x-powered-by')
app.use(express.json({ limit: '100kb' }))
app.get('/health', (_q, r) => r.json({ ok: true }))
app.use((q, r, next) => {
  const t = String(q.headers.authorization || '').replace(/^Bearer\s+/i, '')
  if (!safeEq(t, TOKEN)) return r.status(401).json({ error: 'unauthorized' })
  next()
})
const wrap = (fn) => async (q, r) => { try { r.json(await fn(q)) } catch (e) { r.status(e.code && e.code < 600 ? e.code : 500).json({ error: e.message || 'error' }) } }

app.get('/sessions', wrap(() => ({ sessions: Object.keys(meta).map(view) })))
app.post('/sessions', wrap(async (q) => {
  const id = cleanId(q.body.id) || 'w' + Date.now().toString(36)
  if (!meta[id] && Object.keys(meta).length >= MAX_SESSIONS) throw Object.assign(new Error('بلغت الحد الأقصى للأرقام'), { code: 400 })
  const mode = q.body.mode === 'code' ? 'code' : 'qr'
  const phone = digits(q.body.phone)
  if (mode === 'code' && phone.length < 8) throw Object.assign(new Error('أدخل رقم الجوال بالصيغة الدولية'), { code: 400 })
  meta[id] = { label: String(q.body.label || id).slice(0, 60), mode, phone: mode === 'code' ? phone : (meta[id]?.phone || '') }
  saveMeta()
  await start(id)
  return view(id)
}))
app.post('/sessions/:id/restart', wrap(async (q) => { const id = cleanId(q.params.id); if (!meta[id]) throw Object.assign(new Error('not found'), { code: 404 }); await start(id); return view(id) }))
app.delete('/sessions/:id', wrap(async (q) => {
  const id = cleanId(q.params.id)
  const st = S.get(id)
  try { await st?.sock?.logout() } catch {}
  try { st?.sock?.end(undefined) } catch {}
  S.delete(id); delete meta[id]; saveMeta()
  fs.rmSync(path.join(DATA, 'auth', id), { recursive: true, force: true })
  return { ok: true }
}))
app.post('/sessions/:id/send', wrap(async (q) => {
  const id = cleanId(q.params.id)
  const to = digits(q.body.to); const text = String(q.body.text || '').slice(0, 4000)
  if (to.length < 8 || !text.trim()) throw Object.assign(new Error('بيانات ناقصة'), { code: 400 })
  return enqueueSend(id, to, text)
}))

// ---- collecting work from WordPress ----
//
// One request carries both halves: what was sent since last time, and a request for more. The pace
// backs off while there is nothing to do, because this runs on a phone battery; it quickens again
// the moment a batch comes back.
let pullStop = false
async function pullLoop() {
  if (!PULL_URL || !HOOK_SECRET) return
  let wait = PULL_EVERY
  let sent = []
  while (!pullStop) {
    let got = 0
    try {
      const body = JSON.stringify({ sent, limit: 10 })
      sent = []
      const r = await fetch(PULL_URL, { method: 'POST', headers: { 'content-type': 'application/json', 'x-mq-signature': sign(HOOK_SECRET, body) }, body, signal: AbortSignal.timeout(20000) })
      const j = r.ok ? await r.json().catch(() => null) : null
      const jobs = (j && j.data && Array.isArray(j.data.jobs)) ? j.data.jobs : []
      got = jobs.length
      for (const job of jobs) {
        // The number WordPress names, or the only one connected — a phone usually carries one.
        const id = (job.acct && S.has(job.acct)) ? job.acct : [...S.keys()].find((k) => S.get(k)?.status === 'open')
        if (!id) { sent.push({ id: job.id, ok: false }); continue }
        try { const res = await enqueueSend(id, digits(job.to), String(job.text || '')); sent.push({ id: job.id, ok: true, msgId: res?.msgId || '' }) }
        catch (e) { log.warn({ err: String(e) }, 'pull send'); sent.push({ id: job.id, ok: false }) }
      }
    } catch (e) {
      log.warn({ err: String(e) }, 'pull')
    }
    wait = got ? PULL_EVERY : Math.min(PULL_IDLE, Math.round(wait * 1.5))
    await new Promise((r) => setTimeout(r, wait))
  }
}

app.listen(PORT, () => console.log('mutqan wa-gateway on :' + PORT))
for (const id of Object.keys(meta)) start(id).catch((e) => log.error(e))
pullLoop().catch((e) => log.error(e))
