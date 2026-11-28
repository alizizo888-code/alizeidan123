import { post, ApiError } from './_api'

// Writes tell every live hook to refetch right away (before the next poll).
const writeListeners = new Set()
export const onLocalWrite = (fn) => { writeListeners.add(fn); return () => writeListeners.delete(fn) }
const wrote = (coll) => writeListeners.forEach((fn) => { try { fn(coll) } catch { /* ignore */ } })

// ---------------------------------------------------------------- reads go out together
//
// A screen mounts a dozen live hooks at once and the stamps poller refetches several more in the
// same tick, so a single page load was firing ~90 POSTs at /db. Shared hosting answers the first
// few and refuses the rest with 503, which reaches the user as half-empty screens and the "فشل"
// message. The server has taken `op: 'batch'` all along — the app simply never used it.
//
// So every read asked for in the same tick leaves as one request. Identical reads in that tick are
// asked once and the answer is handed to each caller. Writes are never batched: they have side
// effects and an outbox that depends on knowing exactly which one failed.
const READS = new Set(['select', 'selectShared', 'count', 'countShared', 'groupByShared', 'sumShared', 'stamps'])
const PER_REQUEST = 10          // the server allows 20; ten keeps any one request quick to answer
let queue = []
let batching = false

const callOne = (body) => post('/db', body)

function flushReads() {
  const batch = queue
  queue = []
  batching = false
  // Two hooks asking for the very same thing is one question, not two.
  const unique = new Map()
  for (const item of batch) {
    const k = JSON.stringify(item.body)
    const g = unique.get(k)
    if (g) { g.waiters.push(item); continue }
    unique.set(k, { body: item.body, waiters: [item] })
  }
  const groups = [...unique.values()]
  for (let i = 0; i < groups.length; i += PER_REQUEST) {
    const chunk = groups.slice(i, i + PER_REQUEST)
    const settle = (g, run) => { for (const w of g.waiters) { try { run(w) } catch { /* a waiter's own handler */ } } }
    if (chunk.length === 1) {
      const g = chunk[0]
      callOne(g.body).then((d) => settle(g, (w) => w.resolve(d)), (e) => settle(g, (w) => w.reject(e)))
      continue
    }
    post('/db', { op: 'batch', ops: chunk.map((g) => g.body) }).then((rows) => {
      chunk.forEach((g, j) => {
        const r = Array.isArray(rows) ? rows[j] : null
        if (r && r.ok) { settle(g, (w) => w.resolve(r.data)); return }
        const err = (r && r.error) || {}
        // A session the server no longer knows has to go through the single-request path, which is
        // where the session check and the sign-out decision live.
        if ((r && r.status === 401) || err.code === 'unauthorized') {
          callOne(g.body).then((d) => settle(g, (w) => w.resolve(d)), (e) => settle(g, (w) => w.reject(e)))
          return
        }
        settle(g, (w) => w.reject(new ApiError(err.message || 'حدث خطأ غير متوقع.', err.code || 'BATCH', (r && r.status) || 500)))
      })
    }, (e) => { for (const g of chunk) settle(g, (w) => w.reject(e)) })
  }
}

const call = (op, body) => {
  const full = { op, ...body }
  if (!READS.has(op)) { return callOne(full) }
  return new Promise((resolve, reject) => {
    queue.push({ body: full, resolve, reject })
    if (!batching) { batching = true; setTimeout(flushReads, 0) }
  })
}
// Outbox: a patch/upsert-by-id that fails only because the network is down is kept on the device and re-sent (technician in a lift, customer in a basement…).
// Inserts without an id are never retried (would risk duplicates); server-side rejections are never retried.
const OUT_KEY = 'mq_outbox'
const RETRYABLE = new Set(['updateShared', 'update', 'upsertShared', 'upsert'])
const readOut = () => { try { return JSON.parse(localStorage.getItem(OUT_KEY) || '[]') } catch { return [] } }
const saveOut = (a) => { try { a.length ? localStorage.setItem(OUT_KEY, JSON.stringify(a.slice(-200))) : localStorage.removeItem(OUT_KEY) } catch { /* ignore */ } }
let flushing = false
async function flushOutbox() {
  if (flushing) return
  flushing = true
  try {
    let q = readOut()
    while (q.length) {
      const it = q[0]
      try { await call(it.op, { coll: it.coll, ...it.body }); wrote(it.coll) }
      catch (e) { if (e && e.code === 'NETWORK') break }   // still offline: keep the rest, try later
      q = q.slice(1); saveOut(q)
    }
  } finally { flushing = false }
}
if (typeof window !== 'undefined') {
  window.addEventListener('online', flushOutbox); window.addEventListener('focus', flushOutbox)
  setInterval(flushOutbox, 15000); setTimeout(flushOutbox, 3000)
}
const writeCall = async (op, coll, body) => {
  try { const r = await call(op, { coll, ...body }); wrote(coll); return r }
  catch (e) {
    const ok = e && e.code === 'NETWORK' && RETRYABLE.has(op) && (op.startsWith('update') || body.id)
    if (!ok) throw e
    saveOut([...readOut(), { op, coll, body, at: Date.now() }])
    wrote(coll)
    return { queued: true }
  }
}

// A device that placed an order can be rung about it (see PersonalCalls).
const ORDERED_KEY = 'mq_has_order'
export const markOrdered = () => { try { localStorage.setItem(ORDERED_KEY, '1') } catch { /* ignore */ } }
export const hasOrdered = () => { try { return localStorage.getItem(ORDERED_KEY) === '1' } catch { return false } }

export const db = {
  // shared collections (readable per server policy)
  selectShared: (coll, filters = {}, opts = {}) => call('selectShared', { coll, filters, opts }),
  countShared: (coll, filters = {}) => call('countShared', { coll, filters }),
  groupByShared: (coll, field, opts = {}) => call('groupByShared', { coll, field, filters: opts.filters || {}, opts: { limit: opts.limit } }),
  sumShared: (coll, field, filters = {}) => call('sumShared', { coll, field, filters }),
  insertShared: (coll, data, id, opts = {}) => writeCall('insertShared', coll, { data, id: id || null, opts }).then((r) => { if (coll === 'orders') markOrdered(); return r }),
  updateShared: (coll, id, data) => writeCall('updateShared', coll, { id, data }),
  upsertShared: (coll, data, id, opts = {}) => writeCall('upsertShared', coll, { data, id: id || null, opts }),
  deleteShared: (coll, id) => writeCall('deleteShared', coll, { id }),
  // private (per-user) collections
  select: (coll, filters = {}, opts = {}) => call('select', { coll, filters, opts }),
  count: (coll, filters = {}) => call('count', { coll, filters }),
  groupBy: (coll, field, opts = {}) => call('groupByShared', { coll, field, filters: opts.filters || {}, opts: { limit: opts.limit } }),
  insert: (coll, data, id, opts = {}) => writeCall('insert', coll, { data, id: id || null, opts }),
  update: (coll, id, data) => writeCall('update', coll, { id, data }),
  delete: (coll, id) => writeCall('delete', coll, { id }),
  stamps: (topics) => call('stamps', { topics }),
}
