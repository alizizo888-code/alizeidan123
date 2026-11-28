import { useState } from 'react'
import { X, Send } from 'lucide-react'
import { isValidPhone } from '../registration'

// The customer is never asked to register. At the very end he only gives his mobile (once — this device remembers
// it), so the confirmation and every update reach him on WhatsApp. No account, no check boxes, no confirm/correct
// screens: he picks the service, presses send, and the rest is ours.
const inputCls = 'w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-3.5 text-base text-slate-900 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/15'

export default function RegisterModal({ open, onClose, onDone, initial = {} }) {
  const [phone, setPhone] = useState(initial.phone || '')
  const [err, setErr] = useState('')
  if (!open) return null

  function send() {
    if (!isValidPhone(phone)) return setErr('اكتب رقم جوال صحيح، مثال: 05xxxxxxxx')
    setErr('')
    onDone({ type: 'guest', name: (initial.name || '').trim(), phone: phone.trim(), at: new Date().toISOString() })
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-[300] flex items-end md:items-center justify-center" style={{ height: 'var(--visual-height, 100dvh)' }} dir="rtl">
      <div className="w-full max-w-md bg-white rounded-t-3xl md:rounded-3xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))] fade-up">
        <div className="flex items-center justify-between mb-2">
          <h3 className="font-display font-black text-slate-900 text-lg">رقم جوالك</h3>
          <button onClick={onClose} className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center hover:bg-slate-200" aria-label="إغلاق">
            <X size={16} className="text-slate-500" />
          </button>
        </div>
        <p className="text-xs text-slate-500 mb-3">هنبعتلك تأكيد الطلب ومتابعته على واتساب.</p>
        <input
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') send() }}
          placeholder="05xxxxxxxx"
          dir="ltr"
          inputMode="tel"
          autoComplete="tel"
          autoFocus
          className={`${inputCls} text-center tracking-wider`}
        />
        {err && <p className="text-rose-600 text-xs font-bold text-center mt-2">{err}</p>}
        <button onClick={send} className="mt-3 w-full flex items-center justify-center gap-2 bg-gradient-to-l from-emerald-600 to-teal-600 text-white font-display font-black rounded-2xl py-3.5 active:scale-[0.98] transition-transform">
          <Send size={17} /> إرسال الطلب
        </button>
      </div>
    </div>
  )
}
