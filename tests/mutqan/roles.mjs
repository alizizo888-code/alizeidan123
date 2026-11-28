// Does each of the four roles survive a bad moment?
//
// The role every staff screen checks comes from one place: social.getMyRole() returns
// currentUser()?.role. So the moment the cached user is dropped, the role becomes null and every
// staff screen shows "no permission" — for the customer, the technician, operations and the owner
// alike. The owner alone has a second anchor (mq_owner_uid in localStorage), which is why the owner
// kept working while operations and technicians were thrown out.
//
// This walks the real chain — the shipped _api.js and auth.js — for each role.
// Run: node --import ./tests/mutqan/register-loader.mjs tests/mutqan/roles.mjs

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
const { auth } = await import('../../mutqan-app/src/lib/auth.js')

let queue = []
globalThis.fetch = async () => {
  const next = queue.shift() || { status: 200, body: { data: { ok: true } } }
  return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body }
}

// What every staff screen ends up asking, through social.getMyRole -> currentUser()?.role
const roleNow = () => api.currentUser()?.role || null

const ROLES = ['owner', 'operations', 'supervisor', 'technician']

// A bad moment the app must ride out. None of these mean "this person has no job here".
const HICCUPS = [
  ['an answer with no user', { status: 200, body: { data: {} } }],
  ['a 500 from the server', { status: 500, body: { error: { code: 'server_error' } } }],
  ['a firewall page instead of JSON', { status: 403, body: null }],
  ['a role refusal on another call', { status: 403, body: { error: { code: 'forbidden' } } }],
]

for (const role of ROLES) {
  for (const [label, reply] of HICCUPS) {
    store.clear()
    api.setSession('tok-' + role, { id: 'u-' + role, role, displayName: role })
    queue = [reply]
    await auth.refresh()
    t(`${role}: keeps the role through ${label}`, roleNow() === role, { got: roleNow() })
  }
}

// The one thing that must end a session, for every role alike.
for (const role of ROLES) {
  store.clear()
  api.setSession('tok-' + role, { id: 'u-' + role, role })
  queue = [{ status: 401, body: { error: { code: 'unauthorized' } } }]
  await auth.refresh()
  t(`${role}: a token the server refuses does end the session`, roleNow() === null && api.getToken() === '')
}

// A role the owner changed must actually take effect on the next load.
store.clear()
api.setSession('tok-x', { id: 'u-x', role: 'technician' })
queue = [{ status: 200, body: { data: { user: { id: 'u-x', role: 'operations' } } } }]
await auth.refresh()
t('a promotion from technician to operations is picked up', roleNow() === 'operations', { got: roleNow() })

store.clear()
api.setSession('tok-x', { id: 'u-x', role: 'operations' })
queue = [{ status: 200, body: { data: { user: { id: 'u-x', role: null } } } }]
await auth.refresh()
t('a role the owner removed is dropped', roleNow() === null)

// A customer is not staff and must stay a customer, not become one by accident.
store.clear()
api.setSession('tok-c', { id: 'u-c', role: null, displayName: 'عميل' })
queue = [{ status: 200, body: { data: {} } }]
await auth.refresh()
t('a customer with no role keeps their account through a hiccup', api.currentUser()?.id === 'u-c' && roleNow() === null)

// Several bad moments in a row, which is what a flaky connection actually looks like.
store.clear()
api.setSession('tok-ops', { id: 'u-ops', role: 'operations' })
for (let i = 0; i < 6; i++) {
  queue = [HICCUPS[i % HICCUPS.length][1]]
  await auth.refresh()
}
t('operations survives six bad moments in a row', roleNow() === 'operations', { got: roleNow() })

console.log(`\n${pass} passed, ${fail} failed`)
process.exit(fail === 0 ? 0 : 1)
