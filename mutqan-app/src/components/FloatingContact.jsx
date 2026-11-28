import { useEffect, useRef, useState } from 'react'
import { isWaMode } from '../lib/waMode'
import { useNavigate } from 'react-router-dom'
import { Sparkles, MessageCircle, Phone } from 'lucide-react'
import { share } from '../lib/share'
import { team } from '../lib/team'
import { useSession } from '../hooks/useSession'
import { useWaNumbers, pickNumber, deptMeta } from '../hooks/useWaNumbers'
import { useContact } from '../hooks/useContact'
import CustomerHotline, { useCustomerCall } from './CustomerHotline'
import { useChatActive } from '../lib/chatFocus'

// Three floating actions on the customer/visitor side:
// MUTQAN AI chat · human contact on WhatsApp · direct phone call.
// Numbers come from the WhatsApp center (owner / operations manage them).
export default function FloatingContact({ bottomClass = 'bottom-[calc(5.75rem+env(safe-area-inset-bottom,0px))] md:bottom-6' }) {
  if (isWaMode()) return null   // inside WhatsApp: he is already talking to us there
  const navigate = useNavigate()
  const { session } = useSession()
  const { numbers } = useWaNumbers()
  const contact = useContact()
  const hot = useCustomerCall()
  const chatting = useChatActive()
  const gidRef = useRef(null)
  const [toast, setToast] = useState('')
  // الأربع طرق ظاهرين على طول — المالك طلبهم «ثابتين وظاهرين وواضحين» (2026-11-23). كانوا اتلموا في زرار
  // واحد يوم 2026-11-22 لأنهم كانوا بيغطوا الكروت، فالحل إنهم يفضلوا ظاهرين بحجم أصغر شوية بدل ما يتخبوا.
  useEffect(() => { team.groupId().then((g) => { gidRef.current = g }).catch(() => {}) }, [])

  const enabled = numbers.filter((n) => n.enabled && n.phone)
  const flash = (m) => { setToast(m); setTimeout(() => setToast(''), 2600) }

  function whatsapp() {
    const n = contact.wa ? { phone: contact.wa, department: 'orders' } : (pickNumber(numbers, 'orders') || pickNumber(numbers, 'support') || enabled[0])
    if (!n) return navigate('/contact')
    const who = session?.name ? ` معك ${session.name}.` : ''
    share.whatsappOrder({
      phone: n.phone,
      text: (n.prefill || deptMeta(n.department).prefill) + who,
      collection: 'wa_leads',
      groupId: gidRef.current || undefined,
      record: { numberId: n.id, numberLabel: n.label || '', phone: n.phone, department: n.department, customerName: session?.name || '', customerPhone: session?.phone || '', via: 'floating', at: new Date().toISOString() },
    })
  }

  function call() {
    if (contact.phone) { window.location.href = `tel:${contact.phone.startsWith('+') ? contact.phone : '+' + contact.phone}`; return }
    const callers = enabled.filter((n) => n.acceptsCalls)
    const pool = callers.length ? callers : []
    const n = pool.length ? pool[Math.floor(Math.random() * pool.length)] : null
    const phone = contact.phone || n?.phone || (enabled.find((x) => ['support', 'orders'].includes(x.department)) || enabled[0])?.phone
    if (!phone) return flash('رقم الاتصال لم يُضف بعد — تواصل مع MUTQAN AI أو اطلب الخدمة مباشرة')
    window.location.href = `tel:+${String(phone).replace(/\D/g, '')}`
  }

  const btn = 'w-11 h-11 md:w-12 md:h-12 rounded-full text-white flex items-center justify-center shadow-lg active:scale-90 transition-transform hover:scale-105'

  return (
    <>
      <div className={`fixed left-3 md:left-6 ${bottomClass} z-20 flex flex-col-reverse gap-2.5 transition-all duration-200 ${chatting ? 'opacity-0 pointer-events-none -translate-x-20' : ''}`} aria-hidden={chatting || undefined} dir="rtl">
        <button onClick={() => navigate('/ai')} aria-label="تحدث مع MUTQAN AI" className={`${btn} relative bg-gradient-to-br from-sky-400 to-blue-700 shadow-sky-400/50`}>
          <span className="absolute inset-0 rounded-full bg-sky-400/40 animate-ping" style={{ animationDuration: '2.4s' }} />
          <Sparkles size={19} className="relative" />
          <span className="absolute -top-1 -right-1 text-[8px] font-black bg-white text-blue-700 rounded-full px-1 shadow">AI</span>
        </button>
        {(contact.wa || enabled.length > 0) && <button onClick={whatsapp} aria-label="تواصل مع موظف على واتساب" className={`${btn} bg-gradient-to-br from-emerald-400 to-green-600 shadow-emerald-400/50`}>
          <MessageCircle size={20} />
        </button>}
        <CustomerHotline hook={hot} className={`${btn} bg-gradient-to-br from-violet-500 to-indigo-700 shadow-violet-400/50`} />
        {(contact.phone || enabled.length > 0) && <button onClick={call} aria-label="اتصال مباشر" className={`${btn} bg-gradient-to-br from-rose-400 to-pink-600 shadow-rose-400/50`}>
          <Phone size={19} />
        </button>}
      </div>
      {toast && (
        <div className="fixed bottom-32 md:bottom-10 left-1/2 -translate-x-1/2 z-40 bg-slate-900/90 text-white text-xs font-bold rounded-full px-4 py-2 shadow-lg max-w-[90vw] text-center" dir="rtl">
          {toast}
        </div>
      )}
    </>
  )
}
