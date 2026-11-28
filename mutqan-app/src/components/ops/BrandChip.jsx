import { brandMeta } from '../../lib/netBrand'

// Which of the network's sites an order came from (hidden for the two core faces unless forced).
export default function BrandChip({ order, always = false, className = '' }) {
  const m = order?.brand ? brandMeta(order.brand) : null
  if (!m || (m.core && !always)) return null
  return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black whitespace-nowrap ${className}`} style={{ background: m.color + '33', color: m.color === '#334155' ? '#cbd5e1' : m.color }}><i className="w-1.5 h-1.5 rounded-full" style={{ background: m.color }} />{m.name}</span>
}
