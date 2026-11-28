import { MapPin, Clock, Wrench, Zap } from 'lucide-react'
import StatusBadge from './StatusBadge'
import { ago } from '../lib/ago'
import { isInhouseActive } from '../hooks/useOpsMode'
import { SPECIALTIES } from '../constants'
import { placeText } from '../lib/orderNo'

function timeAgo(iso) { return ago(iso) }

export default function OrderCard({ order, onClick, showClient = false, dark = false }) {
  const spec = SPECIALTIES.find((s) => s.id === order.specialtyId) || { icon: '🔧', label: order.specialtyId || 'خدمة' }
  return (
    <button
      onClick={onClick}
      className={`w-full text-right rounded-2xl p-4 flex flex-col gap-2.5 transition-all active:scale-[0.99] ${
        dark
          ? 'bg-white/[0.04] hover:bg-white/[0.07] border border-white/10'
          : 'bg-white hover:shadow-md border border-black/5 shadow-sm'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2 min-w-0">
          <span className="text-xl shrink-0">{spec.icon}</span>
          <div className="min-w-0">
            <p className={`font-display font-bold text-sm truncate ${dark ? 'text-white' : 'text-slate-900'}`}>{spec.label}</p>
            {showClient && <p className={`text-xs truncate ${dark ? 'text-slate-400' : 'text-slate-500'}`}>{order.clientName}</p>}
          </div>
        </div>
        <StatusBadge status={order.status} size="sm" dark={dark} inhouse={isInhouseActive(order)} />
      </div>
      {order.description && <p className={`text-xs line-clamp-2 ${dark ? 'text-slate-400' : 'text-slate-500'}`}>{order.description}</p>}
      <div className={`flex items-center gap-3 text-[11px] flex-wrap ${dark ? 'text-slate-500' : 'text-slate-400'}`}>
        <span className="flex items-center gap-1"><MapPin size={12} /> {placeText(order).slice(0, 28)}</span>
        <span className="flex items-center gap-1"><Clock size={12} /> {timeAgo(order.createdAt)}</span>
        {order.priority === 'urgent' && (
          <span className={`flex items-center gap-1 font-bold ${dark ? 'text-rose-400' : 'text-rose-600'}`}><Zap size={12} /> عاجل</span>
        )}
        {order.technicianName && (
          <span className={`flex items-center gap-1 ${dark ? 'text-slate-400' : 'text-slate-500'}`}><Wrench size={12} /> {order.technicianName}</span>
        )}
      </div>
    </button>
  )
}
