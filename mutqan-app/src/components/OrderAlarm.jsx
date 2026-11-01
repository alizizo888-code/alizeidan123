import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Siren, X, BellOff } from 'lucide-react'
import { useNotifySettings } from '../hooks/useNotifySettings'
import { startAlarm, stopAlarm, onAlarm, alarmOn } from '../lib/alarm'

// Staff only. A high-priority alert (new order, urgent order, hotline call, technician asking for contact…) rings every few seconds until it is opened or silenced,
// and shows the order details right on the card so the supervisor can read it without leaving the screen he is on.
export default function OrderAlarm() {
  const navigate = useNavigate()
  const { settings } = useNotifySettings()
  const cfgRef = useRef(settings.sound)
  cfgRef.current = settings.sound
  const [items, setItems] = useState([])
  const [ringing, setRinging] = useState(alarmOn())
  useEffect(() => {
    window.__ALARM_UI__ = true
    const on = (e) => {
      const m = e.detail || {}
      if (m.priority !== 'high' || m.silent) return
      const id = m.id || Math.random().toString(36).slice(2)
      setItems((l) => [{ ...m, id }, ...l.filter((x) => x.id !== id)].slice(0, 4))
      startAlarm(cfgRef.current)
    }
    window.addEventListener('mutqan:push', on)
    const off = onAlarm(setRinging)
    return () => { window.__ALARM_UI__ = false; window.removeEventListener('mutqan:push', on); off(); stopAlarm() }
  }, [])
  if (!items.length) return null
  const open = (m) => { stopAlarm(); setItems((l) => l.filter((x) => x.id !== m.id)); if (m.url) navigate(String(m.url).replace(/^#/, '')) }
  const close = (m) => { setItems((l) => { const n = l.filter((x) => x.id !== m.id); if (!n.length) stopAlarm(); return n }) }
  const closeAll = () => { setItems([]); stopAlarm() }
  // The alarm never takes over the screen: the newest alert is shown in full, older ones shrink to one line, and the whole stack is capped at under half the screen (scrolls inside),
  // so the page's own buttons underneath stay reachable even when several orders arrive together.
  return (
    <div dir="rtl" data-keep-colors className="fixed z-[85] inset-x-0 top-[calc(env(safe-area-inset-top,0px)+3.4rem)] px-3 pointer-events-none">
      <div className="max-w-md w-full mx-auto max-h-[42vh] overflow-y-auto flex flex-col gap-1.5 pointer-events-auto">
        {items.length > 1 && (
          <div className="flex items-center gap-2 rounded-xl bg-rose-800 text-white border border-rose-200/40 px-3 py-1.5 text-[11px] font-black">
            <span className="flex-1">🔔 {items.length} تنبيهات عاجلة</span>
            <button onClick={closeAll} className="underline">إغلاق الكل</button>
          </div>
        )}
        {items.map((m, i) => i > 0 ? (
          <div key={m.id} className="flex items-center gap-2 rounded-xl bg-rose-600 text-white border border-rose-200/60 shadow-lg px-3 py-2 text-xs font-bold">
            <Siren size={14} className="shrink-0" />
            <span className="flex-1 min-w-0 truncate">{m.title}</span>
            {m.url && <button onClick={() => open(m)} className="shrink-0 rounded-lg bg-white text-rose-700 px-2.5 py-1 font-black">فتح</button>}
            <button onClick={() => close(m)} aria-label="إغلاق" className="shrink-0 opacity-80"><X size={14} /></button>
          </div>
        ) : (
          <div key={m.id} className="rounded-2xl bg-rose-600 text-white border border-rose-200/60 shadow-2xl p-3">
            <div className="flex items-start gap-2">
              <Siren size={20} className={'shrink-0 mt-0.5 ' + (ringing ? 'animate-pulse' : '')} />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-black leading-snug">{m.title}</p>
                {m.body && <p className="text-xs mt-1 whitespace-pre-line leading-relaxed opacity-95 max-h-24 overflow-y-auto">{m.body}</p>}
              </div>
              <button onClick={() => close(m)} aria-label="إغلاق" className="shrink-0 opacity-80"><X size={16} /></button>
            </div>
            <div className="flex gap-2 mt-2.5">
              {m.url && <button onClick={() => open(m)} className="flex-1 rounded-xl bg-white text-rose-700 text-sm font-black py-2">فتح</button>}
              {ringing && <button onClick={() => stopAlarm()} className="rounded-xl bg-black/25 text-sm font-bold px-3 py-2 flex items-center gap-1.5"><BellOff size={14} />إسكات</button>}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
