import { DISPATCH_META, dispatchOf } from './helpers'

// Where the order went: «فني الموقع» (built-in site technician) · team technician · «واتساب مباشر» · «جهة خارجية» (+ the party's name).
export default function DispatchBadge({ order, className = '' }) {
  const k = dispatchOf(order); if (!k) return null
  const m = DISPATCH_META[k]
  const extra = k === 'external' && order.externalParty?.name ? ` · ${order.externalParty.name}` : k === 'tech' && order.technicianName ? ` · ${order.technicianName}` : ''
  return <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black whitespace-nowrap ${m.cls} ${className}`}>{m.icon} {m.label}{extra}</span>
}
