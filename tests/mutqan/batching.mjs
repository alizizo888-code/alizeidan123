// Reads leave together.
//
// This is the test for the 503s on the live sites: a screen mounts a dozen live hooks and the
// stamps poller refetches more in the same tick, so one page load fired ~90 POSTs at /db. Shared
// hosting answered the first few and refused the rest, which reached the user as half-empty
// screens. The server has taken `op: 'batch'` all along; the app now uses it.
//
// It imports the real src/lib/db.js, so it tests what ships.
// Run: node --import ./tests/mutqan/register-loader.mjs tests/mutqan/batching.mjs

const store = new Map()
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
}
globalThis.window = undefined

let pass = 0
let fail = 0
const t = (name, cond, extra) => {
  if (cond) { pass++; return }
  fail++
  console.log('FAIL batching: ' + name + (extra === undefined ? '' : '  -> ' + JSON.stringify(extra)))
}

/** Every request the app makes, and what the server is told to answer. */
let sent = []
let reply = null
globalThis.fetch = async (url, init) => {
  const body = JSON.parse(init.body || '{}')
  sent.push(body)
  const answer = reply ? reply(body) : null
  if (answer) return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
  // The default server: a batch answers one row per op, a single read answers on its own.
  const data = body.op === 'batch'
    ? body.ops.map((o) => ({ ok: true, data: ['row:' + o.op + ':' + (o.coll || '')] }))
    : ['row:' + body.op + ':' + (body.coll || '')]
  return { ok: true, status: 200, json: async () => ({ data }) }
}

const { db } = await import('../../mutqan-app/src/lib/db.js')
const reset = () => { sent = []; reply = null }

// ---------------------------------------------------------------- the page load
reset()
let rows = await Promise.all([
  db.selectShared('orders'), db.selectShared('technicians'), db.selectShared('services'),
  db.selectShared('offers'), db.countShared('orders'), db.selectShared('articles'),
])
t('six reads in one tick go out as one request', sent.length === 1, sent.map((s) => s.op))
t('and that request is the batch the server already understood', sent[0].op === 'batch' && sent[0].ops.length === 6, sent[0].op)
t('each caller still gets its own answer', rows[0][0] === 'row:selectShared:orders' && rows[4][0] === 'row:countShared:orders', rows)

// ---------------------------------------------------------------- the same question twice
reset()
rows = await Promise.all([db.selectShared('orders'), db.selectShared('orders'), db.selectShared('technicians')])
t('two hooks asking the same thing ask the server once', sent[0].ops.length === 2, sent[0].ops)
t('and both of them are answered', rows[0][0] === 'row:selectShared:orders' && rows[1][0] === 'row:selectShared:orders', rows)

// ---------------------------------------------------------------- a screenful
reset()
await Promise.all(Array.from({ length: 24 }, (_, i) => db.selectShared('c' + i)))
t('24 reads become 3 requests, not 24', sent.length === 3, sent.length)
t('and no request carries more than the ten we allow', sent.every((s) => s.ops.length <= 10), sent.map((s) => s.ops.length))

// ---------------------------------------------------------------- one read on its own
reset()
await db.selectShared('orders')
t('a single read is sent as itself, not wrapped in a batch', sent[0].op === 'selectShared', sent[0].op)

// ---------------------------------------------------------------- writes stay apart
reset()
reply = (b) => (b.op === 'insertShared' ? { status: 200, body: { data: { id: 'x1' } } } : null)
await Promise.all([db.insertShared('orders', { a: 1 }), db.insertShared('orders', { a: 2 })])
t('writes are never batched: each one is sent on its own', sent.length === 2 && sent.every((s) => s.op === 'insertShared'), sent.map((s) => s.op))

// ---------------------------------------------------------------- one op fails, the rest do not
reset()
reply = (b) => (b.op !== 'batch' ? null : {
  status: 200,
  body: { data: [{ ok: true, data: ['fine'] }, { ok: false, status: 400, error: { code: 'bad_op', message: 'عملية غير معروفة.' } }] },
})
const settled = await Promise.allSettled([db.selectShared('orders'), db.selectShared('nope')])
t('the healthy read still resolves', settled[0].status === 'fulfilled' && settled[0].value[0] === 'fine', settled[0])
t('the failed one rejects with the server\'s own reason', settled[1].status === 'rejected' && settled[1].reason.code === 'bad_op', settled[1].reason && settled[1].reason.code)

// ---------------------------------------------------------------- a dead session
// Inside a batch there is no session check, so a refused token is asked again on its own, where
// that check lives. Anything less would leave a signed-out person staring at empty screens.
reset()
let singles = 0
reply = (b) => {
  if (b.op === 'batch') return { status: 200, body: { data: b.ops.map(() => ({ ok: false, status: 401, error: { code: 'unauthorized', message: 'انتهت الجلسة' } })) } }
  singles++
  return { status: 200, body: { data: ['after-retry'] } }
}
rows = await Promise.all([db.selectShared('orders'), db.selectShared('technicians')])
t('a refused token sends each read again on its own', singles === 2, singles)
t('and the rows arrive from that second attempt', rows[0][0] === 'after-retry', rows)

// ---------------------------------------------------------------- the whole request failing
reset()
reply = () => { throw new Error('offline') }
const down = await Promise.allSettled([db.selectShared('orders'), db.selectShared('technicians')])
t('when the request itself fails, every caller hears about it', down.every((r) => r.status === 'rejected'), down.map((r) => r.status))

console.log(`${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
