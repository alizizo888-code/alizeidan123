import { useEffect, useMemo, useState } from 'react'
import { X, MessageCircle, Handshake, Loader2, Check, ChevronLeft, ChevronRight, Phone } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { useAcConfig } from '../hooks/useAcConfig'
import { KINDS, condOf, kindOf, statusOf, shopOffer, waLink } from '../lib/usedAc'

const keyOf = (u) => u.id
const store = {
  get: (k) => { try { return localStorage.getItem(k) || '' } catch { return '' } },
  set: (k, v) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } },
}

// Customer-facing catalogue of second-hand AC units (split / window), each priced by its condition and look.
export default function UsedAcStore() {
  const { data, loading } = useLiveShared('used_ac', { order: '-createdAt', limit: 120 })
  const { cfg } = useAcConfig()
  const [kind, setKind] = useState('all')
  const [open, setOpen] = useState(null)
  const units = useMemo(() => (data || []).filter((u) => u.status !== 'hidden').filter((u) => kind === 'all' || u.kind === kind)
    .sort((a, b) => (a.status === 'sold') - (b.status === 'sold')), [data, kind])

  return (
    <div>
      <div className="grid grid-cols-3 gap-2 mb-4">
        {[{ id: 'all', label: 'الكل', icon: '🛒' }, ...KINDS].map((k) => (
          <button key={k.id} onClick={() => setKind(k.id)}
            className={`py-2.5 rounded-xl text-xs font-black border-2 transition ${kind === k.id ? 'bg-sky-700 border-sky-800 text-white shadow-lg' : 'bg-white border-slate-300 text-slate-800'}`}>
            <span className="block text-base">{k.icon}</span>{k.label}
          </button>
        ))}
      </div>
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3"><div className="h-60 rounded-2xl bg-white animate-pulse" /><div className="h-60 rounded-2xl bg-white animate-pulse" /></div>
      ) : units.length === 0 ? (
        <div className="text-center py-14">
          <p className="text-4xl mb-2">❄️</p>
          <p className="text-sm font-black text-slate-800">لا توجد أجهزة معروضة الآن</p>
          <p className="text-xs font-bold text-slate-500 mt-1">نضيف أجهزة جديدة باستمرار — تواصل معنا لو تبحث عن نوع معين</p>
          <a href={waLink(cfg.waNumber, 'السلام عليكم، أبحث عن جهاز تكييف مستعمل')} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 mt-4 bg-emerald-600 text-white font-black text-sm rounded-xl px-5 py-3"><MessageCircle size={16} />تواصل واتساب</a>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {units.map((u) => <UnitCard key={keyOf(u)} u={u} onOpen={() => setOpen(u)} />)}
        </div>
      )}
      {open && <UnitSheet u={open} wa={cfg.waNumber} onClose={() => setOpen(null)} />}
    </div>
  )
}

function Badge({ bg, children }) { return <span className="text-[10px] font-black text-white rounded-full px-2.5 py-1 shadow" style={{ backgroundColor: bg }}>{children}</span> }

function UnitCard({ u, onOpen }) {
  const c = condOf(u.condition); const st = statusOf(u.status || 'available'); const k = kindOf(u.kind)
  const photo = (u.photos || [])[0]
  const off = u.status === 'sold'
  return (
    <button onClick={onOpen} className={`text-right bg-white rounded-2xl border-2 shadow-sm overflow-hidden active:scale-[0.98] transition ${off ? 'opacity-60 border-slate-200' : 'border-slate-200 hover:border-sky-500'}`}>
      <div className="relative h-44 bg-slate-100">
        {photo ? <img src={photo} alt={u.title} className="w-full h-full object-cover" loading="lazy" /> : <div className="w-full h-full flex items-center justify-center text-5xl">{k.icon}</div>}
        <div className="absolute top-2 right-2 flex gap-1.5"><Badge bg={c.bg}>{c.label}</Badge>{u.status && u.status !== 'available' && <Badge bg={st.bg}>{st.label}</Badge>}</div>
        <div className="absolute bottom-2 left-2"><Badge bg="#0f172a">{k.label}</Badge></div>
      </div>
      <div className="p-3.5">
        <h3 className="font-display font-black text-slate-900 text-sm leading-snug">{u.title}</h3>
        <p className="text-[11px] font-bold text-slate-600 mt-1 line-clamp-2">{[u.brand, u.capacity, u.age ? `عمره ${u.age}` : ''].filter(Boolean).join(' · ')}</p>
        <div className="flex items-end justify-between mt-2.5">
          {u.price ? <span className="font-display font-black text-sky-800 text-xl">{u.price} <span className="text-[11px]">ر.س</span></span> : <span className="text-xs font-black text-slate-600">السعر بالتواصل</span>}
          <span className="flex items-center gap-1 text-[11px] font-black text-emerald-800 bg-emerald-100 border border-emerald-300 rounded-full px-2.5 py-1"><Handshake size={12} />قابل للتفاوض</span>
        </div>
      </div>
    </button>
  )
}

function UnitSheet({ u, wa, onClose }) {
  const [i, setI] = useState(0)
  const photos = u.photos || []
  const c = condOf(u.condition); const k = kindOf(u.kind)
  const [neg, setNeg] = useState(false)
  useEffect(() => { document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = '' } }, [])
  const specs = [['النوع', k.label], ['الماركة', u.brand], ['السعة', u.capacity], ['العمر', u.age], ['الحالة', `${c.label} — ${c.hint}`], ['الضمان', u.warranty]].filter(([, v]) => v)
  const msg = `السلام عليكم، مهتم بالجهاز: ${u.title}${u.price ? ` (السعر المعروض ${u.price} ر.س)` : ''}`
  return (
    <div className="fixed inset-0 z-[60] bg-black/60 flex items-end md:items-center justify-center" onClick={onClose} dir="rtl">
      <div className="bg-white w-full md:max-w-lg max-h-[92dvh] overflow-y-auto rounded-t-3xl md:rounded-3xl" onClick={(e) => e.stopPropagation()}>
        <div className="relative bg-slate-100 h-64">
          {photos.length ? <img src={photos[i % photos.length]} alt={u.title} className="w-full h-full object-cover" /> : <div className="w-full h-full flex items-center justify-center text-6xl">{k.icon}</div>}
          <button onClick={onClose} className="absolute top-3 left-3 w-10 h-10 rounded-full bg-black/70 text-white flex items-center justify-center"><X size={18} /></button>
          <div className="absolute top-3 right-3"><Badge bg={c.bg}>{c.label}</Badge></div>
          {photos.length > 1 && <>
            <button onClick={() => setI((i - 1 + photos.length) % photos.length)} className="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/60 text-white flex items-center justify-center"><ChevronRight size={18} /></button>
            <button onClick={() => setI((i + 1) % photos.length)} className="absolute left-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/60 text-white flex items-center justify-center"><ChevronLeft size={18} /></button>
          </>}
        </div>
        {photos.length > 1 && <div className="flex gap-2 overflow-x-auto px-4 pt-3">{photos.map((p, n) => <img key={p + n} src={p} onClick={() => setI(n)} className={`w-16 h-16 rounded-lg object-cover shrink-0 border-2 ${n === i % photos.length ? 'border-sky-600' : 'border-transparent'}`} />)}</div>}
        <div className="p-4 flex flex-col gap-3">
          <h2 className="font-display font-black text-slate-900 text-lg">{u.title}</h2>
          <div className="flex items-end justify-between">
            {u.price ? <span className="font-display font-black text-sky-800 text-3xl">{u.price} <span className="text-sm">ر.س</span></span> : <span className="font-black text-slate-700">السعر بالتواصل</span>}
            <span className="text-[11px] font-black text-emerald-800 bg-emerald-100 border border-emerald-300 rounded-full px-2.5 py-1">قابل للتفاوض</span>
          </div>
          <div className="grid grid-cols-2 gap-2">
            {specs.map(([a, b]) => <div key={a} className="bg-slate-100 border border-slate-300 rounded-xl px-3 py-2"><p className="text-[10px] font-black text-slate-600">{a}</p><p className="text-xs font-black text-slate-900">{b}</p></div>)}
          </div>
          {u.appearance && <p className="bg-amber-50 border-r-4 border-amber-500 rounded-xl p-3 text-xs font-bold text-amber-900 leading-relaxed">📋 الشكل والحالة: {u.appearance}</p>}
          {u.defects && <p className="bg-rose-50 border-r-4 border-rose-500 rounded-xl p-3 text-xs font-bold text-rose-900 leading-relaxed">⚠️ ملاحظات: {u.defects}</p>}
          {u.status && u.status !== 'available' ? (
            <p className="text-center font-black text-slate-700 bg-slate-200 rounded-xl py-3">هذا الجهاز {statusOf(u.status).label} حالياً</p>
          ) : (
            <>
              {!neg && <button onClick={() => setNeg(true)} disabled={!u.price} className="w-full bg-gradient-to-l from-emerald-600 to-teal-600 text-white font-display font-black rounded-2xl py-4 shadow-xl ring-2 ring-emerald-200 flex items-center justify-center gap-2 disabled:opacity-50"><Handshake size={18} />تفاوض على السعر</button>}
              {neg && <Negotiate u={u} onDone={() => {}} />}
              <div className="grid grid-cols-2 gap-2">
                <a href={waLink(wa, msg)} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-2 bg-green-600 text-white font-black text-sm rounded-xl py-3"><MessageCircle size={16} />واتساب</a>
                <a href={`tel:+${String(wa || '').replace(/\D/g, '')}`} className="flex items-center justify-center gap-2 bg-slate-900 text-white font-black text-sm rounded-xl py-3"><Phone size={16} />اتصال</a>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

function Negotiate({ u }) {
  const [name, setName] = useState(store.get('mq_shop_name'))
  const [phone, setPhone] = useState(store.get('mq_shop_phone'))
  const [price, setPrice] = useState('')
  const [busy, setBusy] = useState(false)
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const send = async (p) => {
    setErr(''); setBusy(true)
    try {
      store.set('mq_shop_name', name); store.set('mq_shop_phone', phone)
      setRes(await shopOffer(u.id, p, name, phone))
    } catch (e) { setErr(e?.message || 'تعذر الإرسال، حاول مرة أخرى.') }
    setBusy(false)
  }
  const inp = 'w-full bg-white border-2 border-slate-300 rounded-xl px-3 py-3 text-sm font-bold text-slate-900 focus:outline-none focus:border-emerald-600'
  const done = res?.status === 'accepted'
  return (
    <div className="bg-emerald-50 border-2 border-emerald-500 rounded-2xl p-3.5 flex flex-col gap-2.5">
      <p className="text-sm font-black text-emerald-900">اكتب سعرك وجوالك، وأرد عليك فوراً 🤝</p>
      {!done && <>
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="الاسم" className={inp} />
        <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="الجوال 05xxxxxxxx" inputMode="tel" dir="ltr" className={`${inp} text-right`} />
        <input value={price} onChange={(e) => setPrice(e.target.value.replace(/[^\d]/g, ''))} placeholder="سعرك المقترح (ر.س)" inputMode="numeric" className={inp} />
        <button onClick={() => send(price)} disabled={busy || !price || !phone} className="bg-emerald-700 text-white font-black rounded-xl py-3 disabled:opacity-50 flex items-center justify-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : <Handshake size={16} />}أرسل عرضي</button>
      </>}
      {res && (
        <div className={`rounded-xl p-3 text-sm font-black leading-relaxed ${done ? 'bg-green-700 text-white' : 'bg-white border-2 border-amber-500 text-slate-900'}`}>
          {done && <Check size={16} className="inline ml-1" />}{res.message}
          {res.status === 'counter' && <button onClick={() => send(res.price)} disabled={busy} className="mt-2 w-full bg-emerald-700 text-white font-black rounded-lg py-2.5">أوافق على {res.price} ر.س</button>}
          {res.status === 'final' && <button onClick={() => send(res.price)} disabled={busy} className="mt-2 w-full bg-emerald-700 text-white font-black rounded-lg py-2.5">أوافق على {res.price} ر.س</button>}
        </div>
      )}
      {err && <p className="text-rose-700 text-xs font-black">{err}</p>}
    </div>
  )
}
