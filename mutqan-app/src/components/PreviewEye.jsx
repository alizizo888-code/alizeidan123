import { Eye } from 'lucide-react'

// «معاينة» (owner, 2026-11-24): after a change in the settings — a bank switched off, an account added, an order
// edited — one tap opens what the CUSTOMER sees now, in a new tab, so the change is checked with his own eyes.
export const customerUrl = (hash) => `${(typeof window !== 'undefined' && window.__MUTQAN__?.app) || '/app/'}#${hash}`

export default function PreviewEye({ url, label = 'معاينة', title = 'افتح اللي العميل بيشوفه دلوقتي', className = '' }) {
  if (!url) return null
  return (
    <a href={url} target="_blank" rel="noopener noreferrer" title={title} data-preview
      className={`shrink-0 inline-flex items-center gap-1.5 bg-sky-500/15 border border-sky-400/30 text-sky-300 rounded-xl px-3 py-2 text-xs font-black hover:bg-sky-500/25 ${className}`}>
      <Eye size={14} /> {label}
    </a>
  )
}
