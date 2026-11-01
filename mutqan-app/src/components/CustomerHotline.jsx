import { useEffect, useRef, useState } from 'react'
import { Headphones } from 'lucide-react'
import CallOverlay from './CallOverlay'
import { CallSession, createCall, setCallStatus, hotlineSupported } from '../lib/hotline'
import { useHotlineConfig } from '../hooks/useHotline'
import { useSession } from '../hooks/useSession'
import { push } from '../lib/push'
import { team } from '../lib/team'
import { getDeviceId } from '../lib/device'
import { hotlink } from '../lib/link'
import { baseCode, keysOf } from '../lib/callbook'
import { useContact } from '../hooks/useContact'

const RING_TIMEOUT = 45000

// Customer-side hotline button: rings the operations supervisor inside the app (free WebRTC voice).
export function useCustomerCall() {
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

  async function start() {
    if (!hotlineSupported()) return setCall({ phase: 'failed', note: 'المتصفح لا يدعم المكالمات الصوتية — استخدم الاتصال الهاتفي أو واتساب.' }) || setTimeout(() => setCall(null), 4000)
    setCall({ phase: 'connecting' })
    // Is a paired device online? (If the line was never paired — or the answer does not come in 4 s — it rings as before.)
    let st = null
    try { st = await Promise.race([hotlink.state(), new Promise((_, no) => setTimeout(() => no(new Error('t')), 4000))]) } catch { /* ring as before */ }
    if (st && st.linked && !st.online) return offline(st)
    try {
      const id = await createCall({ name: session?.name, phone: session?.phone, userId: session?.id, deviceId: getDeviceId() })
      ref.current.id = id
      const s = new CallSession({
        id, role: 'caller', turn: cfg.turn,
        onRemote: (stream) => setCall((c) => (c ? { ...c, stream } : c)),
        onState: (st) => {
          if (st === 'connected') { clearTimeout(ref.current.t); setCall((c) => (c ? { ...c, phase: 'live' } : c)) }
          else if (st === 'failed') { setCallStatus(id, 'ended').catch(() => {}); finish('failed', 'تعذر الاتصال الصوتي على شبكتك — جرّب الاتصال الهاتفي أو واتساب.') }
          else if (st === 'ended' || st === 'declined' || st === 'missed') finish(st === 'declined' ? 'failed' : 'ended', st === 'declined' ? 'المشرف مشغول حاليًا — جرّب بعد قليل أو تواصل واتساب.' : '')
        },
      })
      ref.current.s = s
      await s.startCaller()
      setCall({ id, phase: 'ringing' })
      ref.current.t = setTimeout(() => { setCallStatus(id, 'missed').catch(() => {}); finish('failed', 'لم يرد أحد حاليًا — اطلب خدمتك أو راسلنا على واتساب وسنعاود الاتصال.') }, RING_TIMEOUT)
    } catch (e) {
      ref.current.s?.close()
      setCall({ phase: 'failed', note: e && /Permission|NotAllowed/i.test(e.name || e.message || '') ? 'اسمح بالميكروفون من إعدادات المتصفح ثم أعد المحاولة.' : 'تعذر بدء المكالمة الآن.' })
      setTimeout(() => setCall(null), 4500)
    }
  }
  function hangup() { const id = ref.current.id; if (id) setCallStatus(id, 'ended').catch(() => {}); finish('ended') }
  useEffect(() => () => { clearTimeout(ref.current.t); ref.current.s?.close() }, [])
  return { start, hangup, call, enabled: cfg.enabled && hotlineSupported(), setMute: (m) => ref.current.s?.setMuted(m) }
}

export default function CustomerHotline({ hook, className }) {
  const { start, hangup, call, enabled, setMute } = hook
  if (!enabled) return null
  return (
    <>
      <button onClick={start} aria-label="خط ساخن — اتصال مباشر بمشرف العمليات داخل التطبيق" className={className}><Headphones size={20} /></button>
      {call && <CallOverlay title="الخط الساخن — مشرف العمليات" subtitle="مكالمة صوتية داخل التطبيق" phase={call.phase} stream={call.stream} note={call.note} onHangup={hangup} onMute={setMute}
        extra={call.links && call.links.length > 0 && <div className="grid gap-2">{call.links.map((l) => <a key={l.label} href={l.href} target="_blank" rel="noopener noreferrer" className="rounded-xl bg-white/10 border border-white/15 py-3 text-center text-sm font-black text-white">{l.label}</a>)}</div>} />}
    </>
  )
}
