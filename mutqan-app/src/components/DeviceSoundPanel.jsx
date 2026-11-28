import { useEffect, useState } from 'react'
import { Play, Smartphone, ChevronDown } from 'lucide-react'
import { TONES, VIBES, TONE_KINDS, kindTone, playKind, playTone, chime, vibrate, vibeSupported, deviceSound, setDeviceSound, onDeviceSound, effectiveSound } from '../lib/alert'
import { useNotifySettings } from '../hooks/useNotifySettings'

// Everyone, on their own phone: which ringtone and which vibration THIS device uses, on top of the company setting.
// Nothing here changes anyone else's device. «زي إعداد الشركة» = follow the owner's choice.
export default function DeviceSoundPanel({ startOpen = false }) {
  const { settings } = useNotifySettings()
  const company = settings.sound
  const [d, setD] = useState(deviceSound)
  const [open, setOpen] = useState(startOpen)
  useEffect(() => onDeviceSound(setD), [])
  const set = (patch) => setD(setDeviceSound(patch))
  const eff = effectiveSound(company)
  const canVibe = vibeSupported()
  const chip = (on) => `inline-flex items-center rounded-full border text-[11px] font-bold overflow-hidden ${on ? 'bg-primary/15 border-primary text-primary' : 'bg-white border-slate-200 text-slate-600'}`

  const TonePick = ({ field, title, high }) => (
    <div>
      <p className="text-[11px] font-bold text-slate-500 mb-1.5">{title}</p>
      <div className="flex flex-wrap gap-1.5">
        <button onClick={() => set({ [field]: '' })} className={chip(!d[field]) + ' px-3 py-1.5'}>زي إعداد الشركة ({TONES[company[field]]?.label || '—'})</button>
        {Object.entries(TONES).map(([id, t]) => (
          <span key={id} className={chip(d[field] === id)}>
            <button onClick={() => { set({ [field]: id }); playTone(id, { volume: eff.volume ?? 0.7, repeat: high ? 2 : 1 }) }} className="px-3 py-1.5">{t.label}</button>
            <button onClick={() => playTone(id, { volume: eff.volume ?? 0.7 })} className="px-2 py-1.5 border-r border-slate-200" aria-label={'تجربة ' + t.label}><Play size={11} /></button>
          </span>
        ))}
      </div>
    </div>
  )
  const VibePick = ({ field, title }) => (
    <div>
      <p className="text-[11px] font-bold text-slate-500 mb-1.5">{title}</p>
      <div className="flex flex-wrap gap-1.5">
        <button onClick={() => set({ [field]: '' })} className={chip(!d[field]) + ' px-3 py-1.5'}>زي إعداد الشركة ({VIBES[company[field]]?.label || '—'})</button>
        {Object.entries(VIBES).map(([id, v]) => (
          <button key={id} onClick={() => { set({ [field]: id }); vibrate(id) }} className={chip(d[field] === id) + ' px-3 py-1.5'}>📳 {v.label}</button>
        ))}
      </div>
    </div>
  )

  return (
    <section className="rounded-2xl bg-white border border-sky-100 shadow-sm" dir="rtl">
      <button onClick={() => setOpen(!open)} aria-expanded={open} className="w-full flex items-center gap-3 p-3.5 text-right">
        <span className="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center shrink-0"><Smartphone size={17} className="text-primary" /></span>
        <span className="flex-1 min-w-0">
          <span className="block font-black text-sm text-slate-900">نغمة واهتزاز هذا الجهاز</span>
          <span className="block text-[11px] text-slate-500 truncate">{eff.enabled === false ? '🔕 الصوت مكتوم على الجهاز ده' : `🔔 ${TONES[eff.normalTone]?.label} · مهم: ${TONES[eff.highTone]?.label}${eff.vibrate === false ? ' · بدون اهتزاز' : ' · 📳 ' + VIBES[eff.highVibe].label}`}</span>
        </span>
        <ChevronDown size={16} className={'text-slate-400 transition-transform ' + (open ? 'rotate-180' : '')} />
      </button>
      {open && (
        <div className="px-3.5 pb-4 flex flex-col gap-4">
          <p className="text-[11px] text-slate-500 leading-relaxed">اختيارك هنا للجهاز ده بس. أي حاجة تسيبها «زي إعداد الشركة» بتمشي على اختيار المالك.</p>
          <label className="flex items-center gap-3">
            <span className="flex-1 text-sm font-bold text-slate-800">كتم الصوت على الجهاز ده</span>
            <input type="checkbox" checked={!!d.mute} onChange={(e) => set({ mute: e.target.checked || '' })} className="w-5 h-5 accent-rose-500" />
          </label>
          <label className="flex items-center gap-3">
            <span className="flex-1 text-sm font-bold text-slate-800">🔊 قول التنبيهات بصوت (عندك طلب جديد، تم الدفع…)</span>
            <input type="checkbox" checked={d.voice !== false} onChange={(e) => set({ voice: e.target.checked ? '' : false })} className="w-5 h-5 accent-emerald-500" />
          </label>
          <TonePick field="normalTone" title="نغمة الإشعارات العادية" />
          <TonePick field="highTone" title="نغمة الطلبات الجديدة والمهمة" high />
          <label className="flex flex-col gap-1.5 text-[11px] font-bold text-slate-500">مستوى الصوت: {Math.round((eff.volume ?? 0.7) * 100)}%{typeof d.volume !== 'number' && ' (زي الشركة)'}
            <span className="flex items-center gap-2">
              <input type="range" min="0.1" max="1" step="0.05" value={eff.volume ?? 0.7} onChange={(e) => set({ volume: Number(e.target.value) })} className="flex-1 accent-emerald-500" aria-label="مستوى الصوت" />
              {typeof d.volume === 'number' && <button onClick={() => set({ volume: '' })} className="text-[10px] text-primary underline">رجوع</button>}
            </span>
          </label>
          <div className="flex flex-col gap-2">
            <p className="text-[11px] font-bold text-slate-500">الاهتزاز</p>
            <div className="flex gap-1.5">
              {[{ v: '', l: 'زي الشركة' }, { v: true, l: 'شغّال' }, { v: false, l: 'مقفول' }].map((o) => (
                <button key={String(o.v)} onClick={() => set({ vibrate: o.v })} className={chip((d.vibrate ?? '') === o.v) + ' flex-1 justify-center py-1.5'}>{o.l}</button>
              ))}
            </div>
            {!canVibe && <p className="text-[10px] text-amber-600 leading-relaxed">الجهاز ده (أو المتصفح) ما بيدعمش الاهتزاز من التطبيق — زي الآيفون. الصوت والإشعار شغالين عادي.</p>}
          </div>
          {eff.vibrate !== false && <VibePick field="normalVibe" title="اهتزاز الإشعارات العادية" />}
          {eff.vibrate !== false && <VibePick field="highVibe" title="اهتزاز الطلبات الجديدة والمهمة" />}
          <div className="rounded-xl border border-slate-200 p-3 flex flex-col gap-2.5">
            <p className="text-xs font-black text-slate-800">نغمة لكل نوع إشعار</p>
            <p className="text-[10px] text-slate-500 -mt-1.5">كل نوع ليه صوت مختلف عشان تعرف جه إيه من غير ما تبص.</p>
            {Object.entries(TONE_KINDS).map(([k, meta]) => {
              const cur = kindTone(k).tone
              return (
                <div key={k} className="flex items-center gap-2">
                  <span className="flex-1 min-w-0 text-[12px] font-bold text-slate-700 truncate">{meta.label}</span>
                  <select value={d['tone_' + k] || ''} onChange={(e) => { set({ ['tone_' + k]: e.target.value }); setTimeout(() => playKind(k, company), 50) }} className="text-[11px] rounded-lg border border-slate-200 bg-white px-1.5 py-1 max-w-[42%]" aria-label={'نغمة ' + meta.label}>
                    <option value="">الافتراضي ({TONES[meta.tone]?.label})</option>
                    {Object.entries(TONES).map(([id, t]) => <option key={id} value={id}>{t.label}</option>)}
                  </select>
                  <button onClick={() => playKind(k, company)} className="shrink-0 rounded-lg bg-slate-100 p-1.5" aria-label={'تجربة ' + meta.label} title={TONES[cur]?.label}><Play size={12} /></button>
                </div>
              )
            })}
          </div>
          <div className="flex gap-2">
            <button onClick={() => chime('normal', company)} className="flex-1 rounded-xl bg-slate-100 py-2.5 text-xs font-bold text-slate-700">جرّب إشعار عادي</button>
            <button onClick={() => chime('high', company)} className="flex-1 rounded-xl bg-rose-500 py-2.5 text-xs font-black text-white">جرّب طلب جديد</button>
          </div>
        </div>
      )}
    </section>
  )
}
