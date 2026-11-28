import { useEffect, useMemo, useState } from 'react'
import { Loader2, MapPin, Clock, Navigation } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { maps } from '../lib/maps'
import { specialtyLabel } from '../constants'
import FieldMapCanvas from './FieldMapCanvas'

// «متاحة للقبول» as a live radar (owner order 2026-11-22): every job still looking for a hand — not distributed
// yet, the room is on manual, or it went to a colleague who has not accepted. Distance and time from where he
// stands, on a list and on a live map. «خدها» takes a free job at once; one a colleague holds becomes a request to
// operations. Refreshed every 15 seconds.
const km = (m) => (m == null ? '' : m < 1000 ? `${Math.round(m)} م` : `${(m / 1000).toFixed(1)} كم`)
const ago = (iso) => { const m = Math.round((Date.now() - Date.parse(iso || '')) / 60000); return !Number.isFinite(m) ? '' : m < 60 ? `من ${m} د` : `من ${Math.round(m / 60)} س` }

export default function TechRadarPanel({ myProfile, onOpen }) {
  const [rows, setRows] = useState(null)
  const [here, setHere] = useState(null)
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [view, setView] = useState('list')

  useEffect(() => {
    let alive = true
    const load = () => { if (document.visibilityState !== 'hidden') post('/field', { op: 'radar' }).then((r) => alive && setRows(r?.orders || [])).catch(() => alive && setRows((x) => x || [])) }
    load(); const t = setInterval(load, 15000)
    maps.getCurrentLocation().then((p) => alive && setHere({ lat: p.lat, lng: p.lng })).catch(() => {})
    return () => { alive = false; clearInterval(t) }
  }, [])

  const from = here || (myProfile?.lat != null ? { lat: myProfile.lat, lng: myProfile.lng } : null)
  const list = useMemo(() => (rows || []).map((o) => {
    let d = null
    try { if (from && o.lat != null) d = maps.distance(from.lat, from.lng, o.lat, o.lng) } catch { /* no position */ }
    return { ...o, dist: d, mins: d != null ? Math.max(3, Math.round(d / 1000 / 35 * 60)) : null }
  }).sort((a, b) => (a.free === b.free ? (a.dist ?? 1e9) - (b.dist ?? 1e9) : a.free ? -1 : 1)), [rows, from])

  async function claim(o) {
    setBusy(o.id); setMsg('')
    try {
      const r = await post('/field', { op: 'claim', orderId: o.id })
      setMsg(r?.requested ? 'اتبعت طلبك للعمليات — هيردوا عليك' : 'الطلب بقى بتاعك ✓')
      setRows((x) => (x || []).filter((y) => y.id !== o.id || r?.requested))
      if (r?.taken && onOpen) onOpen(o)
    } catch (e) { setMsg(errText(e, 'تعذّر')) } finally { setBusy('') }
  }

  const points = list.filter((o) => o.lat != null).map((o) => ({ id: o.id, lat: o.lat, lng: o.lng, emoji: o.free ? '🆕' : '🔁', color: o.free ? '#ef4444' : '#f59e0b', pulse: o.free, label: `${specialtyLabel(o.specialtyId)} · ${o.district || ''} ${o.dist != null ? '· ' + km(o.dist) : ''}`, data: o }))
  if (from) points.push({ id: 'me', lat: from.lat, lng: from.lng, emoji: '🧰', color: '#10b981', label: 'أنت هنا' })

  return (
    <div className="flex flex-col gap-3">
      <div className="relative rounded-3xl overflow-hidden border border-emerald-400/20 bg-[radial-gradient(circle_at_center,rgba(16,185,129,.18),rgba(2,6,23,.9)_70%)] h-40 flex items-center justify-center">
        <style>{'@keyframes mqsweep{to{transform:rotate(360deg)}}@keyframes mqping{0%{transform:scale(.2);opacity:.9}100%{transform:scale(1.6);opacity:0}}'}</style>
        {[0, 1, 2].map((i) => <span key={i} className="absolute rounded-full border border-emerald-400/50" style={{ width: 140, height: 140, animation: `mqping 2.4s ${i * 0.8}s infinite ease-out` }} />)}
        <span className="absolute w-[150px] h-[150px] rounded-full" style={{ background: 'conic-gradient(from 0deg, rgba(16,185,129,.5), transparent 25%)', animation: 'mqsweep 2.8s linear infinite' }} />
        <div className="relative text-center">
          <p className="text-3xl font-black text-white">{rows == null ? '…' : list.length}</p>
          <p className="text-[11px] font-bold text-emerald-200">{rows == null ? 'بنستكشف المكان…' : list.length ? 'طلب محتاج فني حواليك' : 'مفيش طلبات مفتوحة دلوقتي'}</p>
        </div>
      </div>
      <div className="flex gap-2">
        {[['list', 'القائمة'], ['map', '🗺️ الخريطة الحية']].map(([k, l]) => <button key={k} onClick={() => setView(k)} className={`flex-1 rounded-xl py-2 text-xs font-black border ${view === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{l}</button>)}
      </div>
      {msg && <p className="text-xs font-black text-center text-amber-200">{msg}</p>}
      {view === 'map' ? (
        <FieldMapCanvas points={points} height="60vh" onSelect={(p) => p?.data && setMsg(`${specialtyLabel(p.data.specialtyId)} — ${p.data.district || ''} ${p.data.dist != null ? '(' + km(p.data.dist) + ')' : ''}`)} />
      ) : (
        <div className="grid gap-2 md:grid-cols-2">
          {list.map((o) => (
            <div key={o.id} className={`rounded-2xl p-3 border ${o.free ? 'border-rose-400/40 bg-rose-500/5' : 'border-amber-400/30 bg-amber-500/5'}`}>
              <div className="flex items-center gap-2">
                <p className="flex-1 text-sm font-black text-white">{specialtyLabel(o.specialtyId)}{o.priority === 'urgent' ? ' 🔥' : ''}</p>
                <span className="text-[10px] text-slate-400">{ago(o.createdAt)}</span>
              </div>
              {o.serviceLabel && <p className="text-[11px] text-slate-300 mt-0.5 truncate">{o.serviceLabel}</p>}
              <div className="flex flex-wrap gap-x-3 gap-y-1 mt-1.5 text-[11px] text-slate-300">
                {o.district && <span className="flex items-center gap-1"><MapPin size={11} />{o.district}</span>}
                {o.dist != null && <span className="flex items-center gap-1"><Navigation size={11} />{km(o.dist)} · ~{o.mins} د</span>}
                {o.appointmentAt && <span className="flex items-center gap-1"><Clock size={11} />{new Date(o.appointmentAt).toLocaleString('ar-SA', { weekday: 'short', hour: 'numeric', minute: '2-digit' })}</span>}
                {o.price > 0 && <span>💰 {Math.round(o.price)} ر.س</span>}
              </div>
              {!o.free && <p className="text-[10px] text-amber-300 mt-1">مع {o.takenBy} ولسه ما قبلهوش</p>}
              <button onClick={() => claim(o)} disabled={busy === o.id} className={`mt-2 w-full rounded-xl py-2 text-xs font-black flex items-center justify-center gap-1 ${o.free ? 'bg-rose-500 text-white' : 'bg-amber-400 text-black'}`}>
                {busy === o.id ? <Loader2 size={14} className="animate-spin" /> : null}{o.free ? 'خدها — أسندها لي' : 'اطلبها من العمليات'}
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
