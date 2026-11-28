import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Share2, Download } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { useTeamRole } from '../hooks/useTeamRole'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { useAssignment } from '../hooks/useAssignment'
import AssignModal from '../components/ops/AssignModal'
import AccessDenied from '../components/AccessDenied'
import OrdersList, { mapUrl, num, appLink } from '../components/ops/OrdersList'
import TechPerformanceTable from '../components/ops/TechPerformance'
import { openWhatsApp, buildDispatchMessage } from '../lib/waOrder'
import { orderFacts } from '../lib/orderFacts'
import { orderBrandName } from '../lib/netBrand'
import { STATUS_META } from '../constants'
import { sourceLabel } from '../components/ops/helpers'

// لوحة الطلبات — the operations list on its own page (also a tab in the operations room).
function csvOf(rows) {
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const head = ['رقم الطلب', 'الموقع/الجهة', 'العميل', 'الجوال', 'الخدمة', 'الوقت المطلوب', 'تاريخ الطلب', 'العنوان', 'اللوكيشن', 'الفني', 'الحالة', 'المصدر', 'تأكيد العميل', 'رسالة واتساب']
  const body = rows.map((o) => { const f = orderFacts(o); return [num(o), orderBrandName(o), o.clientName, o.clientPhone, [f.service, f.kind, f.units].filter(Boolean).join(' — '), f.when, o.createdAt, o.address, mapUrl(o), o.technicianName || '', (STATUS_META[o.status] || {}).label || o.status, sourceLabel(o), o.customerConfirmed ? 'نعم' : 'لا', o.waSent ? 'اتبعتت' : o.waQueued ? 'في الطابور' : 'لا'] })
  return '﻿' + [head, ...body].map((r) => r.map(esc).join(',')).join('\n')
}

export default function OrdersBoard() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { role, isOwner, loading: roleLoading } = useTeamRole()
  const { profiles, settings } = useTechProfiles()
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 300 })
  const { linkTech, assignToSite, siteTech, markForwarded } = useAssignment({ profiles, settings, orders })
  const [assignOrder, setAssignOrder] = useState(null)
  const canAssign = isOwner || role === 'owner' || role === 'operations'
  const allowed = canAssign || role === 'supervisor'
  const live = (orders || []).filter((o) => !['completed', 'closed', 'cancelled'].includes(o.status))
  const exportCsv = () => {
    const blob = new Blob([csvOf(orders || [])], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `orders-${new Date().toISOString().slice(0, 10)}.csv`; a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 4000)
  }
  const shareList = () => openWhatsApp('', [`📋 *لوحة الطلبات — ${live.length} طلب شغال*`, ...live.slice(0, 25).map((o) => { const fx = orderFacts(o); return `#${num(o)} · ${o.clientName || 'عميل'} · ${fx.service}${fx.units ? ' ' + fx.units : ''} · ${fx.when} · ${o.technicianName || 'بدون فني'}${mapUrl(o) ? '\n' + mapUrl(o) : ''}` })].join('\n\n'))

  if (roleLoading) return <div className="h-full flex items-center justify-center text-slate-400 bg-[#090c14]">جارِ التحقق…</div>
  if (!allowed) return <AccessDenied need="المالك ومشرف العمليات والمشرفين" />

  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-7xl mx-auto w-full px-4 py-3 flex items-center gap-2">
          <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="min-w-0 flex-1">
            <p className="text-[11px] text-primary font-bold tracking-widest">العمليات والمشرفين</p>
            <h1 className="font-display text-lg font-black text-white truncate">لوحة الطلبات</h1>
          </div>
          <button onClick={shareList} className="shrink-0 flex items-center gap-1.5 bg-emerald-500 text-black rounded-xl px-3 py-2 text-[11px] font-black"><Share2 size={14} /> <span className="hidden sm:inline">مشاركة القائمة</span></button>
          <button onClick={exportCsv} className="shrink-0 flex items-center gap-1.5 bg-white/10 text-white rounded-xl px-3 py-2 text-[11px] font-black"><Download size={14} /> <span className="hidden sm:inline">تصدير Excel</span></button>
        </div>
      </header>
      <div className="max-w-7xl mx-auto px-4 pt-3">
        <OrdersList orders={orders} profiles={profiles} canAssign={canAssign} onAssign={setAssignOrder} initialFilter={['attention', 'open', 'wait', 'today', 'nomap', 'all'].includes(params.get('f')) ? params.get('f') : 'open'} />
        <TechPerformanceTable profiles={profiles} orders={orders} />
      </div>
      {assignOrder && canAssign && (
        <AssignModal order={assignOrder} profiles={(profiles || []).filter((t) => !t.isSite)} orders={orders} siteTech={siteTech} parties={settings?.externalParties || []}
          onAssign={(o, t) => linkTech(o, t, 'operations')}
          onSite={(o) => assignToSite(o)}
          onWhatsApp={async (o) => { openWhatsApp('', buildDispatchMessage(o, { appUrl: appLink(o) })); await markForwarded(o, 'whatsapp') }}
          onExternal={async (o, party) => { openWhatsApp(party.phone, buildDispatchMessage(o, { appUrl: appLink(o) })); await markForwarded(o, 'external', party) }}
          onClose={() => setAssignOrder(null)} />
      )}
    </div>
  )
}
