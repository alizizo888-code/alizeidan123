// Swipe an order (owner, 2026-11-22) — anywhere in the staff app, on every row marked data-order-id:
//   ← to the left: the trash appears beside it; let go over it and the order goes to «سلة المحذوفات» (owner; it can be
//     brought back for 90 days).
//   → to the right: what the order needs next appears — «إسناد» for a new one, «تحويل» for one with a technician,
//     «تأكيد» for one the customer has not confirmed — and letting go opens exactly that.
// A short drag springs back; vertical scrolling is untouched. Press-and-hold / right-click stay the full menu
// (LongPressMenu). Phones and tablets swipe with the finger, computers with the mouse (drag sideways).
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../lib/db'
import { owner } from '../lib/ownerApi'

const THRESHOLD = 110
const SKIP = 'input,textarea,select,button,a,[contenteditable="true"],.leaflet-container,[data-no-swipe]'

/** What a right swipe does with an order: [label, icon, path suffix]. */
export function nextStep(o = {}) {
  const st = String(o.status || 'new')
  if (['new', 'forced', 'inhouse', 'pending'].includes(st) && o.customerConfirmed === false) return ['تأكيد الطلب', '✓', '?confirm=1']
  if (['new', 'forced', 'inhouse', 'pending', 'unassigned'].includes(st)) return ['إسناد لفني', '👷', '?assign=1']
  if (['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(st)) return ['تحويل لفني تاني', '🔁', '?assign=1']
  return ['فتح الطلب', '↗', '']
}

export default function SwipeActions({ role }) {
  const nav = useNavigate()
  const [hint, setHint] = useState(null)   // {rect, dx, label, icon, side}
  const [toast, setToast] = useState('')
  useEffect(() => {
    let s = null
    const status = (id) => s && s.id === id ? s.o : null
    const down = (e) => {
      if (e.button > 0) return
      const row = e.target.closest && e.target.closest('[data-order-id]')
      if (!row || e.target.closest(SKIP)) return
      s = { row, id: row.dataset.orderId, x: e.clientX, y: e.clientY, dx: 0, on: false, o: null }
      db.selectShared('orders', { id: s.id }, { limit: 1 }).then((r) => { if (s && s.id === r?.[0]?.id) s.o = r[0] }).catch(() => {})
    }
    const move = (e) => {
      if (!s) return
      const dx = e.clientX - s.x; const dy = e.clientY - s.y
      if (!s.on) { if (Math.abs(dy) > 12) { s = null; return } if (Math.abs(dx) < 14 || Math.abs(dx) < Math.abs(dy) * 1.5) return; s.on = true }
      s.dx = dx
      s.row.style.transition = 'none'; s.row.style.transform = `translateX(${dx}px)`
      const o = status(s.id) || {}
      const [label, icon] = dx < 0 ? ['سلة المحذوفات', '🗑'] : nextStep(o)
      setHint({ rect: s.row.getBoundingClientRect(), dx, label, icon, side: dx < 0 ? 'left' : 'right', ready: Math.abs(dx) > THRESHOLD, canDelete: role === 'owner' })
    }
    const up = async () => {
      if (!s) return
      const t = s; s = null
      t.row.style.transition = 'transform .18s ease'; t.row.style.transform = ''
      setHint(null)
      if (!t.on || Math.abs(t.dx) < THRESHOLD) return
      // the click that ends a swipe must not also open the row
      const stop = (ev) => { ev.preventDefault(); ev.stopPropagation() }; document.addEventListener('click', stop, { capture: true, once: true }); setTimeout(() => document.removeEventListener('click', stop, { capture: true }), 400)
      const o = t.o || { id: t.id }
      if (t.dx < 0) {
        if (role !== 'owner') { setToast('الحذف للمالك بس'); return }
        t.row.style.opacity = '0.3'
        try { await owner.deleteOrder(t.id); setToast(`اتنقل لسلة المحذوفات ✓ — تقدر ترجّعه من «تحكمات المالك ← سلة المحذوفات»`); t.row.style.display = 'none' } catch (e) { t.row.style.opacity = ''; setToast(e?.message || 'تعذر الحذف') }
        return
      }
      const [, , q] = nextStep(o)
      nav(`/staff/order/${t.id}${q}`)
    }
    document.addEventListener('pointerdown', down, true)
    document.addEventListener('pointermove', move, true)
    document.addEventListener('pointerup', up, true)
    document.addEventListener('pointercancel', up, true)
    return () => { ['pointerdown', 'pointermove', 'pointerup', 'pointercancel'].forEach((ev, i) => document.removeEventListener(ev, [down, move, up, up][i], true)) }
  }, [role])
  useEffect(() => { if (!toast) return; const t = setTimeout(() => setToast(''), 4000); return () => clearTimeout(t) }, [toast])
  return (
    <>
      {hint && (() => {
        const w = Math.min(Math.abs(hint.dx), hint.rect.width)
        const left = hint.side === 'left' ? hint.rect.right - w : hint.rect.left
        const red = hint.side === 'left'
        return (
          <div className="fixed z-[150] pointer-events-none flex items-center justify-center gap-1.5 rounded-xl font-black text-sm text-white overflow-hidden" dir="rtl"
            style={{ top: hint.rect.top, height: hint.rect.height, left, width: w, background: red ? (hint.canDelete ? (hint.ready ? '#e11d48' : '#9f1239') : '#475569') : (hint.ready ? '#059669' : '#065f46'), opacity: Math.min(1, w / 60) }}>
            <span className={`text-xl ${hint.ready ? 'scale-125' : ''} transition-transform`}>{hint.icon}</span>
            {w > 90 && <span className="truncate">{red && !hint.canDelete ? 'الحذف للمالك' : hint.label}</span>}
          </div>
        )
      })()}
      {toast && <div className="fixed bottom-[calc(env(safe-area-inset-bottom,0px)+80px)] inset-x-0 z-[160] flex justify-center px-4" dir="rtl"><p className="max-w-md rounded-2xl bg-slate-900 text-white text-xs font-bold px-4 py-3 shadow-2xl">{toast}</p></div>}
    </>
  )
}
