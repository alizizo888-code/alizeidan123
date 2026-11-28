import { useMemo, useRef, useState } from 'react'
import { MapPin, Share2, UserPlus, Zap } from 'lucide-react'
import LiveFleet from './LiveFleet'
import { openWhatsApp } from '../../lib/waOrder'
import { orderFacts } from '../../lib/orderFacts'
import { orderBrandName } from '../../lib/netBrand'
import { STATUS_META } from '../../constants'
import { DONE, ageText, kmText, rankTechs } from './helpers'
import { assignmentOf, hasMap, mapUrl, num, locationText } from './OrdersList'
import { attentionOf, FLAG_META } from '../../lib/orderWatch'

// The operations room as Ali picked it (template 2, 18 Nov): the orders beside the live map. «الطلبات بس» shows the
// same orders without the map — light on a phone, nothing heavy loads.
export default function MapFirst({ orders, profiles, mode, canAssign, busyId, onAssign, onAuto, onOpenOrder }) {
  const live = useMemo(() => {
    const act = (orders || []).filter((o) => !DONE.includes(o.status) && o.status !== 'cancelled')
    // what needs a person comes first (idea 4), then no technician, then waiting for «قبول»
    const rank = (o) => (attentionOf(o).length ? 0 : 1) * 3 + (!o.technicianId && !o.inhouse ? 0 : o.status === 'assigned' ? 1 : 2)
    return act.sort((a, b) => rank(a) - rank(b) || new Date(b.createdAt) - new Date(a.createdAt))
  }, [orders])
  const waiting = live.filter((o) => !o.technicianId && !o.inhouse).length
  const stuck = live.filter((o) => attentionOf(o).length).length
  const listOnly = mode === 'list'
  // Tapping a card (or its technician) flies the live map to it; on a phone the map is scrolled into view first.
  const [focus, setFocus] = useState(null)
  const mapBox = useRef(null)
  const [sel, setSel] = useState('')
  const go = (f) => {
    setFocus({ ...f, at: Date.now() }); if (f.kind === 'order') setSel(f.id)
    if (mapBox.current && window.innerWidth < 1024) mapBox.current.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const card = (o) => {
    const fx = orderFacts(o)
    const st = STATUS_META[o.status] || {}
    const a = assignmentOf(o)
    const free = !o.technicianId && !o.inhouse
    const near = free && hasMap(o) ? rankTechs(o, profiles, orders).filter((r) => r.ok).slice(0, 2) : []
    return (
      <article key={o.id} data-mapfirst-card onClick={(e) => { if (listOnly || e.target.closest('button,a')) return; go({ kind: 'order', id: o.id, lat: hasMap(o) ? Number(o.lat) : null, lng: hasMap(o) ? Number(o.lng) : null, tech: o.technicianId || '', title: o.clientName || 'عميل', n: num(o), address: o.address || '' }) }}
        className={`rounded-2xl bg-[#121826] p-3 grid gap-1.5 border ${listOnly ? '' : 'cursor-pointer hover:bg-[#172033]'} ${sel === o.id && !listOnly ? 'border-primary ring-2 ring-primary/40' : free ? 'border-rose-400/50' : 'border-white/10'}`}>
        <div className="flex items-center gap-2">
          <button onClick={() => onOpenOrder(o)} className="font-black text-primary" dir="ltr">#{num(o)}</button>
          <span className="flex-1 font-black text-white text-sm truncate">{[fx.service, fx.units].filter(Boolean).join(' ')}</span>
          <span className="text-[10px] font-black rounded-full px-2 py-0.5" style={{ background: (st.color || '#64748b') + '33', color: st.color }}>{free ? 'بدون فني' : st.label || o.status}</span>
        </div>
        {(() => { const why = attentionOf(o); return why.length > 0 && <div className="flex flex-wrap gap-1" data-attention-tags>{why.map((w) => <span key={w.key} title={w.text} className={`rounded-full px-1.5 py-0.5 text-[10px] font-black ${FLAG_META[w.key]?.cls || ''}`}>{FLAG_META[w.key]?.icon} {w.text}</span>)}</div> })()}
        <p className="text-[12px] text-slate-300 truncate">{o.clientName || 'عميل'}{o.clientPhone ? ' · ' + o.clientPhone : ''} · {o.address || (hasMap(o) ? 'لوكيشن على الخريطة' : 'من غير لوكيشن')}</p>
        <p className="text-[11px] text-slate-400">⏰ {fx.when} · صدر من {ageText(o.createdAt)} · {orderBrandName(o)}</p>
        <p className="text-[11px]">
          <span className={o.customerConfirmed ? 'text-emerald-300' : 'text-amber-300'}>{o.customerConfirmed ? '✓ أكد' : '⏳ ما أكدش'}</span>
          <span className="text-slate-600"> · </span>
          <span className={o.waSent ? 'text-emerald-300' : 'text-amber-300'}>{o.waSent ? '✓ واتساب اتبعت' : o.waQueued ? '⏳ واتساب في الطابور' : '⏳ واتساب لسه'}</span>
          {!free && <><span className="text-slate-600"> · </span>{o.technicianId && !listOnly
            ? <button onClick={() => go({ kind: 'tech', id: o.technicianId })} className={`underline decoration-dotted ${a.ok ? 'text-emerald-300' : 'text-amber-300'}`}>👷 {o.technicianName || 'الفني'} — {a.stage}{a.how ? ' (' + a.how + ')' : ''} 🗺️</button>
            : <span className={a.ok ? 'text-emerald-300' : 'text-amber-300'}>👷 {o.technicianName || 'فريق الموقع'} — {a.stage}{a.how ? ' (' + a.how + ')' : ''}</span>}</>}
        </p>
        {near.length > 0 && <p className="text-[11px] text-sky-200">أقرب فني: {near.map((r) => `${r.t.displayName || 'فني'} ${kmText(r.dist)}`).join(' · ')}</p>}
        <div className="flex flex-wrap gap-1.5 mt-0.5">
          {canAssign && ['new', 'forced', 'assigned'].includes(o.status) && !o.inhouse && (
            <button onClick={() => onAssign(o)} className="flex-1 min-w-[96px] h-10 inline-flex items-center justify-center gap-1 rounded-xl bg-primary text-black text-[12px] font-black"><UserPlus size={14} /> {o.technicianId ? 'إعادة توجيه' : near[0] ? 'إسناد' : 'إسناد يدوي'}</button>
          )}
          {canAssign && free && (
            <button onClick={() => onAuto(o)} disabled={busyId === o.id} className="h-10 px-3 inline-flex items-center gap-1 rounded-xl bg-emerald-500 text-black text-[12px] font-black disabled:opacity-50"><Zap size={14} /> آلي</button>
          )}
          {hasMap(o) && <button onClick={() => openWhatsApp('', locationText(o))} className="h-10 px-3 inline-flex items-center gap-1 rounded-xl border border-white/15 text-slate-100 text-[12px] font-bold"><Share2 size={14} /> واتساب</button>}
          {hasMap(o) && <a href={mapUrl(o)} target="_blank" rel="noopener" className="h-10 px-3 inline-flex items-center gap-1 rounded-xl border border-white/15 text-slate-100 text-[12px] font-bold"><MapPin size={14} /> فتح</a>}
        </div>
      </article>
    )
  }

  return (
    <div className="flex flex-wrap gap-3 items-start" data-map-first={listOnly ? 'list' : 'map'}>
      <aside className={`grid gap-2 content-start ${listOnly ? 'w-full sm:grid-cols-2 xl:grid-cols-3' : 'flex-[1_1_340px] max-w-full lg:max-w-[400px] lg:max-h-[78vh] lg:overflow-y-auto'}`}>
        <p className="text-[12px] text-slate-400 font-bold sm:col-span-full">{live.length} طلب شغال · <span className={waiting ? 'text-rose-300' : ''}>{waiting} بدون فني</span> · <span className={stuck ? 'text-rose-300' : ''} data-stuck-count>🚨 {stuck} محتاج تدخل</span>{!listOnly && <span className="text-slate-500 font-normal"> · اضغط على الطلب يوديك لمكانه على الخريطة</span>}</p>
        {live.length === 0 ? <p className="text-slate-500 text-xs text-center py-6 sm:col-span-full">مفيش طلبات شغالة دلوقتي</p> : live.map(card)}
      </aside>
      {!listOnly && (
        <div ref={mapBox} className="flex-[999_1_560px] min-w-0 scroll-mt-2">
          <LiveFleet canAssign={canAssign} onOpenOrder={onOpenOrder} focus={focus} />
        </div>
      )}
    </div>
  )
}
