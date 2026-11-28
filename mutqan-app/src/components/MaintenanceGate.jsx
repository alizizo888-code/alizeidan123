import { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { API_BASE } from '../lib/_api'

// «وضع الصيانة» (owner, 2026-11-24): the customer pages show the pulled-plug picture while it is on. The server answers
// the page itself with it too; this covers the app opened from the phone's cache. Staff screens and the desk are never
// blocked — the owner switches it off from there.
export default function MaintenanceGate() {
  const { pathname } = useLocation()
  const [m, setM] = useState(null)
  const staff = pathname.startsWith('/staff') || (typeof window !== 'undefined' && (window.__DESK_KIND__ || window.__DESK_BRAND__))
  useEffect(() => {
    if (staff) return
    let live = true
    const check = () => fetch(API_BASE + '/maint', { cache: 'no-store' }).then((r) => r.json()).then((j) => live && setM(j && j.on ? j : null)).catch(() => {})
    check(); const t = setInterval(check, 60000)
    return () => { live = false; clearInterval(t) }
  }, [staff])
  if (staff || !m) return null
  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-100 dark:bg-slate-950 p-4" dir="rtl" data-maintenance>
      <style>{`.mq-l{animation:mqpull 3s ease-in-out infinite}.mq-r{animation:mqpush 3s ease-in-out infinite}.mq-z{animation:mqz 3s infinite;opacity:0}
@keyframes mqpull{0%,100%{transform:translateX(0)}45%,70%{transform:translateX(-26px)}}@keyframes mqpush{0%,100%{transform:translateX(0)}45%,70%{transform:translateX(26px)}}
@keyframes mqz{0%,40%{opacity:0}45%,55%{opacity:1}60%,100%{opacity:0}}@media(prefers-reduced-motion:reduce){.mq-l,.mq-r,.mq-z{animation:none}}`}</style>
      <div className="w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 p-7 text-center shadow-xl">
        <svg viewBox="0 0 320 120" className="w-full max-w-[300px] mx-auto" aria-hidden="true">
          <path d="M0 60 H96" stroke="#64748b" strokeWidth="6" fill="none" strokeLinecap="round" />
          <g className="mq-l"><rect x="92" y="38" width="46" height="44" rx="10" fill="#0e7490" /><rect x="138" y="48" width="18" height="6" rx="3" fill="#64748b" /><rect x="138" y="66" width="18" height="6" rx="3" fill="#64748b" /></g>
          <g className="mq-z"><path d="M160 34 l-6 14 h10 l-6 14" stroke="#f59e0b" strokeWidth="4" fill="none" strokeLinecap="round" strokeLinejoin="round" /></g>
          <g className="mq-r"><rect x="180" y="38" width="46" height="44" rx="10" fill="#334155" /><rect x="186" y="50" width="8" height="5" rx="2" fill="#fff" /><rect x="186" y="66" width="8" height="5" rx="2" fill="#fff" /></g>
          <path d="M226 60 H320" stroke="#64748b" strokeWidth="6" fill="none" strokeLinecap="round" />
        </svg>
        <h1 className="text-2xl font-black text-slate-900 dark:text-white mt-3">{m.title || 'الموقع في صيانة سريعة'}</h1>
        <p className="text-slate-600 dark:text-slate-300 leading-loose mt-2 whitespace-pre-line">{m.msg || 'راجعين خلال وقت قصير.'}</p>
      </div>
    </div>
  )
}
