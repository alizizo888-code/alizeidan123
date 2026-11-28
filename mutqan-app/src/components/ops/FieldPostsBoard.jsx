import { useMemo, useState } from 'react'
import { db } from '../../lib/db'
import { useLiveShared } from '../../lib/useLive'
import ManualOrderModal from '../ManualOrderModal'

// Operations' view of everything technicians send from the field (owner order 2026-11-22): new jobs they found,
// appointments, road warnings, pins, notes, reminders shared with the room, and «اطلبها من العمليات» on a job a
// colleague holds — with «إسناد له» right there.
const KL = { job: '🆕 طلب جديد من الميدان', appt: '📅 موعد', warn: '⚠️ تحذير', pin: '📍 دبوس', note: '📝 ملاحظة', remind: '⏰ منبّه', voice: '🎙️ رسالة صوتية' }

export default function FieldPostsBoard({ profiles }) {
  const { data } = useLiveShared('field_posts', { order: '-createdAt', limit: 80 })
  const [showAll, setShowAll] = useState(false)
  const list = useMemo(() => (data || []).filter((p) => p.to !== 'self' && (showAll || p.status !== 'done') && Date.now() - Date.parse(p.createdAt) < 3 * 86400000), [data, showAll])
  const [busy, setBusy] = useState('')
  const [mk, setMk] = useState(null)
  async function done(p) { await db.updateShared('field_posts', p.id, { status: 'done', doneAt: new Date().toISOString() }).catch(() => {}) }
  async function giveTo(p) {
    const t = (profiles || []).find((x) => x.userId === p.byId)
    if (!p.orderId || !t) return
    setBusy(p.id)
    try {
      const at = new Date().toISOString()
      await db.updateShared('orders', p.orderId, { technicianId: t.userId, technicianName: t.displayName || p.byName, status: 'assigned', assignedAt: at, handler: 'technician', dispatch: 'tech', forced: false })
      await done(p)
    } catch (e) { alert((e && e.message) || 'تعذّر') } finally { setBusy('') }
  }
  if (!list.length && !showAll) return null
  return (
    <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2" dir="rtl">
      <div className="flex items-center gap-2"><p className="flex-1 text-xs font-black text-white">📣 من الميدان ({list.length})</p><button onClick={() => setShowAll((x) => !x)} className="text-[11px] text-slate-400">{showAll ? 'المفتوح بس' : 'الكل'}</button></div>
      <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
        {list.map((p) => (
          <div key={p.id} className={`rounded-xl p-2.5 border ${p.kind === 'job' || p.claim ? 'border-rose-400/40 bg-rose-500/5' : p.kind === 'warn' ? 'border-amber-400/40 bg-amber-500/10' : 'border-white/10 bg-black/20'} ${p.status === 'done' ? 'opacity-50' : ''}`}>
            <p className="text-[11px] font-black text-white">{p.claim ? '📡 فني طالب طلب' : KL[p.kind] || p.kind} <span className="text-slate-400 font-bold">— {p.byName} · {new Date(p.createdAt).toLocaleTimeString('ar-SA', { hour: 'numeric', minute: '2-digit' })}</span></p>
            <p className="text-xs text-slate-200 mt-1 whitespace-pre-wrap">{p.text}</p>
            {p.audio && <audio src={p.audio} controls preload="none" className="w-full h-9 mt-1" />}
            {p.at && <p className="text-[11px] text-sky-300 mt-1">🕒 {new Date(p.at).toLocaleString('ar-SA', { weekday: 'short', hour: 'numeric', minute: '2-digit' })}</p>}
            {(p.clientName || p.clientPhone) && <p className="text-[11px] text-slate-300 mt-0.5">👤 {p.clientName} {p.clientPhone && <a href={`tel:${p.clientPhone}`} className="text-emerald-300" dir="ltr">{p.clientPhone}</a>}</p>}
            {p.lat != null && <a href={`https://www.google.com/maps?q=${p.lat},${p.lng}`} target="_blank" rel="noreferrer" className="text-[11px] text-emerald-300">📍 الموقع</a>}
            {p.photos?.length > 0 && <div className="flex gap-1 mt-1 overflow-x-auto">{p.photos.map((u) => <a key={u} href={u} target="_blank" rel="noreferrer"><img src={u} alt="" className="w-14 h-14 rounded-lg object-cover" /></a>)}</div>}
            {p.status !== 'done' && (
              <div className="flex gap-1.5 mt-2">
                {p.claim && p.orderId && <button onClick={() => giveTo(p)} disabled={busy === p.id} className="rounded-lg bg-rose-500 text-white text-[11px] font-black px-2.5 py-1">إسناد له</button>}
                {p.kind === 'job' && !p.orderId && <button onClick={() => setMk(p)} className="rounded-lg bg-emerald-500 text-black text-[11px] font-black px-2.5 py-1">حوّله لطلب</button>}
                {p.orderId && <a href={`#/staff/order/${p.orderId}`} className="rounded-lg bg-white/10 text-white text-[11px] font-black px-2.5 py-1">الطلب</a>}
                <button onClick={() => done(p)} className="rounded-lg bg-emerald-500/30 text-emerald-100 text-[11px] font-black px-2.5 py-1">تم ✓</button>
              </div>
            )}
          </div>
        ))}
      </div>
      {mk && <ManualOrderModal by="operations" initial={{ name: mk.clientName || '', phone: String(mk.clientPhone || '').replace(/^\+?966/, '0'), description: mk.text || '', address: mk.place || '', channel: 'field', sourceCode: '' }}
        onClose={() => setMk(null)}
        onCreated={async (o) => {
          // the place, photos and the technician who found it travel with the job; he gets it if he is free
          const t = (profiles || []).find((x) => x.userId === mk.byId)
          const patch = { fieldPostId: mk.id, foundBy: mk.byId, foundByName: mk.byName, ...(mk.lat != null ? { lat: mk.lat, lng: mk.lng } : {}), ...(mk.photos?.length ? { photos: mk.photos } : {}), ...(mk.at ? { appointmentAt: mk.at } : {}) }
          if (t) Object.assign(patch, { technicianId: t.userId, technicianName: t.displayName || mk.byName, status: 'assigned', assignedAt: new Date().toISOString(), handler: 'technician', dispatch: 'tech' })
          await db.updateShared('orders', o.id, patch).catch(() => {})
          await db.updateShared('field_posts', mk.id, { orderId: o.id, status: 'done', doneAt: new Date().toISOString() }).catch(() => {})
          setMk(null)
        }} />}
    </section>
  )
}
