import { useEffect, useMemo, useState } from 'react'
import { Minus, Plus, ShieldCheck, Flame } from 'lucide-react'
import { useAcConfig } from '../hooks/useAcConfig'
import { AC_MODES, AC_TYPES, AC_WARRANTY, acQuote, hasDepth } from '../acPricing'

const sel = 'w-full bg-white border-2 border-slate-300 rounded-xl px-3 py-3 text-sm font-black text-slate-900 shadow-sm focus:outline-none focus:border-sky-600'
// Strong, saturated colours per service mode so the choice is obvious at a glance.
const MODE_ON = { wash: 'bg-sky-600 border-sky-700', install: 'bg-emerald-600 border-emerald-700', maintenance: 'bg-orange-600 border-orange-700' }

// Shown only after the customer picks the "مكيفات" specialty: mode, type, quantity, warranty, VAT -> live price.
export default function AcCalculator({ offerKey, onChange }) {
  const { cfg, offers, media } = useAcConfig()
  const [mode, setMode] = useState('wash')
  const [type, setType] = useState('سبليت')
  const [qty, setQty] = useState(1)
  const [full, setFull] = useState(true)
  const [warranty, setWarranty] = useState('بدون')
  const [vat, setVat] = useState(false)
  const [photo, setPhoto] = useState(0)

  const types = mode === 'install' ? AC_TYPES.install : AC_TYPES[mode].map((v) => ({ v, l: v }))
  useEffect(() => { setType(types[0].v) }, [mode]) // eslint-disable-line

  // Prefill from an offer card (?offer=KEY)
  useEffect(() => {
    const o = offerKey && offers.find((x) => (x.key || x.id) === offerKey)
    if (o) { setMode(o.acMode); setType(o.acType || 'سبليت'); setQty(Number(o.qty) || 1) }
  }, [offerKey, offers.length]) // eslint-disable-line

  const q = useMemo(() => acQuote(cfg, { mode, type, qty, full, warranty, vat, offers }), [cfg, mode, type, qty, full, warranty, vat, offers])
  useEffect(() => { onChange?.({ ...q, full, warranty, vat }) }, [q.total, q.mode, q.type, q.qty, full, warranty, vat]) // eslint-disable-line

  const pics = media.filter((m) => (m.specialty || 'ac') === 'ac' && (!m.category || m.category === mode)).sort((a, b) => (a.sort ?? 99) - (b.sort ?? 99))
  useEffect(() => {
    if (pics.length < 2) return
    const t = setTimeout(() => setPhoto((p) => (p + 1) % pics.length), 3500)
    return () => clearTimeout(t)
  }, [photo, pics.length])

  const quickOffers = offers.filter((o) => o.active !== false && o.acMode === mode)

  return (
    <div className="flex flex-col gap-4">
      {pics.length > 0 && (
        <div className="relative h-36 rounded-2xl overflow-hidden bg-slate-100 shadow-sm">
          <img src={pics[photo % pics.length].url} alt={pics[photo % pics.length].title || ''} className="w-full h-full object-cover" onError={(e) => { e.currentTarget.style.display = 'none' }} />
          {pics[photo % pics.length].title && <p className="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/60 to-transparent text-white text-[11px] font-bold px-3 py-2">{pics[photo % pics.length].title}</p>}
        </div>
      )}

      <div className="grid grid-cols-3 gap-2 bg-slate-200/70 p-1.5 rounded-2xl">
        {AC_MODES.map((m) => (
          <button key={m.id} onClick={() => setMode(m.id)} className={`py-2.5 rounded-xl text-xs font-black transition border-2 ${mode === m.id ? `${MODE_ON[m.id] || MODE_ON.wash} text-white shadow-lg scale-[1.02]` : 'bg-white text-slate-800 border-slate-300'}`}>
            <span className="block text-base">{m.icon}</span>{m.label}
          </button>
        ))}
      </div>

      {quickOffers.length > 0 && (
        <div className="flex flex-col gap-2">
          {quickOffers.map((o) => (
            <button key={o.key || o.id} onClick={() => { setType(o.acType || 'سبليت'); setQty(Number(o.qty) || 1) }}
              className="flex items-center justify-between gap-2 text-right bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
              <span className="flex items-center gap-1.5 text-xs font-black text-amber-900"><Flame size={14} className="text-amber-500" />{o.title}{o.badge ? <em className="not-italic text-[10px] bg-amber-200 rounded-full px-2 py-0.5">{o.badge}</em> : null}</span>
              <span className="text-sm font-black text-amber-700">{o.price} ر.س</span>
            </button>
          ))}
        </div>
      )}

      <label className="block text-xs font-black text-slate-800">نوع المكيف
        <select value={type} onChange={(e) => setType(e.target.value)} className={`${sel} mt-1.5`}>
          {types.map((t) => <option key={t.v} value={t.v}>{t.l}</option>)}
        </select>
      </label>

      {mode !== 'maintenance' && (
        <div className="flex items-center justify-between bg-white border-2 border-sky-300 rounded-xl px-3 py-2.5 shadow-sm">
          <div><p className="text-xs font-black text-slate-800">عدد المكيفات</p><p className="text-[10px] font-bold text-emerald-700">خصم تلقائي عند زيادة العدد</p></div>
          <div className="flex items-center gap-1">
            <button onClick={() => setQty(Math.max(1, qty - 1))} className="w-10 h-10 rounded-lg bg-slate-800 text-white flex items-center justify-center active:scale-95"><Minus size={14} /></button>
            <span className="w-9 text-center text-lg font-black text-slate-900">{qty}</span>
            <button onClick={() => setQty(Math.min(50, qty + 1))} className="w-10 h-10 rounded-lg bg-sky-600 text-white flex items-center justify-center active:scale-95"><Plus size={14} /></button>
          </div>
        </div>
      )}

      {hasDepth(mode, type) && (
        <div className="grid grid-cols-2 gap-2 bg-slate-200/70 p-1.5 rounded-2xl">
          {[[true, 'داخلي وخارجي'], [false, 'داخلي فقط']].map(([v, l]) => (
            <button key={l} onClick={() => setFull(v)} className={`py-2.5 rounded-xl text-xs font-black border-2 ${full === v ? 'bg-indigo-600 border-indigo-700 text-white shadow-md' : 'bg-white border-slate-300 text-slate-800'}`}>{l}</button>
          ))}
        </div>
      )}

      {mode === 'maintenance' && (
        <p className="bg-amber-50 border-r-4 border-amber-400 rounded-xl p-3 text-[11px] font-bold text-amber-900 leading-relaxed">
          الكشفية مجانية لفحص حتى مكيفين، وإذا زاد العدد عن مكيفين ولم يتم العمل أو الاتفاق تُحتسب كشفية الزيارة {cfg.visitFee} ريال.
        </p>
      )}

      {mode === 'wash' && (
        <label className="block text-xs font-black text-slate-800"><span className="inline-flex items-center gap-1"><ShieldCheck size={13} className="text-amber-500" />مدة الضمان</span>
          <select value={warranty} onChange={(e) => setWarranty(e.target.value)} className={`${sel} mt-1.5 !border-amber-500 bg-amber-50`}>
            {AC_WARRANTY.map((w) => <option key={w.v} value={w.v}>{w.l}</option>)}
          </select>
        </label>
      )}

      <label className="flex items-center justify-between bg-white border-2 border-slate-300 rounded-xl px-3 py-3 shadow-sm text-xs font-black text-slate-900">
        ضريبة القيمة المضافة ({cfg.vatPct}%)
        <input type="checkbox" checked={vat} onChange={(e) => setVat(e.target.checked)} className="w-5 h-5 accent-sky-600" />
      </label>

      <div className="rounded-2xl bg-gradient-to-br from-blue-700 via-sky-600 to-cyan-600 text-white p-4 text-center shadow-xl ring-4 ring-sky-200">
        {q.onInspection && !q.total ? (
          <>
            <p className="text-xs font-bold opacity-95">السعر</p>
            <p className="text-lg font-black">{mode === 'maintenance' ? 'يُحدَّد بعد الفحص' : 'يُحدَّد بعد المعاينة'}</p>
          </>
        ) : (
          <>
            <p className="text-xs font-bold opacity-95">{q.offer ? `🎉 عرض مفعّل: ${q.offer.title}` : 'الإجمالي التقديري'}</p>
            <p className="text-3xl font-black">{q.total} <span className="text-sm">ر.س</span></p>
            {q.warrantyAdd > 0 && <p className="text-[10px] opacity-80">يشمل إضافة الضمان {q.warrantyAdd} ر.س</p>}
          </>
        )}
      </div>
    </div>
  )
}
