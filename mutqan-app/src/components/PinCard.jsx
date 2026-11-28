// The card that opens when a pin on a live map is tapped (owner order 2026-11-20) — the technician's map, the operations
// room's live map and the field map all open the same card: who it is, how he rates and is rated, the job and where it
// is, one-tap buttons (share the location, open it in the phone's GPS, drive there, Waze, call, open the order), and —
// for a returning customer — every earlier visit: where, what, by whom, the report, the warranty.
// The data comes from the server (/field op «pin»), so a technician sees the history of a customer another colleague served,
// but a phone number only on his own job.
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { X, Share2, Navigation, MapPin, Phone, ClipboardList, Loader2, Star, History } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { isNative, openNav } from '../lib/native'
import { maps } from '../lib/maps'
import { STATUS_META } from '../constants'
import { kmText, etaText } from '../lib/eta'

const mapsLink = (lat, lng) => `https://www.google.com/maps?q=${lat},${lng}`
const fmt = (iso) => { try { return iso ? new Date(iso).toLocaleDateString('ar-SA-u-nu-latn', { day: 'numeric', month: 'short', year: 'numeric' }) : '' } catch { return '' } }
const daysAgo = (iso) => { const t = iso ? new Date(iso).getTime() : 0; return t ? Math.max(0, Math.floor((Date.now() - t) / 86400000)) : null }
const KIND = { vip: '⭐ عميل مميز', returning: '🔁 عميل قديم', new: '🆕 عميل جديد' }

/** Open the location in whatever GPS app the phone uses (Android: geo:, iPhone: Apple Maps). */
export function openGps(lat, lng, label = '') {
  const ua = navigator.userAgent || ''
  const url = /iPhone|iPad|iPod/i.test(ua) ? `https://maps.apple.com/?ll=${lat},${lng}&q=${encodeURIComponent(label || 'الموقع')}`
    : /Android/i.test(ua) ? `geo:${lat},${lng}?q=${lat},${lng}(${encodeURIComponent(label || 'الموقع')})`
      : mapsLink(lat, lng)
  try { window.location.href = url } catch { window.open(mapsLink(lat, lng), '_blank', 'noopener') }
}

export async function shareLocation(lat, lng, label = '') {
  const text = `${label ? label + '\n' : ''}📍 ${mapsLink(lat, lng)}`
  try { if (navigator.share) { await navigator.share({ title: label || 'الموقع', text, url: mapsLink(lat, lng) }); return } } catch { return }
  window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
}

const Btn = ({ onClick, href, icon: I, label, tone = 'bg-white/10 text-white' }) => {
  const cls = `flex-1 min-w-[30%] flex flex-col items-center justify-center gap-0.5 rounded-xl py-2 text-[11px] font-black ${tone}`
  return href ? <a href={href} onClick={onClick} target="_blank" rel="noopener noreferrer" className={cls}><I size={16} />{label}</a>
    : <button type="button" onClick={onClick} className={cls}><I size={16} />{label}</button>
}
const Row = ({ k, v }) => (v ? <p className="text-[12px] text-slate-300 leading-6"><span className="text-slate-500">{k}: </span>{v}</p> : null)
const Stars = ({ n }) => (n ? <span className="text-amber-400 font-black">{'★'.repeat(Math.round(n))}<span className="text-slate-500 text-[10px] font-bold"> {n}</span></span> : null)

/**
 * pin: { orderId?, phone?, key?, lat, lng, title?, kind? ('order'|'customer'|'tech'|'shop'), phoneDirect?, extra? }
 * me: { lat, lng } — the viewer's position, for the distance line.
 */
export default function PinCard({ pin, me, onClose }) {
  const nav = useNavigate()
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const lookup = pin && (pin.orderId || pin.phone || pin.key || (pin.kind === 'customer' && pin.lat != null))
  useEffect(() => {
    setD(null); setErr('')
    if (!lookup) return
    let live = true
    post('/field', { op: 'pin', orderId: pin.orderId || '', phone: pin.phone || '', key: pin.key || '', lat: pin.lat, lng: pin.lng })
      .then((r) => { if (live) setD(r) }).catch((e) => { if (live) setErr(errText(e, 'تعذر تحميل الكارت')) })
    return () => { live = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pin?.orderId, pin?.phone, pin?.key, pin?.lat, pin?.lng])
  if (!pin) return null

  const lat = d?.lat ?? pin.lat, lng = d?.lng ?? pin.lng
  const c = d?.customer || {}
  const o = d?.order
  const phone = c.phone || pin.phoneDirect || ''
  const title = c.name || pin.title || 'عميل'
  const dist = me?.lat != null && lat != null ? (() => { try { return maps.distance(me.lat, me.lng, lat, lng) } catch { return null } })() : null
  const st = o ? STATUS_META[o.status] || {} : null
  const ago = daysAgo(c.lastAt)

  return (
    <div className="fixed inset-x-0 md:right-64 bottom-0 z-[1200] max-h-[78vh] overflow-y-auto bg-[#0d1220] border-t border-white/10 rounded-t-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl" dir="rtl" data-pin-card>
      <button onClick={onClose} aria-label="إغلاق" className="absolute left-3 top-3 text-slate-400"><X size={18} /></button>
      <div className="pr-1 pl-7">
        <p className="font-black text-white text-base">{pin.emoji ? pin.emoji + ' ' : ''}{title}
          {c.kind && <span className="text-[11px] font-bold text-sky-300 mr-1.5">{KIND[c.kind] || ''}</span>}</p>
        {(c.techStars || c.gaveStars) && (
          <p className="text-[11px] text-slate-400 mt-0.5 flex flex-wrap gap-x-3">
            {c.techStars ? <span>تقييم الفنيين له: <Stars n={c.techStars} /></span> : null}
            {c.gaveStars ? <span>تقييمه لنا: <Stars n={c.gaveStars} /></span> : null}
          </p>
        )}
        {c.tags?.length > 0 && <div className="flex flex-wrap gap-1 mt-1">{c.tags.map((t) => <span key={t} className="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-slate-300">{t}</span>)}</div>}
        {dist != null && <p className="text-[11px] font-black text-sky-300 mt-1">📍 على بُعد {kmText(dist)} منك · وصول {etaText(dist)}</p>}
        {c.visits > 0 && <p className="text-[11px] text-amber-200 mt-1">🔁 خدمناه {c.visits} {c.visits > 1 ? 'مرات' : 'مرة'} — آخرها {c.lastService}{c.lastTech ? ` مع ${c.lastTech}` : ''}{ago != null ? ` من ${ago} يوم` : ''}</p>}
        {!phone && c.phoneMasked && <p className="text-[10px] text-slate-500 mt-0.5" dir="ltr">☎ {c.phoneMasked}</p>}
      </div>

      {lat != null && (
        <div className="flex flex-wrap gap-1.5 mt-3">
          <Btn icon={Share2} label="مشاركة الموقع" onClick={() => shareLocation(lat, lng, `${title}${o ? ' — ' + o.service : ''}`)} />
          <Btn icon={MapPin} label="فتح بالـGPS" onClick={() => openGps(lat, lng, title)} />
          <Btn icon={Navigation} label="توجّه للموقع" tone="bg-blue-600 text-white" href={maps.navigationUrl(lat, lng, 'google')} onClick={(e) => { if (isNative()) { e.preventDefault(); openNav(lat, lng, 'google') } }} />
          <Btn icon={Navigation} label="Waze" tone="bg-sky-500 text-white" href={maps.navigationUrl(lat, lng, 'waze')} onClick={(e) => { if (isNative()) { e.preventDefault(); openNav(lat, lng, 'waze') } }} />
          {phone && <Btn icon={Phone} label="اتصال" tone="bg-emerald-600 text-white" href={`tel:${String(phone).replace(/[^\d+]/g, '')}`} />}
          {(o?.id || pin.orderId) && <Btn icon={ClipboardList} label="فتح الطلب" tone="bg-primary text-black" onClick={() => { onClose?.(); nav(`/staff/order/${o?.id || pin.orderId}`) }} />}
        </div>
      )}
      {lat != null && <p className="text-[10px] text-slate-500 mt-1.5" dir="ltr">{Number(lat).toFixed(5)}, {Number(lng).toFixed(5)}</p>}

      {pin.extra}
      {lookup && !d && !err && <p className="text-xs text-slate-400 mt-3 flex items-center gap-2"><Loader2 size={13} className="animate-spin" />بيجيب بيانات العميل وسجله…</p>}
      {err && <p className="text-xs text-rose-300 mt-3">{err}</p>}

      {o && (
        <div className="mt-3 rounded-2xl border border-white/10 bg-white/[0.04] p-3 grid gap-0.5">
          <p className="text-sm font-black text-white flex items-center gap-2">#{o.n} · {o.service}
            <span className="text-[10px] font-black rounded-full px-2 py-0.5" style={{ background: (st.color || '#64748b') + '33', color: st.color }}>{st.label || o.status}</span>
            {o.priority === 'urgent' && <span className="text-[10px] text-rose-300">عاجل</span>}</p>
          <Row k="المطلوب" v={o.details} />
          <Row k="العطل" v={o.fault} />
          <Row k="الموعد" v={o.appointmentAt ? fmt(o.appointmentAt) : o.when} />
          <Row k="العنوان" v={o.address} />
          <Row k="الفني" v={o.tech} />
          <Row k="السعر" v={o.price ? `${o.price} ر.س` : ''} />
          <Row k="الموقع اللي طلب منه" v={o.site} />
        </div>
      )}

      {d?.history?.length > 0 && (
        <div className="mt-3 rounded-2xl border border-amber-400/20 bg-amber-500/[0.05] p-3 grid gap-2">
          <p className="text-[12px] font-black text-amber-200 flex items-center gap-1"><History size={13} />سجله معانا ({d.history.length})</p>
          {d.history.map((h) => {
            const hs = STATUS_META[h.status] || {}
            return (
              <div key={h.id} className="rounded-xl bg-black/25 p-2 grid gap-0.5">
                <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                  <b className="text-white">#{h.n}</b><span className="text-slate-200">{h.service}</span><span className="text-slate-500">{fmt(h.at)}</span>
                  <span className="rounded-full px-1.5 text-[10px] font-bold" style={{ background: (hs.color || '#64748b') + '33', color: hs.color }}>{hs.label || h.status}</span>
                  {h.stars ? <span className="text-amber-400 text-[10px] flex items-center gap-0.5"><Star size={10} fill="currentColor" />{h.stars}</span> : null}
                </div>
                <Row k="المطلوب" v={h.details} />
                <Row k="العطل" v={h.fault} />
                <Row k="التقرير" v={h.report} />
                {h.abort && <Row k="تعذّر الإصلاح" v={`${h.abort.reason} — ${h.abort.condition}${h.abort.by ? ` (${h.abort.by})` : ''}`} />}
                <Row k="الفني" v={h.tech} />
                <Row k="السعر" v={h.price ? `${h.price} ر.س${h.paid ? ' (مدفوع)' : ''}` : ''} />
                <Row k="الضمان" v={h.warranty} />
                <Row k="المكان" v={h.address} />
                {h.onClient?.note && <p className="text-[11px] text-slate-300">«{h.onClient.note}» <span className="text-slate-500">— رأي الفني</span></p>}
                <div className="flex gap-1.5 mt-1">
                  <button onClick={() => { onClose?.(); nav(`/staff/order/${h.id}`) }} className="rounded-lg bg-white/10 px-3 py-1 text-[11px] font-bold">فتح الطلب</button>
                  {h.lat != null && <button onClick={() => openGps(h.lat, h.lng, title)} className="rounded-lg bg-white/10 px-3 py-1 text-[11px] font-bold">المكان على الـGPS</button>}
                </div>
              </div>
            )
          })}
        </div>
      )}
      {d && !o && !(d.history?.length) && lookup && <p className="text-xs text-slate-500 mt-3">مفيش سجل سابق للعميل ده.</p>}
    </div>
  )
}
