// Taps inside the assistant's answers: an order, a search result, a page. Internal links («#/staff/order/…») open in the
// app itself; the drawer closes so the page is in view.
import { useNavigate } from 'react-router-dom'
import { ExternalLink } from 'lucide-react'

export default function ChatLinks({ links, onGo }) {
  const nav = useNavigate()
  const list = (Array.isArray(links) ? links : []).filter((l) => l && l.href)
  if (!list.length) return null
  return (
    <div className="mt-2 grid gap-1.5">
      {list.slice(0, 12).map((l) => (
        <button key={l.href + l.label} onClick={() => { const h = String(l.href); if (h.startsWith('#/')) { nav(h.slice(1)); onGo?.() } else { window.location.href = h } }}
          className="text-right flex items-center gap-1.5 rounded-xl bg-sky-400/10 border border-sky-400/30 hover:bg-sky-400/20 px-2.5 py-1.5 text-[12.5px] font-bold text-sky-100">
          <ExternalLink size={13} className="shrink-0" /><span className="truncate">{l.label}</span>
        </button>
      ))}
    </div>
  )
}
