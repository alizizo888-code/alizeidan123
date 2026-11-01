import { post } from './_api'
import { getDeviceId } from './device'

// Hotline link code + Telegram + the desk's doors into WordPress (server: Mutqan_Link / Mutqan_Telegram / Mutqan_Rest::h_desk).
// The link code and the bot token are typed by the owner in Settings and go straight to the server; they are never kept on this device.
const BRAND = (typeof window !== 'undefined' && window.__DESK_BRAND__) || 'site'
export const brandName = () => (BRAND === 'oxygen' ? 'أكسجين' : BRAND === 'mutqan' ? 'متقن' : 'التطبيق')
const FLAG = 'hotlink_' + BRAND
const ls = {
  get: (k) => { try { return localStorage.getItem(k) } catch { return null } },
  set: (k, v) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } },
  del: (k) => { try { localStorage.removeItem(k) } catch { /* ignore */ } },
}
const platform = () => (/Android/i.test(navigator.userAgent) ? 'أندرويد' : /iPhone|iPad|iPod/i.test(navigator.userAgent) ? 'آيفون' : 'متصفح')
const label = () => `${brandName()} · ${platform()}`
const dev = () => ({ deviceId: getDeviceId(), label: label() })
const mark = (r) => { if (r && r.linkedHere) ls.set(FLAG, '1'); else ls.del(FLAG); return r }

export const isLinkedHere = () => ls.get(FLAG) === '1'

export const hotlink = {
  state: () => post('/link', { op: 'state' }),                                   // public: { linked, online, tg }
  status: () => post('/link', { op: 'status', ...dev() }).then(mark),
  set: (code) => post('/link', { op: 'set', code, ...dev() }).then(mark),
  link: (code) => post('/link', { op: 'link', code, ...dev() }).then(mark),
  unlink: (target) => post('/link', { op: 'unlink', ...dev(), ...(target ? { target } : {}) }).then(mark),
  clear: () => post('/link', { op: 'clear', ...dev() }).then(mark),
  ping: () => post('/link', { op: 'ping', ...dev() }).then((r) => { if (r && r.linked === false) ls.del(FLAG); return r }),
}

export const telegram = {
  status: () => post('/link', { op: 'tg_status' }),
  save: (token) => post('/link', { op: 'tg_save', token }),
  off: () => post('/link', { op: 'tg_off' }),
  pair: () => post('/link', { op: 'tg_pair' }),
  unpair: () => post('/link', { op: 'tg_unpair' }),
  test: () => post('/link', { op: 'tg_test' }),
}

// WordPress doors for the owner (and the device report the server shows in wp-admin → المكتب السري).
export const wp = {
  overview: () => post('/desk', { op: 'wp_overview' }),
  pages: (type = 'page', q = '') => post('/desk', { op: 'wp_pages', type, q }),
  // One-time address that logs the site's administrator in (a year-long cookie) and lands on `to`; the way back is the «العودة للتطبيق» button inside wp-admin.
  sso: (to) => post('/desk', { op: 'wp_sso', to, back: location.origin + location.pathname }),
}
export async function openWp(to) {
  const r = await wp.sso(to)
  if (!r || !r.url) throw new Error('لم يصل رابط الدخول')
  window.location.href = r.url
}

export const reportDevice = (step, info) => post('/desk', { op: 'diag', app: (typeof window !== 'undefined' && window.__DESK_KIND__) || 'web', build: (typeof window !== 'undefined' && window.__DESK_BUILD__) || '', step, info: String(info || '').slice(0, 160) }).catch(() => {})
