// Pure helpers (unit-tested without WhatsApp).
import crypto from 'node:crypto'

export const sign = (secret, body) => crypto.createHmac('sha256', secret).update(body).digest('hex')

export function safeEq(a, b) {
  const x = Buffer.from(String(a)); const y = Buffer.from(String(b))
  return x.length === y.length && crypto.timingSafeEqual(x, y)
}

export const cleanId = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 32)
export const digits = (s) => String(s || '').replace(/\D/g, '')
export const jidToPhone = (jid) => digits(String(jid || '').split('@')[0].split(':')[0])
export const phoneToJid = (p) => `${digits(p)}@s.whatsapp.net`
export const isUserJid = (jid) => /@(s\.whatsapp\.net|lid)$/.test(String(jid || ''))

// Text + media kind out of a Baileys message object.
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
  if (m.documentMessage) return { text: m.documentMessage.fileName || m.documentMessage.caption || '', kind: 'document' }
  if (m.stickerMessage) return { text: '', kind: 'sticker' }
  if (m.locationMessage) {
    const l = m.locationMessage
    return { text: `https://maps.google.com/?q=${l.degreesLatitude},${l.degreesLongitude}`, kind: 'location' }
  }
  if (m.contactMessage) return { text: m.contactMessage.displayName || '', kind: 'contact' }
  if (m.reactionMessage) return { text: '', kind: 'reaction' }
  return { text: '', kind: 'other' }
}

// Anti-ban pacing: random delay between sends + a daily cap per account.
export class Pacer {
  constructor({ minMs = 1500, maxMs = 5000, dailyCap = 200, now = () => Date.now(), rand = Math.random } = {}) {
    Object.assign(this, { minMs, maxMs, dailyCap, now, rand })
    this.day = ''; this.count = 0
  }
  _roll() {
    const d = new Date(this.now()).toISOString().slice(0, 10)
    if (d !== this.day) { this.day = d; this.count = 0 }
  }
  canSend() { this._roll(); return this.count < this.dailyCap }
  used() { this._roll(); return this.count }
  mark() { this._roll(); this.count++ }
  delay() { return Math.round(this.minMs + this.rand() * (this.maxMs - this.minMs)) }
}

// Where a customer came from. Order of trust:
//  1. A Click-to-WhatsApp ad (Meta/Instagram) stamps the first message with externalAdReply.
//  2. A tag the business put in the pre-filled text of its own link, e.g. wa.me/9665…?text=…%20%23ماب
//  3. The number itself: each SIM is advertised on one channel (SESSIONS=sim01:ماب:map,…).
export const SOURCES = {
  ads: ['#اعلان', '#إعلان', '#ممول', '#ad', '#ads', '#snap', '#سناب'],
  map: ['#ماب', '#خرائط', '#جوجل', '#map', '#maps', '#gmb'],
  tiktok: ['#تيك', '#تيكتوك', '#tiktok', '#tt'],
  haraj: ['#حراج', '#haraj'],
  site: ['#موقع', '#site', '#web'],
}
function ctxOf(msg) {
  let m = msg || {}
  if (m.ephemeralMessage) m = m.ephemeralMessage.message || {}
  for (const k of Object.keys(m)) { const c = m[k]?.contextInfo; if (c) return c }
  return null
}
export function detectSource(msg, text, fallback = '') {
  const c = ctxOf(msg)
  const ad = c?.externalAdReply
  if (ad && (ad.sourceType === 'ad' || ad.sourceId || ad.ctwaClid || c.conversionSource)) {
    const host = String(ad.sourceUrl || '').toLowerCase()
    const net = /instagram/.test(host) ? 'instagram' : /tiktok/.test(host) ? 'tiktok' : 'facebook'
    return { source: 'ads', adNet: net, adId: String(ad.sourceId || ''), adTitle: String(ad.title || ad.body || '').slice(0, 120) }
  }
  const t = String(text || '').toLowerCase()
  for (const [k, tags] of Object.entries(SOURCES)) if (tags.some((g) => t.includes(g.toLowerCase()))) return { source: k }
  return fallback ? { source: fallback } : {}
}

// SESSIONS="sim01:حراج:haraj, sim02:ماب:map:9665xxxxxxxx" → [{id,label,source,phone}]
// A phone in the 4th field means "pair by code" (typed on that same phone) instead of a QR.
export function parseSessions(s) {
  return String(s || '').split(/[,;\n]+/).map((x) => x.trim()).filter(Boolean).map((x) => {
    const [id, label, source, phone] = x.split(':').map((y) => (y || '').trim())
    return { id: cleanId(id), label: label || cleanId(id), source: (source || '').toLowerCase().replace(/[^a-z]/g, ''), phone: digits(phone) }
  }).filter((x) => x.id)
}
