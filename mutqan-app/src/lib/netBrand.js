// The network's runtime face. ONE build serves every site: the server injects window.__MUTQAN__.brand
// from the host name (Oxygen_Network::brand_config), and the app wears whatever name that gives.
//
// It used to work that way only for the satellites, while the two original faces showed the name
// compiled into the bundle. That assumed each of them got a build of its own, so the day the متقن
// build was deployed to oxygen11.com the site called itself متقن — the brand had nowhere to come
// from but the bundle. Now no visible name comes from the bundle: the host decides, and the baked
// words below are the fallback for a dev server or a test, where nothing is injected.
import { BLOG_NAMES } from './blogNames'
const B = (typeof window !== 'undefined' && window.__MUTQAN__ && window.__MUTQAN__.brand) || {}
export const NET = {
  id: B.id || '',
  core: B.core !== false,                       // no injected brand (tests, dev server) = a core site
  name: B.name || '',
  full: B.full || '',
  latin: typeof B.latin === 'string' ? B.latin : '',   // Latin wordmark; only the two original faces have one
  color: /^#[0-9a-f]{6}$/i.test(B.color || '') ? B.color : '',
  phone: String(B.phone || '').replace(/\D/g, ''),
  wa: String(B.wa || '').replace(/\D/g, ''),
  wa2: String(B.wa2 || '').replace(/\D/g, ''),
}
// The word baked into this build: the fallback when no face was injected, and the word the renaming
// below looks for. tools/brand.py rewrites it along with the rest of the source.
const BAKED = 'متقن'
const BAKED_LATIN = 'MUTQAN'

// The name this site shows. The host's face first; the baked word only where nothing was injected.
export let BN = NET.name || BAKED
// Latin wordmark places (header, banner, footer): the two original faces have one, the rest show their Arabic name.
export let BL = NET.latin || NET.name || BAKED_LATIN
export let BFULL = NET.full || `${BAKED} للصيانة والمقاولات العامة`
// Both names together, as the tab title, the splash and the passkey prompt show them.
export const brandPair = (sep = ' ') => (BL && BL !== BN ? BN + sep + BL : BN)
// True when the face injected is not the one compiled in, so visible text still says the wrong name.
export let NET_RENAMED = !!NET.name && NET.name !== BAKED
export const brandId = () => NET.id || 'mutqan'

// Replace the baked brand word inside any data tree of strings (bot scripts, defaults).
export function rebrand(v) {
  if (!NET_RENAMED) return v
  if (typeof v === 'string') return v.replace(/مُتقن|متقن/g, NET.name)
  if (Array.isArray(v)) return v.map(rebrand)
  if (v && typeof v === 'object') { const o = {}; for (const k of Object.keys(v)) o[k] = rebrand(v[k]); return o }
  return v
}

// Colour scales built from the brand colour: lighter steps mix with white, darker with black.
const mix = (h, t, to) => { const n = parseInt(h.slice(1), 16); const c = [(n >> 16) & 255, (n >> 8) & 255, n & 255]; return '#' + c.map((x) => Math.round(x + (to - x) * t).toString(16).padStart(2, '0')).join('') }
export function brandScale(hex) {
  const up = { 50: 0.92, 100: 0.84, 200: 0.68, 300: 0.5, 400: 0.28, 500: 0.1 }
  const down = { 700: 0.18, 800: 0.34, 900: 0.5, 950: 0.64 }
  const s = { 600: hex }
  for (const [k, t] of Object.entries(up)) s[k] = mix(hex, t, 255)
  for (const [k, t] of Object.entries(down)) s[k] = mix(hex, t, 0)
  return s
}
// The baked skin already belongs to the baked face, so only a different face needs its colours built.
export const brandTheme = () => (!NET_RENAMED || !NET.color ? null : { sky: brandScale(NET.color), blue: brandScale(mix(NET.color, 0.25, 0)) })

// Mirror of Oxygen_Network::registry() (tests/mutqan/network.php checks both stay equal). The two core faces are labelled by the build itself.
export const NETWORK = [
  { id: 'oxygen', name: 'أكسجين', color: '#38bdf8', core: true },
  { id: 'mutqan', name: 'مُتقن', color: '#12a39a', core: true },
  { id: 'motakhases', name: 'المتخصص', color: '#1d4ed8' },
  { id: 'muhandis', name: 'المهندس', color: '#0f766e' },
  { id: 'aqsa', name: 'الأقصى', color: '#b45309' },
  { id: 'raad', name: 'رعد السماء', color: '#6d28d9' },
  { id: 'mutamakkin', name: 'المتمكن', color: '#be123c' },
  { id: 'khabeer', name: 'الخبير', color: '#0369a1' },
  { id: 'homeservice', name: 'هوم سيرفيس', color: '#15803d' },
  { id: 'ibtikar', name: 'ابتكار راحة', color: '#c2410c' },
  { id: 'mutamad', name: 'المعتمد', color: '#334155' },
  { id: 'misriya', name: 'المصرية', color: '#a21caf' },
  { id: 'nukhba', name: 'النخبة', color: '#0e7490' },
  { id: 'riyada', name: 'الريادة', color: '#7c3aed' },
  { id: 'baraa', name: 'البراعة', color: '#ca8a04' },
  { id: 'safwa', name: 'الصفوة', color: '#db2777' },
  { id: 'tamayuz', name: 'التميز', color: '#0d9488' },
  { id: 'nasmat', name: 'نسمة الحجاز', color: '#2563eb' },
  { id: 'wahat', name: 'واحة الراحة', color: '#65a30d' },
  { id: 'amaan', name: 'الأمان', color: '#dc2626' },
  { id: 'kafaa', name: 'الكفاءة', color: '#0891b2' },
  { id: 'jawda', name: 'الجودة', color: '#9a3412' },
]
// Blogger-only faces (blog-<id>.oxygen11.com) — mirror of Oxygen_Network::blog_brands(); their orders keep their own tag.
export const BLOG_NETWORK = [
  { id: 'nasim', name: 'نسيم البارد', color: '#0284c7', twin: 'oxygen' },
  { id: 'qimma', name: 'القمة للتبريد', color: '#0e7490', twin: 'mutqan' },
  { id: 'baroda', name: 'برودة', color: '#0369a1', twin: 'motakhases' },
  { id: 'taqa', name: 'الطاقة الآمنة', color: '#ca8a04', twin: 'muhandis' },
  { id: 'nabea', name: 'النبع', color: '#0891b2', twin: 'aqsa' },
  { id: 'hisn', name: 'الحصن', color: '#4d7c0f', twin: 'raad' },
  { id: 'safaa', name: 'صفاء الماء', color: '#2563eb', twin: 'mutamakkin' },
  { id: 'muhtarif', name: 'المحترف', color: '#9333ea', twin: 'khabeer' },
  { id: 'baytak', name: 'بيتك', color: '#16a34a', twin: 'homeservice' },
  { id: 'manakh', name: 'المناخ', color: '#ea580c', twin: 'ibtikar' },
  { id: 'amin', name: 'الأمين', color: '#475569', twin: 'mutamad' },
  { id: 'dar', name: 'الدار', color: '#be185d', twin: 'misriya' },
  { id: 'ayn', name: 'العين الساهرة', color: '#047857', twin: 'nukhba' },
  { id: 'shams', name: 'شمس الحجاز', color: '#d97706', twin: 'riyada' },
  { id: 'lamsa', name: 'لمسة فن', color: '#e11d48', twin: 'baraa' },
  { id: 'naqaa', name: 'النقاء', color: '#0d9488', twin: 'safwa' },
  { id: 'itqan', name: 'الإتقان', color: '#b91c1c', twin: 'tamayuz' },
  { id: 'ishara', name: 'الإشارة', color: '#1e40af', twin: 'nasmat' },
  { id: 'rahhal', name: 'الرحّال', color: '#a16207', twin: 'wahat' },
  { id: 'miftah', name: 'المفتاح الذهبي', color: '#6d28d9', twin: 'amaan' },
  { id: 'idara', name: 'الإدارة الذكية', color: '#1d4ed8', twin: 'kafaa' },
  { id: 'bina', name: 'البناء المتين', color: '#78350f', twin: 'jawda' },
]
// Sites the owner linked later (names and colours from the hub, see registerBrands): new domains show by name too.
const LINKED = []
export function registerBrands(list) {
  for (const b of list || []) if (b && b.id && !NETWORK.some((x) => x.id === b.id) && !LINKED.some((x) => x.id === b.id)) LINKED.push({ id: b.id, name: b.name || b.id, color: b.color || '#64748b', host: b.host || '', linked: true })
}
export const brandMeta = (id) => NETWORK.find((b) => b.id === id) || LINKED.find((b) => b.id === id) || BLOG_NETWORK.find((b) => b.id === id) || null
export const allBrands = () => [...NETWORK, ...LINKED]
// The site an order came from, for staff screens and dispatch texts. Every site files into the one operations
// room, so an order shows ITS site's name — a متقن order on the hub says متقن (no tag = the site's own face).
export const orderBrandName = (o) => { const m = o && o.brand ? brandMeta(o.brand) : null; return m && m.id !== NET.id ? m.name : BN }

// Orders that arrive from a brand's Blogger face land on the hub app as /app/?via=<brand id>#/... ; remember that tag for the session
// so the order is stamped with the brand it really came from (the server only honours it for valid registry ids).
export function viaBrand() {
  if (typeof window === 'undefined') return null
  try {
    const q = new URLSearchParams(window.location.search).get('via') || ''
    if (/^[a-z]{3,20}$/.test(q)) sessionStorage.setItem('mutqan_via', q)
    return sessionStorage.getItem('mutqan_via') || null
  } catch { return null }
}
viaBrand()

// A customer who comes from a Blogger face (?via=<blog>&bn=<its name>) lands on the hub's app; until now the app then
// called itself «أكسجين» / «متقن» and the visitor thought he had been sent somewhere else (owner report 2026-11-22).
// On the hub the app now wears the name of the site he came from, for the whole visit. Orders still file into the hub.
;(function wearVisitorBrand() {
  if (typeof window === 'undefined' || (NET.id && NET.id !== 'oxygen' && NET.id !== 'mutqan')) return
  try {
    const via = viaBrand(); if (!via || via === NET.id) return
    const q = new URLSearchParams(window.location.search).get('bn') || ''
    if (q && /^[\u0600-\u06FF\s\-ـa-zA-Z0-9]{2,30}$/.test(q)) sessionStorage.setItem('mutqan_via_bn', q.trim())
    const m = brandMeta(via) || (BLOG_NAMES[via] ? { id: via, ...BLOG_NAMES[via] } : null)
    const name = (m && m.name) || sessionStorage.getItem('mutqan_via_bn') || ''
    if (!name) return
    NET.name = name; NET.latin = ''; NET.full = `${name} للصيانة والمقاولات العامة`
    if (m && /^#[0-9a-f]{6}$/i.test(m.color || '')) NET.color = m.color
    BN = name; BL = name; BFULL = NET.full; NET_RENAMED = name !== BAKED
    try { document.title = document.title.replace(/أكسجين|متقن|OXYGEN|MUTQAN/g, name) } catch { /* no title */ }
  } catch { /* storage blocked (in a frame): keep the host's face */ }
})()

// The article the customer was reading on a Blogger face (?src=<title>), kept for the session.
export function viaSource() {
  if (typeof window === 'undefined') return ''
  try {
    const q = (new URLSearchParams(window.location.search).get('src') || '').replace(/[#\n\r]/g, ' ').trim().slice(0, 70)
    if (q) sessionStorage.setItem('mutqan_src', q)
    return sessionStorage.getItem('mutqan_src') || ''
  } catch { return '' }
}
viaSource()

// Every WhatsApp link the app opens carries where the customer came from: «بخصوص: <article>» and «#<site>», so the
// line that receives it knows the site and the article (Mutqan_WA_Central::detect / ::source on the server).
export function tagWa(href) {
  try {
    const u = new URL(href, window.location.href)
    if (!/(^|\.)wa\.me$|api\.whatsapp\.com$/.test(u.hostname)) return href
    const via = viaBrand(); const src = viaSource()
    let t = u.searchParams.get('text') || ''
    if (src && t.indexOf('بخصوص:') === -1) t = (t ? t + '\n' : '') + 'بخصوص: ' + src
    if (via && t.indexOf('#' + via) === -1) t = (t ? t + ' ' : '') + '#' + via
    if (!t) return href
    u.searchParams.set('text', t)
    return u.toString()
  } catch { return href }
}
if (typeof window !== 'undefined' && !window.__mqWaTag) {
  window.__mqWaTag = true
  try {
    const open = window.open.bind(window)
    window.open = (url, ...rest) => open(typeof url === 'string' ? tagWa(url) : url, ...rest)
    document.addEventListener('click', (e) => { const a = e.target && e.target.closest && e.target.closest('a[href]'); if (a && /wa\.me|api\.whatsapp\.com/.test(a.getAttribute('href') || '')) a.href = tagWa(a.href) }, true)
  } catch { /* ignore */ }
}
