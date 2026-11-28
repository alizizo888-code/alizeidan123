import { useEffect, useState } from 'react'
import { Loader2, Copy, Power, Link2 } from 'lucide-react'
import { post } from '../lib/_api'

// «زراير التشغيل» (owner, 2026-11-24): the whole site in maintenance, and the WhatsApp order-page link — one place.
function Toggle({ on, onClick, busy, testId }) {
  return (
    <button onClick={onClick} disabled={busy} data-switch={testId} className={`w-14 h-8 rounded-full p-1 transition-colors ${on ? 'bg-rose-500' : 'bg-slate-600'} disabled:opacity-50`}>
      <span className={`block w-6 h-6 rounded-full bg-white transition-transform ${on ? '-translate-x-6' : ''}`} />
    </button>
  )
}

export default function SiteSwitches() {
  const [s, setS] = useState(null)
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [form, setForm] = useState({ title: '', msg: '' })
  const load = () => post('/maintenance', { op: 'get' }).then((r) => { setS(r); setForm({ title: r.title || '', msg: r.msg || '' }) }).catch((e) => setMsg(e?.message || 'تعذر التحميل'))
  useEffect(() => { load() }, [])
  const set = async (body, what) => {
    setBusy(what); setMsg('')
    try { const r = await post('/maintenance', body); setS((x) => ({ ...x, ...r })); setMsg('اتحفظ ✅') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') }
    setBusy('')
  }
  if (!s) return <p className="text-xs text-slate-400 p-3 flex items-center gap-1">{msg || <><Loader2 size={12} className="animate-spin" /> بحمّل…</>}</p>
  return (
    <div className="grid gap-3" dir="rtl">
      <section className={`rounded-2xl border p-4 grid gap-3 ${s.on ? 'border-rose-400/50 bg-rose-500/10' : 'border-white/10 bg-white/[0.04]'}`} data-maint-card>
        <div className="flex items-center gap-3">
          <Power size={20} className={s.on ? 'text-rose-300' : 'text-slate-400'} />
          <div className="flex-1">
            <p className="font-black text-white">🔌 وضع الصيانة — كل المواقع</p>
            <p className="text-[11px] text-slate-400">{s.on ? `شغّال دلوقتي${s.by ? ' — ' + s.by : ''}: العملاء والزوار شايفين صفحة «الفيشة مفصولة»` : 'مقفول — الموقع شغّال عادي'}</p>
          </div>
          <Toggle on={s.on} busy={busy === 'm'} testId="maint" onClick={() => { if (!s.on && !window.confirm('هتقفل الموقع على العملاء والزوار وتظهر صفحة الصيانة. متأكد؟')) return; set({ op: 'set', on: !s.on, ...form }, 'm') }} />
        </div>
        <input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} placeholder="العنوان" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
        <textarea value={form.msg} onChange={(e) => setForm((f) => ({ ...f, msg: e.target.value }))} rows={2} placeholder="الرسالة" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white resize-none" />
        <button onClick={() => set({ op: 'set', ...form }, 't')} disabled={!!busy} className="rounded-xl bg-white/10 text-white font-bold py-2 text-sm">حفظ النص</button>
        <p className="text-[10px] text-slate-400 leading-relaxed">شغّالين وقت الصيانة: لوحة التحكم دي، تطبيق الفنيين، العمليات، مركز الواتساب والبوت، وصفحات التوثيق والدفع. ولو عايز تشوف الموقع نفسه وهو مقفول:</p>
        <button onClick={() => { navigator.clipboard?.writeText(s.bypass); setMsg('اتنسخ رابط الدخول وقت الصيانة 📋') }} className="flex items-center justify-center gap-1 rounded-xl border border-white/15 text-slate-200 py-2 text-xs font-bold"><Copy size={13} /> انسخ رابط الدخول وقت الصيانة</button>
      </section>
      {s.storeLink !== null && s.storeLink !== undefined && (
        <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 flex items-center gap-3" data-store-card>
          <Link2 size={20} className="text-sky-300" />
          <div className="flex-1">
            <p className="font-black text-white">📲 رابط صفحة الطلب على الواتساب</p>
            <p className="text-[11px] text-slate-400">أول ما البوت يفهم الخدمة بيبعت للعميل رابط صفحة الطلب والخدمة متحددة فيها.</p>
          </div>
          <button onClick={() => set({ op: 'store_link', on: !s.storeLink }, 's')} disabled={busy === 's'} className={`w-14 h-8 rounded-full p-1 ${s.storeLink ? 'bg-emerald-500' : 'bg-slate-600'}`} data-switch="store">
            <span className={`block w-6 h-6 rounded-full bg-white transition-transform ${s.storeLink ? '-translate-x-6' : ''}`} />
          </button>
        </section>
      )}
      {msg && <p className="text-xs font-bold text-amber-300">{msg}</p>}
    </div>
  )
}
