import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2, RefreshCw } from 'lucide-react'
import { post } from '../lib/_api'

// «جدول المهام» (owner, 2026-11-23): orders booked for a later day wait here instead of reaching a technician days
// ahead. The evening before, operations and the owner get tomorrow's list; on the day, from 7 am, each one is opened for
// dispatch (and in «آلي» mode given to a technician). The log shows every notice. Server: Mutqan_Schedule.
export default function TaskSchedule() {
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState('')
  const load = async (body = { op: 'list' }) => {
    setErr('')
    try { setData(await post('/schedule', body)) } catch (e) { setErr(e?.message || 'تعذر التحميل') }
    setBusy('')
  }
  useEffect(() => { load() }, [])
  const release = (id) => { setBusy(id); load({ op: 'release', id }) }
  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl" data-task-schedule>
      <header className="sticky top-0 z-10 bg-[#090c14]/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-xl bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-white" /></button>
        <div className="flex-1"><h1 className="font-black text-white">📅 جدول المهام</h1><p className="text-[11px] text-slate-400">الطلبات المحجوزة لأيام جاية — بتتوزع يوم الطلب من 7 الصبح</p></div>
        <button onClick={() => load()} className="w-9 h-9 rounded-xl bg-white/5 flex items-center justify-center" aria-label="تحديث"><RefreshCw size={16} className="text-slate-300" /></button>
      </header>
      <div className="max-w-3xl mx-auto p-4 grid gap-4">
        {err && <p className="text-rose-300 text-sm">{err}</p>}
        {!data && !err && <p className="text-slate-400 text-sm flex items-center gap-2"><Loader2 size={14} className="animate-spin" /> جارِ التحميل…</p>}
        {data && data.days.length === 0 && <p className="text-slate-400 text-sm rounded-2xl border border-white/10 p-4">مفيش طلبات محجوزة لأيام جاية دلوقتي.</p>}
        {data?.days.map((d) => (
          <section key={d.day} className="rounded-2xl border border-white/10 bg-white/[0.04] p-3 grid gap-2" data-day={d.day}>
            <p className="font-black text-white text-sm">{d.label} <span className="text-slate-500 font-normal">· {d.orders.length} طلب</span></p>
            {d.orders.map((o) => (
              <div key={o.id} className="rounded-xl bg-black/20 p-2.5 flex items-center gap-2">
                <button onClick={() => navigate(`/staff/order/${o.id}`)} className="flex-1 text-right">
                  <p className="text-sm font-bold text-white">#{o.number} · {o.client || o.phone}</p>
                  <p className="text-[11px] text-slate-400">{o.service}{o.time ? ' · ' + o.time : ''}{o.tech ? ' · 👷 ' + o.tech : ''}</p>
                </button>
                {o.released ? <span className="text-[10px] rounded-lg bg-emerald-500/15 text-emerald-200 px-2 py-1">اتفتح للتوزيع</span>
                  : <button disabled={busy === o.id} onClick={() => release(o.id)} className="text-[11px] font-black rounded-lg bg-amber-400 text-black px-2.5 py-1.5 disabled:opacity-50">افتحه دلوقتي</button>}
              </div>
            ))}
          </section>
        ))}
        {data?.log?.length > 0 && (
          <section className="rounded-2xl border border-white/10 bg-white/[0.03] p-3 grid gap-1" data-sched-log>
            <p className="font-black text-white text-sm mb-1">🔔 سجل التنبيهات</p>
            {data.log.map((l, i) => <p key={i} className="text-[11px] text-slate-300">{l.text} <span className="text-slate-500">· {new Date(l.at).toLocaleString('ar-SA-u-nu-latn', { dateStyle: 'short', timeStyle: 'short' })}</span></p>)}
          </section>
        )}
      </div>
    </div>
  )
}
