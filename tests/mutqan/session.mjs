// The session rule that decides whether a person stays signed in.
//
// This is the test for the bug that threw the owner and the supervisors out of whole screens: the
// app treated a 403 ("this action is not for your role") as a reason to re-check the session, and
// an imperfect re-check signed them out. A 403 is an answer about the action, not about the session.
//
// It imports the real src/lib/_api.js, so it tests what ships, not a copy of the rule.
// Run: node --import ./tests/mutqan/register-loader.mjs tests/mutqan/session.mjs

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
  console.log('FAIL ' + name + (extra === undefined ? '' : '  -> ' + JSON.stringify(extra)))
}

const api = await import('../../mutqan-app/src/lib/_api.js')

/** Queues the replies the next fetch calls should give, and records what was asked for. */
let queue = []
let asked = []
globalThis.fetch = async (url, init) => {
  asked.push(String(url))
  const next = queue.shift() || { status: 200, body: { data: { ok: true } } }
  return {
    ok: next.status >= 200 && next.status < 300,
    status: next.status,
    json: async () => next.body,
  }
}

const signedIn = () => api.getToken() !== ''
function reset(token = 'tok-' + Math.random().toString(16).slice(2)) {
  store.clear()
  api.setSession(token, { id: 'u1', role: 'owner' })
  queue = []
  asked = []
}

// ---------------------------------------------------------------- a role refusal
reset()
queue = [{ status: 403, body: { error: { code: 'forbidden', message: 'هذا الإجراء للمالك فقط.' } } }]
let err = null
try { await api.post('/team', { op: 'list' }) } catch (e) { err = e }
t('a role refusal still throws, so the screen can say why', err && err.code === 'forbidden', err && err.code)
t('a role refusal does NOT sign the person out', signedIn())
t('and it does not even ask the server about the session', asked.filter((u) => u.endsWith('/auth')).length === 0, asked)

// A supervisor hitting several owner-only endpoints in one screen must survive all of them.
reset()
for (let i = 0; i < 5; i++) {
  queue.push({ status: 403, body: { error: { code: 'forbidden', message: 'للمالك فقط' } } })
  try { await api.post('/team', { op: 'x' + i }) } catch { /* expected */ }
}
t('five role refusals in a row still leave the person signed in', signedIn())

// The other 403s are about the account, not the session, so they must not sign out either.
for (const code of ['pending', 'disabled', 'not_staff']) {
  reset()
  queue = [{ status: 403, body: { error: { code, message: 'x' } } }]
  try { await api.post('/db', { op: 'list' }) } catch { /* expected */ }
  t(`a 403 '${code}' does not sign the person out`, signedIn())
}

// ---------------------------------------------------------------- a real expired session
reset()
queue = [
  { status: 401, body: { error: { code: 'unauthorized', message: 'سجّل الدخول' } } },  // the request
  { status: 401, body: { error: { code: 'unauthorized' } } },                          // /auth me, first try
  { status: 401, body: { error: { code: 'unauthorized' } } },                          // /auth me, second try
]
err = null
try { await api.post('/db', { op: 'list' }) } catch (e) { err = e }
t('a token the server refuses twice does sign the person out', !signedIn())
t('and the message tells them to sign in again', err && err.code === 'SESSION_EXPIRED', err && err.code)

// ---------------------------------------------------------------- a session that is still good
reset('good-token')
queue = [
  { status: 401, body: { error: { code: 'unauthorized' } } },
  { status: 200, body: { data: { user: { id: 'u1', role: 'owner' } } } },   // /auth me says the session is fine
]
try { await api.post('/db', { op: 'list' }) } catch { /* the original request still failed */ }
t('a 401 that the check disproves keeps the person signed in', signedIn())

// ---------------------------------------------------------------- the server is having a bad day
for (const bad of [
  { label: 'a 500', reply: { status: 500, body: { error: { code: 'server_error' } } } },
  { label: 'a firewall page', reply: { status: 403, body: null } },
  { label: 'a reply in an unexpected shape', reply: { status: 200, body: { data: {} } } },
]) {
  reset()
  queue = [
    { status: 401, body: { error: { code: 'unauthorized' } } },
    bad.reply,
    bad.reply,
  ]
  try { await api.post('/db', { op: 'list' }) } catch { /* expected */ }
  t(`${bad.label} from the session check is not proof of sign-out`, signedIn())
}

// ---------------------------------------------------------------- offline
reset()
queue = [{ status: 401, body: { error: { code: 'unauthorized' } } }]
const realFetch = globalThis.fetch
let calls = 0
globalThis.fetch = async (url, init) => {
  calls++
  if (calls === 1) return realFetch(url, init)
  throw new Error('network down')
}
try { await api.post('/db', { op: 'list' }) } catch { /* expected */ }
t('losing the network does not sign the person out', signedIn())
globalThis.fetch = realFetch

// ---------------------------------------------------------------- signing in is never interfered with
reset('')
queue = [{ status: 401, body: { error: { code: 'bad_credentials' } } }]
err = null
try { await api.post('/auth', { op: 'login' }) } catch (e) { err = e }
t('a wrong password is reported as itself, not as an expired session', err && err.code === 'bad_credentials', err && err.code)

// ---------------------------------------------------------------- the page-load refresh
// A second sign-out path, which runs before anything is drawn. It used to wipe the session whenever
// /auth me came back without a user — which the server sent for a cache hiccup as readily as for a
// dead session. That is the screen that says "لم يتم التعرف على حساب مسجّل".
const { auth } = await import('../../mutqan-app/src/lib/auth.js')

reset('page-load-token')
queue = [{ status: 200, body: { data: {} } }]   // 200, no user: ambiguous, not proof
await auth.refresh()
t('an answer with no user keeps the person signed in', signedIn())

reset('page-load-token')
queue = [{ status: 500, body: { error: { code: 'server_error' } } }]
await auth.refresh()
t('a 500 on page load keeps the person signed in', signedIn())

reset('page-load-token')
queue = [{ status: 403, body: { error: { code: 'forbidden' } } }]
await auth.refresh()
t('a 403 on page load keeps the person signed in', signedIn())

reset('page-load-token')
queue = [{ status: 401, body: { error: { code: 'unauthorized' } } }]
await auth.refresh()
t('only the server refusing the token signs them out', !signedIn())

reset('page-load-token')
queue = [{ status: 200, body: { data: { user: { id: 'u1', role: 'operations' } } } }]
await auth.refresh()
t('a good answer refreshes the role', signedIn() && api.currentUser().role === 'operations')

console.log(`\n${pass} passed, ${fail} failed`)
process.exit(fail === 0 ? 0 : 1)
