// The network's runtime face. One build serves all 12 sites: the server injects window.__MUTQAN__.brand from the host name
// (Oxygen_Network::brand_config). The two core sites (متقن / أكسجين) keep the name baked in by tools/brand.py; the 10 satellites
// take name, colour and phone numbers from the server so a single bundle can carry any of them.
const B = (typeof window !== 'undefined' && window.__MUTQAN__ && window.__MUTQAN__.brand) || {}
export const NET = {
  id: B.id || '',
  core: B.core !== false,                       // no injected brand (tests, dev server) = a core site
  name: B.name || '',
  full: B.full || '',
  color: /^#[0-9a-f]{6}$/i.test(B.color || '') ? B.color : '',
  phone: String(B.phone || '').replace(/\D/g, ''),
  wa: String(B.wa || '').replace(/\D/g, ''),
  wa2: String(B.wa2 || '').replace(/\D/g, ''),
}
// «متقن» in the stock build (→ «أكسجين» in the Oxygen build); a satellite shows its own name.
export const BN = NET.core ? 'متقن' : NET.name
// Latin wordmark places (header, banner, footer): the stock build keeps MUTQAN, a satellite shows its Arabic name.
export const BL = NET.core ? 'MUTQAN' : NET.name
export const BFULL = NET.core ? 'متقن للصيانة والمقاولات العامة' : NET.full
export const brandId = () => NET.id || 'mutqan'

// Replace the stock brand word inside any data tree of strings (bot scripts, defaults) for a satellite.
export function rebrand(v) {
  if (NET.core) return v
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
export const brandTheme = () => (NET.core || !NET.color ? null : { sky: brandScale(NET.color), blue: brandScale(mix(NET.color, 0.25, 0)) })

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
export const brandMeta = (id) => NETWORK.find((b) => b.id === id) || null
// The brand an order came from, for staff screens and dispatch texts (no tag = the site's own face).
export const orderBrandName = (o) => { const m = o && o.brand ? brandMeta(o.brand) : null; return m && !m.core ? m.name : BN }
