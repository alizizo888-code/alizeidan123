// «جاي منين» (owner order 2026-11-19): every order carries where the customer came from — which site and host,
// which page (home, app, article, sub-article, Blogger, the installed web app, a landing page), the article and
// its code (#oxygen/a26), the channel (Google search, Google ads, TikTok, Facebook, WhatsApp, direct…), the
// referrer and the campaign. The FIRST visit is kept too, so a customer who came from an article on Monday and
// ordered from the app on Wednesday still shows the article.
// The WordPress pages write `mq_last_page` (Mutqan_WA_Central::link_tagger) so the app on the same host knows
// which article sent the customer; Blogger faces pass ?via=<site>&src=<title>.
import { NET, viaBrand, viaSource } from './netBrand'

const FIRST = 'mq_origin_first', LAST = 'mq_origin_last'
const ls = { get: (k) => { try { return JSON.parse(localStorage.getItem(k) || 'null') } catch { return null } }, set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch { /* private mode */ } } }

function channelOf(ref, q) {
  const r = (ref || '').toLowerCase()
  if (q.get('gclid') || q.get('gbraid') || q.get('wbraid') || /^(google|adwords)$/i.test(q.get('utm_source') || '') && /cpc|ppc|paid/i.test(q.get('utm_medium') || '')) return 'google_ads'
  const s = (q.get('utm_source') || '').toLowerCase()
  if (s) { for (const [k, v] of [['tiktok', 'tiktok'], ['facebook', 'facebook'], ['fb', 'facebook'], ['instagram', 'instagram'], ['ig', 'instagram'], ['snap', 'snapchat'], ['whatsapp', 'whatsapp'], ['google', 'google'], ['bing', 'bing'], ['youtube', 'youtube']]) if (s.includes(k)) return v }
  if (!r) return 'direct'
  if (/google\./.test(r)) return 'google'
  if (/bing\.|duckduckgo/.test(r)) return 'bing'
  if (/tiktok/.test(r)) return 'tiktok'
  if (/facebook|fb\.|messenger/.test(r)) return 'facebook'
  if (/instagram/.test(r)) return 'instagram'
  if (/snapchat/.test(r)) return 'snapchat'
  if (/t\.co\/|twitter|x\.com/.test(r)) return 'x'
  if (/youtube|youtu\.be/.test(r)) return 'youtube'
  if (/whatsapp|wa\.me/.test(r)) return 'whatsapp'
  if (/blogspot\.|blogger\.|\/\/blog(-[a-z]+)?\.oxygen11\.com/.test(r)) return 'blogger'
  if (/oxygen11\.com/.test(r)) { try { return new URL(r).host === location.host ? 'internal' : 'network' } catch { return 'network' } }
  return 'other'
}

function pageOf(ref, standalone, q) {
  if (q.get('via') || /\/\/blog(-[a-z]+)?\.oxygen11\.com|blogspot\./.test(ref || '')) return 'blogger'
  if (q.get('lp') || /\/(lp|landing)\//.test(location.pathname)) return 'landing'
  if (standalone) return 'webapp'
  const art = ls.get('mq_last_page')
  if (art && art.at && Date.now() - art.at < 6 * 3600e3) return art.sub ? 'sub_article' : art.kind === 'p' ? 'page' : 'article'
  return /\/app\/?$/.test(location.pathname) ? 'app' : 'home'
}

function snapshot() {
  if (typeof window === 'undefined') return {}
  const q = new URLSearchParams(location.search)
  const ref = document.referrer || ''
  const standalone = (() => { try { return matchMedia('(display-mode: standalone)').matches || navigator.standalone === true } catch { return false } })()
  const art = ls.get('mq_last_page')
  const freshArt = art && art.at && Date.now() - art.at < 6 * 3600e3 ? art : null
  const via = viaBrand(), src = viaSource()
  const utm = {}; for (const k of ['source', 'medium', 'campaign', 'term', 'content']) { const v = q.get('utm_' + k); if (v) utm[k] = v.slice(0, 80) }
  return {
    site: via || NET.id || '', host: location.host, page: pageOf(ref, standalone, q), channel: channelOf(ref, q),
    code: freshArt?.code || (via ? '#' + via : ''), title: freshArt?.title || src || '', url: freshArt?.url || (ref && !/oxygen11\.com\/app/.test(ref) ? ref : ''),
    entry: location.href.split('#')[0], referrer: ref, utm, gclid: q.get('gclid') || '', pwa: standalone, at: new Date().toISOString(),
  }
}

// Captured once per page load; the first visit on this device is remembered for good.
let cur = null
export function captureOrigin() {
  if (cur) return cur
  cur = snapshot()
  if (!ls.get(FIRST)) ls.set(FIRST, cur)
  // an internal hop (app → app) must not overwrite where he really came from
  const last = ls.get(LAST)
  if (!last || cur.channel !== 'internal' || cur.code) ls.set(LAST, cur)
  return cur
}
captureOrigin()

/** The origin to put on an order: this visit (or the last real one), with the first visit attached. */
export function orderOrigin() {
  const now = captureOrigin(), last = ls.get(LAST) || now, first = ls.get(FIRST)
  const o = now.channel === 'internal' && !now.code ? { ...last, host: now.host, entry: now.entry } : now
  return { ...o, first: first && first.at !== o.at ? { site: first.site, host: first.host, page: first.page, channel: first.channel, code: first.code, title: first.title, url: first.url, referrer: first.referrer, at: first.at } : undefined }
}

export const PAGE_LABEL = { home: 'الصفحة الرئيسية', app: 'التطبيق', article: 'مقالة', sub_article: 'مقالة فرعية', page: 'صفحة', blogger: 'مدونة بلوجر', landing: 'صفحة هبوط', webapp: 'الويب آب المثبّت', whatsapp: 'واتساب', manual: 'إدخال يدوي', other: 'غير معروف' }
export const CHANNEL_LABEL = { google: 'بحث جوجل', google_ads: 'إعلان جوجل', bing: 'بحث بينج', tiktok: 'تيك توك', facebook: 'فيسبوك', instagram: 'إنستجرام', snapchat: 'سناب شات', x: 'إكس', youtube: 'يوتيوب', whatsapp: 'واتساب', blogger: 'بلوجر', network: 'موقع من الشبكة', direct: 'دخول مباشر', internal: 'من داخل الموقع', manual: 'يدوي', other: 'مصدر آخر' }
// any «#site/a26» code inside a text, for clickable chips
export const CODE_RE = /#[a-z][a-z0-9]{1,23}\/[apb]\d{1,20}/gi
