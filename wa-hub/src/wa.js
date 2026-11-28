// The linked WhatsApp lines (Baileys). Linking is by QR only, from the hub's own page.
import fs from 'node:fs'
import path from 'node:path'
import pino from 'pino'
import QRCode from 'qrcode'
import makeWASocket, * as B from '@whiskeysockets/baileys'
const { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = B
// optional helper: used when this Baileys has it, otherwise the plain key store (a missing name must never stop the line)
const cachedKeys = (keys, log) => (typeof B.makeCacheableSignalKeyStore === 'function' ? B.makeCacheableSignalKeyStore(keys, log) : keys)
import { digits } from './text.js'

const jidToPhone = (jid) => digits(String(jid || '').split('@')[0].split(':')[0])
const isLid = (j) => /@lid$/.test(String(j || ''))
const isPn = (j) => /@s\.whatsapp\.net$/.test(String(j || ''))
const isUserJid = (j) => isLid(j) || isPn(j)

export function extract(msg) {
  let m = msg || {}
  if (m.ephemeralMessage) m = m.ephemeralMessage.message || {}
  if (m.viewOnceMessage) m = m.viewOnceMessage.message || {}
  if (m.documentWithCaptionMessage) m = m.documentWithCaptionMessage.message || {}
  if (m.conversation) return { text: m.conversation, kind: 'text' }
  if (m.extendedTextMessage) return { text: m.extendedTextMessage.text || '', kind: 'text' }
  if (m.imageMessage) return { text: m.imageMessage.caption || '', kind: 'image' }
  if (m.videoMessage) return { text: m.videoMessage.caption || '', kind: 'video' }
  if (m.audioMessage) return { text: '', kind: m.audioMessage.ptt ? 'voice' : 'audio' }
  if (m.documentMessage) return { text: m.documentMessage.fileName || '', kind: 'document' }
  if (m.stickerMessage) return { text: '', kind: 'sticker' }
  if (m.locationMessage || m.liveLocationMessage) { const l = m.locationMessage || m.liveLocationMessage; return { text: `https://maps.google.com/?q=${l.degreesLatitude},${l.degreesLongitude}`, kind: 'location' } }
  if (m.contactMessage) return { text: m.contactMessage.displayName || '', kind: 'contact' }
  if (m.reactionMessage) return { text: '', kind: 'reaction' }
  if (m.buttonsResponseMessage) return { text: m.buttonsResponseMessage.selectedDisplayText || '', kind: 'text' }
  if (m.listResponseMessage) return { text: m.listResponseMessage.title || '', kind: 'text' }
  return { text: '', kind: 'other' }
}

export function createLines({ dataDir, onMessage, onStatus, onContact, log = pino({ level: process.env.LOG_LEVEL || 'warn' }) }) {
  const AUTH = path.join(dataDir, 'auth')
  fs.mkdirSync(AUTH, { recursive: true })
  const LIDF = path.join(dataDir, 'lid-map.json')
  const lidMap = (() => { try { return JSON.parse(fs.readFileSync(LIDF, 'utf8')) } catch { return {} } })()
  let lidT = null
  const saveLid = () => { clearTimeout(lidT); lidT = setTimeout(() => { try { fs.writeFileSync(LIDF, JSON.stringify(lidMap)) } catch {} }, 1500) }
  const learn = (lid, pn) => { if (!isLid(lid) || !isPn(pn)) return; const l = jidToPhone(lid); const p = jidToPhone(pn); if (l && p && lidMap[l] !== p) { lidMap[l] = p; saveLid() } }
  async function realJid(sock, jid, key) {
    if (!isLid(jid)) return jid
    for (const a of [key?.remoteJidAlt, key?.senderPn, key?.participantAlt, key?.participantPn]) if (isPn(a)) { learn(jid, a); return a }
    const known = lidMap[jidToPhone(jid)]
    if (known) return known + '@s.whatsapp.net'
    try { const pn = await sock.signalRepository?.lidMapping?.getPNForLID?.(jid); const j = String(pn || '').replace(/:\d+@/, '@'); if (isPn(j)) { learn(jid, j); return j } } catch {}
    return jid
  }
  // Reply to the very address the customer wrote from (his LID chat or his number): answering a LID chat on the
  // phone-number address opens a second encryption session on his phone — one cause of «في انتظار هذه الرسالة».
  const LASTF = path.join(dataDir, 'last-jid.json')
  const lastJid = (() => { try { return JSON.parse(fs.readFileSync(LASTF, 'utf8')) } catch { return {} } })()
  let lastT = null
  const rememberJid = (phone, jid) => { const d = digits(phone); if (!d || !isUserJid(jid) || lastJid[d] === jid) return; lastJid[d] = jid; clearTimeout(lastT); lastT = setTimeout(() => { try { fs.writeFileSync(LASTF, JSON.stringify(lastJid)) } catch {} }, 1500) }
  const toJid = (to) => { const d = digits(to); if (lastJid[d]) return lastJid[d]; return lidMap['@' + d] ? d + '@lid' : `${d}@s.whatsapp.net` }

  // Every message we send is kept (per line, the last 2000, on disk) so that when his phone could not decrypt it and
  // asks for it again (a retry receipt), it is sent again instead of staying «في انتظار هذه الرسالة» for ever.
  const SENTF = (id) => path.join(dataDir, 'sent-' + id + '.json')
  const sentStore = new Map()   // line → Map(msgId → message)
  const sentOf = (id) => {
    if (!sentStore.has(id)) { let m = new Map(); try { m = new Map(Object.entries(JSON.parse(fs.readFileSync(SENTF(id), 'utf8')))) } catch {} sentStore.set(id, m) }
    return sentStore.get(id)
  }
  const sentT = new Map()
  const keepSent = (id, msgId, message) => {
    if (!msgId || !message) return
    const m = sentOf(id); m.set(msgId, message)
    while (m.size > 2000) m.delete(m.keys().next().value)
    clearTimeout(sentT.get(id)); sentT.set(id, setTimeout(() => { try { fs.writeFileSync(SENTF(id), JSON.stringify(Object.fromEntries(m))) } catch {} }, 2000))
  }
  // How many times a message was retried (Baileys' retry counter) — a tiny in-memory store.
  const retryCache = (() => { const m = new Map(); return { get: (k) => m.get(k), set: (k, v) => { m.set(k, v); if (m.size > 5000) m.delete(m.keys().next().value) }, del: (k) => m.delete(k), flushAll: () => m.clear() } })()

  const S = new Map()   // id → {sock, status, qr, phone, name, retry, sent:Set}
  const view = (id) => { const s = S.get(id) || {}; return { id, status: s.status || 'stopped', qr: s.status === 'qr' ? s.qr : null, phone: s.phone || '', name: s.name || '' } }

  async function start(id) {
    const old = S.get(id)
    if (old?.sock) { try { old.sock.end(undefined) } catch {} }
    const st = old || { retry: 0, sent: new Set() }
    st.status = 'connecting'; st.qr = null
    S.set(id, st)
    const { state, saveCreds } = await useMultiFileAuthState(path.join(AUTH, id))
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }))
    const sock = makeWASocket({
      version, logger: log, browser: Browsers.ubuntu('Chrome'), printQRInTerminal: false, markOnlineOnConnect: false, syncFullHistory: false,
      // a link in our text shows its card (picture + title) — the order page (2026-11-24); needs link-preview-js
      generateHighQualityLinkPreview: true,
      // cached signal keys: no half-written key files when many messages arrive together (a broken session = «waiting for this message»)
      auth: { creds: state.creds, keys: cachedKeys(state.keys, log) },
      msgRetryCounterCache: retryCache,
      maxMsgRetryCount: 5,
      // his phone asks for a message it could not open: give it back so it is encrypted and sent again
      getMessage: async (key) => sentOf(id).get(key?.id) || undefined,
    })
    st.sock = sock
    sock.ev.on('creds.update', saveCreds)
    sock.ev.on('connection.update', async (u) => {
      if (u.qr) { st.qr = await QRCode.toDataURL(u.qr, { margin: 1, width: 320 }); st.status = 'qr'; onStatus?.(id, view(id)) }
      if (u.connection === 'open') {
        st.status = 'open'; st.retry = 0; st.qr = null
        st.phone = jidToPhone(sock.user?.id); st.name = sock.user?.name || ''
        onStatus?.(id, view(id))
      }
      if (u.connection === 'close') {
        const code = u.lastDisconnect?.error?.output?.statusCode
        if (code === DisconnectReason.loggedOut) { st.status = 'logged_out'; fs.rmSync(path.join(AUTH, id), { recursive: true, force: true }); onStatus?.(id, view(id)); return }
        if (st.stopped) return
        st.status = 'closed'; onStatus?.(id, view(id))
        const wait = Math.min(60000, 2000 * 2 ** Math.min(st.retry++, 5))
        setTimeout(() => { if (S.get(id) === st && !st.stopped) start(id).catch((e) => log.error(e)) }, wait)
      }
    })
    const learnContacts = (list) => {
      for (const c of list || []) {
        if (isLid(c.lid) && isPn(c.id)) learn(c.lid, c.id)
        if (isLid(c.id) && isPn(c.phoneNumber || c.jid)) learn(c.id, c.phoneNumber || c.jid)
        // A name saved in the phone's own address book: someone the owner knows personally (or a colleague).
        if (c.name && onContact) { const pn = isPn(c.id) ? c.id : isPn(c.phoneNumber) ? c.phoneNumber : (lidMap[jidToPhone(c.id)] ? lidMap[jidToPhone(c.id)] + '@s.whatsapp.net' : ''); if (pn) onContact(id, jidToPhone(pn), String(c.name).slice(0, 60)) }
      }
    }
    sock.ev.on('contacts.upsert', learnContacts)
    sock.ev.on('contacts.update', learnContacts)
    sock.ev.on('lid-mapping.update', (u) => { for (const x of [].concat(u || [])) learn(x?.lid, x?.pn) })
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
      if (type !== 'notify') return
      for (const msg of messages) {
        try {
          const raw = msg.key?.remoteJid
          if (!isUserJid(raw) || !msg.message) continue
          if (st.sent.has(msg.key.id)) continue
          const x = extract(msg.message)
          if (x.kind === 'reaction' || x.kind === 'other') continue
          const jid = await realJid(sock, raw, msg.key)
          if (!msg.key.fromMe) rememberJid(jidToPhone(jid), raw)
          if (isLid(jid)) { const d = jidToPhone(jid); if (!lidMap['@' + d]) { lidMap['@' + d] = 1; saveLid() } }
          const me = st.phone || jidToPhone(sock.user?.id); const myLid = jidToPhone(sock.user?.lid || '')
          const self = !!msg.key.fromMe && (jidToPhone(jid) === me || (myLid && jidToPhone(raw) === myLid))
          await onMessage({ line: id, phone: self ? me : jidToPhone(jid), text: x.text, kind: x.kind, name: msg.pushName || '', fromMe: !!msg.key.fromMe, self })
        } catch (e) { log.warn(e) }
      }
    })
    return view(id)
  }

  async function stop(id, logout = false) {
    const st = S.get(id)
    if (st) { st.stopped = true; if (logout) { try { await st.sock?.logout() } catch {} } try { st.sock?.end(undefined) } catch {} }
    S.delete(id)
    if (logout) fs.rmSync(path.join(AUTH, id), { recursive: true, force: true })
  }

  async function sendText(id, to, text, media = '') {
    const st = S.get(id)
    if (!st || st.status !== 'open') throw new Error('الخط مش متصل')
    const jid = toJid(to)
    await st.sock.sendPresenceUpdate('composing', jid).catch(() => {})
    await new Promise((r) => setTimeout(r, 600 + Math.random() * 1400))
    // A photo (a part from the site's media library) goes with the text as its caption; if it cannot be fetched, the text alone.
    let sent = null
    if (media) { try { sent = await st.sock.sendMessage(jid, { image: { url: media }, caption: text }) } catch (e) { log.warn(e) } }
    if (!sent) sent = await st.sock.sendMessage(jid, { text })
    if (sent?.key?.id) { st.sent.add(sent.key.id); if (st.sent.size > 1000) st.sent.delete(st.sent.values().next().value); keepSent(id, sent.key.id, sent.message || { conversation: text }) }
    st.sock.sendPresenceUpdate('paused', jid).catch(() => {})
    return { waId: sent?.key?.id || '' }
  }

  /** «Delete for everyone» for a message this line sent (within WhatsApp's window). */
  async function revoke(id, to, waId) {
    const st = S.get(id)
    if (!st || st.status !== 'open' || !waId) throw new Error('الخط مش متصل')
    const jid = toJid(to)
    await st.sock.sendMessage(jid, { delete: { remoteJid: jid, fromMe: true, id: String(waId) } })
    return true
  }

  return { start, stop, sendText, revoke, view, isOpen: (id) => S.get(id)?.status === 'open', ids: () => [...S.keys()] }
}
