// Long-press menu (owner, 2026-11-22): press and hold any order or number tile — anywhere in the staff app — and a menu
// of what can be done with it opens: open, open in a new page, details (its data, when it last changed, what the number
// means), send / share on WhatsApp, forward to a technician, ask the assistant about it, delete (owner).
// A page opts in by marking the element: data-order-id="<id>" on an order, data-tile="<list kind or path>" plus
// data-tile-label / data-tile-value on a number tile. Right-click on a computer does the same.
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { X, ExternalLink, Info, Send, Share2, UserCog, Sparkles, Trash2, Copy, Phone, MapPin, ChevronLeft } from 'lucide-react'
import { db } from '../lib/db'
import { openAssistant } from '../lib/voice'
import { owner } from '../lib/ownerApi'
import { STATUS_META } from '../constants'
import { orderNo } from '../lib/orderNo'

const HOLD_MS = 550
const TILE_HELP = {
  unassigned: 'طلبات جديدة لسه محدش اتسند لها — التوزيع من لوحة التوجيه أو من صفحة الطلب.',
  running: 'طلبات الفني قبلها أو في الطريق أو بيشتغل فيها دلوقتي.',
  assigned: 'طلبات اتسندت لفني ولسه ما قبلهاش أو ما بدأش.',
  doneToday: 'الطلبات اللي اكتملت النهارده.',
  failed: 'اتلغت النهارده أو فيها تصعيد ولسه ما اتقفلتش.',
  techsOn: 'الفنيين المتاحين لاستلام شغل دلوقتي من إجمالي الفنيين.',
  revToday: 'مجموع أسعار الطلبات اللي اكتملت النهارده.',
  revMonth: 'مجموع أسعار الطلبات اللي اكتملت الشهر ده.',
  today: 'كل الطلبات اللي اتسجلت النهارده.',
  rated: 'متوسط تقييمات العملاء على الطلبات المكتملة.',
  cancelled: 'نسبة الطلبات الملغية من الكل.',
  unpaid: 'الطلبات المكتملة اللي لسه مادفعتش.',
}
const when = (iso) => { if (!iso) return '—'; const d = new Date(iso); return isNaN(d) ? '—' : d.toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' }) }
const ago = (iso) => { const m = iso ? Math.round((Date.now() - new Date(iso).getTime()) / 60000) : null; if (m == null || isNaN(m)) return ''; return m < 1 ? 'دلوقتي' : m < 60 ? `من ${m} د` : m < 1440 ? `من ${Math.round(m / 60)} س` : `من ${Math.round(m / 1440)} يوم` }
const waLink = (phone, text) => `https://wa.me/${String(phone || '').replace(/\D/g, '').replace(/^0/, '966')}?text=${encodeURIComponent(text)}`
const orderText = (o) => [`طلب #${o.number || orderNo(o)}`, o.serviceLabel || o.specialtyId, o.clientName && `العميل: ${o.clientName}`, o.address && `العنوان: ${o.address}`, o.lat && `الموقع: https://maps.google.com/?q=${o.lat},${o.lng}`, (o.appointmentAt || o.preferredTime) && `الموعد: ${when(o.appointmentAt) !== '—' ? when(o.appointmentAt) : o.preferredTime}`].filter(Boolean).join('\n')

export function useHoldTarget(onHold) {
  useEffect(() => {
    let timer = null; let start = null; let fired = false
    const target = (el) => el && el.closest && el.closest('[data-order-id],[data-tile]')
    const down = (e) => {
      const t = target(e.target); if (!t) return
      fired = false; start = { x: e.clientX, y: e.clientY }
      clearTimeout(timer)
      timer = setTimeout(() => { fired = true; try { navigator.vibrate?.(30) } catch { /* ignore */ } onHold(t) }, HOLD_MS)
    }
    const move = (e) => { if (start && (Math.abs(e.clientX - start.x) > 10 || Math.abs(e.clientY - start.y) > 10)) { clearTimeout(timer); start = null } }
    const up = () => { clearTimeout(timer); start = null }
    // the click that ends a long press must not also open the item
    const click = (e) => { if (fired) { e.preventDefault(); e.stopPropagation(); fired = false } }
    const ctx = (e) => { const t = target(e.target); if (t) { e.preventDefault(); clearTimeout(timer); onHold(t) } }
    document.addEventListener('pointerdown', down, true)
    document.addEventListener('pointermove', move, true)
    document.addEventListener('pointerup', up, true)
    document.addEventListener('pointercancel', up, true)
    document.addEventListener('click', click, true)
    document.addEventListener('contextmenu', ctx, true)
    return () => {
      clearTimeout(timer)
      document.removeEventListener('pointerdown', down, true); document.removeEventListener('pointermove', move, true)
      document.removeEventListener('pointerup', up, true); document.removeEventListener('pointercancel', up, true)
      document.removeEventListener('click', click, true); document.removeEventListener('contextmenu', ctx, true)
    }
  }, [onHold])
}

export default function LongPressMenu({ role }) {
  const nav = useNavigate()
  const [it, setIt] = useState(null)        // {kind:'order', id, o} | {kind:'tile', to, label, value}
  const [view, setView] = useState('menu')
  const [msg, setMsg] = useState('')
  const hold = useRef((el) => {
    setView('menu'); setMsg('')
    if (el.dataset.orderId) {
      const id = el.dataset.orderId
      setIt({ kind: 'order', id, o: null })
      db.selectShared('orders', { id }, { limit: 1 }).then((r) => { if (Array.isArray(r) && r[0]) setIt((x) => (x && x.id === id ? { ...x, o: r[0] } : x)) }).catch(() => {})
    } else {
      setIt({ kind: 'tile', to: el.dataset.tile, label: el.dataset.tileLabel || el.innerText.split('\n').slice(-1)[0], value: el.dataset.tileValue || el.innerText.split('\n')[0] })
    }
  }).current
  useHoldTarget(hold)
  if (!it) return null
  const close = () => setIt(null)
  const isOwner = role === 'owner'
  const go = (path) => { close(); nav(path) }
  const newPage = (path) => { close(); window.open(window.location.href.split('#')[0] + '#' + path, '_blank') }
  const copy = async (t) => { try { await navigator.clipboard.writeText(t); setMsg('اتنسخ ✓') } catch { setMsg('مقدرتش أنسخ') } }

  let title = ''; let actions = []; let details = []
  if (it.kind === 'order') {
    const o = it.o || { id: it.id }
    const path = `/staff/order/${it.id}`
    const st = STATUS_META[o.status] || {}
    title = `طلب #${o.number || String(it.id).slice(-6)}${o.clientName ? ' · ' + o.clientName : ''}`
    details = [
      ['الحالة', st.label || o.status || '—'], ['الخدمة', o.serviceLabel || o.specialtyId || '—'], ['العميل', o.clientName || '—'],
      ['الجوال', o.clientPhone || '—'], ['العنوان', o.address || '—'], ['الفني', o.technicianName || 'لسه'],
      ['الموعد', o.appointmentAt ? when(o.appointmentAt) : (o.preferredTime || '—')], ['السعر', o.price ? `${o.price} ر.س` : '—'],
      ['اتسجل', `${when(o.createdAt)} ${ago(o.createdAt)}`], ['آخر تغيير', `${when(o.updatedAt)} ${ago(o.updatedAt)}`],
      ['المصدر', o.brand || o.source || '—'],
      ...((o.statusHistory || []).slice(-5).reverse().map((h) => [`↳ ${(STATUS_META[h.status] || {}).label || h.status}`, `${when(h.at)}${h.by ? ' · ' + h.by : ''}`])),
    ]
    actions = [
      ['فتح الطلب', ExternalLink, () => go(path)],
      ['فتح في صفحة جديدة', ExternalLink, () => newPage(path)],
      ['التفاصيل والسجل', Info, () => setView('details')],
      o.clientPhone && ['إرسال للعميل واتساب', Send, () => { window.open(waLink(o.clientPhone, `أهلًا ${o.clientName || ''} 🌷\n${orderText(o)}`), '_blank'); close() }],
      o.clientPhone && ['اتصال بالعميل', Phone, () => { window.location.href = `tel:${o.clientPhone}`; close() }],
      o.lat && ['فتح الموقع على الخريطة', MapPin, () => { window.open(`https://maps.google.com/?q=${o.lat},${o.lng}`, '_blank'); close() }],
      ['مشاركة / توجيه على واتساب', Share2, () => { window.open(`https://wa.me/?text=${encodeURIComponent(orderText(o))}`, '_blank'); close() }],
      ['تحويل لفني / إسناد', UserCog, () => go(`${path}?assign=1`)],
      ['نسخ بيانات الطلب', Copy, () => copy(orderText(o))],
      ['اسأل المساعد عنه', Sparkles, () => { close(); openAssistant({ ask: `عايز أعرف عن الطلب #${o.number || String(it.id).slice(-6)}:\n${orderText(o)}\nالحالة: ${st.label || o.status || ''}` }) }],
      isOwner && ['حذف الطلب', Trash2, async () => { if (!window.confirm('حذف الطلب ده نهائيًا؟')) return; try { await owner.deleteOrder(it.id); setMsg('اتحذف ✓'); setTimeout(close, 700) } catch (e) { setMsg(e?.message || 'تعذر الحذف') } }, true],
    ].filter(Boolean)
  } else {
    const path = (it.to || '').startsWith('/') ? it.to : `/staff/admin/list/${it.to}`
    const help = TILE_HELP[it.to] || ''
    title = `${it.label} — ${it.value}`
    details = [['الرقم', it.value], ['معناه', help || '—'], ['آخر تحديث', 'لحظي — بيتحدث مع البيانات']]
    actions = [
      ['فتح القائمة', ExternalLink, () => go(path)],
      ['فتح في صفحة جديدة', ExternalLink, () => newPage(path)],
      ['الشرح والتفاصيل', Info, () => setView('details')],
      ['اسأل المساعد عنه', Sparkles, () => { close(); openAssistant({ ask: `اشرحلي رقم «${it.label}» (${it.value}) النهارده وإيه اللي وراه وإيه المطلوب مني.` }) }],
      ['مشاركة على واتساب', Share2, () => { window.open(`https://wa.me/?text=${encodeURIComponent(`${it.label}: ${it.value}`)}`, '_blank'); close() }],
    ]
  }

  return (
    <div className="fixed inset-0 z-[200] flex items-end sm:items-center justify-center bg-black/60" dir="rtl" onClick={close}>
      <div className="w-full sm:max-w-md max-h-[85vh] overflow-y-auto bg-[#0f1526] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2 mb-3">
          {view === 'details' && <button onClick={() => setView('menu')} className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center"><ChevronLeft size={16} className="text-white rotate-180" /></button>}
          <p className="flex-1 font-black text-white text-sm truncate">{title}</p>
          <button onClick={close} aria-label="إغلاق" className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center"><X size={16} className="text-white" /></button>
        </div>
        {view === 'menu' ? (
          <div className="grid gap-1.5">
            {actions.map(([label, I, run, danger]) => (
              <button key={label} onClick={run} className={`flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-bold text-right ${danger ? 'bg-rose-600/20 text-rose-200' : 'bg-white/5 text-white hover:bg-white/10'}`}><I size={17} />{label}</button>
            ))}
          </div>
        ) : (
          <div className="grid gap-1">
            {it.kind === 'order' && !it.o && <p className="text-xs text-slate-400">جارِ التحميل…</p>}
            {details.map(([k, v], i) => <div key={k + i} className="flex gap-3 text-xs border-b border-white/5 py-1.5"><span className="text-slate-400 w-24 shrink-0">{k}</span><span className="text-white flex-1 break-words">{v}</span></div>)}
          </div>
        )}
        {msg && <p className="text-xs text-emerald-300 mt-2">{msg}</p>}
      </div>
    </div>
  )
}
