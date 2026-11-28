// «جاي منين»: the site, page, article (with its code), channel and referrer an order came from.
// Codes like #oxygen/a26 stay as they are; tapping one opens the article finder with that code.
import { useNavigate } from 'react-router-dom'
import { Compass, ExternalLink, Search } from 'lucide-react'
import { brandMeta } from '../lib/netBrand'
import { PAGE_LABEL, CHANNEL_LABEL, CODE_RE } from '../lib/origin'

export function CodeChip({ code, className = '' }) {
  const nav = useNavigate()
  if (!code) return null
  return (
    <button type="button" onClick={(e) => { e.stopPropagation(); nav('/staff/sources?q=' + encodeURIComponent(code)) }} title="افتح المقالة الأصلية"
      className={`inline-flex items-center gap-1 rounded-full bg-cyan-500/15 border border-cyan-400/40 px-2 py-0.5 text-[11px] font-black text-cyan-200 font-mono ${className}`} dir="ltr">
      <Search size={11} />{code}
    </button>
  )
}

/** Plain text with every «#site/a26» turned into a chip. */
export function WithCodes({ text }) {
  const t = String(text || ''); const out = []; let last = 0
  for (const m of t.matchAll(CODE_RE)) { out.push(t.slice(last, m.index)); out.push(<CodeChip key={m.index} code={m[0].toLowerCase()} className="mx-0.5" />); last = m.index + m[0].length }
  out.push(t.slice(last))
  return <>{out}</>
}

// Blogger only tells us its home page; the title (?src=) is what finds the article itself.
const rootOnly = (u) => /^https?:\/\/[^/]+\/?$/.test(String(u || ''))
function FindTitle({ title, site }) {
  const nav = useNavigate()
  return <button type="button" onClick={(e) => { e.stopPropagation(); nav('/staff/sources?q=' + encodeURIComponent(title) + (site ? '&brand=' + encodeURIComponent(site) : '')) }} className="inline-flex items-center gap-0.5 text-cyan-300 mr-1"><Search size={11} />دوّر عليها</button>
}

const Row = ({ k, children }) => (children ? <p className="text-xs text-slate-300 leading-6"><span className="text-slate-500">{k}: </span>{children}</p> : null)

export default function OriginCard({ order, compact = false }) {
  const o = order?.origin || {}
  const siteId = o.site || order?.brand || ''
  const site = siteId ? (brandMeta(siteId)?.name || siteId) : ''
  const sent = o.sentFrom && o.host && o.sentFrom !== o.host ? o.sentFrom : ''
  const first = o.first && (o.first.code || o.first.title || o.first.channel) && (o.first.code !== o.code || o.first.channel !== o.channel) ? o.first : null
  const nothing = !o.page && !o.channel && !o.code && !o.title && !site
  if (compact) {
    return (
      <span className="inline-flex flex-wrap items-center gap-1 text-[11px] text-slate-300">
        <Compass size={12} className="text-cyan-300" />{site || 'غير معروف'}{o.page ? ` · ${PAGE_LABEL[o.page] || o.page}` : ''}{o.channel && o.channel !== 'other' ? ` · ${CHANNEL_LABEL[o.channel] || o.channel}` : ''}
        {o.code && o.code.includes('/') && <CodeChip code={o.code} />}
      </span>
    )
  }
  return (
    <div className="rounded-2xl bg-cyan-500/[0.06] border border-cyan-400/25 p-3 grid gap-0.5" dir="rtl">
      <p className="text-sm font-black text-white flex items-center gap-1.5 mb-1"><Compass size={15} className="text-cyan-300" />جاي منين</p>
      {nothing && <p className="text-xs text-slate-400">الطلب ده اتسجل قبل تتبّع المصدر (أو من غير بيانات). الطلبات الجديدة بتسجل الموقع والصفحة والمقالة تلقائيًا.</p>}
      <Row k="الموقع">{site}{o.host ? <span className="text-slate-500" dir="ltr"> ({o.host})</span> : null}</Row>
      <Row k="الصفحة">{o.page ? PAGE_LABEL[o.page] || o.page : ''}{o.platform ? ` — ${o.platform === 'blogger' ? 'بلوجر' : 'ووردبريس'}` : ''}</Row>
      <Row k="المقالة">{o.title || o.code ? <>{o.title}{o.code && o.code.includes('/') ? <> <CodeChip code={o.code} /></> : null}{o.url && !(o.title && rootOnly(o.url)) ? <a href={o.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-cyan-300 mr-1"><ExternalLink size={11} />فتح</a> : null}{o.title && (!o.url || rootOnly(o.url)) ? <FindTitle title={o.title} site={siteId} /> : null}</> : ''}</Row>
      <Row k="دخل من">{o.channel ? CHANNEL_LABEL[o.channel] || o.channel : ''}{o.utm?.campaign ? ` — حملة: ${o.utm.campaign}` : ''}{o.gclid ? ' (إعلان جوجل)' : ''}</Row>
      <Row k="الرابط اللي جه منه">{o.referrer ? <span dir="ltr" className="break-all">{o.referrer}</span> : ''}</Row>
      <Row k="رقم الواتساب">{(() => { const l = String(o.line || order?.waLine || ''); const m = l.match(/^mwc:(\d+)$/); return m ? `مركز الواتساب — حساب رقم ${m[1]}` : l })()}</Row>
      <Row k="اتبعت فعلًا من">{sent ? <span dir="ltr">{sent}</span> : ''}</Row>
      {first && <Row k="أول زيارة">{[brandMeta(first.site)?.name || first.site, PAGE_LABEL[first.page], CHANNEL_LABEL[first.channel], first.title].filter(Boolean).join(' · ')}{first.code && first.code.includes('/') ? <> <CodeChip code={first.code} /></> : null}</Row>}
    </div>
  )
}
