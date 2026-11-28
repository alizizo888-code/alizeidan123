// «التوأم الرقمي للمقر» (owner, 2026-11-28): the whole company as one 3D building, drawn from the live data.
//   البوابة            — the real visitors on the sites right now (Google search, ad, Blogger, WhatsApp, the site assistant…)
//   استقبال الذكاء الاصطناعي — orders the customer has not confirmed yet, each customer with his mood over his head
//   غرفة العمليات والديسباتش  — orders waiting for a technician + the operations staff on shift
//   مكاتب الفنيين       — every technician, by his real state (free on shift / has a job to prepare / off)
//   الجراج والميدان      — technicians out on the road / at the customer, with the time to arrive
//   مكتب المالك          — today's numbers and the button to send an order to the assistant
// Tap anyone → his card with real actions (open, speed up, assign / transfer, confirm, cancel, call, ask the assistant).
// No 3D library: CSS 3D transforms only (no install, light on phones). Drag to turn, pinch / buttons to zoom, 4 views.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, RotateCcw, ZoomIn, ZoomOut, X, ExternalLink, Zap, UserCog, CheckCircle2, Ban, Phone, Sparkles, Eye, RefreshCw } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { post } from '../lib/_api'
import { openAssistant } from '../lib/voice'
import { useTeamRole } from '../hooks/useTeamRole'
import AccessDenied from '../components/AccessDenied'

const OPEN = ['new', 'forced', 'pending', 'unassigned', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']
const WAITING = ['new', 'forced', 'pending', 'unassigned', 'inhouse']
const PREP = ['assigned', 'accepted']
const FIELD = ['en_route', 'nearby', 'arrived', 'in_progress']
const ST = { new: 'جديد', forced: 'بانتظار فني', pending: 'معلّق', unassigned: 'بدون فني', inhouse: 'تنفيذ داخلي', assigned: 'مسند', accepted: 'قبله الفني', en_route: 'في الطريق', nearby: 'قرب يوصل', arrived: 'وصل', in_progress: 'شغّال', completed: 'اكتمل', cancelled: 'ملغي', closed: 'مقفول' }
const SRC = { google: ['🔎', 'بحث جوجل'], ad: ['📣', 'إعلان جوجل'], blogger: ['📝', 'مدونة بلوجر'], whatsapp: ['💬', 'واتساب'], social: ['📱', 'سوشيال'], bot: ['🤖', 'مع المساعد'], direct: ['🚶', 'دخول مباشر'], site: ['🔗', 'موقع تاني'] }
const MOOD = (min) => (min >= 30 ? ['😡', 'زعلان — مستني من ' + min + ' د', '#f43f5e'] : min >= 12 ? ['😐', 'بدأ يقلق — ' + min + ' د', '#f59e0b'] : ['🙂', 'انتظار طبيعي — ' + min + ' د', '#22c55e'])
const mins = (iso) => { const t = Date.parse(iso || ''); return Number.isFinite(t) ? Math.max(0, Math.round((Date.now() - t) / 60000)) : 0 }
const today = (iso) => { const d = new Date(iso || 0); const n = new Date(); return d.toDateString() === n.toDateString() }
const svc = (o) => o.serviceLabel || o.specialtyLabel || o.specialtyId || 'خدمة'
const said = (o) => String(o.problem || o.notes || o.details || o.description || o.clientNote || '').slice(0, 70)

// The floor plan, in floor units (1 unit = 10 px). x across, y deep.
const ROOMS = [
  { id: 'gate', name: 'بوابة العملاء والزوار', x: 0, y: 0, w: 26, d: 16, color: '#0ea5e9', h: 2 },
  { id: 'ai', name: 'استقبال الذكاء الاصطناعي', x: 28, y: 0, w: 26, d: 16, color: '#8b5cf6', h: 5 },
  { id: 'ops', name: 'غرفة العمليات والديسباتش', x: 56, y: 0, w: 30, d: 16, color: '#f59e0b', h: 5 },
  { id: 'techs', name: 'مكاتب الفنيين', x: 0, y: 18, w: 40, d: 18, color: '#12a39a', h: 4 },
  { id: 'garage', name: 'الجراج والميدان', x: 42, y: 18, w: 26, d: 18, color: '#64748b', h: 3 },
  { id: 'owner', name: 'مكتب المالك / المدير', x: 70, y: 18, w: 16, d: 18, color: '#e11d48', h: 6 },
]
const U = 10
const VIEWS = { 'منظور': { rx: 58, rz: -38, z: 1 }, 'من فوق': { rx: 8, rz: 0, z: 1.05 }, 'من البوابة': { rx: 66, rz: 0, z: 1.15 }, 'جانبي': { rx: 62, rz: -78, z: 1 } }

export default function DigitalTwin() {
  const nav = useNavigate()
  const { role } = useTeamRole()
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 300 })
  const [fleet, setFleet] = useState(null)
  const [pres, setPres] = useState({ visitors: [], today: {} })
  const [sel, setSel] = useState(null)      // { kind, item }
  const [view, setView] = useState(VIEWS['منظور'])
  const [busy, setBusy] = useState('')
  const [toast, setToast] = useState('')
  const [, tick] = useState(0)
  const stage = useRef(null)

  const load = useCallback(async () => {
    const vis = document.visibilityState === 'visible'
    if (!vis) return
    post('/ops', { op: 'fleet' }).then(setFleet).catch(() => {})
    post('/ops', { op: 'presence' }).then((r) => r && setPres(r)).catch(() => {})
  }, [])
  useEffect(() => { load(); const t = setInterval(load, 10000); const m = setInterval(() => tick((x) => x + 1), 30000); return () => { clearInterval(t); clearInterval(m) } }, [load])
  useEffect(() => { if (!toast) return undefined; const t = setTimeout(() => setToast(''), 2600); return () => clearTimeout(t) }, [toast])

  // ── who stands where (all from the real data) ──
  const W = useMemo(() => {
    const list = (orders || []).filter((o) => o && !o.deletedAt)
    const open = list.filter((o) => OPEN.includes(o.status))
    const reception = open.filter((o) => WAITING.includes(o.status) && o.customerConfirmed === false)
    const dispatch = open.filter((o) => WAITING.includes(o.status) && o.customerConfirmed !== false)
    const techRows = (fleet?.techs || [])
    const byTech = {}
    for (const o of open) if (o.technicianId) (byTech[o.technicianId] = byTech[o.technicianId] || []).push(o)
    const techs = techRows.map((t) => {
      const jobs = byTech[t.id] || []
      const out = jobs.find((o) => FIELD.includes(o.status))
      const prep = jobs.find((o) => PREP.includes(o.status))
      const place = out ? 'garage' : prep ? 'garage-prep' : 'techs'
      return { ...t, jobsList: jobs, out, prep, place }
    })
    const done = list.filter((o) => ['completed', 'closed'].includes(o.status) && today(o.completedAt || o.updatedAt || o.createdAt))
    const cancelled = list.filter((o) => o.status === 'cancelled' && today(o.updatedAt || o.createdAt))
    const newToday = list.filter((o) => today(o.createdAt))
    const money = done.reduce((s, o) => s + (Number(o.finalPrice || o.price || o.total) || 0), 0)
    return { reception, dispatch, techs, staff: fleet?.staff || [], done, cancelled, newToday, money, open }
  }, [orders, fleet])

  // ── turning and zooming the building ──
  const drag = useRef(null)
  const onDown = (e) => { if (e.target.closest('[data-ent]')) return; drag.current = { x: e.clientX, y: e.clientY, v: view }; try { stage.current?.setPointerCapture(e.pointerId) } catch { /* ignore */ } }
  const onMove = (e) => {
    const d = drag.current; if (!d) return
    const rz = d.v.rz + (e.clientX - d.x) * 0.35
    const rx = Math.max(0, Math.min(75, d.v.rx - (e.clientY - d.y) * 0.25))
    setView({ ...d.v, rz, rx })
  }
  const onUp = () => { drag.current = null }
  const zoom = (f) => setView((v) => ({ ...v, z: Math.max(0.45, Math.min(2.4, v.z * f)) }))
  const onWheel = (e) => zoom(e.deltaY < 0 ? 1.1 : 0.9)
  // pinch
  const pinch = useRef(null)
  const onTouch = (e) => {
    if (e.touches.length !== 2) { pinch.current = null; return }
    const d = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY)
    if (!pinch.current) { pinch.current = { d, z: view.z }; drag.current = null; return }
    setView((v) => ({ ...v, z: Math.max(0.45, Math.min(2.4, pinch.current.z * d / pinch.current.d)) }))
  }

  if (role && !['owner', 'operations', 'supervisor'].includes(role)) return <AccessDenied />

  // ── actions on a selected order ──
  const act = async (key, fn, ok) => { setBusy(key); try { await fn(); setToast(ok) } catch (e) { setToast(e?.message || 'ماتمش — جرّب تاني') } finally { setBusy('') } }
  const hurry = (o) => act('hurry', () => db.updateShared('orders', o.id, { priority: 'urgent', statusHistory: [...(o.statusHistory || []), { status: o.status, at: new Date().toISOString(), by: 'التوأم الرقمي', note: 'تعجيل' }] }), 'اتعلّم «عاجل» ✅')
  const toOps = (o) => act('ops', () => db.updateShared('orders', o.id, { customerConfirmed: true, statusHistory: [...(o.statusHistory || []), { status: o.status, at: new Date().toISOString(), by: 'التوأم الرقمي', note: 'تحويل لغرفة العمليات' }] }), 'اتحوّل لغرفة العمليات ✅')
  const cancel = (o) => { if (!window.confirm('إلغاء الطلب ده؟')) return; act('cancel', () => db.updateShared('orders', o.id, { status: 'cancelled', statusHistory: [...(o.statusHistory || []), { status: 'cancelled', at: new Date().toISOString(), by: 'التوأم الرقمي' }] }), 'اتلغى') }
  const open = (o, q = '') => nav(`/staff/order/${o.id}${q}`)
  const ask = (text) => openAssistant({ ask: text })

  const S = (n) => n * U
  return (
    <div dir="rtl" className="h-full min-h-0 flex flex-col bg-[#05070d] text-white">
      <div className="shrink-0 flex items-center gap-2 px-3 py-2 border-b border-white/10 bg-[#0b0f1a]">
        <button onClick={() => nav('/staff/operations')} className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center"><ChevronRight size={16} /></button>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-black truncate">🏢 التوأم الرقمي للمقر — مباشر</p>
          <p className="text-[10px] text-slate-400 truncate">زوار {pres.visitors?.length || 0} · استقبال {W.reception.length} · ديسباتش {W.dispatch.length} · فنيين {W.techs.length} · في الميدان {W.techs.filter((t) => t.out).length}</p>
        </div>
        <button onClick={load} className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center" title="تحديث"><RefreshCw size={15} /></button>
      </div>

      <div className="shrink-0 flex gap-1.5 px-3 py-2 overflow-x-auto">
        {Object.entries(VIEWS).map(([k, v]) => <button key={k} onClick={() => setView(v)} className="shrink-0 rounded-full px-3 py-1 text-[11px] font-black bg-white/10 border border-white/10">{k}</button>)}
        <button onClick={() => zoom(1.15)} className="shrink-0 rounded-full w-8 h-7 bg-white/10 flex items-center justify-center"><ZoomIn size={14} /></button>
        <button onClick={() => zoom(0.87)} className="shrink-0 rounded-full w-8 h-7 bg-white/10 flex items-center justify-center"><ZoomOut size={14} /></button>
        <button onClick={() => setView(VIEWS['منظور'])} className="shrink-0 rounded-full w-8 h-7 bg-white/10 flex items-center justify-center"><RotateCcw size={14} /></button>
      </div>

      <div ref={stage} className="relative flex-1 min-h-[340px] overflow-hidden select-none" style={{ perspective: '1400px', touchAction: 'none', background: 'radial-gradient(ellipse at 50% 30%, #12203a 0%, #05070d 70%)' }}
        onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp} onWheel={onWheel} onTouchMove={onTouch} onTouchEnd={() => { pinch.current = null }}>
        <div className="absolute left-1/2 top-1/2" style={{ transformStyle: 'preserve-3d', transform: `translate(-50%,-45%) scale(${view.z}) rotateX(${view.rx}deg) rotateZ(${view.rz}deg)`, transition: drag.current ? 'none' : 'transform .5s ease', width: S(86), height: S(36) }}>
          {/* ground */}
          <div className="absolute" style={{ left: -S(4), top: -S(10), width: S(94), height: S(50), background: 'repeating-linear-gradient(0deg,#0d1424 0 19px,#111a2e 19px 20px),repeating-linear-gradient(90deg,transparent 0 19px,#111a2e 19px 20px)', borderRadius: 18, boxShadow: '0 0 80px #0ea5e933 inset' }} />
          {/* street in front of the gate */}
          <div className="absolute" style={{ left: -S(4), top: -S(9), width: S(94), height: S(6), background: '#1f2937', borderTop: '2px dashed #facc1555', borderRadius: 6 }}>
            <span className="absolute text-[10px] text-slate-400 font-black" style={{ right: 10, top: 6 }}>الشارع · مواقع الشبكة وجوجل والإعلانات</span>
          </div>
          {ROOMS.map((r) => <Room key={r.id} r={r} />)}

          {/* ── people and cars ── */}
          {(pres.visitors || []).slice(0, 28).map((v, i) => {
            const [ic] = SRC[v.src] || SRC.direct
            return <Ent key={'v' + v.id} x={1.5 + (i % 7) * 3.5} y={2 + Math.floor(i / 7) * 3.6} rz={view.rz} rx={view.rx} emoji={v.live ? '🧍' : '🚶'} badge={ic} dim={!v.live} label={(SRC[v.src] || SRC.direct)[1]} onClick={() => setSel({ kind: 'visitor', item: v })} />
          })}
          {W.reception.slice(0, 18).map((o, i) => {
            const m = MOOD(mins(o.createdAt))
            return <Ent key={'r' + o.id} x={30 + (i % 6) * 4} y={3 + Math.floor(i / 6) * 4.4} rz={view.rz} rx={view.rx} emoji="🧑" badge={m[0]} bubble={said(o) || svc(o)} ring={m[2]} urgent={o.priority === 'urgent'} onClick={() => setSel({ kind: 'order', item: o })} />
          })}
          <Ent x={50} y={13} rz={view.rz} rx={view.rx} emoji="🤖" label="موظف الاستقبال الذكي" onClick={() => ask('إيه حالة الطلبات اللي لسه العميل ماأكدهاش؟')} />
          {W.dispatch.slice(0, 18).map((o, i) => {
            const m = MOOD(mins(o.createdAt))
            return <Ent key={'d' + o.id} x={58 + (i % 6) * 4.5} y={3 + Math.floor(i / 6) * 4.4} rz={view.rz} rx={view.rx} emoji="📋" badge={m[0]} bubble={`${svc(o)} · ${ST[o.status] || o.status}`} ring={m[2]} urgent={o.priority === 'urgent'} onClick={() => setSel({ kind: 'order', item: o })} />
          })}
          {W.staff.slice(0, 4).map((s, i) => <Ent key={'s' + s.id} x={60 + i * 6} y={14} rz={view.rz} rx={view.rx} emoji="🎧" label={s.name} onClick={() => setSel({ kind: 'staff', item: s })} />)}
          {W.techs.filter((t) => t.place === 'techs').slice(0, 24).map((t, i) => (
            <Ent key={'t' + t.id} x={2 + (i % 8) * 4.8} y={21 + Math.floor(i / 8) * 5} rz={view.rz} rx={view.rx} emoji={t.off || !t.shift ? '😴' : '👷'} dim={!t.live} label={t.name} ring={t.color} onClick={() => setSel({ kind: 'tech', item: t })} />
          ))}
          {W.techs.filter((t) => t.place !== 'techs').slice(0, 14).map((t, i) => (
            <Ent key={'g' + t.id} x={44 + (i % 4) * 6} y={21 + Math.floor(i / 4) * 5} rz={view.rz} rx={view.rx} emoji={t.out ? (t.out.status === 'in_progress' || t.out.status === 'arrived' ? '🔧' : '🚐') : '🚙'} ring={t.color}
              bubble={t.out ? `${ST[t.out.status]}${t.job?.etaMin && ['en_route', 'nearby'].includes(t.out.status) ? ' · يوصل ~' + t.job.etaMin + ' د' : ''}` : 'بيجهز للطلب'} label={t.name} onClick={() => setSel({ kind: 'tech', item: t })} />
          ))}
          <Ent x={77} y={26} rz={view.rz} rx={view.rx} emoji="🧑‍💼" label="المالك" bubble={`اليوم: ${W.newToday.length} طلب · ${W.done.length} اكتمل`} onClick={() => setSel({ kind: 'owner', item: null })} />
        </div>

        <div className="absolute bottom-2 inset-x-2 flex gap-1.5 overflow-x-auto pointer-events-auto">
          {Object.entries(pres.today || {}).map(([k, n]) => <span key={k} className="shrink-0 rounded-full bg-black/60 border border-white/10 px-2.5 py-1 text-[10px] font-bold">{(SRC[k] || SRC.direct)[0]} {(SRC[k] || SRC.direct)[1]}: {n}</span>)}
          {!Object.keys(pres.today || {}).length && <span className="rounded-full bg-black/60 px-2.5 py-1 text-[10px] text-slate-400">زوار اليوم بيتعدّوا من أول زيارة بعد التحديث</span>}
        </div>
        {toast && <div className="absolute top-3 inset-x-0 flex justify-center"><span className="rounded-full bg-emerald-500 text-black text-xs font-black px-4 py-2 shadow-xl">{toast}</span></div>}
      </div>

      {sel && (
        <div className="fixed inset-0 z-[80] bg-black/60 flex items-end sm:items-center justify-center" onClick={() => setSel(null)}>
          <div dir="rtl" onClick={(e) => e.stopPropagation()} className="w-full max-w-md max-h-[80dvh] overflow-y-auto bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div className="flex items-start gap-2 mb-3">
              <div className="flex-1 min-w-0"><Card sel={sel} /></div>
              <button onClick={() => setSel(null)} className="text-slate-400"><X size={18} /></button>
            </div>
            {sel.kind === 'order' && (() => { const o = sel.item; return (
              <div className="grid grid-cols-2 gap-2">
                <Btn icon={ExternalLink} onClick={() => open(o)}>فتح الطلب</Btn>
                <Btn icon={Zap} busy={busy === 'hurry'} onClick={() => hurry(o)} tone="amber">تعجيل</Btn>
                {o.customerConfirmed === false && <Btn icon={CheckCircle2} onClick={() => open(o, '?confirm=1')} tone="green">اعتماد / تأكيد</Btn>}
                {o.customerConfirmed === false && <Btn icon={Eye} busy={busy === 'ops'} onClick={() => toOps(o)}>تحويل للعمليات</Btn>}
                <Btn icon={UserCog} onClick={() => open(o, '?assign=1')} tone="primary">{o.technicianId ? 'تحويل لفني تاني' : 'إسناد لفني'}</Btn>
                {(o.clientPhone || o.phone) && <Btn icon={Phone} onClick={() => { location.href = 'tel:' + (o.clientPhone || o.phone) }}>اتصال بالعميل</Btn>}
                <Btn icon={Sparkles} onClick={() => ask(`لخصلي الطلب رقم ${o.number || o.id} واقترح الخطوة الجاية`)}>اسأل المساعد</Btn>
                {role !== 'supervisor' && <Btn icon={Ban} busy={busy === 'cancel'} onClick={() => cancel(o)} tone="rose">إلغاء</Btn>}
              </div>
            ) })()}
            {sel.kind === 'tech' && (() => { const t = sel.item; return (
              <div className="grid gap-2">
                {(t.jobsList || []).map((o) => <button key={o.id} onClick={() => setSel({ kind: 'order', item: o })} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-xs"><b>{svc(o)}</b> · {o.clientName || 'عميل'} · <span className="text-primary">{ST[o.status] || o.status}</span></button>)}
                {!(t.jobsList || []).length && <p className="text-xs text-slate-400">مفيش طلبات مفتوحة معاه دلوقتي.</p>}
                <div className="grid grid-cols-2 gap-2">
                  {t.phone && <Btn icon={Phone} onClick={() => { location.href = 'tel:' + t.phone }}>اتصال</Btn>}
                  <Btn icon={ExternalLink} onClick={() => nav('/staff/map')}>مكانه على الخريطة</Btn>
                  {W.dispatch[0] && <Btn icon={UserCog} tone="primary" onClick={() => open(W.dispatch[0], '?assign=1')}>إدّيله أقدم طلب منتظر</Btn>}
                  <Btn icon={Sparkles} onClick={() => ask(`الفني ${t.name}: فين دلوقتي وإيه شغله النهارده وإمتى يرجع؟`)}>اسأل المساعد</Btn>
                </div>
              </div>
            ) })()}
            {sel.kind === 'visitor' && <p className="text-xs text-slate-400">الزائر لسه ماطلبش — أول ما يطلب بيظهر في الاستقبال بطلبه.</p>}
            {sel.kind === 'staff' && <Btn icon={ExternalLink} onClick={() => nav('/staff/map')}>مكانه على الخريطة</Btn>}
            {sel.kind === 'owner' && (
              <div className="grid gap-2">
                <p className="text-xs text-slate-300">اكتب أو قول أي أمر للعمليات أو البوت أو الفنيين:</p>
                {['ارفع حد أسعار الغسيل النهارده', 'وقّف استقبال الطلبات وخلّيها لبكرة', 'ابعت للفنيين: الشغل بكرة يبدأ 8 الصبح', 'مين أكتر فني متأخر النهارده؟'].map((q) => <Btn key={q} icon={Sparkles} onClick={() => ask(q)}>{q}</Btn>)}
                <Btn icon={Sparkles} tone="primary" onClick={() => openAssistant({})}>أمر جديد للمساعد</Btn>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )

  function Card({ sel: s }) {
    if (s.kind === 'order') {
      const o = s.item; const m = MOOD(mins(o.createdAt))
      return (<>
        <p className="text-[11px] font-bold" style={{ color: m[2] }}>{m[0]} {m[1]}</p>
        <p className="font-black text-sm">{svc(o)} · {o.clientName || 'عميل'}{o.priority === 'urgent' ? ' · ⚡عاجل' : ''}</p>
        <p className="text-[11px] text-slate-400">#{o.number || o.id} · {ST[o.status] || o.status}{o.technicianName ? ' · ' + o.technicianName : ''}{o.origin?.label ? ' · جاي من ' + o.origin.label : ''}</p>
        {said(o) && <p className="mt-1 text-xs bg-white/5 rounded-lg px-2 py-1">💬 {said(o)}</p>}
        {(o.statusHistory || []).length > 0 && <div className="mt-2 flex flex-wrap gap-1">{o.statusHistory.slice(-6).map((h, i) => <span key={i} className="text-[10px] rounded bg-white/10 px-1.5 py-0.5">{ST[h.status] || h.status}{h.note ? ' · ' + h.note : ''}</span>)}</div>}
      </>)
    }
    if (s.kind === 'tech') { const t = s.item; return (<>
      <p className="font-black text-sm" style={{ color: t.color }}>{t.code ? t.code + ' · ' : ''}{t.name}</p>
      <p className="text-[11px] text-slate-400">{t.live ? '🟢 أونلاين' : '⚪ مش أونلاين'}{t.off ? ' · موقوف' : ''}{t.battery != null ? ' · 🔋' + t.battery + '%' : ''}{t.motion?.moving ? ' · بيتحرك ' + Math.round(t.motion.kmh || 0) + ' كم/س' : ''}</p>
      {t.out && <p className="text-xs mt-1">{ST[t.out.status]} عند {t.out.clientName || 'عميل'}{t.job?.etaMin && ['en_route', 'nearby'].includes(t.out.status) ? ` · يوصل بعد ~${t.job.etaMin} د (${t.job.km} كم)` : ''}</p>}
    </>) }
    if (s.kind === 'visitor') { const v = s.item; const src = SRC[v.src] || SRC.direct; return (<>
      <p className="font-black text-sm">{src[0]} زائر — {src[1]}</p>
      <p className="text-[11px] text-slate-400">{v.site} · {v.title || v.path}</p>
      <p className="text-[11px] text-slate-400">{v.live ? 'موجود دلوقتي' : 'آخر ظهور من ' + Math.round(v.ago / 60) + ' د'} · قعد {Math.round((v.stay || 0) / 60)} د · {v.pages || 1} صفحة · {v.mobile ? 'موبايل' : 'كمبيوتر'}{v.ordered ? ' · سبق وطلب' : ''}</p>
    </>) }
    if (s.kind === 'staff') return <p className="font-black text-sm">🎧 {s.item.name} · {s.item.role || 'عمليات'}</p>
    return (<>
      <p className="font-black text-sm">🧑‍💼 مكتب المالك</p>
      <p className="text-[11px] text-slate-400">اليوم: {W.newToday.length} طلب جديد · {W.done.length} اكتمل · {W.cancelled.length} اتلغى · مفتوح {W.open.length}{W.money ? ` · ${W.money} ر.س` : ''}</p>
    </>)
  }
}

function Room({ r }) {
  const s = (n) => n * U
  const wall = (style) => <div className="absolute" style={{ background: r.color + '30', border: `1px solid ${r.color}88`, ...style }} />
  return (
    <div className="absolute" style={{ left: s(r.x), top: s(r.y), width: s(r.w), height: s(r.d), transformStyle: 'preserve-3d' }}>
      <div className="absolute inset-0 rounded-md" style={{ background: `linear-gradient(135deg, ${r.color}26, ${r.color}0d)`, border: `2px solid ${r.color}aa`, boxShadow: `0 0 30px ${r.color}44` }} />
      {wall({ left: 0, top: 0, width: s(r.w), height: s(r.h), transformOrigin: 'top', transform: 'rotateX(-90deg)' })}
      {wall({ left: 0, top: 0, width: s(r.h), height: s(r.d), transformOrigin: 'left', transform: 'rotateY(90deg)' })}
      <div className="absolute text-[11px] font-black whitespace-nowrap px-2 py-0.5 rounded" style={{ right: 6, bottom: 4, background: r.color, color: '#05070d' }}>{r.name}</div>
    </div>
  )
}

// A person / car standing on the floor, always facing the viewer (undoes the floor's rotation).
function Ent({ x, y, rz, rx, emoji, badge, bubble, label, ring, dim, urgent, onClick }) {
  return (
    <div data-ent className="absolute" style={{ left: x * U, top: y * U, transformStyle: 'preserve-3d', transform: `rotateZ(${-rz}deg) rotateX(${-rx}deg)`, transformOrigin: '0 0', zIndex: Math.round(y * 10) }}>
      <button onClick={onClick} className="relative -translate-x-1/2 -translate-y-full flex flex-col items-center active:scale-95" style={{ opacity: dim ? 0.5 : 1 }}>
        {bubble && <span className="mb-0.5 max-w-[120px] truncate rounded-lg bg-white text-black text-[9px] font-bold px-1.5 py-0.5 shadow">{bubble}</span>}
        <span className="relative text-[22px] leading-none" style={{ filter: ring ? `drop-shadow(0 0 4px ${ring})` : undefined }}>
          {emoji}
          {badge && <span className="absolute -top-2 -left-2 text-[12px]">{badge}</span>}
          {urgent && <span className="absolute -top-2 -right-2 text-[11px] animate-pulse">⚡</span>}
        </span>
        {label && <span className="mt-0.5 max-w-[90px] truncate rounded bg-black/70 text-[8px] font-bold px-1 text-white">{label}</span>}
      </button>
    </div>
  )
}

function Btn({ icon: Icon, children, onClick, busy, tone }) {
  const c = { primary: 'bg-primary text-black border-primary', green: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40', amber: 'bg-amber-500/20 text-amber-300 border-amber-500/40', rose: 'bg-rose-500/15 text-rose-300 border-rose-500/40' }[tone] || 'bg-white/5 text-slate-200 border-white/10'
  return <button disabled={busy} onClick={onClick} className={`flex items-center justify-center gap-1.5 rounded-xl border px-2 py-2.5 text-xs font-black disabled:opacity-50 ${c}`}>{Icon && <Icon size={14} />}{busy ? '…' : children}</button>
}
