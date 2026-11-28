import { useEffect, useState } from 'react'
import { Loader2, Lock, Camera, Save } from 'lucide-react'
import { post } from '../lib/_api'

// «سجل الهوية» (owner, 2026-11-24): the technician fills it once himself — his photo and his data — then it locks and
// only operations / the owner change anything (photo, phone, all of it). Pictures stay private on the server.
const FIELDS = [
  ['fullName', 'الاسم الكامل (زي الإقامة)'], ['iqamaNo', 'رقم الإقامة / الهوية', 'ltr'], ['iqamaExpiry', 'تاريخ انتهاء الإقامة', 'ltr', 'date'],
  ['nationality', 'الجنسية'], ['phone', 'رقم الجوال', 'ltr'], ['emergencyPhone', 'رقم للطوارئ (قريب)', 'ltr'], ['birthDate', 'تاريخ الميلاد', 'ltr', 'date'],
  ['address', 'السكن (الحي / المدينة)'], ['notes', 'ملاحظات'],
]
const FILES = [['photo', '📷 صورتك الشخصية'], ['iqamaFront', '🪪 الإقامة — الوش'], ['iqamaBack', '🪪 الإقامة — الضهر'], ['license', '🚗 رخصة القيادة'], ['other', '📄 أي ورق تاني']]

const shrink = (file, max = 1400) => new Promise((resolve, reject) => {
  const img = new Image(); const u = URL.createObjectURL(file)
  img.onload = () => { const k = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u); resolve(c.toDataURL('image/jpeg', 0.85)) }
  img.onerror = reject; img.src = u
})

export default function TechIdCard({ techId = '', staff = false, compact: compact0 = false, home = false }) {
  const [rec, setRec] = useState(null)
  const [f, setF] = useState({})
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [consent, setConsent] = useState(false)
  const [share, setShare] = useState(null)   // {reason, to}
  useEffect(() => { post('/techid', { op: 'get', techId }).then((r) => { setRec(r); setF(r.data || {}) }).catch((e) => setMsg(e?.message || 'تعذر التحميل')) }, [techId])
  if (!rec) return <div className="text-xs text-slate-400 flex items-center gap-1 p-3">{msg || <><Loader2 size={12} className="animate-spin" /> بفتح السجل…</>}</div>
  const can = staff || !rec.locked
  // on his home page: the whole form only until he confirms it; then just his photo and «locked»
  const compact = compact0 || (home && rec.locked)
  const save = async (done = false) => {
    setBusy('save'); setMsg('')
    try { const r = await post('/techid', { op: 'save', techId, data: f, done, consent }); setRec(r); setF(r.data || {}); setMsg(done ? 'اتحفظ واتقفل ✅ — أي تعديل بعد كده عن طريق العمليات' : 'اتحفظ ✅') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') }
    setBusy('')
  }
  const upload = async (kind, file) => {
    if (!file) return
    setBusy(kind); setMsg('')
    try { const image = await shrink(file); const r = await post('/techid', { op: 'file', techId, kind, image }); setRec(r) } catch (e) { setMsg(e?.message || 'تعذر الرفع') }
    setBusy('')
  }
  const missing = !rec.files?.photo || !(rec.data || {}).fullName || !(rec.data || {}).iqamaNo
  return (
    <section className="grid gap-3" dir="rtl" data-techid>
      <div className="flex items-center gap-3">
        <div className="w-16 h-16 rounded-2xl bg-white/10 overflow-hidden flex items-center justify-center shrink-0">
          {rec.files?.photo ? <img src={rec.files.photo} alt="" className="w-full h-full object-cover" /> : <Camera size={22} className="text-slate-500" />}
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-black text-white">🪪 سجل الهوية</p>
          <p className="text-[11px] text-slate-400">{rec.locked ? <span className="inline-flex items-center gap-1"><Lock size={11} /> مقفول — التعديل عن طريق العمليات</span> : staff ? 'لسه الفني ما أكّدش بياناته' : 'املاه مرة واحدة: صورتك وبياناتك. بعد التأكيد أي تعديل عن طريق العمليات.'}</p>
        </div>
        {staff && <button onClick={async () => { setBusy('lock'); try { setRec(await post('/techid', { op: 'save', techId, data: {}, locked: !rec.locked })) } catch (e) { setMsg(e?.message) } setBusy('') }} className="shrink-0 rounded-lg bg-white/10 px-2 py-1 text-[10px] font-bold text-slate-200">{rec.locked ? 'افتح له التعديل' : 'اقفل'}</button>}
      </div>
      {!compact && (
        <>
          <div className="grid grid-cols-2 gap-2">
            {FILES.map(([k, l]) => (
              <label key={k} className={`rounded-xl border border-white/10 bg-white/[0.03] p-2 text-center ${can ? 'cursor-pointer' : ''}`}>
                {rec.files?.[k] ? <img src={rec.files[k]} alt="" className="w-full h-24 object-cover rounded-lg mb-1" /> : <div className="h-24 rounded-lg border border-dashed border-white/15 flex items-center justify-center text-slate-500 text-xs mb-1">{busy === k ? <Loader2 size={14} className="animate-spin" /> : 'ارفع صورة'}</div>}
                <span className="text-[10px] font-bold text-slate-300">{l}</span>
                {can && <input type="file" accept="image/*" capture={k === 'photo' ? 'user' : undefined} className="hidden" onChange={(e) => upload(k, e.target.files?.[0])} />}
              </label>
            ))}
          </div>
          <div className="grid gap-2">
            {FIELDS.map(([k, l, dir, type]) => (
              <label key={k} className="text-[11px] font-bold text-slate-400">{l}
                <input value={f[k] || ''} onChange={(e) => setF((x) => ({ ...x, [k]: e.target.value }))} readOnly={!can} type={type || 'text'} dir={dir || 'rtl'}
                  className={`mt-1 w-full rounded-lg border px-3 py-2 text-sm text-white ${can ? 'bg-white/10 border-white/15' : 'bg-white/[0.03] border-white/5 text-slate-300'}`} />
              </label>
            ))}
          </div>
          {can && (
            <div className="grid grid-cols-2 gap-2">
              <button onClick={() => save(false)} disabled={!!busy} className="rounded-xl bg-white/10 text-white font-black py-2.5 text-sm flex items-center justify-center gap-1">{busy === 'save' ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} حفظ</button>
              {!staff && <button onClick={() => save(true)} disabled={!!busy || missing || !consent} className="rounded-xl bg-primary text-black font-black py-2.5 text-sm disabled:opacity-40">تأكيد وقفل البيانات</button>}
            </div>
          )}
          {!staff && can && (
            <label className="flex items-start gap-2 rounded-xl border border-amber-400/30 bg-amber-500/5 p-2.5 text-[11px] text-amber-100 leading-relaxed" data-consent>
              <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-0.5" />
              <span>أوافق إن الإدارة تشارك بيانات هويتي (الاسم، الصورة، رقم الإقامة وصورتها، رقم الجوال) مع العميل أو الجهات الرسمية لو حصلت مشكلة أو شكوى في شغل عملته باسم المؤسسة، عشان تتحل أو تتاخد قانونيًا بيني وبين العميل.</span>
            </label>
          )}
          {!staff && missing && can && <p className="text-[10px] text-amber-300">لازم صورتك + الاسم الكامل + رقم الإقامة قبل التأكيد.</p>}
          {staff && rec.consentAt && <p className="text-[10px] text-emerald-300">✓ وافق على بند المشاركة يوم {new Date(rec.consentAt).toLocaleDateString('ar-SA')}</p>}
          {staff && (
            share ? (
              <div className="grid gap-2 rounded-xl border border-rose-400/30 bg-rose-500/5 p-3" data-share>
                <p className="text-xs font-black text-rose-200">مشاركة بيانات الهوية (للمساءلة)</p>
                <input value={share.reason} onChange={(e) => setShare((x) => ({ ...x, reason: e.target.value }))} placeholder="السبب: رقم الطلب والمشكلة" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
                <input value={share.to} onChange={(e) => setShare((x) => ({ ...x, to: e.target.value }))} placeholder="لمين؟ (اسم العميل / الجهة)" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
                <p className="text-[10px] text-slate-400">بيتشارك: الاسم، الصورة، رقم الإقامة وصورتها، الجوال، الجنسية. السكن ورقم الطوارئ وتاريخ الميلاد مش بيتشاركوا. كل مشاركة بتتسجل في السجل باسمك.</p>
                <div className="grid grid-cols-2 gap-2">
                  <button disabled={busy === 'share'} onClick={async () => {
                    setBusy('share'); setMsg('')
                    try {
                      await post('/techid', { op: 'share', techId, reason: share.reason, to: share.to })
                      const d = rec.data || {}
                      const text = ['🪪 بيانات الفني', d.fullName && `الاسم: ${d.fullName}`, d.iqamaNo && `رقم الإقامة: ${d.iqamaNo}`, d.nationality && `الجنسية: ${d.nationality}`, d.phone && `الجوال: ${d.phone}`, `السبب: ${share.reason}`].filter(Boolean).join('\n')
                      const files = []
                      for (const k of ['photo', 'iqamaFront']) { const u = rec.files?.[k]; if (u) { const b = await (await fetch(u)).blob(); files.push(new File([b], `${k}.jpg`, { type: b.type || 'image/jpeg' })) } }
                      if (navigator.canShare && files.length && navigator.canShare({ files })) await navigator.share({ files, text })
                      else if (navigator.share) await navigator.share({ text })
                      else { await navigator.clipboard.writeText(text); setMsg('اتنسخت البيانات — الصقها في الواتساب، والصور حمّلها من فوق') }
                      setShare(null)
                    } catch (e) { if (e?.name !== 'AbortError') setMsg(e?.message || 'تعذرت المشاركة') }
                    setBusy('')
                  }} className="rounded-xl bg-rose-500 text-white font-black py-2.5 text-sm disabled:opacity-50">مشاركة</button>
                  <button onClick={() => setShare(null)} className="rounded-xl bg-white/10 text-white font-black py-2.5 text-sm">إلغاء</button>
                </div>
              </div>
            ) : <button onClick={() => setShare({ reason: '', to: '' })} className="rounded-xl bg-rose-500/15 border border-rose-400/30 text-rose-200 font-black py-2.5 text-sm" data-share-open>📤 مشاركة بيانات الهوية (مشكلة / شكوى)</button>
          )}
          {staff && (rec.log || []).length > 0 && (
            <details className="text-[11px] text-slate-400"><summary className="cursor-pointer">سجل التعديلات ({rec.log.length})</summary>
              {rec.log.map((x, i) => <p key={i}>{new Date(x.at).toLocaleString('ar-SA')} — {x.by}: {x.what}</p>)}
            </details>
          )}
        </>
      )}
      {msg && <p className="text-[11px] font-bold text-amber-300">{msg}</p>}
    </section>
  )
}
