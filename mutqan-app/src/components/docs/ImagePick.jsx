import { useRef, useState } from 'react'
import { ImagePlus, Loader2, X } from 'lucide-react'
import { storage } from '../../lib/storage'

// A picture for the invoices and quotes (logo, stamp, signature, header, footer): uploaded to the site, its link kept.
export default function ImagePick({ label, value, onChange, fallback, hint }) {
  const inp = useRef(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  const up = async (f) => {
    if (!f) return
    setBusy(true); setErr('')
    try { const { url } = await storage.upload(f, f.name || 'image.png'); onChange(url) } catch (e) { setErr(e?.message || 'ما اترفعتش') } finally { setBusy(false); if (inp.current) inp.current.value = '' }
  }
  const shown = value || fallback
  return (
    <div className="rounded-xl bg-white/5 border border-white/10 p-2 grid gap-1.5">
      <p className="text-xs text-slate-300 font-bold">{label}</p>
      <div className="h-20 rounded-lg bg-white flex items-center justify-center overflow-hidden">{shown ? <img src={shown} alt="" className="max-h-full max-w-full object-contain" /> : <span className="text-slate-400 text-xs">لسه مفيش</span>}</div>
      <div className="flex gap-1.5">
        <button type="button" onClick={() => inp.current?.click()} className="flex-1 rounded-lg bg-sky-500/20 text-sky-200 text-xs font-bold py-1.5 flex items-center justify-center gap-1">{busy ? <Loader2 size={12} className="animate-spin" /> : <ImagePlus size={12} />}{value ? 'تغيير' : 'رفع صورة'}</button>
        {value && <button type="button" onClick={() => onChange('')} className="rounded-lg bg-white/10 text-slate-300 text-xs px-2" title={fallback ? 'رجوع للأصلي' : 'مسح'}><X size={12} /></button>}
      </div>
      {hint && <p className="text-[10px] text-slate-500">{hint}</p>}
      {err && <p className="text-[10px] text-rose-300">{err}</p>}
      <input ref={inp} type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={(e) => up(e.target.files?.[0])} />
    </div>
  )
}
