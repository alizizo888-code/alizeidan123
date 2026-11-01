import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { PhoneIncoming, PhoneOutgoing, PhoneMissed, Plus, Link2, MessageCircle, Phone, X } from 'lucide-react'
import { db } from '../lib/db'
import { digits, fmtDur, logManualCall, needsFollowUp, mergeLinks, STATUS_LABEL, LINK_ICON, LINK_LABEL, KIND_LABEL } from '../lib/callbook'
import CallLinkSheet from './CallLinkSheet'

const inp = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-slate-500 outline-none focus:border-primary'
const STATUS_CLS = { answered: 'bg-emerald-500/15 text-emerald-300 border-emerald-400/30', missed: 'bg-rose-500/15 text-rose-300 border-rose-400/30', declined: 'bg-amber-500/15 text-amber-300 border-amber-400/30', ringing: 'bg-sky-500/15 text-sky-300 border-sky-400/30', active: 'bg-sky-500/15 text-sky-300 border-sky-400/30' }
const when = (iso) => { try { return new Date(iso).toLocaleString('ar-SA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return '' } }
const sameDay = (a, b) => new Date(a).toDateString() === new Date(b).toDateString()
const FILTERS = [['all', 'الكل'], ['pending', 'تحتاج متابعة'], ['unknown', 'غير محددة الهوية'], ['linked', 'مرتبطة']]

// Operations: the hotline call register. Every caller carries a CODE until he is registered or linked to a customer / order / WhatsApp chat / bot.
export default function CallsTab({ log = [], contacts = [] }) {
  const nav = useNavigate()
  const [sp] = useSearchParams()
  const [filter, setFilter] = useState('all')
  const [sheetId, setSheetId] = useState(null)
  const [manual, setManual] = useState(false)
  const byCode = useMemo(() => Object.fromEntries((contacts || []).map((c) => [c.code || c.id, c])), [contacts])
  const countBy = useMemo(() => { const m = {}; for (const l of log || []) m[l.code] = (m[l.code] || 0) + 1; return m }, [log])
  const linksOf = (l) => mergeLinks(l.links || [], byCode[l.code]?.links || [])
  const unknown = (l) => linksOf(l).length === 0 && (byCode[l.code]?.kind || 'guest') === 'guest'
  const pending = (l) => needsFollowUp(l)
  const rows = useMemo(() => (log || []).filter((l) => (filter === 'pending' ? pending(l) : filter === 'unknown' ? unknown(l) : filter === 'linked' ? linksOf(l).length > 0 : true)), [log, filter, byCode])
  const today = (log || []).filter((l) => sameDay(l.startedAt || l.createdAt, Date.now())).length
  const missed = (log || []).filter(pending).length
  const unk = new Set((log || []).filter(unknown).map((l) => l.code)).size

  useEffect(() => { const want = sp.get('call'); if (want && (log || []).some((l) => l.id === want)) setSheetId(want) }, [sp, (log || []).length])
  const sheetRow = sheetId ? (log || []).find((l) => l.id === sheetId) : null
  const done = (l) => db.updateShared('call_log', l.id, { followedUpAt: l.followedUpAt ? null : new Date().toISOString() }).catch(() => {})
  const open = (l) => {
    if (l.type === 'order') nav(`/staff/order/${l.ref}`)
    else if (l.type === 'bot') nav(`/staff/handoff/${l.ref}`)
    else if (l.type === 'wa') nav('/staff/wa-inbox')
  }

  return (
    <section className="grid gap-3">
      <div className="grid grid-cols-3 gap-2">
        {[['مكالمات اليوم', today, 'text-sky-300'], ['فائتة بلا متابعة', missed, missed ? 'text-rose-300' : 'text-emerald-300'], ['أكواد غير محددة', unk, unk ? 'text-amber-300' : 'text-emerald-300']].map(([k, v, c]) => (
          <div key={k} className="rounded-2xl bg-white/5 border border-white/10 p-3 text-center"><p className={`font-display text-2xl font-black ${c}`}>{v}</p><p className="text-[10px] text-slate-400 font-bold mt-0.5">{k}</p></div>
        ))}
      </div>
      <div className="flex items-center gap-1.5 overflow-x-auto">
        {FILTERS.map(([id, label]) => <button key={id} onClick={() => setFilter(id)} className={`shrink-0 rounded-xl px-3.5 py-2 text-xs font-black border ${filter === id ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{label}</button>)}
        <button onClick={() => setManual(true)} className="mr-auto shrink-0 flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-black bg-emerald-500 text-black"><Plus size={14} /> تسجيل مكالمة هاتفية</button>
      </div>
      {rows.length === 0 && <p className="rounded-2xl bg-white/5 border border-white/10 text-center text-slate-500 text-xs py-8">لا توجد مكالمات في هذا العرض. تُسجَّل مكالمات الخط الساخن هنا تلقائيًا، ويمكنك تسجيل المكالمات الهاتفية العادية يدويًا.</p>}
      <div className="grid gap-2">
        {rows.map((l) => {
          const ct = byCode[l.code]; const links = linksOf(l); const name = l.name || ct?.name; const phone = l.phone || ct?.phone; const d = digits(phone)
          const Icon = l.status === 'missed' ? PhoneMissed : l.direction === 'out' ? PhoneOutgoing : PhoneIncoming
          return (
            <div key={l.id} className="rounded-2xl bg-white/5 border border-white/10 p-3 grid gap-2 text-white">
              <div className="flex items-start gap-3">
                <span className={`w-9 h-9 rounded-full flex items-center justify-center shrink-0 ${l.status === 'missed' ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/15 text-emerald-300'}`}><Icon size={17} /></span>
                <div className="flex-1 min-w-0">
                  <p className="font-black text-sm flex items-center gap-2 flex-wrap"><span className="font-mono text-[11px] bg-sky-500/15 text-sky-300 rounded-md px-1.5 py-0.5">{l.code}</span><span className="truncate">{name || 'عميل غير مسجّل'}</span>{ct?.kind && ct.kind !== 'guest' && <span className="text-[10px] text-emerald-300 font-bold">● {KIND_LABEL[ct.kind]}</span>}</p>
                  <p className="text-[11px] text-slate-400 mt-0.5 flex flex-wrap gap-x-3"><span dir="ltr">{phone || 'بدون رقم'}</span><span>{when(l.startedAt || l.createdAt)}</span><span>{l.source === 'phone' ? '☎️ هاتف' : '🎧 الخط الساخن'}</span>{countBy[l.code] > 1 && <span>{countBy[l.code]} مكالمات</span>}</p>
                </div>
                <div className="text-left shrink-0"><span className={`inline-block rounded-full border text-[10px] font-black px-2 py-0.5 ${STATUS_CLS[l.status] || STATUS_CLS.answered}`}>{STATUS_LABEL[l.status] || l.status}</span><p className="text-[10px] text-slate-500 mt-1 font-mono">{fmtDur(l.durationSec)}</p></div>
              </div>
              {l.note && <p className="text-[11px] text-slate-300 bg-black/20 rounded-lg px-2.5 py-1.5 leading-relaxed">{l.note}</p>}
              {links.length > 0 && <div className="flex flex-wrap gap-1.5">{links.map((x) => <button key={x.type + x.ref} onClick={() => open(x)} className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 border border-emerald-400/25 text-emerald-200 text-[11px] font-bold px-2.5 py-1">{LINK_ICON[x.type]} {LINK_LABEL[x.type]}: {x.label}</button>)}</div>}
              <div className="flex flex-wrap items-center gap-1.5">
                <button onClick={() => setSheetId(l.id)} className="flex items-center gap-1.5 rounded-lg bg-primary text-black text-[11px] font-black px-3 py-1.5"><Link2 size={13} /> {links.length ? 'تعديل الربط' : 'ربط / تسجيل'}</button>
                {d.length >= 7 && <a href={`tel:+${d}`} className="flex items-center gap-1.5 rounded-lg bg-white/10 text-[11px] font-black px-3 py-1.5"><Phone size={13} /> اتصال</a>}
                {d.length >= 7 && <a href={`https://wa.me/${d}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 rounded-lg bg-white/10 text-[11px] font-black px-3 py-1.5"><MessageCircle size={13} /> واتساب</a>}
                {(l.status === 'missed' || l.status === 'declined') && <button onClick={() => done(l)} className={`mr-auto rounded-lg text-[11px] font-black px-3 py-1.5 ${l.followedUpAt ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-200'}`}>{l.followedUpAt ? '✔ تمت المعاودة' : 'تمت المعاودة؟'}</button>}
              </div>
            </div>
          )
        })}
      </div>
      {sheetRow && <CallLinkSheet key={sheetId} entry={{ ...sheetRow, callId: sheetRow.callId || sheetRow.id, keys: byCode[sheetRow.code]?.keys || [] }} contact={byCode[sheetRow.code]} onClose={() => setSheetId(null)} />}
      {manual && <ManualCall contacts={contacts} onClose={() => setManual(false)} onLogged={(id) => { setManual(false); setSheetId(id) }} />}
    </section>
  )
}

function ManualCall({ contacts, onClose, onLogged }) {
  const [f, setF] = useState({ phone: '', name: '', note: '', direction: 'in' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function save() {
    if (digits(f.phone).length < 7) return setErr('اكتب رقم المتصل')
    setBusy(true); setErr('')
    try { const r = await logManualCall({ ...f, contacts }); onLogged(r.entry.callId) } catch { setErr('تعذر الحفظ — أعد المحاولة.'); setBusy(false) }
  }
  return (
    <div className="fixed inset-0 z-[110] bg-black/70 backdrop-blur-sm flex items-end md:items-center justify-center p-0 md:p-4" onClick={onClose} dir="rtl">
      <div className="w-full max-w-md rounded-t-3xl md:rounded-3xl bg-[#0f1626] border border-white/10 text-white p-4 grid gap-3" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2"><p className="flex-1 font-black text-sm">تسجيل مكالمة هاتفية</p><button onClick={onClose} className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center"><X size={16} /></button></div>
        <div className="flex gap-1.5">{[['in', 'واردة'], ['out', 'صادرة']].map(([k, v]) => <button key={k} onClick={() => setF({ ...f, direction: k })} className={`flex-1 rounded-xl py-2 text-xs font-black border ${f.direction === k ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{v}</button>)}</div>
        <input className={inp} dir="ltr" inputMode="tel" placeholder="رقم المتصل" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
        <input className={inp} placeholder="الاسم (إن عُرف)" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        <textarea className={inp + ' resize-none'} rows={2} placeholder="ملاحظة" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} />
        {err && <p className="text-xs font-bold text-amber-300">{err}</p>}
        <button onClick={save} disabled={busy} className="rounded-xl bg-emerald-500 text-black font-black py-3 text-sm disabled:opacity-50">{busy ? 'جارِ الحفظ…' : 'حفظ وإعطاء كود'}</button>
        <p className="text-[11px] text-slate-500 leading-relaxed">يُعطى المتصل كودًا ثابتًا من رقمه، فإذا عاود الاتصال يُعرف تلقائيًا. بعد الحفظ تُفتح شاشة الربط بالعميل أو الطلب أو المحادثة.</p>
      </div>
    </div>
  )
}
