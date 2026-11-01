import { useState } from 'react'
import { X, Phone, Loader2, MapPin, Home, MessageCircle, Handshake } from 'lucide-react'
import { specialtyLabel } from '../../constants'
import { rankTechs, kmText, ageText } from './helpers'

// Manual dispatch: pick the technician yourself. Ranked by availability then distance (live GPS when the technician's app is open).
export default function AssignModal({ order, profiles, orders, onAssign, onClose, siteTech = null, onSite, onWhatsApp, onExternal, parties = [] }) {
  const [busy, setBusy] = useState('')
  const [ext, setExt] = useState(false)
  const [pName, setPName] = useState('')
  const [pPhone, setPPhone] = useState('')
  const wrap = async (key, fn) => { setBusy(key); try { await fn(); onClose() } finally { setBusy('') } }
  const ranked = rankTechs(order, profiles, orders)
  const go = async (t) => { setBusy(t.userId); try { await onAssign(order, t); onClose() } finally { setBusy('') } }
  return (
    <div className="fixed inset-0 z-[70] bg-black/70 flex items-end sm:items-center justify-center" onClick={onClose}>
      <div dir="rtl" onClick={(e) => e.stopPropagation()} className="w-full max-w-lg max-h-[85vh] overflow-y-auto bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <div className="flex items-start gap-2 mb-3">
          <div className="flex-1 min-w-0">
            <p className="text-[11px] text-primary font-bold">إسناد يدوي</p>
            <p className="font-black text-white text-sm truncate">{specialtyLabel(order.specialtyId)} · {order.clientName || 'عميل'}</p>
            <p className="text-[11px] text-slate-400 truncate flex items-center gap-1"><MapPin size={11} />{order.address || 'بدون عنوان'} · منذ {ageText(order.createdAt)}</p>
          </div>
          <button onClick={onClose} className="text-slate-400"><X size={18} /></button>
        </div>
        {/* Three ways to hand the order over besides picking a team technician — each one is tagged on the order so operations always knows where it went. */}
        <div className="grid gap-2 mb-3">
          {onSite && (
            <button disabled={!!busy || !siteTech} onClick={() => wrap('site', () => onSite(order))} className="flex items-center gap-3 rounded-xl border border-sky-400/40 bg-sky-500/15 px-3 py-3 text-right disabled:opacity-50">
              <Home size={18} className="text-sky-300 shrink-0" />
              <span className="flex-1 min-w-0"><span className="block text-sm font-black text-white">فني الموقع</span><span className="block text-[11px] text-sky-200/80">{siteTech ? 'تنفيذ داخلي بواسطة مستخدم الموقع «' + (siteTech.displayName || 'فني الموقع') + '»' : 'غير مضبوط بعد'}</span></span>
              {busy === 'site' ? <Loader2 size={14} className="animate-spin" /> : <span className="rounded-lg bg-sky-400 text-black font-black text-xs px-3 py-2">إسناد</span>}
            </button>
          )}
          {onWhatsApp && (
            <button disabled={!!busy} onClick={() => wrap('wa', () => onWhatsApp(order))} className="flex items-center gap-3 rounded-xl border border-green-400/40 bg-green-500/15 px-3 py-3 text-right disabled:opacity-50">
              <MessageCircle size={18} className="text-green-300 shrink-0" />
              <span className="flex-1 min-w-0"><span className="block text-sm font-black text-white">واتساب مباشر للفنيين</span><span className="block text-[11px] text-green-200/80">يفتح واتساب برسالة جاهزة بكل التفاصيل — تختار الفني أو المجموعة</span></span>
              {busy === 'wa' ? <Loader2 size={14} className="animate-spin" /> : <span className="rounded-lg bg-green-400 text-black font-black text-xs px-3 py-2">إرسال</span>}
            </button>
          )}
          {onExternal && !ext && (
            <button disabled={!!busy} onClick={() => setExt(true)} className="flex items-center gap-3 rounded-xl border border-amber-400/40 bg-amber-500/15 px-3 py-3 text-right disabled:opacity-50">
              <Handshake size={18} className="text-amber-300 shrink-0" />
              <span className="flex-1 min-w-0"><span className="block text-sm font-black text-white">جهة خارجية</span><span className="block text-[11px] text-amber-200/80">تحويل الطلب لشريك أو مقاول من خارج الفريق</span></span>
              <span className="rounded-lg bg-amber-400 text-black font-black text-xs px-3 py-2">اختيار</span>
            </button>
          )}
          {onExternal && ext && (
            <div className="rounded-xl border border-amber-400/40 bg-amber-500/10 p-3">
              <p className="text-xs font-black text-amber-200 mb-2">الجهة الخارجية</p>
              {parties.length > 0 && <div className="flex gap-1.5 flex-wrap mb-2">{parties.map((x) => <button key={x.phone} onClick={() => { setPName(x.name); setPPhone(x.phone) }} className="rounded-full bg-white/10 border border-white/15 text-[11px] text-white px-3 py-1">{x.name}</button>)}</div>}
              <input value={pName} onChange={(e) => setPName(e.target.value)} placeholder="اسم الجهة" className="w-full rounded-lg bg-white/10 border border-white/15 p-2.5 text-sm text-white outline-none mb-2" />
              <input value={pPhone} onChange={(e) => setPPhone(e.target.value)} inputMode="tel" dir="ltr" placeholder="رقم واتساب الجهة (مثال 9665XXXXXXXX)" className="w-full rounded-lg bg-white/10 border border-white/15 p-2.5 text-sm text-white outline-none" />
              <div className="flex gap-2 mt-2">
                <button disabled={!!busy || !pName.trim() || String(pPhone).replace(/\D/g, '').length < 9} onClick={() => wrap('ext', () => onExternal(order, { name: pName.trim(), phone: String(pPhone).replace(/\D/g, '') }))} className="flex-1 rounded-lg bg-amber-400 text-black font-black text-sm py-2.5 disabled:opacity-50">{busy === 'ext' ? '…' : 'تحويل وفتح واتساب'}</button>
                <button onClick={() => setExt(false)} className="rounded-lg bg-white/10 text-sm px-3">رجوع</button>
              </div>
            </div>
          )}
        </div>
        <p className="text-[11px] font-black text-slate-300 mb-1.5">أو اختر فنيًا من الفريق</p>
        {ranked.length === 0 && <p className="text-slate-500 text-xs text-center py-6">لا يوجد فنيون مسجّلون</p>}
        <div className="grid gap-2">
          {ranked.map(({ t, dist, rem, ok, pos, score, reasons }, i) => (
            <div key={t.userId} className={`flex items-center gap-2 rounded-xl border px-3 py-2.5 ${ok ? 'bg-white/5 border-white/10' : 'bg-white/[0.02] border-white/5 opacity-70'}`}>
              <span className={`w-2.5 h-2.5 rounded-full shrink-0 ${!ok ? 'bg-slate-600' : pos?.live ? 'bg-emerald-400 animate-pulse' : 'bg-emerald-700'}`} />
              <div className="flex-1 min-w-0">
                <p className="text-white font-bold text-sm truncate">{i === 0 && ok && Number.isFinite(dist) ? '⭐ ' : ''}{t.displayName || 'فني'}</p>
                <p className="text-[11px] text-slate-400">{kmText(dist)}{pos?.live ? ' · مباشر' : ''} · متبقي اليوم {rem}/{t.dailyCapacity || 0}{t.active === false ? ' · مغلق' : ''}</p>
                {ok && <p className="text-[10px] text-emerald-300/80">تطابق {score}% — {(reasons || []).filter((r) => /أونلاين|متفرغ|مشغول|تخصص|أنجز|غير متصل|ظهر/.test(r)).join(' · ')}</p>}
              </div>
              {t.phone && <a href={`tel:${t.phone}`} className="w-8 h-8 rounded-lg bg-emerald-600/80 flex items-center justify-center"><Phone size={14} /></a>}
              <button onClick={() => go(t)} disabled={!!busy} className="rounded-lg bg-primary text-black font-black text-xs px-3 py-2 disabled:opacity-50">{busy === t.userId ? <Loader2 size={13} className="animate-spin" /> : 'إسناد'}</button>
            </div>
          ))}
        </div>
        <p className="text-[10px] text-slate-500 mt-3">الفني غير المتاح (سعة مكتملة أو مغلق) يمكن إسناده يدويًا أيضًا عند الحاجة.</p>
      </div>
    </div>
  )
}
