import { useEffect, useRef, useState } from 'react'
import { Headphones } from 'lucide-react'
import CallOverlay from './CallOverlay'
import { CallSession, createCall, setCallStatus, hotlineSupported, callnet, SIDES } from '../lib/hotline'
import { auth } from '../lib/auth'
import { useHotlineConfig } from '../hooks/useHotline'
import { useSession } from '../hooks/useSession'
import { push } from '../lib/push'
import { team } from '../lib/team'
import { getDeviceId } from '../lib/device'
import { hotlink } from '../lib/link'
import { baseCode, keysOf } from '../lib/callbook'
import { useContact } from '../hooks/useContact'

const RING_TIMEOUT = 45000
// After the answer the voice path still has to come up (direct, or the site relay after ~9 s). The ring timer used to keep
// running through that: a call answered late was then marked "missed" and cut while the two sides were still connecting.
const CONNECT_TIMEOUT = 30000

// Places an in-app call (free WebRTC voice). start() with no target rings operations (the hotline); start({ to: 'tech' | 'client', orderId })
// or start({ to: 'tech', toUser }) rings one person, if the server allows it (see Mutqan_Callnet).
export function useCall() {
  const { session } = useSession()
  const cfg = useHotlineConfig()
  const contact = useContact()
  const [call, setCall] = useState(null)   // { id, phase, stream, note }
  const ref = useRef({ s: null, t: null, id: null })

  const finish = (phase, note) => { clearTimeout(ref.current.t); ref.current.s?.close(); ref.current.s = null; setCall((c) => (c ? { ...c, phase, note } : c)); setTimeout(() => setCall(null), phase === 'ended' ? 1200 : 4500) }

  // Nobody is online on the paired hotline: do not ring into the void. Record the call-back request (with the caller's code) and offer the direct ways.
  async function offline(st) {
    const who = { name: session?.name, phone: session?.phone, userId: session?.id, deviceId: getDeviceId() }
    try { await createCall({ ...who, callback: true }) } catch { /* the buttons below still work */ }
    const code = baseCode(keysOf(who))
    const links = []
    if (contact.wa) links.push({ href: `https://wa.me/${contact.wa}`, label: 'واتساب' })
    if (contact.phone) links.push({ href: `tel:${contact.phone}`, label: 'اتصال عادي' })
    if (st && st.tg) links.push({ href: `https://t.me/${st.tg}?start=c_${code}`, label: 'تيليجرام' })
    setCall({ phase: 'failed', persist: true, note: 'لا أحد متصل بالخط الساخن الآن — سجّلنا طلب معاودة الاتصال برمزك ' + code + '. تواصل معنا مباشرة:', links })
  }

  async function start(target) {
    const t = target && typeof target === 'object' && (target.to === 'tech' || target.to === 'client') ? target : { to: 'ops' }
    const toOps = t.to === 'ops'
    const me = auth.getCurrentUser()
    const name = session?.name || me?.displayName || me?.name || ''
    if (!hotlineSupported()) return setCall({ target: t, phase: 'failed', note: 'المتصفح لا يدعم المكالمات الصوتية — استخدم الاتصال الهاتفي أو واتساب.' }) || setTimeout(() => setCall(null), 4000)
    setCall({ target: t, phase: 'connecting' })
    // Is a paired device online? (If the line was never paired — or the answer does not come in 4 s — it rings as before.)
    let st = null
    if (toOps) {
      try { st = await Promise.race([hotlink.state(), new Promise((_, no) => setTimeout(() => no(new Error('t')), 4000))]) } catch { /* ring as before */ }
      if (st && st.linked && !st.online) return offline(st)
    }
    try {
      const id = toOps
        ? await createCall({ name, phone: session?.phone, userId: session?.id, deviceId: getDeviceId() })
        : await callnet.start({ to: t.to, orderId: t.orderId, toUser: t.toUser, name, phone: session?.phone || me?.phone || '' })
      if (!id) throw new Error('no call')
      ref.current.id = id
      const s = new CallSession({
        id, role: 'caller', turn: cfg.turn,
        onRemote: (stream) => setCall((c) => (c ? { ...c, stream } : c)),
        onState: (st) => {
          if (st === 'connected') { clearTimeout(ref.current.t); setCall((c) => (c ? { ...c, phase: 'live' } : c)) }
          else if (st === 'answered') {
            clearTimeout(ref.current.t)
            setCall((c) => (c && c.phase === 'ringing' ? { ...c, phase: 'connecting' } : c))
            ref.current.t = setTimeout(() => { setCallStatus(id, 'ended').catch(() => {}); finish('failed', 'تم الرد لكن تعذر توصيل الصوت — جرّب الاتصال الهاتفي أو واتساب.') }, CONNECT_TIMEOUT)
          }
          else if (st === 'failed') { setCallStatus(id, 'ended').catch(() => {}); finish('failed', 'تعذر الاتصال الصوتي على شبكتك — جرّب الاتصال الهاتفي أو واتساب.') }
          else if (st === 'ended' || st === 'declined' || st === 'missed') finish(st === 'declined' ? 'failed' : 'ended', st === 'declined' ? (toOps ? 'المشرف مشغول حاليًا — جرّب بعد قليل أو تواصل واتساب.' : 'مشغول حاليًا — جرّب بعد قليل.') : '')
        },
      })
      ref.current.s = s
      await s.startCaller()
      setCall({ id, target: t, phase: 'ringing' })
      ref.current.t = setTimeout(() => { setCallStatus(id, 'missed').catch(() => {}); finish('failed', toOps ? 'لم يرد أحد حاليًا — اطلب خدمتك أو راسلنا على واتساب وسنعاود الاتصال.' : 'لم يتم الرد — جرّب بعد قليل.') }, RING_TIMEOUT)
    } catch (e) {
      ref.current.s?.close()
      // The server's own words when it refuses (e.g. «الاتصال بالفني يتم بإذن من العمليات»).
      const mic = e && /Permission|NotAllowed/i.test(`${e.name} ${e.message}`) && !e.status
      setCall({ target: t, phase: 'failed', note: mic ? 'اسمح بالميكروفون من إعدادات المتصفح ثم أعد المحاولة.' : (e?.status && e?.message) || 'تعذر بدء المكالمة الآن.' })
      setTimeout(() => setCall(null), 4500)
    }
  }
  function hangup() { const id = ref.current.id; if (id) setCallStatus(id, 'ended').catch(() => {}); finish('ended') }
  useEffect(() => () => { clearTimeout(ref.current.t); ref.current.s?.close() }, [])
  return { start, hangup, call, enabled: cfg.enabled && hotlineSupported(), setMute: (m) => ref.current.s?.setMuted(m) }
}

export const useCustomerCall = useCall

// The call screen for a call this device placed: says plainly who is being rung.
export function OutgoingCall({ hook }) {
  const { hangup, call, setMute } = hook
  if (!call) return null
  const t = call.target || { to: 'ops' }
  const side = SIDES[t.to] || SIDES.ops
  const title = t.to === 'ops' ? 'الخط الساخن — مشرف العمليات' : `${side.label}${t.name ? ' — ' + t.name : ''}`
  return <CallOverlay who={t.to} title={title} subtitle="مكالمة صوتية داخل التطبيق" phase={call.phase} stream={call.stream} note={call.note} onHangup={hangup} onMute={setMute}
    extra={call.links && call.links.length > 0 && <div className="grid gap-2">{call.links.map((l) => <a key={l.label} href={l.href} target="_blank" rel="noopener noreferrer" className="rounded-xl bg-white/10 border border-white/15 py-3 text-center text-sm font-black text-white">{l.label}</a>)}</div>} />
}

export default function CustomerHotline({ hook, className }) {
  const { start, enabled } = hook
  if (!enabled) return null
  return (
    <>
      <button onClick={() => start()} aria-label="خط ساخن — اتصال مباشر بمشرف العمليات داخل التطبيق" className={className}><Headphones size={20} /></button>
      <OutgoingCall hook={hook} />
    </>
  )
}
