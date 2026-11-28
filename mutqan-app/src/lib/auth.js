import { post, setSession, currentUser, onUser, getToken, getAnonId, restoreSession } from './_api'
import { showAuthModal } from './auth-modal'

let refreshed = false
/**
 * Brings the cached user up to date on every page load.
 *
 * It must never end a session on its own judgement. Only one thing proves a session is over: the
 * server refusing the token with 'unauthorized'. An answer carrying no user, a 500, a firewall page
 * or no answer at all are all reasons to keep what we have and try again later — signing someone out
 * by mistake costs far more than showing a slightly stale name.
 */
async function refresh() {
  if (!getToken()) return
  try {
    const r = await post('/auth', { op: 'me' })
    if (r && r.user) setSession(getToken(), r.user)
    // No user in the answer: keep the cached session. The server says 401 when it means it.
  } catch (e) {
    if (e && e.code === 'unauthorized') setSession('', null)
  }
}
if (typeof window !== 'undefined' && !refreshed) { refreshed = true; restoreSession().finally(() => refresh()) }

export const auth = {
  getCurrentUser: () => currentUser(),
  isAuthenticated: () => !!currentUser() && !!getToken(),
  // The owner is the single account with role "owner" (created in WordPress admin).
  isAppOwner: () => currentUser()?.role === 'owner',
  onAuthChange(cb) { return onUser(cb) },
  refresh,
  // Customer sign-in / sign-up (phone + password) in a small modal. Resolves the user or null.
  signIn: (opts) => showAuthModal(opts),
  async login({ phone, password }) {
    const r = await post('/auth', { op: 'login', phone, password })
    setSession(r.token, r.user); return r.user
  },
  async signUp({ name, phone, password }) {
    const r = await post('/auth', { op: 'signup', name, phone, password })
    setSession(r.token, r.user); return r.user
  },
  async updateProfile(patch) {
    const r = await post('/auth', { op: 'profile', ...patch })
    if (r.user) setSession(getToken(), r.user)
    return r.user
  },
  changePassword: (current, next) => post('/auth', { op: 'password', current, next }),
  async signOut() {
    try { await post('/auth', { op: 'logout' }) } catch { /* token is dropped locally anyway */ }
    setSession('', null)
  },
  anonymousId: getAnonId,
}

export const adoptSession = (r) => { if (r && r.token) setSession(r.token, r.user) }
