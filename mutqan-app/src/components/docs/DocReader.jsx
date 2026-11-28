import { useRef, useState } from 'react'
import { FileSearch, Loader2 } from 'lucide-react'
import { post, errText } from '../../lib/_api'

// «ارفع السجل التجاري أو الشهادة الضريبية أو العنوان الوطني» → the AI reads it and fills the customer (owner, 2026-11-22).
// Nothing is saved here: the fields come back filled for him to check.
const toB64 = (file) => new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.onerror = bad; r.readAsDataURL(file) })

export default function DocReader({ onFill, accountId = '', label = 'اقرأ بيانات العميل من السجل / الشهادة الضريبية / العنوان الوطني' }) {
  const inp = useRef(null)
  const [busy, setBusy] = useState(false); const [msg, setMsg] = useState('')
  const pick = async (picked) => {
    const list = Array.from(picked || []).slice(0, 3)
    if (!list.length) return
    setBusy(true); setMsg('')
    const merged = {}; const notes = []; const files = []
    for (const f of list) {
      if (f.size > 6 * 1024 * 1024) { notes.push(`${f.name}: أكبر من 6 ميجا`); continue }
      try {
        const r = await post('/ops', { op: 'doc_extract', mime: f.type || 'application/pdf', data: await toB64(f), name: f.name, accountId: accountId || '' })
        for (const [k, v] of Object.entries(r || {})) if (v && k !== 'file' && !merged[k]) merged[k] = v
        if (r?.file?.id) files.push(r.file)
        if (r.vatWarn) notes.push(r.vatWarn)
      } catch (e) { notes.push(`${f.name}: ${errText(e, 'ما اتقراش')}`) }
    }
    const got = ['name', 'crNo', 'vatNo', 'city', 'district', 'street', 'building', 'zip', 'addNo'].filter((k) => merged[k])
    if (got.length || files.length) onFill(merged, files)
    setMsg((got.length ? `✅ اتملى ${got.length} خانة — راجعها قبل الحفظ.` : '⚠️ ما لقيتش بيانات واضحة.') + (files.length ? ` · 📁 ${files.length} ملف اتحفظ في ملف العميل` : '') + (notes.length ? ' ' + notes.join(' · ') : ''))
    setBusy(false); if (inp.current) inp.current.value = ''
  }
  return (
    <div className="grid gap-1">
      <button type="button" disabled={busy} onClick={() => inp.current?.click()} className="rounded-xl border border-dashed border-violet-400/50 bg-violet-500/10 text-violet-200 px-3 py-2 text-xs font-bold flex items-center justify-center gap-1.5">
        {busy ? <Loader2 size={14} className="animate-spin" /> : <FileSearch size={14} />}{busy ? 'الذكاء الاصطناعي بيقرا الملف…' : '🤖 ' + label}
      </button>
      <input ref={inp} type="file" accept="image/*,application/pdf" multiple hidden onChange={(e) => pick(e.target.files)} />
      {msg && <p className="text-[11px] text-slate-300">{msg}</p>}
    </div>
  )
}
