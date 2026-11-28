import { useEffect, useRef, useState } from 'react'
import { Phone, PhoneOff, X } from 'lucide-react'
import CallOverlay from './CallOverlay'
import CallLinkSheet from './CallLinkSheet'
import CallerCard from './CallerCard'
import { useLiveShared } from '../lib/useLive'
import { CallSession, setCallStatus, myCallId, sideOf } from '../lib/hotline'
import { db } from '../lib/db'
import { useHotlineConfig } from '../hooks/useHotline'
import { useTeamRole } from '../hooks/useTeamRole'
import { auth } from '../lib/auth'
import { enableBackgroundPush } from '../lib/push'
import { entryFor, infoOf, syncCalls } from '../lib/callbook'

// Operations side: rings (sound + notification) when a customer calls the hotline; answer/decline; talk inside the app.
// Every caller is shown with his CODE (and name / history when known); operations/owner also write each call to the call register (call_log).
function ringLoop() {
  let stop = false, ctx
  try { ctx = new (window.AudioContext || window.webkitAudioContext)() } catch { return () => {} }
  // No ring tone (owner decision): the call is announced by voice; the phone only vibrates while it waits.
  const beep = () => {
    if (stop) return
    try { navigator.vibrate?.([400, 200, 400]) } catch { /* ignore */ }
    setTimeout(beep, 2500)
  }
  ctx.resume?.().finally(beep)
  return () => { stop = true; try { ctx.close() } catch { /* ignore */ } }
}

// `personal`: the customer app — rings only for calls aimed at this device's account (a technician or operations calling about an order).
// On staff screens operations / owner / supervisor also ring for the hotline; a technician rings only for calls aimed at him.
export default function IncomingCalls({ personal = false }) {
  const { role, isOwner } = useTeamRole()
  const opsDesk = !personal && (isOwner || ['owner', 'operations', 'supervisor'].includes(role))
  const canLog = !personal && (isOwner || role === 'operations' || role === 'owner')
  const { data: calls, loading: lc } = useLiveShared('calls', { order: '-createdAt', limit: 20 })
  const { data: log, loading: ll } = useLiveShared('call_log', { order: '-createdAt', limit: personal ? 1 : 300 })
  const { data: contacts, loading: lk } = useLiveShared('call_contacts', { limit: personal ? 1 : 1000 })
  const me = myCallId()
  const opsLine = (c) => !c.to || c.to === 'ops'
  // Which ringing calls are this phone's to answer.
  const forMe = (c) => (c.from?.id || '') !== me && (c.toUser === me || (opsDesk && opsLine(c)))
  const cfg = useHotlineConfig()
  const [active, setActive] = useState(null)   // { id, name, phone, phase, stream, info }
  const [sheetId, setSheetId] = useState(null)
  const [after, setAfter] = useState(null)     // { id, code } — banner offered after an answered call
  const [tick, setTick] = useState(0)
  const ref = useRef({ s: null, live: false })
  const sync = useRef({ busy: false, again: false, seen: new Map() })
  const latest = useRef({})
  latest.current = { calls, log, contacts }
  const ready = !lc && !ll && !lk

  const fresh = (calls || []).filter((c) => c.status === 'ringing' && c.cSig?.sdp && Date.now() - new Date(c.createdAt).getTime() < 60000 && forMe(c))
  const ringing = !active && fresh.length ? fresh[0] : null
  // The caller's code card belongs to the hotline register; a call between the sides of a job shows who is calling instead.
  const info = ringing && ready && opsLine(ringing) && !personal ? infoOf(ringing, contacts, log) : null
  const shownName = (c, i) => i?.name || c?.from?.name || (c?.name && c.name !== 'عميل' ? c.name : '')
  const ringSide = ringing ? sideOf(ringing) : null

  useEffect(() => { const i = setInterval(() => setTick((n) => n + 1), 15000); return () => clearInterval(i) }, [])

  // Call register: one idempotent pass whenever the calls / log / contacts change (and every 15 s, so a call nobody answered turns "missed" by itself).
  useEffect(() => {
    if (!canLog || !ready) return
    const r = sync.current
    if (r.busy) { r.again = true; return }
    r.busy = true
    ;(async () => {
      do {
        r.again = false
        const { calls: c, log: l, contacts: k } = latest.current
        try { await syncCalls((c || []).filter(opsLine), l, k, r.seen) } catch { /* next change retries */ }
      } while (r.again)
    })().finally(() => { r.busy = false })
  }, [canLog, ready, calls, log, contacts, tick])

  useEffect(() => {
    if (!ringing) return
    const stop = ringLoop()
    try { if (document.hidden && 'Notification' in window && Notification.permission === 'granted') new Notification(`📞 مكالمة واردة من ${sideOf(ringing).label}`, { body: shownName(ringing, null) || '', tag: 'hotline', requireInteraction: true }) } catch { /* ignore */ }
    return stop
  }, [ringing?.id])   // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { if (!after) return; const t = setTimeout(() => setAfter(null), 40000); return () => clearTimeout(t) }, [after])

  function finish(id, code, phase) {
    ref.current.s?.close()
    if (ref.current.live && phase === 'ended' && canLog) setAfter({ id, code })
    ref.current.live = false
    setActive((a) => (a ? { ...a, phase } : a))
    setTimeout(() => setActive(null), 2500)
  }

  async function answer(c) {
    const inf = ready && opsLine(c) && !personal ? infoOf(c, contacts, log) : null
    ref.current.live = false
    setActive({ id: c.id, name: shownName(c, inf), phone: c.phone || inf?.phone || '', phase: 'connecting', info: inf, who: c.from?.role || 'client', ops: opsLine(c) })
    try {
      // Two operations devices ring together: only the first to answer takes the call (a second answer would overwrite the first one's line).
      const now = (await db.selectShared('calls', { id: c.id }, { limit: 1 }).catch(() => []))?.[0]
      if (now && now.status !== 'ringing') { setActive((a) => (a ? { ...a, phase: 'failed', note: now.status === 'active' ? 'رد على المكالمة جهاز آخر.' : 'انتهت المكالمة قبل الرد.' } : a)); setTimeout(() => setActive(null), 3000); return }
      c = now?.cSig?.sdp ? now : c
      const s = new CallSession({
        id: c.id, role: 'callee', turn: cfg.turn,
        onRemote: (stream) => setActive((a) => (a ? { ...a, stream } : a)),
        onState: (st) => {
          if (st === 'connected') { ref.current.live = true; setActive((a) => (a ? { ...a, phase: 'live' } : a)) }
          else if (['failed', 'ended', 'declined', 'missed'].includes(st)) finish(c.id, inf?.code, st === 'failed' ? 'failed' : 'ended')
        },
      })
      ref.current.s = s
      await s.startCallee(c)
      await setCallStatus(c.id, 'active', { answeredBy: auth.getCurrentUser()?.name || 'المشرف' })
    } catch (e) {
      ref.current.s?.close()
      // Say why: a blocked microphone needs a different fix from a network problem.
      const mic = e && /NotAllowed|Permission|Security|NotFound|NotReadable/i.test(`${e.name} ${e.message}`)
      setActive({ id: c.id, name: shownName(c, inf), phase: 'failed', info: inf, note: mic ? 'الميكروفون غير متاح لهذا التطبيق — اسمح بالميكروفون من إعدادات الجهاز/المتصفح ثم رد مرة أخرى.' : 'تعذر فتح الخط — أعد المحاولة.' })
      setTimeout(() => setActive(null), 6000)
    }
  }
  const hang = () => {
    if (!active) return
    if (ref.current.live && canLog && active.ops) setAfter({ id: active.id, code: active.info?.code })
    ref.current.live = false
    setCallStatus(active.id, 'ended').catch(() => {})
    ref.current.s?.close(); setActive(null)
  }
  const decline = (c) => setCallStatus(c.id, 'declined').catch(() => {})
  useEffect(() => { if (personal) return; try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission().then((r) => { if (r === 'granted') enableBackgroundPush() }) } catch { /* ignore */ } }, [])
  useEffect(() => () => ref.current.s?.close(), [])

  // The entry the link sheet works on: the call_log row if it exists, else what the call document says.
  function sheetFor(id) {
    const c = (calls || []).find((x) => x.id === id)
    const row = (log || []).find((l) => (l.callId || l.id) === id)
    const base = c ? entryFor(c, contacts) : null
    if (!base && !row) return null
    const code = row?.code || base.entry.code
    const contact = (contacts || []).find((x) => (x.code || x.id) === code) || base?.contact || null
    const keys = [...new Set([...(base?.keys || []), ...(contact?.keys || [])])]
    return { entry: { ...(base?.entry || {}), ...(row || {}), code, callId: id, keys }, contact }
  }
  const sheet = sheetId ? sheetFor(sheetId) : null

  return (
    <>
      {active && <CallOverlay who={active.who} title={active.name || (active.who === 'client' ? 'عميل غير مسجّل' : sideOf({ from: { role: active.who } }).label)} subtitle={active.phone} phase={active.phase} stream={active.stream} note={active.note} onHangup={hang} onMute={(m) => ref.current.s?.setMuted(m)}
        extra={active.info ? <CallerCard info={active.info} onLink={() => setSheetId(active.id)} /> : null} />}
      {!active && ringing && (
        <div className={`fixed z-[90] top-[calc(env(safe-area-inset-top,0px)+0.75rem)] left-1/2 -translate-x-1/2 w-[min(92vw,420px)] rounded-2xl bg-slate-900 border shadow-2xl p-3 text-white ${ringSide.ring}`} dir="rtl">
          <div className="flex items-center gap-3">
            <span className={`w-11 h-11 shrink-0 rounded-full flex items-center justify-center animate-pulse text-xl ${ringSide.badge}`}>{ringSide.icon}</span>
            <div className="flex-1 min-w-0">
              <p className="flex items-center gap-1.5"><span className={`rounded-full px-2 py-0.5 text-[10px] font-black ${ringSide.badge}`}>{ringSide.label}</span><span className="text-[11px] text-slate-400 font-bold">مكالمة واردة</span></p>
              <p className="font-black text-sm truncate mt-0.5">{shownName(ringing, info) || (ringing.from?.role === 'ops' ? 'مشرف العمليات' : 'عميل غير مسجّل')}</p>
              <p className="text-[11px] text-slate-400" dir="ltr">{ringing.orderId ? `#${String(ringing.orderId).slice(-6)}` : (ringing.phone || info?.phone || 'الخط الساخن')}</p>
            </div>
            <button onClick={() => decline(ringing)} aria-label="رفض" className="w-10 h-10 shrink-0 rounded-full bg-rose-500 flex items-center justify-center"><PhoneOff size={18} /></button>
            <button onClick={() => answer(ringing)} aria-label="رد" className="w-10 h-10 shrink-0 rounded-full bg-emerald-500 text-black flex items-center justify-center"><Phone size={18} /></button>
          </div>
          {info && <div className="mt-2"><CallerCard info={info} compact /></div>}
        </div>
      )}
      {!active && after && (
        <div className="fixed z-[90] bottom-[calc(env(safe-area-inset-bottom,0px)+5rem)] left-1/2 -translate-x-1/2 w-[min(92vw,420px)] rounded-2xl bg-slate-900 border border-sky-400/40 shadow-2xl p-3 flex items-center gap-3 text-white" dir="rtl">
          {after.code && <span className="font-mono text-[11px] bg-sky-500/15 text-sky-300 rounded-md px-1.5 py-0.5 font-black shrink-0">{after.code}</span>}
          <p className="flex-1 text-xs font-bold leading-relaxed">سُجّلت المكالمة — اربطها بعميل أو طلب أو محادثة</p>
          <button onClick={() => { setSheetId(after.id); setAfter(null) }} className="shrink-0 rounded-lg bg-primary text-black text-[11px] font-black px-3 py-2">ربط الآن</button>
          <button onClick={() => setAfter(null)} aria-label="إغلاق" className="shrink-0 w-7 h-7 rounded-full bg-white/10 flex items-center justify-center"><X size={14} /></button>
        </div>
      )}
      {sheet && <CallLinkSheet key={sheetId} entry={sheet.entry} contact={sheet.contact} onClose={() => setSheetId(null)} />}
    </>
  )
}
