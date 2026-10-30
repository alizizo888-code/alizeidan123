import { useMemo, useState } from 'react'
import { CalendarDays, Clock, Landmark, Zap } from 'lucide-react'

// Appointment picker used by every service order: a day (with the real weekday + date), then either an exact hour or a prayer-based slot.
// onChange({ label, at }) — `at` is the ISO time the reminder (15 min before) is based on; null = "as soon as possible".
const PRAYERS = [
  { id: 'قبل الظهر', h: 11, m: 30 }, { id: 'بعد الظهر', h: 13, m: 0 }, { id: 'قبل العصر', h: 15, m: 0 },
  { id: 'بعد العصر', h: 16, m: 30 }, { id: 'بعد المغرب', h: 18, m: 30 }, { id: 'بعد العشاء', h: 20, m: 30 },
]
const WD = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
const hourLabel = (h, m) => `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'ص' : 'م'}`

export default function AppointmentPicker({ onChange, className = '' }) {
  const days = useMemo(() => {
    const out = []
    for (let i = 0; i < 7; i++) {
      const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + i)
      const tag = i === 0 ? 'اليوم' : i === 1 ? 'غداً' : ''
      out.push({ i, d, label: `${WD[d.getDay()]} ${d.getDate()}/${d.getMonth() + 1}${tag ? ` (${tag})` : ''}`, short: `${WD[d.getDay()]} ${d.getDate()}/${d.getMonth() + 1}` })
    }
    return out
  }, [])
  const [day, setDay] = useState(0)
  const [mode, setMode] = useState('prayer')
  const [pick, setPick] = useState('')

  const slots = useMemo(() => {
    const out = []
    const now = Date.now() + 30 * 60 * 1000
    for (let h = 8; h <= 23; h++) for (const m of [0, 30]) {
      const t = new Date(days[day].d); t.setHours(h, m, 0, 0)
      if (t.getTime() > now) out.push({ key: `${h}:${m}`, h, m, label: hourLabel(h, m) })
    }
    return out
  }, [day, days])
  const prayers = useMemo(() => PRAYERS.filter((p) => { const t = new Date(days[day].d); t.setHours(p.h, p.m, 0, 0); return t.getTime() > Date.now() + 15 * 60 * 1000 }), [day, days])

  function emit(nd, nm, np) {
    if (!np) { onChange({ label: '', at: null }); return }
    const base = new Date(days[nd].d)
    let h, m, text
    if (nm === 'prayer') { const p = PRAYERS.find((x) => x.id === np); if (!p) return; h = p.h; m = p.m; text = `${p.id}` }
    else { const [hh, mm] = np.split(':').map(Number); h = hh; m = mm; text = hourLabel(hh, mm) }
    base.setHours(h, m, 0, 0)
    onChange({ label: `${days[nd].short} — ${text}`, at: base.toISOString() })
  }

  const sel = 'w-full h-11 px-3 rounded-2xl border-2 border-sky-400 bg-white font-extrabold text-sm text-slate-800 outline-none'
  const tab = (on) => `h-11 rounded-2xl border-2 font-extrabold text-xs flex items-center justify-center gap-1.5 ${on ? 'bg-sky-600 border-sky-600 text-white' : 'bg-white border-sky-400 text-sky-700'}`
  return (
    <div className={`flex flex-col gap-2 ${className}`}>
      <label className="flex items-center gap-1.5 text-xs font-black text-slate-800"><CalendarDays size={14} /> موعد الزيارة</label>
      <button type="button" onClick={() => { setPick(''); emit(day, mode, '') }} className={`h-10 rounded-2xl border-2 text-xs font-black flex items-center justify-center gap-1.5 ${!pick ? 'bg-amber-400 border-amber-500 text-slate-900' : 'bg-white border-slate-300 text-slate-600'}`}><Zap size={13} /> أقرب وقت ممكن</button>
      <select value={day} onChange={(e) => { const v = Number(e.target.value); setDay(v); setPick(''); emit(v, mode, '') }} className={sel}>
        {days.map((x) => <option key={x.i} value={x.i}>{x.label}</option>)}
      </select>
      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={() => { setMode('prayer'); setPick(''); emit(day, 'prayer', '') }} className={tab(mode === 'prayer')}><Landmark size={14} /> بنظام الصلاة</button>
        <button type="button" onClick={() => { setMode('hour'); setPick(''); emit(day, 'hour', '') }} className={tab(mode === 'hour')}><Clock size={14} /> بالساعة</button>
      </div>
      {mode === 'prayer' ? (
        <select value={pick} onChange={(e) => { setPick(e.target.value); emit(day, 'prayer', e.target.value) }} className={sel}>
          <option value="">اختر وقت الصلاة</option>
          {prayers.map((p) => <option key={p.id} value={p.id}>{p.id.replace('قبل ', 'قبل صلاة ').replace('بعد ', 'بعد صلاة ')}</option>)}
        </select>
      ) : (
        <select value={pick} onChange={(e) => { setPick(e.target.value); emit(day, 'hour', e.target.value) }} className={sel}>
          <option value="">اختر الساعة المناسبة</option>
          {slots.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
        </select>
      )}
      {pick && <p className="text-[11px] font-bold text-emerald-700">🔔 نذكّرك قبل الموعد بـ 15 دقيقة</p>}
    </div>
  )
}
