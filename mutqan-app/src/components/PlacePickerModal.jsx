import { useState } from 'react'
import { X, Loader2, Search, LocateFixed, MapPin } from 'lucide-react'
import { maps } from '../lib/maps'

const inp = 'w-full bg-white border-2 border-sky-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-900 focus:outline-none focus:border-sky-500'

// Add-a-place flow for the field map: search a name via Google Places, or grab the current GPS point — either way
// the caller gets back {name, address, lat, lng} and decides what to save (shop pin, client pin, ...).
export default function PlacePickerModal({ title, onClose, onPick, center }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  async function doSearch() {
    if (!q.trim()) return
    setErr('')
    setBusy(true)
    try {
      const items = await maps.searchPlaces(q.trim(), center?.lat, center?.lng)
      setResults(items)
      if (!items.length) setErr('محدش لقيت بالاسم ده. جرّب اسمًا أدق أو استخدم موقعك الحالي.')
    } catch {
      setErr('تعذر البحث الآن. تأكد من الاتصال وحاول مرة أخرى.')
    } finally {
      setBusy(false)
    }
  }

  async function useGps() {
    setErr('')
    setBusy(true)
    try {
      const loc = await maps.getCurrentLocation()
      const { address } = await maps.reverseGeocode(loc.lat, loc.lng)
      onPick({ name: q.trim() || address || 'موقعي الحالي', address, lat: loc.lat, lng: loc.lng })
    } catch (e) {
      setErr(e?.code === 'PERMISSION_DENIED' ? 'لازم تسمح للتطبيق بالوصول للموقع.' : 'تعذر تحديد موقعك الحالي.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[70] bg-black/70 backdrop-blur-sm flex items-end md:items-center justify-center p-3" onClick={onClose} dir="rtl">
      <div className="bg-sky-50 w-full max-w-md max-h-[92vh] overflow-y-auto rounded-3xl shadow-2xl border-4 border-violet-500" onClick={(e) => e.stopPropagation()}>
        <div className="bg-gradient-to-l from-violet-600 to-purple-500 text-white px-5 py-4 flex items-center gap-3 sticky top-0 z-10">
          <MapPin size={22} />
          <div className="flex-1">
            <p className="font-display font-black text-base">{title || 'إضافة موقع جديد'}</p>
            <p className="text-[11px] font-bold opacity-90">ابحث بالاسم عن طريق جوجل، أو استخدم موقعك الحالي بالـ GPS</p>
          </div>
          <button onClick={onClose} aria-label="إغلاق" className="w-8 h-8 rounded-full bg-black/25 flex items-center justify-center"><X size={16} /></button>
        </div>
        <div className="p-4 flex flex-col gap-3">
          <div className="flex gap-2">
            <input className={inp} placeholder="اسم المحل أو الموقع..." value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && doSearch()} />
            <button onClick={doSearch} disabled={busy || !q.trim()} className="shrink-0 w-11 h-11 rounded-xl bg-violet-600 text-white flex items-center justify-center disabled:opacity-50">
              {busy ? <Loader2 size={18} className="animate-spin" /> : <Search size={18} />}
            </button>
          </div>

          <button onClick={useGps} disabled={busy} className="flex items-center justify-center gap-2 bg-emerald-600 text-white font-display font-black text-sm rounded-2xl py-3 shadow disabled:opacity-60">
            {busy ? <Loader2 size={16} className="animate-spin" /> : <LocateFixed size={16} />}
            استخدم موقعي الحالي (GPS)
          </button>

          {err && <p className="bg-rose-600 text-white text-xs font-black rounded-xl p-2.5">{err}</p>}

          {Array.isArray(results) && results.length > 0 && (
            <div className="flex flex-col gap-2 max-h-64 overflow-y-auto">
              {results.map((r, i) => (
                <button key={r.placeId || i} onClick={() => onPick(r)} className="text-right bg-white border-2 border-slate-200 rounded-xl px-3 py-2.5 hover:border-violet-400">
                  <p className="font-black text-sm text-slate-900">{r.name}</p>
                  {r.address && <p className="text-[11px] text-slate-500 font-bold">{r.address}</p>}
                </button>
              ))}
            </div>
          )}

          {center && (
            <button onClick={() => onPick({ name: q.trim() || 'بدون اسم', address: '', lat: center.lat, lng: center.lng })} className="text-xs font-bold text-slate-500 underline py-1">
              أو ثبّت الدبوس في مركز الخريطة الحالي
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
