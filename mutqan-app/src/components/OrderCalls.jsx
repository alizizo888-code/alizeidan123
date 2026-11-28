import { useEffect, useState } from 'react'
import { Phone, Headphones, Wrench, User, Check, X, Loader2 } from 'lucide-react'
import { useCall, OutgoingCall } from './CustomerHotline'
import { callnet } from '../lib/hotline'
import { markOrdered } from '../lib/db'

const OPEN = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress', 'forced']

// What this person may do about calls on this order, refreshed whenever the order changes (approval, new technician, status).
function useRights(order) {
  const [r, setR] = useState(null)
  const key = `${order?.id}|${order?.technicianId}|${order?.status}|${order?.techCall?.state}|${order?.techCall?.at}`
  useEffect(() => {
    if (!order?.id) return
    let live = true
    callnet.can(order.id).then((x) => { if (live) setR(x) }, () => { if (live) setR(null) })
    return () => { live = false }
  }, [key])   // eslint-disable-line react-hooks/exhaustive-deps
  return [r, setR]
}

const btn = 'flex-1 flex items-center justify-center gap-1.5 font-bold rounded-xl py-2.5 text-xs disabled:opacity-50'

/** Customer's order page: the hotline to operations, and the technician the way operations allows it. */
export function ClientCallCard({ order }) {
  const call = useCall()
  const [r, setR] = useRights(order)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  useEffect(() => { markOrdered() }, [])
  if (!order || !OPEN.includes(order.status) || !call.enabled) return null
  const st = r?.techState || 'none'
  const ask = async () => {
    setBusy(true); setMsg('')
    try { setR(await callnet.request(order.id)); setMsg('أرسلنا طلبك للعمليات — سيصلك إشعار عند الموافقة.') }
    catch (e) { setMsg(e?.message || 'تعذر إرسال الطلب') }
    finally { setBusy(false) }
  }
  return (
    <div className="rounded-2xl bg-white border border-slate-200 p-4 shadow-sm">
      <p className="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1"><Phone size={12} /> اتصال داخل التطبيق</p>
      <div className="flex gap-2 flex-wrap">
        <button onClick={() => call.start()} className={`${btn} bg-sky-50 border border-sky-200 text-sky-700`}><Headphones size={14} /> العمليات</button>
        {st === 'allowed' && <button onClick={() => call.start({ to: 'tech', orderId: order.id, name: order.technicianName })} className={`${btn} bg-amber-50 border border-amber-200 text-amber-700`}><Wrench size={14} /> اتصال بالفني</button>}
        {(st === 'ask' || st === 'refused') && <button disabled={busy} onClick={ask} className={`${btn} bg-amber-50 border border-amber-200 text-amber-700`}>{busy ? <Loader2 size={14} className="animate-spin" /> : <Wrench size={14} />} طلب إذن الاتصال بالفني</button>}
        {st === 'pending' && <span className={`${btn} bg-slate-50 border border-slate-200 text-slate-500`}>⏳ بانتظار موافقة العمليات</span>}
      </div>
      {st === 'refused' && !msg && <p className="text-[11px] text-slate-500 mt-2">العمليات ستتواصل معك بخصوص طلبك، ويمكنك الطلب مرة أخرى.</p>}
      {msg && <p className="text-[11px] text-slate-600 mt-2">{msg}</p>}
      <OutgoingCall hook={call} />
    </div>
  )
}

/** Staff order page: the technician on the job rings the customer or operations; operations rings either side and decides the customer's request. */
export function StaffCallCard({ order, role, isMyTask }) {
  const call = useCall()
  const [r] = useRights(order)
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const ops = ['owner', 'operations', 'supervisor'].includes(role)
  if (!order || !call.enabled || (!ops && !isMyTask)) return null
  const decide = async (allow) => {
    setBusy(allow ? 'yes' : 'no'); setErr('')
    try { await callnet.decide(order.id, allow) } catch (e) { setErr(e?.message || 'تعذر الحفظ') } finally { setBusy('') }
  }
  const tc = order.techCall?.techId === order.technicianId ? order.techCall : null
  const dark = 'bg-white/5 border border-white/10'
  return (
    <div className={`rounded-2xl ${dark} p-4`} dir="rtl">
      <p className="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1"><Phone size={12} /> اتصال داخل التطبيق</p>
      <div className="flex gap-2 flex-wrap">
        {r?.client && <button onClick={() => call.start({ to: 'client', orderId: order.id, name: order.clientName })} className={`${btn} bg-emerald-500/10 border border-emerald-500/30 text-emerald-300`}><User size={14} /> العميل</button>}
        {ops && r?.tech && <button onClick={() => call.start({ to: 'tech', orderId: order.id, name: order.technicianName })} className={`${btn} bg-amber-500/10 border border-amber-500/30 text-amber-300`}><Wrench size={14} /> الفني</button>}
        {!ops && <button onClick={() => call.start()} className={`${btn} bg-sky-500/10 border border-sky-500/30 text-sky-300`}><Headphones size={14} /> العمليات</button>}
      </div>
      {ops && order.technicianId && OPEN.includes(order.status) && r?.mode === 'manual' && (
        <div className="mt-3 rounded-xl bg-amber-500/10 border border-amber-500/30 p-3">
          {tc?.state === 'pending' ? (
            <>
              <p className="text-amber-200 text-xs font-bold">📞 العميل يطلب الاتصال بالفني {order.technicianName || ''}</p>
              <div className="flex gap-2 mt-2">
                <button disabled={!!busy} onClick={() => decide(true)} className={`${btn} bg-emerald-500 text-black`}>{busy === 'yes' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} سماح</button>
                <button disabled={!!busy} onClick={() => decide(false)} className={`${btn} bg-rose-500/20 border border-rose-500/40 text-rose-200`}>{busy === 'no' ? <Loader2 size={14} className="animate-spin" /> : <X size={14} />} رفض</button>
              </div>
            </>
          ) : (
            <div className="flex items-center gap-2">
              <p className="flex-1 text-[11px] text-amber-100 font-bold">اتصال العميل بالفني: {tc?.state === 'approved' ? 'مسموح ✅' : 'غير مسموح'}</p>
              <button disabled={!!busy} onClick={() => decide(tc?.state !== 'approved')} className="shrink-0 rounded-lg bg-white/10 px-3 py-1.5 text-[11px] font-black text-white">{tc?.state === 'approved' ? 'إيقاف' : 'السماح الآن'}</button>
            </div>
          )}
        </div>
      )}
      {err && <p className="text-[11px] text-rose-300 mt-2">{err}</p>}
      <OutgoingCall hook={call} />
    </div>
  )
}

/** Operations switch: how a customer reaches the technician of his order. */
export function CallPolicySwitch() {
  const [mode, setMode] = useState(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { callnet.policy().then((p) => setMode(p?.clientToTech || 'manual'), () => setMode('manual')) }, [])
  const set = async (m) => { setBusy(true); try { setMode((await callnet.setPolicy(m))?.clientToTech || m) } catch { /* keep */ } finally { setBusy(false) } }
  const opts = [['manual', 'بإذن العمليات', 'العميل يطلب، والعمليات توافق أو ترفض لكل طلب'], ['auto', 'تلقائي', 'العميل يتصل بفني طلبه طالما الطلب مفتوح'], ['off', 'متوقف', 'العميل يتواصل مع العمليات فقط']]
  return (
    <div className="rounded-2xl bg-white/5 border border-white/10 p-3 text-white" dir="rtl">
      <p className="text-xs font-black mb-2">📞 اتصال العميل بالفني داخل التطبيق</p>
      <div className="grid gap-1.5">
        {opts.map(([k, l, d]) => (
          <button key={k} disabled={busy || mode === null} onClick={() => set(k)} className={`text-right rounded-xl px-3 py-2 border ${mode === k ? 'bg-primary/15 border-primary/50' : 'bg-white/5 border-white/10'}`}>
            <span className="text-xs font-black">{mode === k ? '● ' : '○ '}{l}</span><span className="block text-[10px] text-slate-400 mt-0.5">{d}</span>
          </button>
        ))}
      </div>
    </div>
  )
}
