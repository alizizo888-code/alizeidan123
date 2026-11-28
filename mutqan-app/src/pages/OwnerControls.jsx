// «تحكمات المالك — الحذف» (owner order 2026-11-19): the owner, and only the owner, deletes an order, cancels or
// deletes a warranty, removes a technician, or switches a service off. Each delete asks once, by name, and is logged.
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Trash2, ShieldOff, UserX, Search, Loader2, History, Power } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { useTechProfiles } from '../hooks/useTechProfiles'
import { useTeamRole } from '../hooks/useTeamRole'
import { useWarrantyPolicy } from '../hooks/useWarranty'
import { orderWarranty, recordWarranty, warrantyText } from '../warranty'
import { ALL_SPECIALTIES, STATUS_META, specialtyLabel } from '../constants'
import { servicesOff, setServiceOff, onServicesOff, refreshServicesOff } from '../lib/servicesOff'
import { owner } from '../lib/ownerApi'
import { orderAmount } from '../payment'
import OriginCard from '../components/OriginCard'
import AccessDenied from '../components/AccessDenied'
import { orderNo } from '../lib/orderNo'
import SiteSwitches from '../components/SiteSwitches'

const TABS = [['switches', '🔌 التشغيل'], ['orders', 'الطلبات'], ['warranties', 'الضمانات'], ['techs', 'الفنيون'], ['services', 'الخدمات'], ['log', '♻️ سلة المحذوفات']]
const day = (t) => (t ? new Date(t).toLocaleDateString('ar-SA-u-nu-latn', { day: 'numeric', month: 'short', year: 'numeric' }) : '')

export default function OwnerControls() {
  const navigate = useNavigate()
  const { isOwner, loading } = useTeamRole()
  const [tab, setTab] = useState('orders')
  const [q, setQ] = useState('')
  const [ask, setAsk] = useState(null)   // { title, detail, run }
  // what was just deleted leaves the screen at once — no grey ghost waiting for the next refresh
  const [gone, setGone] = useState(() => new Set())
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const { data: orders, refetch: refresh } = useLiveShared('orders', { order: '-createdAt', limit: 500 })
  const { data: records } = useLiveShared('warranties', { order: '-updatedAt', limit: 500 })
  const { profiles } = useTechProfiles()
  const { policy } = useWarrantyPolicy()
  const [off, setOff] = useState(servicesOff())
  const [log, setLog] = useState([])
  useEffect(() => onServicesOff(setOff), [])
  useEffect(() => { refreshServicesOff() }, [])
  const [trash, setTrash] = useState([])
  const loadTrash = () => owner.trash().then((l) => setTrash(Array.isArray(l) ? l : [])).catch(() => {})
  useEffect(() => { if (tab === 'log') { owner.log().then((l) => setLog(Array.isArray(l) ? l : [])).catch(() => {}); loadTrash() } }, [tab])
  async function restore(t) {
    setBusy(true)
    try { await owner.restore(t.id); setMsg(`رجع ${t.what}: ${t.label} ✓`); setTrash((x) => x.filter((y) => y.id !== t.id)); setGone((g) => { const n = new Set(g); n.delete(t.docId); return n }); refresh?.() } catch (e) { setMsg(e?.message || 'تعذّر الاسترجاع') } finally { setBusy(false) }
  }

  const needle = q.trim()
  const match = (...xs) => !needle || xs.some((x) => String(x || '').includes(needle))
  const list = useMemo(() => (orders || []).filter((o) => match(o.number, orderNo(o), o.clientName, o.clientPhone, o.address, specialtyLabel(o.specialtyId))), [orders, needle])
  const wOrders = useMemo(() => (orders || []).map((o) => ({ o, w: orderWarranty(o, policy) })).filter((x) => x.w.known && !x.o.warrantyVoid && match(x.o.number, x.o.clientName, x.o.clientPhone, x.o.address)), [orders, policy, needle])
  const wRecords = useMemo(() => (records || []).filter((r) => match(r.clientName, r.clientPhone, r.certificateNo, r.device)), [records, needle])
  const techs = useMemo(() => (profiles || []).filter((t) => match(t.displayName, t.phone)), [profiles, needle])

  if (loading) return <div className="h-full flex items-center justify-center text-slate-500 text-sm" dir="rtl">جارِ التحقق…</div>
  if (!isOwner) return <AccessDenied need="المالك فقط" />

  async function go() {
    if (!ask) return
    setBusy(true); setMsg('')
    try { const r = await ask.run(); if (ask.id) setGone((g) => new Set(g).add(ask.id)); setMsg(ask.done ? ask.done(r) : 'تم ✓ — تقدر ترجّعه من «سلة المحذوفات»'); refresh?.() } catch (e) { setMsg(e?.message || 'تعذر التنفيذ') }
    setBusy(false); setAsk(null)
    setTimeout(() => setMsg(''), 4000)
  }

  const num = (o) => '#' + (o.number || orderNo(o))
  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-[#090c14] text-white p-3 pb-24" dir="rtl">
      <div className="flex items-center gap-2 mb-3">
        <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <h1 className="flex-1 font-black">🗑️ تحكمات المالك — الحذف</h1>
      </div>
      <div className="flex gap-1.5 overflow-x-auto mb-3">{TABS.map(([id, l]) => <button key={id} onClick={() => setTab(id)} className={`shrink-0 rounded-full px-3 py-1.5 text-xs font-bold border ${tab === id ? 'bg-rose-600 border-rose-500' : 'border-white/15 text-slate-300'}`}>{l}</button>)}</div>
      {tab !== 'services' && tab !== 'log' && (
        <label className="flex items-center gap-2 bg-white/5 border border-white/10 rounded-xl px-3 py-2 mb-3"><Search size={15} className="text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث برقم الطلب أو الاسم أو الجوال أو الحي" className="flex-1 bg-transparent outline-none text-sm" /></label>
      )}
      {msg && <p className="mb-3 rounded-xl bg-white/10 px-3 py-2 text-sm">{msg}</p>}

      {tab === 'orders' && (
        <div className="grid gap-2">
          <p className="text-[11px] text-slate-500">{list.length} طلب — الحذف نهائي ومعاه محادثة الطلب وطلبات الضمان بتاعته.</p>
          {list.filter((o) => !gone.has(o.id)).slice(0, 200).map((o) => (
            <div key={o.id} className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-1">
              <div className="flex items-center gap-2"><span className="font-black text-sm">{num(o)}</span><span className="text-xs">{specialtyLabel(o.specialtyId)}</span><span className={`text-[10px] rounded-full px-2 ${STATUS_META[o.status]?.bg || ''}`}>{STATUS_META[o.status]?.label || o.status}</span><span className="mr-auto text-[10px] text-slate-500">{day(o.createdAt)}</span></div>
              <p className="text-xs text-slate-300">{o.clientName || 'بدون اسم'} {o.clientPhone || ''} {o.address ? '· ' + o.address : ''}{orderAmount(o) ? ` · ${orderAmount(o)} ر.س` : ''}{o.technicianName ? ` · ${o.technicianName}` : ''}</p>
              <OriginCard order={o} compact />
              <div className="flex gap-2 mt-1">
                <button onClick={() => navigate('/staff/order/' + o.id)} className="flex-1 bg-white/10 rounded-xl py-1.5 text-xs font-bold">فتح</button>
                <button onClick={() => setAsk({ id: o.id, title: `حذف الطلب ${num(o)}؟`, detail: 'الطلب يختفي ومعاه محادثته وطلبات الضمان بتاعته — وتقدر ترجّعه من «سلة المحذوفات» خلال 90 يوم.', run: () => owner.deleteOrder(o.id), done: (r) => (r?.deleted ? `اتمسح الطلب ${num(o)}` : 'الطلب مش موجود') })} className="px-4 bg-rose-600/80 rounded-xl text-xs font-bold flex items-center gap-1"><Trash2 size={14} />حذف</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {tab === 'warranties' && (
        <div className="grid gap-2">
          <p className="text-[11px] text-slate-500">ضمانات الطلبات المكتملة: «إلغاء الضمان» يسيب الطلب ويلغي ضمانه. ضمانات السجل اليدوي: «حذف» يمسح السجل.</p>
          {wOrders.map(({ o, w }) => (
            <div key={o.id} className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-1">
              <div className="flex items-center gap-2"><span className="font-black text-sm">🛡️ {num(o)}</span><span className="text-xs">{specialtyLabel(o.specialtyId)}</span></div>
              <p className="text-xs text-slate-300">{o.clientName || 'بدون اسم'} {o.clientPhone || ''} · {warrantyText(w)}</p>
              <button onClick={() => setAsk({ title: `إلغاء ضمان الطلب ${num(o)}؟`, detail: 'الطلب يفضل موجود، والضمان بتاعه يتلغي (0 يوم) ويختفي من الخريطة والضمانات.', run: () => owner.voidWarranty(o.id), done: () => `اتلغى ضمان ${num(o)}` })} className="mt-1 self-start px-4 py-1.5 bg-amber-600/80 rounded-xl text-xs font-bold flex items-center gap-1"><ShieldOff size={14} />إلغاء الضمان</button>
            </div>
          ))}
          {wRecords.filter((r) => !gone.has(r.id)).map((r) => (
            <div key={r.id} className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-1">
              <p className="font-black text-sm">📄 {r.clientName || 'ضمان مسجل'} {r.certificateNo ? `· ${r.certificateNo}` : ''}</p>
              <p className="text-xs text-slate-300">{specialtyLabel(r.specialtyId)} · {warrantyText(recordWarranty(r))}</p>
              <button onClick={() => setAsk({ id: r.id, title: 'حذف سجل الضمان؟', detail: `${r.clientName || ''} ${r.certificateNo || ''} — يختفي، وتقدر ترجّعه من «سلة المحذوفات».`, run: () => owner.deleteWarrantyRecord(r.id), done: () => 'اتمسح سجل الضمان' })} className="mt-1 self-start px-4 py-1.5 bg-rose-600/80 rounded-xl text-xs font-bold flex items-center gap-1"><Trash2 size={14} />حذف</button>
            </div>
          ))}
          {!wOrders.length && !wRecords.length && <p className="text-sm text-slate-500">مفيش ضمانات.</p>}
        </div>
      )}

      {tab === 'techs' && (
        <div className="grid gap-2">
          <p className="text-[11px] text-slate-500">حذف الفني بيمسح حسابه وملفه، وطلباته اللي لسه مفتوحة بترجع «جديد» للتوزيع. «فني الموقع» تقدر ترجّعه من سلة المحذوفات خلال 90 يوم. تمسح.</p>
          {techs.filter((t) => !gone.has(t.userId)).map((t) => (
            <div key={t.userId} className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 flex items-center gap-2">
              <div className="flex-1"><p className="font-black text-sm">🧰 {t.displayName || 'فني'}</p><p className="text-xs text-slate-400">{t.phone || ''} {(t.specialties || []).map(specialtyLabel).join('، ')}</p></div>
              {t.isSite ? <span className="text-[10px] text-slate-500">نظام</span> : <button onClick={() => setAsk({ id: t.userId, title: `حذف الفني ${t.displayName || ''}؟`, detail: 'حسابه يتقفل وملفه يختفي، وطلباته المفتوحة ترجع للتوزيع — وتقدر ترجّعه من «سلة المحذوفات» بنفس دخوله.', run: () => owner.deleteTech(t.userId), done: (r) => `اتمسح الفني${r?.ordersReturned ? ` ورجع ${r.ordersReturned} طلب للتوزيع` : ''}` })} className="px-4 py-1.5 bg-rose-600/80 rounded-xl text-xs font-bold flex items-center gap-1"><UserX size={14} />حذف</button>}
            </div>
          ))}
          {!techs.length && <p className="text-sm text-slate-500">مفيش فنيين.</p>}
        </div>
      )}

      {tab === 'switches' && <SiteSwitches />}
      {tab === 'services' && (
        <div className="grid gap-2">
          <p className="text-[11px] text-slate-500">الخدمة اللي تقفلها تختفي من التطبيق والمواقع وطلب جديد والبوت. الطلبات القديمة بتفضل باسمها، وتقدر ترجّعها بضغطة.</p>
          {ALL_SPECIALTIES.map((s) => {
            const isOff = off.includes(s.id)
            return (
              <div key={s.id} className={`rounded-2xl border p-3 flex items-center gap-2 ${isOff ? 'border-rose-500/30 bg-rose-500/5' : 'border-white/10 bg-white/[0.04]'}`}>
                <span className="text-lg">{s.icon}</span>
                <div className="flex-1"><p className={`font-black text-sm ${isOff ? 'line-through text-slate-500' : ''}`}>{s.label}</p><p className="text-[11px] text-slate-500">{s.desc}</p></div>
                <button onClick={() => (isOff ? setServiceOff(s.id, false).then(setOff).catch((e) => setMsg(e?.message || 'تعذر')) : setAsk({ title: `حذف خدمة «${s.label}»؟`, detail: 'تختفي من كل القوائم اللي العميل والفريق بيختاروا منها. تقدر ترجّعها بعدين.', run: () => setServiceOff(s.id, true).then((x) => { setOff(x); return x }), done: () => `اتقفلت خدمة ${s.label}` }))}
                  className={`px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1 ${isOff ? 'bg-emerald-600/80' : 'bg-rose-600/80'}`}><Power size={13} />{isOff ? 'رجّعها' : 'حذف'}</button>
              </div>
            )
          })}
        </div>
      )}

      {tab === 'log' && (
        <div className="grid gap-1.5">
          {trash.length > 0 && <p className="text-xs font-black text-white mt-1">♻️ ممكن يرجع ({trash.length})</p>}
          {trash.map((t) => (
            <div key={t.id} className="flex items-center gap-2 rounded-xl bg-white/[0.06] border border-white/10 px-3 py-2 text-xs">
              <span className="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-black">{t.what}</span>
              <span className="flex-1 min-w-0 truncate text-slate-200">{t.label}</span>
              <span className="text-[10px] text-slate-500">{day(t.at)}{t.by ? ` · ${t.by}` : ''}</span>
              <button disabled={busy} onClick={() => restore(t)} className="rounded-lg bg-emerald-500 text-black font-black px-2.5 py-1 text-[11px]">استرجاع</button>
            </div>
          ))}
          <p className="text-xs font-black text-white mt-3">السجل</p>
          {log.length ? log.map((l, i) => <p key={i} className="text-xs text-slate-300 rounded-xl bg-white/[0.04] px-3 py-2"><History size={12} className="inline text-slate-500" /> {day(l.at)} — {l.what}: {l.label} <span className="text-slate-500">({l.by})</span></p>) : <p className="text-sm text-slate-500">مفيش عمليات حذف لسه.</p>}
        </div>
      )}

      {ask && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-end sm:items-center justify-center" onClick={() => !busy && setAsk(null)}>
          <div onClick={(e) => e.stopPropagation()} className="w-full max-w-sm bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]" dir="rtl">
            <p className="font-black mb-1">{ask.title}</p>
            <p className="text-sm text-slate-300 mb-4">{ask.detail}</p>
            <div className="flex gap-2"><button disabled={busy} onClick={go} className="flex-1 bg-rose-600 rounded-xl py-2.5 font-black flex items-center justify-center gap-1">{busy ? <Loader2 size={16} className="animate-spin" /> : <Trash2 size={16} />}تأكيد</button><button disabled={busy} onClick={() => setAsk(null)} className="flex-1 bg-white/10 rounded-xl py-2.5 font-bold">إلغاء</button></div>
          </div>
        </div>
      )}
    </div>
  )
}
