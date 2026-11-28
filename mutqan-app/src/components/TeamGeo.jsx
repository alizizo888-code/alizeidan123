// «بث موقعي» on every team map (owner 2026-11-28): my blue dot, a switch to share it live with the team, the team's
// shared points by role, and — for the owner and operations — who sees whom (all technicians / one person / what
// technicians may see / whether operations see the owner). The server decides visibility (Mutqan_TeamGeo).
import { useEffect, useRef, useState } from 'react'
import { post } from '../lib/_api'
import { useTeamRole } from '../hooks/useTeamRole'

const ROLE = {
  owner: { emoji: '👑', color: '#f59e0b' },
  operations: { emoji: '🛰️', color: '#8b5cf6' },
  supervisor: { emoji: '🧭', color: '#06b6d4' },
  technician: { emoji: '🧰', color: '#10b981' },
}
const STAFF = ['owner', 'operations', 'supervisor', 'technician']
const KEY = 'mq_geo_share'
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
const agoTxt = (s) => (s == null ? '' : s < 60 ? 'دلوقتي' : s < 3600 ? `من ${Math.round(s / 60)} د` : `من ${Math.round(s / 3600)} س`)
const btn = 'h-9 rounded-full px-3 text-[12px] font-black shadow-lg border flex items-center gap-1'

export default function TeamGeo({ L, map }) {
  const { role, isOwner } = useTeamRole()
  const staff = isOwner || STAFF.includes(role)
  const manager = isOwner || role === 'operations'
  const [share, setShare] = useState(() => { try { return localStorage.getItem(KEY) === '1' } catch { return false } })
  const [mine, setMine] = useState(null)       // {lat,lng,acc}
  const [people, setPeople] = useState([])
  const [err, setErr] = useState('')
  const [panel, setPanel] = useState(false)
  const layer = useRef(null)
  const meMk = useRef(null)
  const centered = useRef(false)
  const lastSent = useRef(0)

  // My position: watched while the map is open (the blue dot), sent only when «بث موقعي» is on.
  useEffect(() => {
    if (!staff || !navigator.geolocation) return undefined
    const id = navigator.geolocation.watchPosition(
      (p) => { setErr(''); setMine({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy || 0), heading: p.coords.heading }) },
      (e) => setErr(e.code === 1 ? 'اسمح للتطبيق بالموقع من إعدادات الموبايل عشان النقطة الزرقا تظهر' : 'الموقع مش متاح دلوقتي'),
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 })
    return () => navigator.geolocation.clearWatch(id)
  }, [staff])

  // Share: a point every ~10 s while on (and right away when it is switched on).
  useEffect(() => {
    if (!share || !mine || Date.now() - lastSent.current < 10000) return
    lastSent.current = Date.now()
    post('/teamgeo', { op: 'ping', lat: mine.lat, lng: mine.lng, acc: mine.acc, heading: mine.heading }).then((r) => r?.people && setPeople(r.people)).catch((e) => setErr(e?.message || 'تعذر إرسال الموقع'))
  }, [share, mine])
  const toggleShare = () => {
    const on = !share
    setShare(on); try { localStorage.setItem(KEY, on ? '1' : '0') } catch { /* ignore */ }
    lastSent.current = 0
    if (!on) post('/teamgeo', { op: 'stop' }).catch(() => {})
  }

  // The team's points: every 8 s while the screen is visible.
  useEffect(() => {
    if (!staff) return undefined
    let stop = false
    const load = () => { if (document.hidden) return; post('/teamgeo', { op: 'list' }).then((r) => { if (!stop && r?.people) setPeople(r.people) }).catch(() => {}) }
    load(); const t = setInterval(load, 8000)
    return () => { stop = true; clearInterval(t) }
  }, [staff])

  // Draw: my blue dot + everyone else I am allowed to see.
  useEffect(() => {
    if (!L || !map) return
    if (!layer.current) layer.current = L.layerGroup().addTo(map)
    const g = layer.current; g.clearLayers()
    for (const p of people) {
      if (p.me) continue
      const r = ROLE[p.role] || ROLE.technician
      const icon = L.divIcon({ className: '', iconSize: [36, 36], iconAnchor: [18, 18], html: `<div style="width:36px;height:36px;border-radius:50%;background:${r.color};border:3px solid #fff;box-shadow:0 0 0 5px ${r.color}55,0 2px 8px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;font-size:17px;${p.ago < 60 ? 'animation:mqpulse 1.6s infinite;' : 'opacity:.75;'}">${r.emoji}</div>` })
      L.marker([p.lat, p.lng], { icon, title: p.name }).addTo(g).bindTooltip(`${esc(p.name)} — ${esc(p.roleLabel)}<br><small>${agoTxt(p.ago)}${p.hidden ? ' · مخفي عن الباقيين' : ''}</small>`, { direction: 'top', offset: [0, -16] })
    }
    if (mine) {
      const html = `<div style="width:20px;height:20px;border-radius:50%;background:#1a73e8;border:3px solid #fff;box-shadow:0 0 0 ${share ? 9 : 5}px rgba(26,115,232,${share ? '.35' : '.2'})"></div>`
      if (!meMk.current) meMk.current = L.marker([mine.lat, mine.lng], { icon: L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10], html }), zIndexOffset: 1000, title: 'موقعي' }).addTo(map)
      else { meMk.current.setLatLng([mine.lat, mine.lng]); meMk.current.setIcon(L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10], html })) }
      meMk.current.bindTooltip(share ? 'موقعي — بيتبث للفريق' : 'موقعي — مش بيتبث', { direction: 'top' })
    }
  }, [L, map, people, mine, share])
  useEffect(() => () => { try { layer.current?.remove(); meMk.current?.remove() } catch { /* map gone */ } }, [])

  if (!staff) return null
  const center = () => { if (mine && map) { map.setView([mine.lat, mine.lng], Math.max(map.getZoom(), 15)); centered.current = true } else setErr('لسه بنحدد موقعك…') }
  return (
    <>
      <div className="absolute top-2 right-2 z-[500] flex flex-wrap gap-1.5" dir="rtl">
        <button type="button" onClick={toggleShare} className={`${btn} ${share ? 'bg-[#1a73e8] text-white border-[#1a73e8]' : 'bg-white text-slate-800 border-slate-200'}`}>
          <span className={`w-2.5 h-2.5 rounded-full ${share ? 'bg-white animate-pulse' : 'bg-slate-400'}`} />{share ? 'بث موقعي: شغّال' : 'بث موقعي'}
        </button>
        <button type="button" onClick={center} aria-label="موقعي" className={`${btn} bg-white text-[#1a73e8] border-slate-200`}>◎ موقعي</button>
        {manager && <button type="button" onClick={() => setPanel(true)} className={`${btn} bg-white text-slate-800 border-slate-200`}>👁️ الرؤية</button>}
      </div>
      {err && <p className="absolute bottom-2 right-2 left-2 z-[500] rounded-xl bg-black/80 text-amber-200 text-[11px] font-bold p-2 text-center" dir="rtl">{err}</p>}
      {panel && <VisibilityPanel isOwner={isOwner} onClose={() => setPanel(false)} />}
    </>
  )
}

function VisibilityPanel({ isOwner, onClose }) {
  const [d, setD] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => { post('/teamgeo', { op: 'cfg' }).then(setD).catch((e) => setErr(e?.message || 'تعذر التحميل')) }, [])
  const save = async (patch) => {
    setBusy(true); setErr('')
    try { setD(await post('/teamgeo', { op: 'cfg_set', ...patch })) } catch (e) { setErr(e?.message || 'تعذر الحفظ') } finally { setBusy(false) }
  }
  const c = d?.cfg
  const techs = (d?.people || []).filter((p) => p.role === 'technician')
  const others = (d?.people || []).filter((p) => p.role !== 'technician' && (isOwner || p.role !== 'owner'))
  const Row = ({ label, on, onChange, disabled }) => (
    <label className={`flex items-center justify-between gap-2 py-2 border-b border-white/10 text-sm ${disabled ? 'opacity-50' : ''}`}><span>{label}</span>
      <input type="checkbox" checked={!!on} disabled={disabled || busy} onChange={(e) => onChange(e.target.checked)} className="w-5 h-5 accent-[#1a73e8]" /></label>
  )
  const Person = ({ p }) => (
    <Row label={<span>{(ROLE[p.role] || ROLE.technician).emoji} {p.name} <small className="text-slate-400">{p.roleLabel}{p.live ? ` · بيبث ${agoTxt(p.ago)}` : ' · مش بيبث'}</small></span>}
      on={!p.hidden} onChange={(v) => save({ hide: { [p.id]: !v } })} />
  )
  return (
    <div className="fixed inset-0 z-[2000] bg-black/70 flex items-end sm:items-center justify-center" onClick={onClose} dir="rtl">
      <div className="w-full sm:max-w-md max-h-[90dvh] overflow-y-auto bg-[#0d1220] text-white rounded-t-2xl sm:rounded-2xl p-4" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center mb-2"><h2 className="flex-1 font-black">👁️ مين يشوف مكان مين</h2><button onClick={onClose} className="w-8 h-8 rounded-full bg-white/10">✕</button></div>
        {err && <p className="text-xs text-rose-300 mb-2">{err}</p>}
        {!c ? <p className="text-sm text-slate-400 py-6 text-center">جارِ التحميل…</p> : <>
          <Row label="مشاركة المواقع بين الفريق (إجمالي)" on={c.enabled} onChange={(v) => save({ enabled: v })} />
          <Row label="إظهار كل الفنيين على الخريطة" on={c.showTechs} onChange={(v) => save({ showTechs: v })} />
          {isOwner && <Row label="العمليات تشوف مكان المالك" on={c.opsSeeOwner} onChange={(v) => save({ opsSeeOwner: v })} />}
          <div className="py-2 border-b border-white/10">
            <p className="text-sm mb-1.5">الفنيين يشوفوا:</p>
            <div className="grid grid-cols-3 gap-1.5">
              {[['all', 'الكل'], ['staff', 'الإدارة بس'], ['none', 'محدش']].map(([k, l]) => (
                <button key={k} disabled={busy} onClick={() => save({ techsSee: k })} className={`rounded-lg py-2 text-xs font-black border ${c.techsSee === k ? 'bg-[#1a73e8] border-[#1a73e8]' : 'border-white/15 text-slate-300'}`}>{l}</button>
              ))}
            </div>
          </div>
          <p className="text-xs font-black text-slate-400 mt-3 mb-1">الفنيين — كل واحد لوحده (✓ = ظاهر)</p>
          {techs.length ? techs.map((p) => <Person key={p.id} p={p} />) : <p className="text-xs text-slate-500">مفيش فنيين.</p>}
          <p className="text-xs font-black text-slate-400 mt-3 mb-1">الإدارة والعمليات</p>
          {others.map((p) => <Person key={p.id} p={p} />)}
          <p className="text-[11px] text-slate-500 mt-3 leading-5">المالك بيشوف الكل دايمًا. اللي مخفي بيشوف نقطته هو بس. الموقع بيتمسح لوحده بعد 15 دقيقة من آخر تحديث، ومفيش سجل بيتحفظ.</p>
        </>}
      </div>
    </div>
  )
}
