// «تطبيق الإدارة» — the team's Chatwoot (chat.oxygen11.com and its phone apps) shows every customer chat of the hub.
//
// Chatwoot cannot hold QR-linked numbers itself, so each linked line becomes an «API channel» inbox there:
//   hub → Chatwoot : every customer message, and every answer (bot, journey, a person on the phone or the panel), lands
//                    in that customer's conversation — incoming as incoming, ours as outgoing — with an identity card.
//   Chatwoot → hub : an agent's reply (the inbox webhook) goes out on WhatsApp from the same line, and the bot keeps
//                    out of that conversation, as for any person answering.
// Loops cannot happen: what we posted is remembered by its id and its webhook echo ignored; what came from Chatwoot
// is sent with purpose «chatwoot» and never mirrored back. Nothing waits on Chatwoot.
import crypto from 'node:crypto'
import { pkey, intl } from './text.js'

export function createChatwoot({ store, wp, log = () => {}, hookBase }) {
  const mine = new Set()
  let creds = null; let credsAt = 0
  const secret = () => { let k = store.get('cwSecret'); if (!k) { k = crypto.randomBytes(16).toString('hex'); store.set('cwSecret', k) } return k }
  const hookUrl = () => hookBase.replace(/\/+$/, '') + '/cw/hook?k=' + secret()
  const on = () => store.get('cwOn', true) !== false

  async function cfg() {
    if (creds && Date.now() - credsAt < 3600000) return creds
    try { creds = await wp('chatwoot', {}); credsAt = Date.now() } catch (e) { log('warn', 'chatwoot creds: ' + e.message) }
    return creds
  }
  async function api(method, path, body) {
    const c = await cfg(); if (!c) throw new Error('Chatwoot مش مربوط في الموقع')
    const r = await fetch(c.url + '/api/v1/accounts/' + c.account + path, { method, headers: { api_access_token: c.token, 'content-type': 'application/json', accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(15000) })
    const j = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error('Chatwoot ' + r.status + (j && j.message ? ': ' + j.message : ''))
    return j
  }
  const map = () => store.get('cwMap', {}) || {}
  const mapSet = (k, v) => { const m = map(); m[k] = v; store.set('cwMap', m) }

  async function members(inbox) {
    try {
      const a = await api('GET', '/agents'); const ids = (a.payload || a || []).map((x) => x.id).filter(Boolean)
      if (ids.length) await api('POST', '/inbox_members', { inbox_id: inbox, user_ids: ids })
      return ids.length
    } catch (e) { log('warn', 'chatwoot members: ' + e.message); return 0 }
  }
  async function inboxFor(line) {
    const m = map(); if (m['i:' + line.id]) return m['i:' + line.id]
    const r = await api('POST', '/inboxes', { name: ('واتساب · ' + (line.label || line.id) + (line.phone ? ' ' + line.phone : '')).slice(0, 60), channel: { type: 'api', webhook_url: hookUrl() } })
    if (!r.id) throw new Error('Chatwoot ما رجّعش رقم الصندوق')
    mapSet('i:' + line.id, r.id); await members(r.id)
    log('chatwoot', 'صندوق جديد في تطبيق الإدارة: ' + (line.label || line.id))
    return r.id
  }
  async function convFor(chat, line, card) {
    const m = map(); if (m['c:' + chat.pk + ':' + line.id]) return m['c:' + chat.pk + ':' + line.id]
    const inbox = await inboxFor(line)
    const phone = intl(chat.phone)
    const c = await api('POST', '/contacts', { inbox_id: inbox, name: chat.name || chat.contact || '+' + phone, phone_number: '+' + phone, identifier: 'wa:' + phone }).catch(async (e) => {
      // Already there (same phone): find him and add this inbox.
      const s = await api('GET', '/contacts/search?q=' + encodeURIComponent('+' + phone)); const ct = (s.payload || [])[0]; if (!ct) throw e
      const ci = await api('POST', '/contacts/' + ct.id + '/contact_inboxes', { inbox_id: inbox })
      return { payload: { contact: { id: ct.id, contact_inboxes: [{ source_id: ci.source_id, inbox: { id: inbox } }] } } }
    })
    const cp = (c.payload && (c.payload.contact || c.payload)) || c
    let source = ''
    for (const ci of cp.contact_inboxes || []) if ((ci.inbox && ci.inbox.id) === inbox || ci.inbox_id === inbox) source = ci.source_id
    if (!source && c.payload && c.payload.contact_inbox) source = c.payload.contact_inbox.source_id
    const conv = await api('POST', '/conversations', { source_id: source || undefined, inbox_id: inbox, contact_id: cp.id, additional_attributes: { hub_pk: chat.pk, line: line.label || line.id } })
    if (!conv.id) throw new Error('Chatwoot ما رجّعش رقم المحادثة')
    mapSet('c:' + chat.pk + ':' + line.id, conv.id); mapSet('k:' + conv.id, { pk: chat.pk, line: line.id })
    const L = ['🪪 كارت المحادثة', '📞 الخط: ' + (line.label || line.id) + (line.phone ? ' (' + line.phone + ')' : '')]
    if (card && card.site) L.push('🌐 الموقع: ' + card.site)
    if (card && card.source) L.push('📰 جه من: ' + card.source)
    if (card && card.brief) L.push('🧾 ' + card.brief)
    await post(conv.id, L.join('\n'), 'outgoing', true).catch(() => {})
    return conv.id
  }
  async function post(conv, text, type, priv = false) {
    const r = await api('POST', '/conversations/' + conv + '/messages', { content: String(text).slice(0, 9000), message_type: type, private: priv })
    if (r.id) { mine.add(r.id); if (mine.size > 3000) mine.delete(mine.values().next().value) }
    return r
  }

  const WHO = { bot: '🤖 الاستقبال', reply: '🤖 الاستقبال', customer: '🔗 متابعة الطلب', site: '⚙️ النظام', phone: '📱 من الموبايل', agent: '👤 الفريق', room: '', tech: '' }
  /** Every message of a customer chat. Never throws, never blocks WhatsApp. */
  function mirror(pk, lineId, dir, text, by = '') {
    if (!on() || by === 'chatwoot') return
    const chat = store.chat(pk); if (!chat || chat.kind !== 'customer') return
    const line = store.line(lineId || chat.line); if (!line) return
    let card = null; try { card = JSON.parse(chat.greet || 'null') } catch {}
    ;(async () => {
      try {
        const conv = await convFor(chat, line, card)
        const who = dir === 'out' ? (WHO[by] ?? '') : ''
        await post(conv, (who ? who + '\n' : '') + (text || '[رسالة]'), dir === 'in' ? 'incoming' : 'outgoing')
      } catch (e) { log('warn', 'chatwoot mirror: ' + e.message) }
    })()
  }

  /** An agent answered in the app: out on WhatsApp from the same line; the bot keeps out of that conversation. */
  function hook(e, key, send) {
    if (!key || key !== secret()) return { status: 403 }
    if (e.event !== 'message_created') return { ignored: 'event' }
    if (!(e.message_type === 'outgoing' || e.message_type === 1) || e.private) return { ignored: 'type' }
    if (e.id && mine.has(e.id)) return { ignored: 'echo' }
    const conv = (e.conversation && e.conversation.id) || e.conversation_id
    const k = map()['k:' + conv]; if (!k) return { ignored: 'unknown' }
    const chat = store.chat(k.pk); if (!chat) return { ignored: 'unknown' }
    let text = String(e.content || '').trim()
    for (const a of e.attachments || []) if (a.data_url) text = (text + '\n' + a.data_url).trim()
    if (!text) return { ignored: 'empty' }
    send(chat.phone, text, { line: k.line, purpose: 'chatwoot' })
    store.touchChat(k.pk, { humanAt: Date.now(), needsHuman: 0, unread: 0 })
    return { sent: true }
  }

  /** «ربط تطبيق الإدارة»: credentials from the site, one inbox per line, the whole team in every inbox. */
  async function link(lines) {
    creds = null
    const c = await cfg(); if (!c) return { ok: false, error: 'Chatwoot مش مربوط في الموقع لسه' }
    const out = []
    for (const l of lines) { try { const id = await inboxFor(l); out.push({ line: l.label, inbox: id, members: await members(id) }) } catch (e) { out.push({ line: l.label, error: e.message }) } }
    store.set('cwOn', true)
    return { ok: true, url: c.url, inboxes: out }
  }
  async function status() { const c = await cfg(); return { linked: !!c, on: on(), url: c ? c.url : 'https://chat.oxygen11.com', inboxes: Object.keys(map()).filter((k) => k.startsWith('i:')).length } }

  return { mirror, hook, link, status, setOn: (v) => store.set('cwOn', !!v) }
}
