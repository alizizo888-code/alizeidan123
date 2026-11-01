import { Link2 } from 'lucide-react'
import { KIND_LABEL, LINK_ICON, LINK_LABEL } from '../lib/callbook'
import { seenAgo } from '../lib/crm'

const KIND_CLS = { registered: 'bg-emerald-500/15 text-emerald-300 border-emerald-400/30', linked: 'bg-sky-500/15 text-sky-300 border-sky-400/30', guest: 'bg-amber-500/15 text-amber-300 border-amber-400/30' }
export const historyText = (i) => (i.count > 0 ? `اتصل ${i.count === 1 ? 'مرة واحدة' : i.count === 2 ? 'مرتين' : i.count + ' مرات'} من قبل${i.lastAt ? ' · آخر مرة ' + seenAgo(i.lastAt) : ''}` : 'أول اتصال بالخط الساخن')

// Who is calling: the caller's CODE, his name if known, whether he is registered / linked, and his history with us.
// `compact` = one line for the ringing toast; the full card (with the link button) is shown during the call.
export default function CallerCard({ info, onLink, compact }) {
  if (!info) return null
  const kind = KIND_CLS[info.kind] ? info.kind : 'guest'
  if (compact) {
    return (
      <div className="flex items-center gap-2 flex-wrap text-[11px]">
        <span className="font-mono bg-sky-500/15 text-sky-300 rounded-md px-1.5 py-0.5 font-black">{info.code}</span>
        <span className={`rounded-full border px-2 py-0.5 font-black ${KIND_CLS[kind]}`}>{KIND_LABEL[kind]}</span>
        <span className="text-slate-400">{historyText(info)}</span>
      </div>
    )
  }
  return (
    <div className="rounded-2xl bg-white/[0.06] border border-white/10 p-3 grid gap-2 text-white">
      <div className="flex items-center gap-2 flex-wrap">
        <span className="font-mono text-[12px] bg-sky-500/15 text-sky-300 rounded-md px-2 py-0.5 font-black">{info.code}</span>
        <span className="font-black text-sm">{info.name || 'عميل غير مسجّل'}</span>
        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-black ${KIND_CLS[kind]}`}>{KIND_LABEL[kind]}</span>
      </div>
      <p className="text-[11px] text-slate-300 flex flex-wrap gap-x-3">
        {info.phone && <span dir="ltr">{info.phone}</span>}
        <span>{historyText(info)}</span>
      </p>
      {info.links.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {info.links.map((l) => <span key={l.type + l.ref} className="rounded-full bg-emerald-500/10 border border-emerald-400/25 text-emerald-200 text-[11px] font-bold px-2.5 py-1">{LINK_ICON[l.type]} {LINK_LABEL[l.type]}: {l.label}</span>)}
        </div>
      )}
      {onLink && <button onClick={onLink} className="flex items-center justify-center gap-1.5 rounded-xl bg-primary text-black text-xs font-black py-2.5"><Link2 size={14} /> ربط / تسجيل المتصل</button>}
    </div>
  )
}
