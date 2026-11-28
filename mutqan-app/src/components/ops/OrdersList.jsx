import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { MapPin, Share2, Copy, Send, Phone, Search, UserPlus } from 'lucide-react'
import { openWhatsApp, buildDispatchMessage } from '../../lib/waOrder'
import { orderFacts, fullWhen } from '../../lib/orderFacts'
import { orderBrandName } from '../../lib/netBrand'
import { STATUS_META, ORDER_FLOW } from '../../constants'
import { DONE, ageText, isUnassigned, sourceLabel } from './helpers'
import { attentionOf, FLAG_META, orderTimes, minsText, totalMins } from '../../lib/orderWatch'
import { orderNo } from '../../lib/orderNo'

// The operations list: one row per order with everything operations follows — the customer and what we know of him,
// the time asked and when the order came in, the location (open · share · to the technician · copy), whether the
// customer confirmed and whether his WhatsApp copy went out, how far the assignment got (auto or by hand) and how
// the job is moving.
export const FILTERS = [
  // «محتاج تدخل» (owner, 2026-11-23): only what is stuck — no technician, not confirmed, not accepted, stalled, late, an objection
  ['attention', '🚨 محتاج تدخل', (o) => attentionOf(o).length > 0],
  ['open', 'الشغالة', (o) => !DONE.includes(o.status) && o.status !== 'cancelled'],
  ['wait', 'بانتظار الإسناد', isUnassigned],
  ['today', 'طلبات اليوم', (o) => new Date(o.createdAt).toDateString() === new Date().toDateString()],
  ['nomap', 'من غير لوكيشن', (o) => !hasMap(o) && o.status !== 'cancelled'],
  ['all', 'الكل', () => true],
]
export const hasMap = (o) => o && o.lat !== null && o.lat !== undefined && o.lat !== '' && o.lng !== null && o.lng !== undefined && o.lng !== ''
export const mapUrl = (o) => (hasMap(o) ? `https://maps.google.com/?q=${o.lat},${o.lng}` : '')
export const num = (o) => o.number || orderNo(o)
export const appLink = (o) => { try { return new URL('#/staff/order/' + o.id, window.__MUTQAN__?.app || location.origin + '/app/').toString() } catch { return '' } }

export function locationText(o) {
  const f = orderFacts(o)
  return [
    `📍 *موقع العميل — طلب #${num(o)}* (${orderBrandName(o)})`,
    o.clientName ? `👤 ${o.clientName}${o.clientPhone ? ' — ' + o.clientPhone : ''}` : (o.clientPhone ? `📱 ${o.clientPhone}` : ''),
    `🛠️ ${[f.service, f.kind, f.units].filter(Boolean).join(' — ')}`,
    `⏰ ${f.when}`,
    o.address ? `🏠 ${o.address}` : '',
    mapUrl(o) ? `🗺️ ${mapUrl(o)}` : '',
  ].filter(Boolean).join('\n')
}

const MODE = { backup: 'الفني الاحتياطي', transfer: 'اتحوّل آلي (الفني ما قبلش)', auto: 'آلي', operations: 'يدوي', site: 'فني الموقع', 'site-fallback': 'فني الموقع (احتياطي)', whatsapp: 'من واتساب', hub: 'من واتساب', owner: 'يدوي (المالك)' }
/** How far the assignment got, and how it was made (auto or by hand). */
export function assignmentOf(o) {
  const log = (o.transferLog || []).filter((x) => x && (x.to === 'technician' || x.to === 'whatsapp' || x.to === 'external'))
  const last = log[log.length - 1]
  const how = o.dispatch === 'whatsapp' ? 'اتبعت لفنيين على واتساب' : o.dispatch === 'external' ? 'جهة خارجية' + (o.externalParty?.name ? ': ' + o.externalParty.name : '') : last ? (MODE[last.by] || 'يدوي') : ''
  let stage
  if (o.inhouse) stage = 'مع فريق الموقع'
  else if (!o.technicianId && o.status === 'forced') stage = 'معروض على الفنيين — محدش قبل لسه'
  else if (!o.technicianId) stage = o.dispatch ? 'اتحوّل برا الفريق' : 'لسه ما اتسندش'
  else if (o.status === 'assigned') stage = 'وصل للفني — مستني يقبل'
  else if (DONE.includes(o.status)) stage = 'الفني خلّص'
  else if (o.status === 'cancelled') stage = 'اتلغى'
  else stage = 'الفني قبل وشغال'
  return { how, stage, ok: !!o.technicianId && o.status !== 'assigned' }
}
const yes = (v) => <span className="text-emerald-300 font-black">✓ {v}</span>
const wait = (v) => <span className="text-amber-300 font-bold">⏳ {v}</span>
const no = (v) => <span className="text-rose-300 font-bold">✗ {v}</span>

export default function OrdersList({ orders, profiles, canAssign, onAssign, initialFilter = 'open', compact = false }) {
  const navigate = useNavigate()
  const [f, setF] = useState(initialFilter)
  const [q, setQ] = useState('')
  const [note, setNote] = useState('')
  const rows = useMemo(() => {
    const pick = FILTERS.find((x) => x[0] === f)[2]
    const s = q.trim()
    return (orders || []).filter(pick).filter((o) => !s || [num(o), o.id, o.clientName, o.clientPhone, o.address, o.technicianName, orderBrandName(o)].some((v) => String(v || '').includes(s)))
  }, [orders, f, q])
  const techOf = (o) => (profiles || []).find((t) => t.id === o.technicianId || t.userId === o.technicianId)
  const flash = (t) => { setNote(t); setTimeout(() => setNote(''), 2500) }
  const copy = async (text, what) => { try { await navigator.clipboard.writeText(text); flash(what + ' اتنسخ ✅') } catch { flash('مقدرتش أنسخ — افتح الطلب وانسخ يدوي') } }
  const toTech = (o) => { const t = techOf(o); openWhatsApp(t?.phone || '', buildDispatchMessage(o, { appUrl: appLink(o) })) }

  return (
    <div className="flex flex-col gap-2" data-orders-list>
      <div className="flex items-center gap-2 bg-white/5 border border-white/10 rounded-xl px-3 py-2"><Search size={14} className="text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث برقم الطلب، العميل، الجوال، الحي، الفني، الموقع…" className="flex-1 bg-transparent text-xs text-white outline-none" /></div>
      <div className="flex gap-1.5 overflow-x-auto pb-1">
        {FILTERS.map(([id, label, fn]) => <button key={id} onClick={() => setF(id)} className={`shrink-0 rounded-full px-3 py-1.5 text-[11px] font-bold border ${f === id ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{label} ({(orders || []).filter(fn).length})</button>)}
      </div>
      {note && <p className="text-center text-[12px] font-bold text-emerald-300">{note}</p>}
      <div className="overflow-x-auto rounded-2xl border border-white/10">
        <table className="w-full text-right text-[11px] min-w-[1180px]">
          <thead className="bg-white/[0.06] text-slate-300"><tr>{['العميل', 'الموعد ووقت الصدور', 'الطلب', 'الموقع', 'التأكيد والواتساب', 'الإسناد', 'سير المهمة'].map((h) => <th key={h} className="px-3 py-2 font-black whitespace-nowrap">{h}</th>)}</tr></thead>
          <tbody>
            {rows.map((o) => {
              const fx = orderFacts(o)
              const st = STATUS_META[o.status] || {}
              const a = assignmentOf(o)
              const step = Math.max(0, ORDER_FLOW.indexOf(o.status))
              const pct = o.status === 'cancelled' ? 0 : Math.round((step / (ORDER_FLOW.length - 2)) * 100)
              const lastAt = (o.statusHistory || []).slice(-1)[0]?.at
              const why = attentionOf(o)
              const red = why.some((w) => ['unconfirmed', 'late', 'unassigned'].includes(w.key))
              const clock = orderTimes(o)
              return (
                <tr key={o.id} data-board-row data-order-id={o.id} data-attention={why.map((w) => w.key).join(' ') || undefined} className={`border-t border-white/5 text-slate-200 align-top hover:bg-white/[0.03] ${red ? 'bg-rose-500/[0.07] border-r-4 border-r-rose-500' : why.length ? 'bg-amber-500/[0.05] border-r-4 border-r-amber-400' : ''}`}>
                  <td className="px-3 py-2.5 max-w-[170px]">
                    <p className="font-black text-white truncate">{o.clientName || 'عميل'}</p>
                    {o.clientPhone ? <a href={`tel:${o.clientPhone}`} className="inline-flex items-center gap-1 text-[10px] text-emerald-300" dir="ltr"><Phone size={10} /> {o.clientPhone}</a> : <p className="text-[10px] text-slate-500">من غير رقم لسه</p>}
                    <p className="text-[9px] text-slate-500 mt-0.5">{orderBrandName(o)} · {sourceLabel(o)}</p>
                  </td>
                  <td className="px-3 py-2.5 whitespace-nowrap">
                    <p className="font-bold text-white">{fx.when}</p>
                    <p className="text-[9px] text-slate-500">صدر: {o.createdAt ? fullWhen(o.createdAt) : '—'} · من {ageText(o.createdAt)}</p>
                  </td>
                  <td className="px-3 py-2.5 max-w-[190px]">
                    <button onClick={() => navigate(`/staff/order/${o.id}`)} className="font-black text-primary" dir="ltr">#{num(o)}</button>
                    {why.length > 0 && <div className="flex flex-wrap gap-1 my-1" data-attention-tags>{why.map((w) => <span key={w.key} title={w.text} className={`rounded-full px-1.5 py-0.5 text-[9px] font-black ${FLAG_META[w.key]?.cls || ''}`}>{FLAG_META[w.key]?.icon} {FLAG_META[w.key]?.label}</span>)}</div>}
                    <p className="font-bold text-white">{[fx.service, fx.kind].filter(Boolean).join(' — ')}</p>
                    {fx.units && <p className="text-[10px] text-slate-400">{fx.units}</p>}
                    {fx.note && !compact && <p className="text-[10px] text-slate-500 truncate">{fx.note}</p>}
                  </td>
                  <td className="px-3 py-2.5 max-w-[220px]">
                    <p className="text-[10px] text-slate-300 truncate">{o.address || (hasMap(o) ? 'لوكيشن على الخريطة' : '—')}</p>
                    {hasMap(o) ? (
                      <div className="flex flex-wrap gap-1 mt-1">
                        <a href={mapUrl(o)} target="_blank" rel="noopener" className="inline-flex items-center gap-1 rounded-lg bg-sky-500/20 text-sky-200 px-2 py-1 font-bold"><MapPin size={11} /> فتح</a>
                        <button onClick={() => openWhatsApp('', locationText(o))} className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/20 text-emerald-200 px-2 py-1 font-bold"><Share2 size={11} /> واتساب</button>
                        <button onClick={() => toTech(o)} className="inline-flex items-center gap-1 rounded-lg bg-amber-500/20 text-amber-200 px-2 py-1 font-bold"><Send size={11} /> للفني</button>
                        <button onClick={() => copy(locationText(o), 'اللوكيشن')} className="inline-flex items-center gap-1 rounded-lg bg-white/10 text-slate-200 px-2 py-1 font-bold"><Copy size={11} /> نسخ</button>
                      </div>
                    ) : <p className="text-[10px] text-rose-300 mt-1">لسه ما وصلش لوكيشن — بيتحفظ هنا أول ما العميل يبعته</p>}
                  </td>
                  <td className="px-3 py-2.5 whitespace-nowrap leading-5">
                    <p>العميل: {o.customerConfirmed ? yes('أكد' + (o.confirmedVia === 'whatsapp' ? ' من واتساب' : o.confirmedVia === 'site' ? ' من الموقع' : '')) : wait('لسه ما أكدش')}</p>
                    <p>رسالة واتساب: {o.waSent ? yes('اتبعتت') : o.waQueued ? wait('في الطابور') : o.clientPhone ? no('ما اتبعتتش') : wait('مستني رقمه')}</p>
                  </td>
                  <td className="px-3 py-2.5 whitespace-nowrap">
                    <p className={o.technicianName ? 'font-bold text-white' : 'text-rose-300'}>{o.technicianName || (o.inhouse ? 'فريق الموقع' : 'بدون فني')}</p>
                    <p className={`text-[10px] ${a.ok ? 'text-emerald-300' : 'text-amber-300'}`}>{a.stage}</p>
                    {a.how && <p className="text-[9px] text-slate-500">الإسناد: {a.how}</p>}
                    {canAssign && ['new', 'forced', 'assigned'].includes(o.status) && !o.inhouse && <button onClick={() => onAssign(o)} className="mt-1 inline-flex items-center gap-1 rounded-lg bg-primary text-black font-black px-2.5 py-1"><UserPlus size={11} /> {o.technicianId ? 'إعادة توجيه' : 'إسناد'}</button>}
                  </td>
                  <td className="px-3 py-2.5 whitespace-nowrap min-w-[210px]">
                    <span className="inline-block rounded-full px-2 py-0.5 text-[10px] font-black" style={{ background: (st.color || '#64748b') + '33', color: st.color }}>{st.label || o.status}</span>
                    <div className="mt-1.5 h-1.5 rounded-full bg-white/10 overflow-hidden"><div className="h-full rounded-full" style={{ width: pct + '%', background: st.color || '#64748b' }} /></div>
                    {lastAt && <p className="text-[9px] text-slate-500 mt-1">آخر تحديث من {ageText(lastAt)}</p>}
                    <div className="mt-1 grid grid-cols-5 gap-0.5 text-center" data-order-clock title="من الطلب للتأكيد · للإسناد · لقبول الفني · لوصوله · للإقفال">
                      {clock.map((c) => <div key={c.key} className={`rounded px-0.5 py-0.5 ${c.open ? 'bg-amber-500/15 text-amber-200' : c.mins == null ? 'bg-white/[0.03] text-slate-600' : 'bg-white/[0.06] text-slate-300'}`}><p className="text-[8px] opacity-70">{c.label}</p><p className="text-[9px] font-black" dir="ltr">{minsText(c.mins)}</p></div>)}
                    </div>
                    <p className="text-[9px] text-slate-500 mt-0.5">⏱ الإجمالي: {minsText(totalMins(o))}</p>
                  </td>
                </tr>
              )
            })}
            {rows.length === 0 && <tr><td colSpan={7} className="text-center text-slate-500 py-8">مفيش طلبات هنا</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
