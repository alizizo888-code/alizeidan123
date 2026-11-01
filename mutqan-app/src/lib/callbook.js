import { db } from './db'
import { team } from './team'

// Call register for the hotline: every call gets a CODE that identifies the caller until he is registered or linked to a
// customer / an order / a WhatsApp chat / the bot. Source calls live in `calls` (24h signalling docs); the permanent record is
// `call_log` (one row per call) and `call_contacts` (one row per caller code, holding identity keys + links).
//
// NOTE on the server: an upsert MERGES into an existing document, so a "create" that races with a person's edit would wipe links / notes.
// Every first write therefore goes through createOnce() (insert; the unique id answers 409 when it already exists) and later writes are patches.

export const digits = (v) => String(v || '').replace(/\D/g, '')
// Saudi mobile numbers are stored as 966xxxxxxxxx (same as manual orders); anything else keeps its digits.
export const normPhone = (d) => { const x = digits(d); if (x.length < 7) return ''; if (x.startsWith('966')) return x; return x.length === 9 || (x.length === 10 && x.startsWith('0')) ? '966' + x.slice(-9) : x }
export const phoneKey = (v) => { const d = digits(v); return d.length >= 7 ? 'P' + d.slice(-9) : '' }
const isReal = (id) => !!id && !String(id).startsWith('anon-')

function hash36(s) {
  let h = 2166136261
  for (const ch of String(s)) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619) >>> 0 }
  return h.toString(36).toUpperCase().padStart(5, '0').slice(-5)
}

// Identity keys of a call, strongest first: signed-in account, phone number, device.
export const keysOf = (c) => [isReal(c?.userId) ? 'U' + c.userId : '', phoneKey(c?.phone), c?.deviceId ? 'D' + String(c.deviceId).slice(0, 40) : ''].filter(Boolean)

// Finds the caller's contact (any shared key, or the phone stored on the contact) or mints a new code.
// Codes are deterministic: the same phone / account / device always gets the same code.
export function resolveContact(keys, contacts, seed = '') {
  const list = contacts || []
  const hit = keys.length ? list.find((x) => (x.keys || []).some((k) => keys.includes(k)) || (x.phone && keys.includes(phoneKey(x.phone)))) : null
  if (hit) return { code: hit.code || hit.id, contact: hit }
  const base = 'C-' + hash36(keys[0] || 'x' + seed)       // a call without any identity key (old client) is its own caller: seeded by the call id so every screen computes the same code
  const taken = new Set(list.map((x) => x.code || x.id))
  let code = base, n = 0
  while (taken.has(code)) code = base + String.fromCharCode(66 + n++)
  return { code, contact: null }
}

const t = (v) => { const x = Date.parse(v); return Number.isFinite(x) ? x : 0 }

// ringing | active | answered | missed | declined
export function logStatus(c, now = Date.now()) {
  const s = c?.status
  if (s === 'declined') return 'declined'
  if (s === 'missed') return 'missed'
  if (s === 'ended') return c.answeredAt || c.answeredBy ? 'answered' : 'missed'      // the caller hung up while it was still ringing = missed
  if (s === 'active') return now - (t(c.answeredAt) || t(c.createdAt)) > 3 * 3600e3 ? 'answered' : 'active'
  if (s === 'ringing') return now - t(c.createdAt) > 90000 ? 'missed' : 'ringing'
  return s || 'missed'
}
export const durationOf = (c) => { const a = t(c?.answeredAt), e = t(c?.endedAt); return a && e && e > a ? Math.round((e - a) / 1000) : 0 }
export const fmtDur = (s) => (s ? `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}` : '—')

export const STATUS_LABEL = { ringing: 'يرنّ الآن', active: 'قيد المكالمة', answered: 'تم الرد', missed: 'فائتة', declined: 'مرفوضة' }
export const LINK_LABEL = { customer: 'عميل مسجّل', order: 'طلب', wa: 'واتساب', bot: 'البوت' }
export const LINK_ICON = { customer: '👤', order: '🧾', wa: '💬', bot: '🤖' }
export const KIND_LABEL = { registered: 'عميل مسجّل', linked: 'مرتبط', guest: 'غير مسجّل' }

// A missed / declined call nobody called back yet (last 7 days).
export const needsFollowUp = (l, now = Date.now()) => (l?.status === 'missed' || l?.status === 'declined') && !l.followedUpAt && now - (t(l.startedAt) || t(l.createdAt)) < 7 * 86400e3

export const mergeLinks = (...lists) => {
  const out = []
  for (const l of lists.flat()) if (l && l.type && !out.some((x) => x.type === l.type && String(x.ref) === String(l.ref))) out.push(l)
  return out
}

// The row stored in call_log for a `calls` document (plus the caller code). Never carries notes/links: those are written by people only.
export function entryFor(c, contacts) {
  const keys = keysOf(c)
  const { code, contact } = resolveContact(keys, contacts, c.id)
  const named = c.name && c.name !== 'عميل' ? c.name : ''
  return {
    contact, keys,
    entry: {
      callId: c.id, code, name: named || contact?.name || '', phone: c.phone || contact?.phone || '', userId: isReal(c.userId) ? c.userId : '', deviceId: c.deviceId || '',
      status: logStatus(c), answeredBy: c.answeredBy || '', answeredAt: c.answeredAt || null, endedAt: c.endedAt || null, durationSec: durationOf(c),
      startedAt: c.createdAt || new Date().toISOString(), source: 'hotline', direction: 'in',
    },
  }
}

// What operations sees while a call rings / is live: the code, who he is (as far as known) and his history with us.
export function infoOf(c, contacts, log) {
  const { entry, contact, keys } = entryFor(c, contacts)
  const prior = (log || []).filter((l) => l.code === entry.code && (l.callId || l.id) !== c.id)
  return {
    code: entry.code, name: entry.name || contact?.name || '', phone: entry.phone || contact?.phone || '',
    kind: contact?.kind || (keys.some((k) => k.startsWith('U')) ? 'registered' : 'guest'),
    links: contact?.links || [], count: prior.length, lastAt: prior[0]?.startedAt || '',
  }
}

const OPTS = async () => ({ groupId: await team.groupId(), writableBy: 'admins' })
const getDoc = async (coll, id) => { const r = await db.selectShared(coll, { id }, { limit: 1 }); return (r || [])[0] || null }

// Inserts the document only if its id is free (true = created, false = it already existed). Never overwrites.
async function createOnce(coll, data, id, opts) {
  try { await db.insertShared(coll, data, id, opts); return true } catch (e) {
    if (e && e.code === 'NETWORK') throw e
    if (await getDoc(coll, id)) return false
    throw e
  }
}

const mergeKeys = (a, b) => { const out = [...(a || [])]; for (const k of b || []) if (k && !out.includes(k)) out.push(k); return out }

// Creates the contact of a new code, or completes an existing one (new device / phone / name for the same person).
export async function ensureContact(code, keys, contact, info) {
  const name = info.name || '', phone = info.phone || ''
  let cur = contact
  if (!cur) {
    const kind = keys.some((k) => k.startsWith('U')) ? 'registered' : 'guest'
    const doc = { code, keys, name, phone, userId: keys.find((k) => k.startsWith('U'))?.slice(1) || '', kind, links: [], note: '', firstAt: new Date().toISOString() }
    if (await createOnce('call_contacts', doc, code, await OPTS())) return { ...doc, id: code }
    cur = await getDoc('call_contacts', code)                  // another desk minted it first: complete that one
    if (!cur) return { ...doc, id: code }
  }
  const patch = {}
  const merged = mergeKeys(cur.keys, keys)
  if (merged.length !== (cur.keys || []).length) patch.keys = merged
  if (!cur.name && name) patch.name = name
  if (!cur.phone && phone) patch.phone = phone
  if (cur.kind === 'guest' && keys.some((k) => k.startsWith('U'))) { patch.kind = 'registered'; patch.userId = keys.find((k) => k.startsWith('U')).slice(1) }
  if (Object.keys(patch).length) { await db.updateShared('call_contacts', cur.id || code, patch); return { ...cur, ...patch } }
  return cur
}

// One pass over the recent `calls` docs: writes/updates call_log rows. Returns the (possibly extended) contacts list.
// `seen` remembers what was already written (and what failed recently) so an unchanged call costs nothing.
export async function syncCalls(calls, log, contacts, seen) {
  const logBy = Object.fromEntries((log || []).map((l) => [l.callId || l.id, l]))
  let known = [...(contacts || [])]
  const opts = await OPTS()
  for (const c of [...(calls || [])].reverse()) {          // oldest first so the first call of a caller mints the code
    const have = logBy[c.id]
    const base = entryFor(c, known)
    const code = have?.code || base.entry.code            // a row keeps the code it was first given
    const entry = { ...base.entry, code }
    const sig = `${entry.status}|${entry.answeredAt}|${entry.endedAt}`
    if (seen.get(c.id) === sig) continue
    if (have && have.status === entry.status && (have.answeredAt || null) === entry.answeredAt && (have.endedAt || null) === entry.endedAt) { seen.set(c.id, sig); continue }
    const ek = 'err:' + c.id + ':' + sig
    if (Date.now() - (seen.get(ek) || 0) < 120000) continue          // failed a moment ago: do not hammer the server
    try {
      const contact = have?.code ? known.find((x) => (x.code || x.id) === code) || null : base.contact
      const ct = await ensureContact(code, base.keys, contact, entry)
      known = known.some((x) => (x.code || x.id) === code) ? known.map((x) => ((x.code || x.id) === code ? ct : x)) : [...known, ct]
      const patch = { status: entry.status, answeredBy: entry.answeredBy, answeredAt: entry.answeredAt, endedAt: entry.endedAt, durationSec: entry.durationSec }
      if (have) await db.updateShared('call_log', c.id, { ...patch, ...(have.name || !entry.name ? {} : { name: entry.name }), ...(have.phone || !entry.phone ? {} : { phone: entry.phone }) })
      else if (!(await createOnce('call_log', { ...entry, links: [], note: '' }, c.id, opts))) await db.updateShared('call_log', c.id, patch)
      seen.set(c.id, sig)
    } catch { seen.set(ek, Date.now()) }
  }
  return known
}

// Adds a link to a call (and to its caller code). `link` = { type: customer|order|wa|bot, ref, label }.
export async function addLink(entry, contact, link, extra = {}) {
  const opts = await OPTS()
  const same = (x) => x.type === link.type && String(x.ref) === String(link.ref)
  const row = await getDoc('call_log', entry.callId)
  const callLinks = [...(((row ? row.links : entry.links) || []).filter((x) => !same(x))), link]
  const patch = { links: callLinks, ...(extra.name && !(row?.name || entry.name) ? { name: extra.name } : {}), ...(extra.phone && !(row?.phone || entry.phone) ? { phone: extra.phone } : {}) }
  if (row) await db.updateShared('call_log', entry.callId, patch)
  else {
    const base = { ...entry }; delete base.contact; delete base.keys; delete base.links
    if (!(await createOnce('call_log', { ...base, ...patch, note: entry.note || '' }, entry.callId, opts))) await db.updateShared('call_log', entry.callId, patch)
  }
  // the contact is read fresh: the copy held by the screen may be stale and a patch must never drop links another desk added
  const fresh = (await getDoc('call_contacts', entry.code)) || contact || null
  const phone = extra.phone || ''
  const cpatch = {
    links: [...((fresh?.links) || []).filter((x) => !same(x)), link],
    keys: mergeKeys(fresh?.keys || entry.keys, [phoneKey(phone)]),
    kind: link.type === 'customer' || fresh?.kind === 'registered' ? 'registered' : 'linked',
    ...(extra.name && !fresh?.name ? { name: extra.name } : {}), ...(phone && !fresh?.phone ? { phone } : {}),
  }
  if (fresh) await db.updateShared('call_contacts', fresh.id || entry.code, cpatch)
  else {
    const doc = { code: entry.code, keys: mergeKeys(entry.keys, [phoneKey(phone)]), name: entry.name || '', phone: entry.phone || '', userId: entry.userId || '', note: '', firstAt: new Date().toISOString(), ...cpatch }
    if (!(await createOnce('call_contacts', doc, entry.code, opts))) await db.updateShared('call_contacts', entry.code, cpatch)
  }
  return { links: mergeLinks(callLinks, cpatch.links), contactPatch: cpatch }
}

export async function removeLink(entry, contact, link) {
  const same = (x) => x.type === link.type && String(x.ref) === String(link.ref)
  const row = await getDoc('call_log', entry.callId)
  if (row) await db.updateShared('call_log', entry.callId, { links: (row.links || []).filter((x) => !same(x)) })
  const fresh = (await getDoc('call_contacts', entry.code)) || contact
  if (fresh) {
    const rest = (fresh.links || []).filter((x) => !same(x))
    await db.updateShared('call_contacts', fresh.id || entry.code, { links: rest, kind: rest.some((x) => x.type === 'customer') || fresh.userId ? 'registered' : rest.length ? 'linked' : 'guest' })
  }
}

export async function setNote(entry, note) {
  const row = await getDoc('call_log', entry.callId)
  if (row) return db.updateShared('call_log', entry.callId, { note })
  const base = { ...entry }; delete base.contact; delete base.keys
  if (!(await createOnce('call_log', { ...base, note, links: entry.links || [] }, entry.callId, await OPTS()))) return db.updateShared('call_log', entry.callId, { note })
}

// Registers an unknown caller as a customer card (CRM) keyed by phone, like every customer created from an order.
export async function registerCustomer(entry, contact, { name, phone }) {
  const d = digits(phone)
  const key = d.length >= 7 ? 'p' + d.slice(-9) : 'c-' + String(entry.code).replace(/[^A-Za-z0-9-]/g, '')
  const nm = name.trim()
  const existing = await getDoc('customer_profiles', key)           // an existing card (same phone) is never overwritten
  if (!existing) await createOnce('customer_profiles', { name: nm, phone: normPhone(d), callCode: entry.code, createdFrom: 'hotline', updatedAt: new Date().toISOString() }, key, await OPTS())
  return addLink(entry, contact, { type: 'customer', ref: key, label: existing?.name || nm }, { name: existing?.name || nm, phone: normPhone(d) })
}

// A phone call that did not come through the app (the supervisor logs it by hand): same code system, keyed by the number.
export async function logManualCall({ phone, name, note, direction, contacts }) {
  const id = 'm-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
  const fake = { id, phone, name, createdAt: new Date().toISOString() }
  const { entry, keys, contact } = entryFor(fake, contacts)
  const ct = await ensureContact(entry.code, keys, contact, entry)
  await createOnce('call_log', { ...entry, status: 'answered', source: 'phone', direction: direction === 'out' ? 'out' : 'in', note: note || '', links: [] }, id, await OPTS())
  return { entry, contact: ct }
}
