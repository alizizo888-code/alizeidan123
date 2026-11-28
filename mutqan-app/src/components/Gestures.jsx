import { useEffect, useRef, useState } from 'react'
import { installWaLinks } from '../lib/waOpen'

// Phone gestures (owner order 2026-11-22), the way phones behave:
//  - <SwipeItem>: drag a row right or left to dismiss it; press and hold to open its actions.
//  - <ActionSheet>: the actions — a bottom sheet on a phone, a small dialog on a tablet / computer.
//  - <SwipeToClose>: mounted once; any open panel (a sheet, a pop-up, a menu) closes when it is swiped sideways,
//    exactly as tapping outside it does. Scrolling inside it, maps, sliders and text fields are left alone.

// left / right (owner, 2026-11-22): what each side does, shown under the row while it is dragged —
// e.g. left = { icon: '🗑', label: 'حذف', danger: true }, right = { icon: '✓', label: 'فتح' }.
export function SwipeItem({ children, onSwipe, onLongPress, className = '', threshold = 90, left = null, right = null }) {
  const ref = useRef(null)
  const st = useRef(null)
  const [dx, setDx] = useState(0)
  const [leaving, setLeaving] = useState(0)
  const timer = useRef(null)
  const cancelHold = () => { if (timer.current) { clearTimeout(timer.current); timer.current = null } }
  function down(e) {
    const p = e.touches ? e.touches[0] : e
    st.current = { x: p.clientX, y: p.clientY, moved: false, held: false }
    cancelHold()
    if (onLongPress) timer.current = setTimeout(() => { if (st.current && !st.current.moved) { st.current.held = true; try { navigator.vibrate?.(30) } catch { /* no vibration */ } onLongPress() } }, 520)
  }
  function move(e) {
    if (!st.current) return
    const p = e.touches ? e.touches[0] : e
    const x = p.clientX - st.current.x; const y = p.clientY - st.current.y
    if (Math.abs(x) > 8 || Math.abs(y) > 8) { st.current.moved = true; cancelHold() }
    if (onSwipe && Math.abs(x) > Math.abs(y) * 1.4) setDx(x)
  }
  function up() {
    cancelHold()
    if (!st.current) return
    const held = st.current.held
    st.current = null
    if (onSwipe && Math.abs(dx) > threshold) { setLeaving(dx > 0 ? 1 : -1); setTimeout(() => onSwipe(dx > 0 ? 'right' : 'left'), 180) } else setDx(0)
    if (held) { const stop = (ev) => { ev.stopPropagation(); ev.preventDefault() }; ref.current?.addEventListener('click', stop, { capture: true, once: true }) }
  }
  useEffect(() => cancelHold, [])
  const tx = leaving ? leaving * window.innerWidth : dx
  const side = dx < 0 ? left : dx > 0 ? right : null
  const ready = Math.abs(dx) > threshold
  const inner = (
    <div ref={ref} className={side ? '' : className}
      style={{ transform: `translateX(${tx}px)`, opacity: leaving ? 0 : side ? 1 : 1 - Math.min(0.6, Math.abs(dx) / 400), transition: st.current ? 'none' : 'transform .18s ease, opacity .18s ease', touchAction: 'pan-y', position: 'relative' }}
      onTouchStart={down} onTouchMove={move} onTouchEnd={up} onTouchCancel={up}
      onContextMenu={(e) => { if (onLongPress) { e.preventDefault(); onLongPress() } }}>
      {children}
    </div>
  )
  if (!left && !right) return inner
  return (
    <div className={`relative ${className}`}>
      {side && (
        <div className={`absolute inset-0 rounded-2xl flex items-center px-4 gap-2 text-white font-black text-sm ${dx < 0 ? 'justify-end' : 'justify-start'}`} dir="rtl"
          style={{ background: side.danger ? (ready ? '#e11d48' : '#9f1239') : (ready ? '#059669' : '#065f46') }}>
          <span className={`text-xl transition-transform ${ready ? 'scale-125' : ''}`}>{side.icon}</span><span>{side.label}</span>
        </div>
      )}
      {inner}
    </div>
  )
}

export function ActionSheet({ title, actions, onClose }) {
  return (
    <div className="fixed inset-0 z-[90] bg-black/60 flex items-end md:items-center justify-center" dir="rtl" onClick={onClose}>
      <div className="w-full md:max-w-sm bg-white dark:bg-[#0d1220] text-slate-900 dark:text-white rounded-t-3xl md:rounded-3xl p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="w-10 h-1.5 rounded-full bg-slate-300 dark:bg-white/20 mx-auto mb-2 md:hidden" />
        {title && <p className="text-xs font-black text-slate-500 dark:text-slate-400 px-2 pb-2 truncate">{title}</p>}
        <div className="grid gap-1">
          {actions.filter(Boolean).map((a) => (
            <button key={a.label} onClick={() => { onClose(); a.run() }} className={`w-full text-right rounded-xl px-3 py-3 text-sm font-bold flex items-center gap-2 hover:bg-slate-100 dark:hover:bg-white/5 ${a.danger ? 'text-rose-600 dark:text-rose-400' : ''}`}>
              <span className="w-6 text-center">{a.icon}</span>{a.label}
            </button>
          ))}
          <button onClick={onClose} className="w-full rounded-xl px-3 py-3 text-sm font-black bg-slate-100 dark:bg-white/10 mt-1">إلغاء</button>
        </div>
      </div>
    </div>
  )
}

const SKIP = 'input,textarea,select,[contenteditable="true"],.leaflet-container,[data-no-swipe],input[type=range],audio,video,canvas'
function scrollsX(el, stop) {
  for (let n = el; n && n !== stop; n = n.parentElement) {
    if (n.scrollWidth > n.clientWidth + 4) { const o = getComputedStyle(n).overflowX; if (o === 'auto' || o === 'scroll') return true }
  }
  return false
}
// The overlay is the full-screen fixed layer whose own click closes it (the backdrop pattern used everywhere).
function overlayOf(el) {
  for (let n = el; n && n !== document.body; n = n.parentElement) {
    const cs = getComputedStyle(n)
    if (cs.position !== 'fixed' || n.offsetWidth < window.innerWidth - 2 || n.offsetHeight < window.innerHeight * 0.9 || !n.firstElementChild) continue
    // a dimmed backdrop (semi-transparent dark) above the page — not the app shell itself
    const m = cs.backgroundColor.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/)
    const a = m ? (m[4] === undefined ? 1 : Number(m[4])) : 0
    const dark = m && Number(m[1]) < 60 && Number(m[2]) < 60 && Number(m[3]) < 80
    if (dark && a >= 0.25 && a < 0.97 && (Number(cs.zIndex) || 0) >= 30) return n
  }
  return null
}

export function SwipeToClose() {
  useEffect(() => { installWaLinks() }, [])
  useEffect(() => {
    if (!('ontouchstart' in window)) return undefined
    let s = null
    const start = (e) => {
      if (e.touches.length !== 1) return
      const t = e.target
      if (t.closest(SKIP)) return
      const ov = overlayOf(t)
      if (!ov) return
      const panel = [...ov.children].find((c) => c.contains(t)) || ov.firstElementChild
      if (scrollsX(t, ov)) return
      s = { x: e.touches[0].clientX, y: e.touches[0].clientY, ov, panel, dx: 0 }
    }
    const move = (e) => {
      if (!s) return
      const dx = e.touches[0].clientX - s.x; const dy = e.touches[0].clientY - s.y
      if (Math.abs(dy) > Math.abs(dx) * 1.2 && Math.abs(s.dx) < 10) { s = null; return }
      s.dx = dx
      if (s.panel) { s.panel.style.transition = 'none'; s.panel.style.transform = `translateX(${dx}px)`; s.panel.style.opacity = String(1 - Math.min(0.5, Math.abs(dx) / 500)) }
    }
    const end = () => {
      if (!s) return
      const { dx, ov, panel } = s; s = null
      if (panel) { panel.style.transition = 'transform .18s ease, opacity .18s ease' }
      if (Math.abs(dx) > Math.min(140, window.innerWidth * 0.3)) {
        if (panel) { panel.style.transform = `translateX(${dx > 0 ? '100%' : '-100%'})`; panel.style.opacity = '0' }
        // same as tapping the backdrop; a panel without that keeps its own close button
        setTimeout(() => { ov.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })); setTimeout(() => { if (panel && document.contains(panel)) { panel.style.transform = ''; panel.style.opacity = '' } }, 120) }, 160)
      } else if (panel) { panel.style.transform = ''; panel.style.opacity = '' }
    }
    document.addEventListener('touchstart', start, { passive: true })
    document.addEventListener('touchmove', move, { passive: true })
    document.addEventListener('touchend', end)
    document.addEventListener('touchcancel', end)
    return () => { document.removeEventListener('touchstart', start); document.removeEventListener('touchmove', move); document.removeEventListener('touchend', end); document.removeEventListener('touchcancel', end) }
  }, [])
  return null
}
