import ServiceDocs from '../components/ServiceDocs'
import { orderFacts } from '../lib/orderFacts'
import TechTools from '../components/TechTools'
import { sendOrderMsg } from '../lib/orderMsgs'
import { useState, useMemo, useEffect, useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { StaffCallCard } from '../components/OrderCalls'
import { ChevronRight, MapPin, Wrench, Navigation, CheckCircle2, XCircle, Send, Sparkles, GitBranch, Wallet, AlertTriangle, Loader2, Phone, Clock, Ruler } from 'lucide-react'
import { notify, crossedMilestone, fmtDistance } from '../notify'
import { useNotifySettings } from '../hooks/useNotifySettings'
import { typeMeta } from '../hooks/usePaymentMethods'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { useAiSettings } from '../hooks/useAiSettings'
import { scoreTechnicians } from '../assignment'
import { TRANSFER_CHAIN, currentHandler, allowedTransfers, handlerLabel, escalationLabel, PAYMENT_METHODS } from '../workflow'
import { usePaymentMethods } from '../hooks/usePaymentMethods'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { auth } from '../lib/auth'
import { team } from '../lib/team'
import { social } from '../lib/social'
import { push } from '../lib/push'
import { maps } from '../lib/maps'
import MapView from '../components/MapView'
import StatusBadge from '../components/StatusBadge'
import StatusTimeline from '../components/StatusTimeline'
import { SPECIALTIES } from '../constants'
import { brandMeta } from '../lib/netBrand'
import OriginCard from '../components/OriginCard'
import OrderFollowUp from '../components/ops/OrderFollowUp'
import { owner } from '../lib/ownerApi'
import AbortJob from '../components/AbortJob'
import TechJobActions from '../components/TechJobActions'
import { orderNo } from '../lib/orderNo'
import ReceiptProof from '../components/ReceiptProof'
import PreviewEye from '../components/PreviewEye'
import TransferSheet from '../components/TransferSheet'

export default function OrderDetailStaff() {
  const { methods: managedMethods } = usePaymentMethods()
  const { id } = useParams()
  const navigate = useNavigate()
  const { data: orders, loading, refetch } = useLiveShared('orders', { limit: 300 })
  // A brand-new order (opened from a notification, or right after it was sent) may not be in the list this screen
  // already holds: ask the server once more before saying it does not exist.
  const [miss, setMiss] = useState({ id: '', done: false })
  // The list of 300 can be slow on a weak connection: fetch this one order on its own at the same time, so the
  // page opens as soon as IT arrives, and never sits on «جارِ التحميل» forever.
  const [single, setSingle] = useState(null)
  const [slow, setSlow] = useState(false)
  useEffect(() => {
    let live = true
    setSingle(null); setSlow(false)
    if (!id) return
    db.selectShared('orders', { id }, { limit: 1 }).then((r) => { if (live && Array.isArray(r) && r[0]) setSingle(r[0]) }).catch(() => {})
    const t = setTimeout(() => { if (live) setSlow(true) }, 12000)
    return () => { live = false; clearTimeout(t) }
  }, [id])
  const order = useMemo(() => orders.find((o) => o.id === id) || (single && single.id === id ? single : null), [orders, id, single])
  useEffect(() => {
    if (loading || order || !id || miss.id === id) return
    setMiss({ id, done: false })
    Promise.resolve(refetch()).catch(() => {}).finally(() => setMiss({ id, done: true }))
    // eslint-disable-next-line
  }, [loading, !!order, id])
  const realId = auth.getCurrentUser()?.id
  const { profiles } = useTechProfiles()
  const { settings: aiSettings } = useAiSettings()
  const serverPush = aiSettings.followUp.serverPush
  const { settings: notifySettings } = useNotifySettings()
  const [contactNote, setContactNote] = useState('')
  const [showContact, setShowContact] = useState(false)
  const [showAbort, setShowAbort] = useState(false)
  const [progressNote, setProgressNote] = useState('')
  const [showProgress, setShowProgress] = useState(false)
  const [flash, setFlash] = useState('')

  const [gid, setGid] = useState(null)
  const [transferTo, setTransferTo] = useState(null)
  const [transferNote, setTransferNote] = useState('')
  const [role, setRole] = useState(null)
  // «ادخل مكانه» carries on into the order the owner opened from the technician's screen (live test 2026-11-23: the order
  // page fell back to the owner, so there was no «قبول» and nothing could be done in his name)
  const asTech = (() => { try { return sessionStorage.getItem('mq_as_tech') || '' } catch { return '' } })()
  const actingAs = (role === 'owner' || role === 'operations') && asTech ? asTech : ''
  const myId = actingAs || realId
  const byWho = actingAs ? 'technician' : role   // a step done in his place is his step (the owner is kept in actedBy)
  const myName = (actingAs ? (profiles || []).find((p) => p.userId === actingAs)?.displayName : '') || auth.getCurrentUser()?.displayName || 'فني'
  const [busy, setBusy] = useState(false)
  const [showAssign, setShowAssign] = useState(false)
  const [showComplete, setShowComplete] = useState(false)
  const [report, setReport] = useState('')
  const [spareParts, setSpareParts] = useState('')
  // What the technician learned about the customer, written while it is fresh — the office reads it
  // on the customer's card the next time this customer calls.
  const [onClient, setOnClient] = useState({ stars: 0, tags: [], note: '' })
  const [completeErr, setCompleteErr] = useState('')
  const [price, setPrice] = useState('')
  const [receiptCode, setReceiptCode] = useState('')
  const watcherRef = useRef(null)
  const orderRef = useRef(order)
  orderRef.current = order
  const nsRef = useRef(notifySettings)
  nsRef.current = notifySettings
  const distDoneRef = useRef(null)
  const fire = (eventId, o, extra) => notify(eventId, o || order, { settings: notifySettings, serverPush, extra }).catch(() => {})
  const toast = (m) => { setFlash(m); setTimeout(() => setFlash(''), 2400) }

  useEffect(() => {
    async function init() {
      const g = await team.groupId()
      setGid(g)
      const isOwner = auth.isAppOwner()
      setRole(isOwner ? 'owner' : await social.getMyRole(g))
    }
    init()
  }, [])

  const isMyTask = order?.technicianId === myId
  const canAssign = role === 'operations' || role === 'owner'

  // Live location sharing while this technician is on the way (en_route / nearby):
  // distance to the customer is measured from the REAL device position, and the
  // distance-milestone + "nearby" notifications fire from it.
  const tracking = !!order && isMyTask && ['en_route', 'nearby'].includes(order.status)
  useEffect(() => {
    if (!tracking) {
      if (watcherRef.current) { watcherRef.current.stop(); watcherRef.current = null }
      return
    }
    if (distDoneRef.current == null) distDoneRef.current = orderRef.current?.distanceNotifiedKm || []
    let lastWrite = 0
    const watcher = maps.watchLocation(async ({ lat, lng }) => {
      const o = orderRef.current
      if (!o || o.lat == null) return
      const ns = nsRef.current
      try {
        const meters = Math.round(maps.distance(lat, lng, o.lat, o.lng))
        const now = new Date().toISOString()
        const patch = { technicianLocation: { lat, lng, updatedAt: now }, technicianDistanceM: meters }
        const hit = crossedMilestone(meters, ns.distanceMilestonesKm, distDoneRef.current)
        if (hit) {
          distDoneRef.current = hit.done
          patch.distanceNotifiedKm = hit.done
        }
        const becameNearby = o.status === 'en_route' && meters <= (Number(ns.nearbyMeters) || 300)
        if (becameNearby) {
          patch.status = 'nearby'
          patch.statusHistory = [...(o.statusHistory || []), { status: 'nearby', at: now, by: 'system', note: fmtDistance(meters) }]
        }
        // throttle plain position writes to one every ~15s; events always write
        if (!hit && !becameNearby && Date.now() - lastWrite < 15000) return
        lastWrite = Date.now()
        await db.updateShared('orders', o.id, patch)
        if (becameNearby) notify('nearby', { ...o, ...patch }, { settings: ns, extra: { meters } }).catch(() => {})
        else if (hit) notify('distance', { ...o, ...patch }, { settings: ns, extra: { meters } }).catch(() => {})
      } catch {}
    })
    watcherRef.current = watcher
    return () => watcher.stop()
  }, [tracking])

  if (!order && slow) return <Centered>الاتصال بطيء — <button onClick={() => { setSlow(false); refetch() }} className="underline font-black">إعادة المحاولة</button></Centered>
  if (!order && (loading || !(miss.id === id && miss.done))) return <Centered>جارِ التحميل…</Centered>
  if (!order) return <Centered>لم يتم العثور على الطلب</Centered>

  const spec = SPECIALTIES.find((s) => s.id === order.specialtyId) || { icon: '🔧', label: order.specialtyId }

  async function transition(status, extra = {}, eventId) {
    setBusy(true)
    try {
      await db.updateShared('orders', order.id, {
        status,
        statusHistory: [...(order.statusHistory || []), { status, at: new Date().toISOString(), by: byWho, ...(actingAs ? { actedBy: role } : {}) }],
        ...extra,
      })
      if (eventId) fire(eventId, { ...order, status, ...extra })
    } finally {
      setBusy(false)
    }
  }

  // Move the order along the MUTQAN chain — only to parties this role may reach
  async function doTransfer() {
    if (!transferTo) return
    setBusy(true)
    try {
      const now = new Date().toISOString()
      const from = currentHandler(order)
      const patch = {
        handler: transferTo,
        transferLog: [...(order.transferLog || []), { from, to: transferTo, at: now, by: role, note: transferNote.trim() }],
      }
      if (['supervisor', 'owner'].includes(transferTo)) patch.escalation = { reason: order.escalation?.reason || 'manual', level: transferTo, at: now, by: role, note: transferNote.trim() }
      if (transferTo === 'operations' && from === 'technician' && order.technicianId === myId) {
        Object.assign(patch, { status: 'new', technicianId: null, technicianName: null, statusHistory: [...(order.statusHistory || []), { status: 'new', at: now, by: role, note: 'إعادة لغرفة العمليات' }] })
      }
      await db.updateShared('orders', order.id, patch)
      const note = { title: `طلب محوّل إلى ${handlerLabel(transferTo)}`, body: `${spec.label} — ${order.clientName}${transferNote ? ` · ${transferNote}` : ''}`, url: `#/staff/order/${order.id}`, sound: 'bell' }
      try {
        if (transferTo === 'supervisor' || transferTo === 'operations') await push.sendToRole(gid, transferTo, note)
        else await push.sendToAdmins(gid, note)
      } catch {
        try { await push.sendToAdmins(gid, note) } catch {}
      }
      setTransferTo(null)
      setTransferNote('')
    } catch {
      alert('تعذر نقل الطلب — تحقق من صلاحياتك')
    } finally {
      setBusy(false)
    }
  }

  async function recordPayment(method) {
    setBusy(true)
    try {
      await db.updateShared('orders', order.id, { paymentStatus: 'paid', paymentMethod: method, paidAt: new Date().toISOString(), paidRecordedBy: role })
      const m = managedMethods.find((x) => x.id === method)
      const isTransfer = ['bank_transfer', 'wallet_app'].includes(m?.type)
      fire(isTransfer ? 'transfer' : 'paid', { ...order, paymentStatus: 'paid', paymentMethod: method }, { amount: order.price || null, method: m?.name || typeMeta(m?.type).label })
    } finally {
      setBusy(false)
    }
  }

  async function assignTechnician(tech) {
    setBusy(true)
    try {
      await db.updateShared('orders', order.id, {
        technicianId: tech.userId || tech.id,
        technicianName: tech.displayName || tech.name || 'فني',
        assignedAt: new Date().toISOString(),
        forced: false,
        status: 'assigned',
        handler: 'technician',
        transferLog: [...(order.transferLog || []), { from: currentHandler(order), to: 'technician', at: new Date().toISOString(), by: role, note: tech.displayName || '' }],
        statusHistory: [...(order.statusHistory || []), { status: 'assigned', at: new Date().toISOString(), by: role }],
      })
      distDoneRef.current = []
      fire('assigned', { ...order, technicianId: tech.userId || tech.id, technicianName: tech.displayName || tech.name || 'فني', status: 'assigned' })
      setShowAssign(false)
    } finally {
      setBusy(false)
    }
  }

  async function acceptForced() {
    setBusy(true)
    try {
      await db.updateShared('orders', order.id, {
        technicianId: myId,
        technicianName: myName,
        assignedAt: new Date().toISOString(),
        forced: false,
        status: 'assigned',
        statusHistory: [...(order.statusHistory || []), { status: 'assigned', at: new Date().toISOString(), by: role }],
      })
      fire('accepted', { ...order, technicianId: myId, technicianName: myName, status: 'assigned' })
    } finally {
      setBusy(false)
    }
  }

  async function completeService() {
    setBusy(true); setCompleteErr('')
    try {
      const view = (onClient.stars || onClient.tags.length || onClient.note.trim())
        ? { techOnClient: { stars: onClient.stars || null, tags: onClient.tags, note: onClient.note.trim(), by: order.technicianName || '', at: new Date().toISOString() } }
        : {}
      // «كود الاستلام» (owner, 2026-11-23 — idea 12): the customer reads the «رمز الطلب» from his own WhatsApp message to
      // the technician. Optional: a job closes with it or without it; a matching code is written as the customer's receipt.
      const rc = receiptCode.trim().toUpperCase()
      const code = receiptCodeOf(order)
      const receipt = rc && code && rc === code ? { customerReceipt: { at: new Date().toISOString(), via: 'code', by: order.technicianName || role } } : {}
      await db.updateShared('orders', order.id, {
        ...receipt,
        status: 'completed',
        report,
        spareParts,
        price: price ? Number(price) : 0,
        completedAt: new Date().toISOString(),
        statusHistory: [...(order.statusHistory || []), { status: 'completed', at: new Date().toISOString(), by: byWho, ...(actingAs ? { actedBy: role } : {}) }],
        ...view,
      })
      fire('completed', { ...order, status: 'completed' })
      setShowComplete(false)
    } catch (e) {
      // A refused price (below the owner's floor) used to vanish here: the sheet stayed open and
      // nothing said why. The server's reason is shown where the technician is looking.
      setCompleteErr(e?.message || 'تعذّر إنهاء الخدمة — حاول مرة أخرى')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="h-full overflow-y-auto pb-32 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-2xl lg:max-w-6xl mx-auto w-full px-4 py-4 flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center">
            <ChevronRight size={18} className="text-slate-300" />
          </button>
          <h1 className="font-display text-lg font-black text-white truncate">{spec.icon} {spec.label}</h1>
          <div className="mr-auto flex items-center gap-2">
            {['owner', 'operations', 'supervisor'].includes(role) && <PreviewEye url={order.sealUrl || ''} label="" title="صفحة الطلب زي ما العميل شايفها (بعد أي تعديل)" className="!px-2" />}
            <StatusBadge status={order.status} />
          </div>
        </div>
      </header>

      <div className="max-w-2xl lg:max-w-6xl mx-auto w-full px-4 pt-5 flex flex-col gap-5 wide-cols">
        <Card>
          <p className="text-xs font-bold text-slate-500 mb-1">العميل</p>
          <p className="text-white font-bold">{order.clientName}</p>
          <p className="text-slate-400 text-sm" dir="ltr">{order.clientPhone}</p>
          {order.priority === 'urgent' && <p className="text-rose-400 text-xs font-bold mt-1">أولوية عاجلة</p>}
          {order.history?.text && (
            <div className="mt-2 rounded-xl border border-amber-400/40 bg-amber-500/10 px-3 py-2 text-[12px] text-amber-100 whitespace-pre-line font-bold" data-testid="order-history">
              {order.history.repeat ? '✅ تم العمل عنده قبل كده\n' : ''}{order.history.text}
            </div>
          )}
          {['owner', 'operations'].includes(role) ? (
            <button onClick={() => navigate('/staff/invoices?order=' + order.id)} className="mt-2 rounded-xl bg-sky-500/15 text-sky-200 border border-sky-400/30 px-3 py-1.5 text-xs font-bold" data-testid="order-invoice">
              🧾 {order.invoice ? `فاتورة #${order.invoice.number} اتعملت — إصدار فاتورة تانية` : 'إصدار فاتورة (عادية أو ضريبية)'}
            </button>
          ) : null}
        </Card>

        {actingAs && <div className="rounded-xl bg-amber-400/90 text-black text-xs font-black px-3 py-2">👤 أنت داخل مكان الفني: {myName} — أي قبول أو تحديث بيتسجّل باسمه</div>}
        <StaffCallCard order={order} role={role} isMyTask={isMyTask} />

        {(order.escalation || order.outsideServiceArea || order.serviceFeedback === 'not_ok') && (
          <div className="bg-rose-500/10 border border-rose-500/30 rounded-2xl p-3 flex flex-col gap-1">
            {order.escalation && (
              <p className="text-rose-200 text-xs font-bold flex items-center gap-1.5">
                <AlertTriangle size={13} /> تصعيد: {order.escalation.reason === 'manual' ? 'يدوي' : escalationLabel(order.escalation.reason)} → {handlerLabel(order.escalation.level)}
                {order.escalation.note ? ` · ${order.escalation.note}` : ''}
              </p>
            )}
            {order.outsideServiceArea && <p className="text-amber-300 text-xs font-bold">⚠️ موقع العميل خارج نطاق الخدمة المعتمد</p>}
            {order.serviceFeedback === 'not_ok' && <p className="text-rose-300 text-xs font-bold">العميل أفاد أن الخدمة لم تتم بالشكل المطلوب</p>}
            {order.escalation && (role === 'operations' || role === 'owner' || role === 'supervisor') && (
              <button
                disabled={busy}
                onClick={async () => {
                  setBusy(true)
                  try {
                    await db.updateShared('orders', order.id, {
                      escalation: null,
                      transferLog: [...(order.transferLog || []), { from: currentHandler(order), to: currentHandler(order), at: new Date().toISOString(), by: role, note: 'تمت معالجة التصعيد' }],
                    })
                  } finally { setBusy(false) }
                }}
                className="self-start mt-1 text-[11px] font-bold text-emerald-300 bg-emerald-500/10 border border-emerald-500/30 rounded-lg px-2.5 py-1"
              >
                ✓ تمت المعالجة — إغلاق التصعيد
              </button>
            )}
          </div>
        )}


        {order.address && (
          <Card>
            <p className="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1"><MapPin size={12} /> الموقع</p>
            <p className="text-sm text-slate-300 mb-2">{order.address}</p>
            {/* An order typed in by hand or taken on WhatsApp may have an address but no pin: the map crashed the whole page. */}
            {Number.isFinite(Number(order.lat)) && Number.isFinite(Number(order.lng)) && order.lat !== null && order.lng !== null && (
              <div className="rounded-xl overflow-hidden border border-white/10">
                <MapView center={[order.lat, order.lng]} zoom={14} markers={[{ lat: order.lat, lng: order.lng, label: '📍' }]} className="h-40" />
              </div>
            )}
          </Card>
        )}

        {(() => {
          const f = orderFacts(order)
          const rows = [['🏷️', 'الموقع الذي طُلب منه', order.brand && brandMeta(order.brand) && !brandMeta(order.brand).core ? brandMeta(order.brand).name : ''], ['🧰', 'نوع الطلب', f.kind], ['❄️', f.unitsLabel, f.units], ['📝', 'التفاصيل', f.note], ['💰', 'السعر المتفق', (() => { const v = Number(order.agreedPrice || order.price || order.acQuote?.total || 0); return v > 0 ? `${v} ر.س` : '' })()], ['📅', 'الموعد', f.when], ['🕐', 'وقت إرسال الطلب', f.sentAt], ['🧾', 'فاتورة ضريبية', order.taxInvoice ? `مطلوبة${order.invoiceVat ? ` — ${order.invoiceVat}` : ''}` : '']].filter((r) => r[2])
          return rows.length ? (
            <Card>
              <p className="text-xs font-bold text-slate-500 mb-2">تفاصيل الطلب</p>
              <div className="grid gap-1.5">{rows.map(([i, l, v]) => <p key={l} className="text-sm text-white"><span className="text-slate-400 text-xs font-bold">{i} {l}: </span><b>{v}</b></p>)}</div>
            </Card>
          ) : null
        })()}

        {(role === 'owner' || role === 'operations' || role === 'supervisor') && <OriginCard order={order} />}

        {order.technicianName && (
          <Card>
            <p className="text-xs font-bold text-slate-500 mb-1 flex items-center gap-1"><Wrench size={12} /> الفني المسند</p>
            <p className="text-white font-bold">{order.technicianName}</p>
            {order.technicianDistanceM != null && ['en_route', 'nearby'].includes(order.status) && (
              <p className={`mt-1 text-xs font-bold flex items-center gap-1 ${order.technicianDistanceM <= (Number(notifySettings.nearbyMeters) || 300) ? 'text-emerald-400' : 'text-amber-300'}`}>
                <Ruler size={12} /> المسافة للعميل: {fmtDistance(order.technicianDistanceM)}
                {order.technicianDistanceM <= (Number(notifySettings.nearbyMeters) || 300) ? ' · قريب' : ' · بعيد'}
                {order.technicianLocation?.updatedAt && <span className="text-slate-500 font-normal"> · آخر تحديث {new Date(order.technicianLocation.updatedAt).toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' })}</span>}
              </p>
            )}
            {tracking && <p className="text-[10px] text-slate-500 mt-1">يُرسل موقعك مباشرة طالما هذه الشاشة مفتوحة</p>}
          </Card>
        )}

        <Card>
          <p className="text-xs font-bold text-slate-500 mb-3">حالة الطلب</p>
          <StatusTimeline status={order.status} order={order} />
        </Card>

        {/* follow-up: what is stuck, the order's clock, the team's internal notes (owner, 2026-11-23) */}
        {(role === 'owner' || role === 'operations' || role === 'supervisor' || role === 'technician') && <OrderFollowUp order={order} role={role} />}

        {role === 'owner' && (
          <button onClick={async () => {
            if (!window.confirm(`حذف الطلب #${order.number || orderNo(order)} نهائيًا؟ مينفعش يرجع.`)) return
            try { await owner.deleteOrder(order.id); navigate('/staff/orders-board', { replace: true }) } catch (e) { setFlash(e?.message || 'تعذر الحذف') }
          }} className="w-full flex items-center justify-center gap-1.5 rounded-2xl border border-rose-500/40 bg-rose-500/10 py-2.5 text-sm font-bold text-rose-200">🗑️ حذف الطلب (المالك)</button>
        )}

        {/* Order transfer chain: AI → Operations → Technician → Supervisor → Owner */}
        {(() => {
          const from = currentHandler(order)
          const targets = allowedTransfers(role, from, isMyTask)
          return (
            <Card>
              <p className="text-xs font-bold text-slate-500 mb-3 flex items-center gap-1"><GitBranch size={12} /> مسار الطلب والجهة المسؤولة</p>
              <div className="flex flex-wrap items-center gap-1 mb-3">
                {TRANSFER_CHAIN.map((s, i) => (
                  <span key={s.id} className="flex items-center gap-1">
                    <span className={`rounded-lg px-2 py-1 text-[10px] font-bold border ${s.id === from ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-400'}`}>{s.label}</span>
                    {i < TRANSFER_CHAIN.length - 1 && <span className="text-slate-600 text-[10px]">←</span>}
                  </span>
                ))}
              </div>
              {(order.transferLog || []).length > 0 && (
                <div className="flex flex-col gap-1 mb-3">
                  {order.transferLog.slice(-4).map((t, i) => (
                    <p key={i} className="text-[10px] text-slate-400">
                      {handlerLabel(t.from)} ← <b className="text-slate-200">{handlerLabel(t.to)}</b>{t.note ? ` · ${t.note}` : ''} <span className="text-slate-600" dir="ltr">{new Date(t.at).toLocaleString('ar')}</span>
                    </p>
                  ))}
                </div>
              )}
              {targets.length > 0 ? (
                <div className="flex flex-wrap gap-1.5">
                  {targets.map((t) => (
                    <button key={t} onClick={() => setTransferTo(t)} className={`rounded-xl px-3 py-2 text-[11px] font-bold border ${transferTo === t ? 'bg-amber-500/20 border-amber-500 text-amber-200' : 'bg-white/5 border-white/10 text-slate-300'}`}>
                      نقل إلى {handlerLabel(t)}
                    </button>
                  ))}
                </div>
              ) : (
                <p className="text-[10px] text-slate-600">لا تملك صلاحية نقل الطلب من مرحلته الحالية</p>
              )}
              {transferTo && (
                <div className="mt-2 flex gap-2">
                  <input value={transferNote} onChange={(e) => setTransferNote(e.target.value)} placeholder="سبب النقل (اختياري)" className="flex-1 min-w-0 bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-xs text-white" />
                  <button disabled={busy} onClick={doTransfer} className="shrink-0 bg-amber-500 text-black font-black rounded-xl px-3 text-xs disabled:opacity-50">تأكيد</button>
                </div>
              )}
            </Card>
          )
        })()}

        {!order.inhouse && ['arrived', 'in_progress', 'completed', 'closed'].includes(order.status) && (isMyTask || role === 'owner' || role === 'operations') && (
          <ServiceDocs order={order} canEdit={['arrived', 'in_progress', 'completed'].includes(order.status)} />
        )}

        {!order.inhouse && (order.status === 'completed' || order.status === 'closed') && (
          <Card>
            <p className="text-xs font-bold text-slate-500 mb-2">تقرير الخدمة</p>
            {order.report && <p className="text-sm text-slate-300 mb-1">{order.report}</p>}
            {order.spareParts && <p className="text-xs text-slate-400 mb-2">قطع الغيار: {order.spareParts}</p>}
            <p className="text-primary font-black">{order.price ? `${order.price} ر.س` : '—'}</p>
            {order.priceSetBy === 'technician' && order.quotedTotal > 0 && Number(order.price) !== Number(order.quotedTotal) && (
              <p className={`text-xs font-bold mt-1 ${order.priceReview ? 'text-rose-300' : 'text-slate-400'}`}>
                {order.priceReview ? '⚠️ ' : ''}السعر كتبه الفني عند الإنهاء — العميل كان معروض عليه {order.quotedTotal} ر.س{order.priceReview ? ' (أعلى بأكثر من النصف — راجعه قبل التحصيل)' : ''}
              </p>
            )}
            {order.welcomeDiscountPct > 0 && <p className="text-amber-400 text-xs font-bold mt-1">🎁 عميل جديد: خصم {order.welcomeDiscountPct}% حتى {order.welcomeDiscountMax || 20} ر.س{order.acQuote?.welcomeOff ? ` — مخصوم فعليًا ${order.acQuote.welcomeOff} ر.س (السعر النهائي ${order.acQuote.total} ر.س)` : ''}</p>}
            {order.rating && <p className="text-amber-400 text-sm mt-2">تقييم العميل: {order.rating}/5</p>}
            <div className="border-t border-white/10 mt-3 pt-3">
              <p className="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1"><Wallet size={12} /> الدفع</p>
              {order.paymentStatus !== 'paid' && order.onlinePayment?.status && (
                <p className={`text-[11px] font-bold mb-2 ${order.onlinePayment.status === 'failed' ? 'text-rose-400' : 'text-amber-400'}`}>
                  دفع إلكتروني: {order.onlinePayment.status === 'failed' ? 'لم يكتمل (أُلغي أو فشل)' : 'رابط دفع أُنشئ — بانتظار تأكيد مزود الدفع'}
                </p>
              )}
              {order.paymentStatus === 'paid' ? (
                <p className="text-emerald-400 text-sm font-bold">✓ مدفوع — {order.paymentMethod === 'online' ? 'إلكترونيًا (مؤكّد من مزود الدفع — يطابَق مع لوحة حسابك)' : (managedMethods.find((m) => m.id === order.paymentMethod)?.name || PAYMENT_METHODS.find((m) => m.id === order.paymentMethod)?.label || '')}</p>
              ) : isMyTask || role === 'operations' || role === 'owner' ? (
                <>
                {order.paymentClaim && <p className="text-amber-400 text-[11px] font-bold mb-2">العميل فتح رابط «{order.paymentClaim.name}» — تحقق من وصول المبلغ ثم اضغط «استلمت» أدناه.</p>}
                {order.paymentProof?.at && ['operations', 'owner', 'supervisor'].includes(role) && (
                  <ReceiptProof order={order} methods={managedMethods} busy={busy} onConfirm={(m) => recordPayment(m)} />
                )}
                {(role === 'operations' || role === 'owner') && (
                  <PayLinkField order={order} />
                )}
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                  {managedMethods.filter((m) => m.enabled && m.type !== 'online').map((m) => (
                    <button key={m.id} disabled={busy} onClick={() => recordPayment(m.id)} className="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-xl py-2 text-[11px] font-bold disabled:opacity-50">
                      استلمت {m.name}
                    </button>
                  ))}
                </div>
                <TransferSheet amount={Number(order.price || order.agreedPrice || order.quotedTotal || 0)} />
                </>
              ) : (
                <p className="text-slate-500 text-xs">لم يُسجّل الدفع بعد</p>
              )}
            </div>
          </Card>
        )}

        {order.inhouse && (
          <Card>
            <p className="text-sm font-black text-sky-300">🏗️ تنفيذ داخلي — بواسطة الشركة</p>
            <p className="text-[11px] text-slate-400 mt-1">غير محتسب في الرصيد أو النسبة أو الدفع. العميل يرى أن الطلب مُسند لفني وجارٍ التنفيذ.</p>
            {(role === 'owner' || role === 'operations') && !['completed', 'closed', 'cancelled'].includes(order.status) && (
              <div className="flex gap-2 mt-3">
                <button disabled={busy} onClick={() => transition('completed', { completedAt: new Date().toISOString() })} className="flex-1 bg-emerald-500 text-black font-black rounded-xl py-2.5 text-sm disabled:opacity-50">تم التنفيذ</button>
                <button disabled={busy} onClick={() => transition('cancelled')} className="flex-1 bg-white/5 border border-white/10 text-slate-300 font-bold rounded-xl py-2.5 text-sm disabled:opacity-50">إلغاء</button>
              </div>
            )}
          </Card>
        )}

        {/* Operations: assign a technician */}
        {canAssign && order.status === 'new' && !order.inhouse && (
          <button onClick={() => setShowAssign(true)} className="w-full bg-primary text-black font-display font-black rounded-2xl py-3.5">
            إسناد لفني
          </button>
        )}

        {/* Technician: accept a mandatory (forced) task */}
        {(role === 'technician' || !!actingAs) && order.status === 'forced' && !order.technicianId && (
          <div className="flex flex-col gap-2">
            <p className="text-rose-300 text-xs font-bold bg-rose-500/10 border border-rose-500/30 rounded-xl p-3 text-center">
              هذه مهمة إسناد إجباري — لن تُربط بك إلا بموافقتك
            </p>
            <button disabled={busy} onClick={acceptForced} className="w-full flex items-center justify-center gap-1.5 bg-rose-500/20 border border-rose-500/40 text-rose-200 font-bold rounded-2xl py-3.5">
              <CheckCircle2 size={16} /> قبول المهمة وربطها بي
            </button>
          </div>
        )}

        {/* Technician actions */}
        {isMyTask && order.status === 'assigned' && (
          <div className="flex gap-2">
            <button disabled={busy} onClick={() => transition('accepted', {}, 'accepted')} className="flex-1 flex items-center justify-center gap-1.5 bg-emerald-500/15 border border-emerald-500/40 text-emerald-300 font-bold rounded-2xl py-3">
              <CheckCircle2 size={16} /> قبول المهمة
            </button>
            <button
              disabled={busy}
              onClick={async () => {
                setBusy(true)
                await db.updateShared('orders', order.id, { status: 'new', technicianId: null, technicianName: null, statusHistory: [...(order.statusHistory || []), { status: 'new', at: new Date().toISOString(), by: byWho, ...(actingAs ? { actedBy: role } : {}), note: 'رفض الفني' }] })
                fire('rejected', order)
                setBusy(false)
              }}
              className="flex-1 flex items-center justify-center gap-1.5 bg-rose-500/15 border border-rose-500/40 text-rose-300 font-bold rounded-2xl py-3"
            >
              <XCircle size={16} /> رفض
            </button>
          </div>
        )}

        {isMyTask && order.status === 'accepted' && (
          <button disabled={busy} onClick={() => transition('en_route', { distanceNotifiedKm: [] }, 'en_route')} className="w-full flex items-center justify-center gap-1.5 bg-amber-500/15 border border-amber-500/40 text-amber-300 font-bold rounded-2xl py-3.5">
            <Navigation size={16} /> بدء التحرك للموقع
          </button>
        )}

        {isMyTask && (order.status === 'en_route' || order.status === 'nearby') && (
          <button disabled={busy} onClick={async () => { await transition('arrived', {}, 'arrived'); sendOrderMsg(order.id, 'arrived', '').catch(() => {}) }} className="w-full bg-teal-500/15 border border-teal-500/40 text-teal-300 font-bold rounded-2xl py-3.5">
            لقد وصلت
          </button>
        )}

        {isMyTask && order.status === 'arrived' && (
          <button disabled={busy} onClick={() => transition('in_progress', {}, 'started')} className="w-full bg-teal-600/15 border border-teal-600/40 text-teal-300 font-bold rounded-2xl py-3.5">
            بدء تنفيذ العمل
          </button>
        )}

        {isMyTask && order.status === 'in_progress' && (
          <div className="flex flex-col gap-2">
            {showProgress ? (
              <div className="flex gap-2">
                <input value={progressNote} onChange={(e) => setProgressNote(e.target.value)} placeholder="ملاحظة للعميل (مثل: باقي ساعة)" className="flex-1 min-w-0 bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-xs text-white" />
                <button
                  onClick={() => { fire('progress', order, { note: progressNote.trim() }); setShowProgress(false); setProgressNote(''); toast('أُرسل للعميل: العمل ما زال جاريًا') }}
                  className="shrink-0 bg-sky-500 text-black font-black rounded-xl px-3 text-xs"
                >إرسال</button>
              </div>
            ) : (
              <button onClick={() => setShowProgress(true)} className="w-full flex items-center justify-center gap-1.5 bg-sky-500/10 border border-sky-500/30 text-sky-300 font-bold rounded-2xl py-3 text-sm">
                <Clock size={15} /> إشعار العميل: العمل ما زال جاريًا
              </button>
            )}
          <button onClick={() => { if (!String(price).trim()) { const v = Number(order.agreedPrice || order.price || order.acQuote?.total || 0); if (v > 0) setPrice(String(v)) } setShowComplete(true) }} className="w-full flex items-center justify-center gap-1.5 bg-emerald-500 text-black font-display font-black rounded-2xl py-3.5">
            <Send size={16} /> إنهاء الخدمة
          </button>
          </div>
        )}

        <TechJobActions order={order} isMyTask={isMyTask && (role === 'technician' || !!actingAs)} toast={toast} />
        {order.invoiceRequest && role !== 'technician' && <p className="rounded-2xl bg-sky-500/10 border border-sky-400/30 text-sky-100 text-xs font-black p-3">🧾 الفني {order.invoiceRequest.byName} طالب إصدار فاتورة العميل — {new Date(order.invoiceRequest.at).toLocaleString('ar-SA')}</p>}
        {/* On site and it can't be fixed: a report, the job leaves him, no share counted (/field «abort»). */}
        {order.technicianId && ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(order.status) && (isMyTask || role === 'operations' || role === 'owner') && (
          <button onClick={() => setShowAbort(true)} className="w-full flex items-center justify-center gap-1.5 bg-rose-500/10 border border-rose-500/40 text-rose-300 font-bold rounded-2xl py-3 text-sm">
            <AlertTriangle size={15} /> تعذّر الإصلاح — تقرير وإيقاف الطلب
          </button>
        )}
        {showAbort && <AbortJob order={order} mine={isMyTask} onClose={() => setShowAbort(false)} onDone={(r) => { setShowAbort(false); toast(r.action === 'cancel' ? 'اتلغى الطلب واتسجل التقرير' : 'رجع الطلب للعمليات واتسجل التقرير — مش هيتحسب عليك'); refetch() }} />}
        {order.techAbort && (
          <Card>
            <p className="text-xs font-black text-rose-300 mb-1 flex items-center gap-1"><AlertTriangle size={12} /> تقرير «تعذّر الإصلاح»</p>
            <p className="text-[12px] text-slate-300">{order.techAbort.reasonLabel || order.techAbort.reason} — {order.techAbort.techName || order.techAbort.byName}</p>
            <p className="text-[12px] text-slate-400 whitespace-pre-wrap">حالة العطل: {order.techAbort.condition}</p>
            {order.techAbort.note && <p className="text-[11px] text-slate-500">{order.techAbort.note}</p>}
            <p className="text-[10px] text-slate-500 mt-1">{new Date(order.techAbort.at).toLocaleString('ar-SA-u-nu-latn')} · {order.techAbort.action === 'cancel' ? 'اتلغى' : 'رجع للعمليات'} · مش محسوب للفني ولا ليه نسبة فيه</p>
          </Card>
        )}

        {/* Technician needs to reach the customer */}
        {isMyTask && ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'].includes(order.status) && (
          <Card>
            <p className="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1"><Phone size={12} /> التواصل مع العميل</p>
            <div className="flex gap-2">
              {order.clientPhone && (
                <a href={`tel:${String(order.clientPhone).replace(/[^\d+]/g, '')}`} className="flex-1 flex items-center justify-center gap-1.5 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 font-bold rounded-xl py-2.5 text-xs">
                  <Phone size={14} /> اتصال
                </a>
              )}
              <button onClick={() => setShowContact((v) => !v)} className="flex-1 bg-amber-500/10 border border-amber-500/30 text-amber-300 font-bold rounded-xl py-2.5 text-xs">
                أحتاج التواصل مع العميل
              </button>
            </div>
            {showContact && (
              <div className="mt-2 flex gap-2">
                <input value={contactNote} onChange={(e) => setContactNote(e.target.value)} placeholder="السبب (مثل: لم أجد العنوان)" className="flex-1 min-w-0 bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-xs text-white" />
                <button
                  onClick={async () => {
                    await db.updateShared('orders', order.id, { contactRequest: { at: new Date().toISOString(), note: contactNote.trim() } }).catch(() => {})
                    fire('contact', order, { note: contactNote.trim() })
                    setShowContact(false); setContactNote(''); toast('تم إشعار العميل والعمليات')
                  }}
                  className="shrink-0 bg-amber-500 text-black font-black rounded-xl px-3 text-xs"
                >إشعار</button>
              </div>
            )}
            {!order.clientId && <p className="text-[10px] text-slate-500 mt-2">العميل زائر بدون حساب — لا تصله إشعارات، تواصل بالاتصال.</p>}
          </Card>
        )}

        <TechTools order={order} role={role} isMyTask={isMyTask} myId={myId} profiles={profiles} fire={fire} toast={toast} />

        {!order.inhouse && (role === 'operations' || role === 'owner') && ['assigned', 'accepted', 'en_route'].includes(order.status) && (
          <button onClick={() => setShowAssign(true)} className="w-full text-slate-400 text-sm font-bold py-2">إعادة إسناد لفني آخر</button>
        )}
      </div>

      {flash && (
        <div className="fixed bottom-10 left-1/2 -translate-x-1/2 z-40 bg-white text-slate-900 text-xs font-bold rounded-full px-4 py-2 shadow-lg">{flash}</div>
      )}

      {showAssign && (
        <AssignSheet
          gid={gid}
          order={order}
          profiles={profiles}
          orders={orders}
          onClose={() => setShowAssign(false)}
          onPick={assignTechnician}
        />
      )}

      {showComplete && (
        <CompleteSheet
          report={report} setReport={setReport}
          spareParts={spareParts} setSpareParts={setSpareParts}
          price={price} setPrice={setPrice}
          order={order} receiptCode={receiptCode} setReceiptCode={setReceiptCode}
          onClient={onClient} setOnClient={setOnClient} err={completeErr}
          onClose={() => setShowComplete(false)}
          onSubmit={completeService}
          busy={busy}
        />
      )}
    </div>
  )
}

function AssignSheet({ gid, order, profiles, orders, onClose, onPick }) {
  const [members, setMembers] = useState([])
  const [loading, setLoading] = useState(true)
  const ranked = useMemo(() => scoreTechnicians(order, profiles || [], orders || [], { distanceFn: maps.distance }).slice(0, 3), [order, profiles, orders])
  useEffect(() => {
    async function load() {
      if (!gid) return
      const list = await social.getGroupMembers(gid)
      const withRoles = []
      for (const m of list) {
        withRoles.push(m)
      }
      setMembers(withRoles.filter((m) => m.role === 'technician'))
      setLoading(false)
    }
    load()
  }, [gid])

  return (
    <div className="fixed inset-0 bg-black/60 z-40 flex items-end justify-center" style={{ height: 'var(--visual-height, 100dvh)' }}>
      <div className="w-full max-w-md bg-[#0e1420] border-t border-white/10 rounded-t-3xl p-5 overflow-y-auto" style={{ maxHeight: 'calc(var(--visual-height, 100dvh) - 3rem)' }} dir="rtl">
        <h3 className="font-display font-black text-white text-lg mb-3">اختر فنيًا</h3>
        {ranked.length > 0 && (
          <div className="mb-4">
            <p className="text-[11px] font-bold text-primary mb-2 flex items-center gap-1"><Sparkles size={12} /> اقتراحات المطابقة الذكية — القرار لك</p>
            <div className="flex flex-col gap-1.5">
              {ranked.map((r, i) => (
                <button
                  key={r.tech.userId}
                  disabled={!r.available}
                  onClick={() => onPick({ userId: r.tech.userId, displayName: r.tech.displayName })}
                  className="text-right bg-primary/10 border border-primary/30 rounded-xl px-3 py-2.5 disabled:opacity-40"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-white font-bold text-sm">{i === 0 && r.available ? '🥇 ' : ''}{r.tech.displayName || 'فني'}</span>
                    <span className={`text-xs font-black ${r.available ? 'text-primary' : 'text-rose-400'}`}>{r.available ? `${r.score}%` : 'غير متاح'}</span>
                  </div>
                  <p className="text-[10px] text-slate-400 mt-0.5">{r.reasons.join(' · ')}</p>
                </button>
              ))}
            </div>
            <p className="text-[11px] font-bold text-slate-500 mt-3">كل فنيي الفريق</p>
          </div>
        )}
        {loading ? (
          <p className="text-slate-500 text-sm text-center py-6">جارِ التحميل…</p>
        ) : members.length === 0 ? (
          <p className="text-slate-500 text-sm text-center py-6">لا يوجد فنيون مسجّلون في الفريق بعد</p>
        ) : (
          <div className="flex flex-col gap-2">
            {members.map((m) => (
              <button key={m.userId || m.id} onClick={() => onPick(m)} className="flex items-center justify-between bg-white/5 border border-white/10 rounded-xl px-4 py-3">
                <span className="text-white font-bold">{m.displayName || m.name || 'فني'}</span>
                <Wrench size={16} className="text-primary" />
              </button>
            ))}
          </div>
        )}
        <button onClick={onClose} className="w-full mt-4 text-slate-400 text-sm font-bold py-2">إلغاء</button>
      </div>
    </div>
  )
}

// Short, factual, and about dealing with the person — never about who they are.
export const CLIENT_TAGS = ['محترم', 'دفع فورًا', 'بيفاصل في السعر', 'مستعجل', 'صعب التعامل', 'المكان صعب الوصول', 'عنده أجهزة تانية', 'يستاهل متابعة']

// The customer's receipt code: the claim code, or the seal code his WhatsApp order message carried («كود التوثيق: 8YJVGCW7»)
function receiptCodeOf(o) {
  if (o?.claimCode) return String(o.claimCode).toUpperCase()
  const m = String(o?.sealUrl || '').match(/\/v\/([A-Z0-9]{8})/i)
  return m ? m[1].toUpperCase() : ''
}

function CompleteSheet({ report, setReport, spareParts, setSpareParts, price, setPrice, order, receiptCode, setReceiptCode, onClient, setOnClient, err, onClose, onSubmit, busy }) {
  const toggle = (t) => setOnClient((v) => ({ ...v, tags: v.tags.includes(t) ? v.tags.filter((x) => x !== t) : [...v.tags, t] }))
  return (
    <div className="fixed inset-0 bg-black/60 z-40 flex items-end justify-center" style={{ height: 'var(--visual-height, 100dvh)' }}>
      <div className="w-full max-w-md bg-[#0e1420] border-t border-white/10 rounded-t-3xl p-5 overflow-y-auto" style={{ maxHeight: 'calc(var(--visual-height, 100dvh) - 3rem)' }} dir="rtl">
        <h3 className="font-display font-black text-white text-lg mb-4">إنهاء الخدمة</h3>
        <label className="block text-xs font-bold text-slate-400 mb-1.5">تقرير العمل</label>
        <textarea value={report} onChange={(e) => setReport(e.target.value)} rows={3} className="w-full bg-white/5 border border-white/10 rounded-xl p-3 text-sm text-white mb-3" placeholder="وصف العمل المنجز" />
        <label className="block text-xs font-bold text-slate-400 mb-1.5">قطع الغيار المستخدمة</label>
        <input value={spareParts} onChange={(e) => setSpareParts(e.target.value)} className="w-full bg-white/5 border border-white/10 rounded-xl p-3 text-sm text-white mb-3" placeholder="اختياري" />
        <label className="block text-xs font-bold text-slate-400 mb-1.5">السعر الإجمالي (ر.س)</label>
        <input value={price} onChange={(e) => setPrice(e.target.value)} type="number" className="w-full bg-white/5 border border-white/10 rounded-xl p-3 text-sm text-white mb-4" placeholder="0" />
        {/* proof of work (owner, 2026-11-23 — ideas 11, 12): photos and the customer's code help, they never hold the job back */}
        <div className="rounded-2xl bg-sky-500/[0.06] border border-sky-400/20 p-3 mb-3 grid gap-2" data-proof>
          <p className="text-xs font-black text-sky-100">📸 صور قبل/بعد: {(order?.photosBefore || []).length} قبل · {(order?.photosAfter || []).length} بعد <span className="font-normal text-slate-400">— اختياري</span></p>
          {!(order?.photosAfter || []).length && <p className="text-[10px] text-slate-400">لو تقدر، صوّر الوحدة بعد الشغل من «مستندات الخدمة» فوق — بتتحفظ في ملف العميل وبتحميك لو حصل خلاف. مش شرط عشان تقفل.</p>}
          {receiptCodeOf(order) && (
            <label className="grid gap-1 text-[11px] text-slate-300 font-bold">🔐 كود استلام العميل (اختياري)
              <input value={receiptCode} onChange={(e) => setReceiptCode(e.target.value)} maxLength={8} dir="ltr" className="w-full bg-white/5 border border-white/10 rounded-xl p-2.5 text-sm text-white tracking-widest text-center" placeholder="كود التوثيق اللي في رسالة العميل" data-receipt-code />
              <span className="text-[10px] font-normal text-slate-500">اطلب من العميل «كود التوثيق» اللي في رسالة طلبه على واتساب — لو صح بيتسجل إن العميل استلم الشغل.</span>
              {receiptCode.trim() && receiptCode.trim().toUpperCase() !== receiptCodeOf(order) && <span className="text-[10px] text-amber-300">الكود مش مطابق — تقدر تقفل عادي، بس الاستلام مش هيتسجل.</span>}
            </label>
          )}
        </div>
        <div className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 mb-4" aria-label="رأيك في العميل">
          <p className="text-xs font-black text-white mb-0.5">رأيك في العميل</p>
          <p className="text-[10px] text-slate-500 mb-2">للعمليات والمالك فقط — العميل ما يشوفوش.</p>
          <div className="flex gap-1 mb-2" role="radiogroup" aria-label="التعامل">
            {[1, 2, 3, 4, 5].map((n) => (
              <button key={n} type="button" role="radio" aria-checked={onClient.stars === n} aria-label={`${n} من 5`} onClick={() => setOnClient((v) => ({ ...v, stars: n }))}
                className={`text-xl leading-none ${n <= onClient.stars ? 'text-amber-400' : 'text-slate-600'}`}>★</button>
            ))}
          </div>
          <div className="flex flex-wrap gap-1.5 mb-2">
            {CLIENT_TAGS.map((t) => (
              <button key={t} type="button" onClick={() => toggle(t)} aria-pressed={onClient.tags.includes(t)}
                className={`rounded-full px-2.5 py-1 text-[11px] font-bold border ${onClient.tags.includes(t) ? 'bg-primary/20 border-primary text-white' : 'bg-white/5 border-white/10 text-slate-400'}`}>{t}</button>
            ))}
          </div>
          <input value={onClient.note} onChange={(e) => setOnClient((v) => ({ ...v, note: e.target.value }))} className="w-full bg-white/5 border border-white/10 rounded-xl p-2.5 text-sm text-white" placeholder="ملاحظة عن العميل (اختياري)" />
        </div>
        {err && <p role="alert" className="text-rose-300 text-xs font-bold mb-3">{err}</p>}
        <button disabled={busy} onClick={onSubmit} className="w-full bg-emerald-500 text-black font-display font-black rounded-2xl py-3.5">تأكيد الإنهاء</button>
        <button onClick={onClose} className="w-full mt-2 text-slate-400 text-sm font-bold py-2">إلغاء</button>
      </div>
    </div>
  )
}

function Card({ children }) {
  return <div className="bg-white/[0.04] border border-white/10 rounded-2xl p-4">{children}</div>
}

function Centered({ children }) {
  return <div className="h-full flex items-center justify-center text-slate-500 text-sm" dir="rtl">{children}</div>
}

function PayLinkField({ order }) {
  const [v, setV] = useState(order.payLink || '')
  const [msg, setMsg] = useState('')
  async function save() {
    const t = v.trim()
    if (t && !/^https:\/\//i.test(t)) return setMsg('الرابط يجب أن يبدأ بـ https://')
    try { await db.updateShared('orders', order.id, { payLink: t || null }); setMsg(t ? 'تم حفظ رابط الدفع — يظهر للعميل' : 'تم حذف الرابط') } catch { setMsg('تعذر الحفظ') }
  }
  return (
    <div className="mb-2">
      <p className="text-[11px] text-slate-400 mb-1">رابط دفع خاص بهذا الطلب (اختياري)</p>
      <div className="flex gap-1.5">
        <input value={v} onChange={(e) => setV(e.target.value)} dir="ltr" placeholder="https://…" className="flex-1 min-w-0 bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-xs text-white" />
        <button onClick={save} className="bg-primary text-black font-black rounded-xl px-3 text-xs">حفظ</button>
      </div>
      {msg && <p className="text-[10px] text-slate-400 mt-1">{msg}</p>}
    </div>
  )
}
