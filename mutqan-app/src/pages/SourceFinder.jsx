// «كاشف المصادر» (owner order 2026-11-19): paste a code (#oxygen/a26 · أكسجين /A26), a whole WhatsApp message,
// or words from a title → the original article: which site, WordPress or Blogger, its title and link.
// Also a search over every site of the network (WordPress + Blogger) for articles and their codes.
import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, Search, ExternalLink, Copy, Loader2, Globe, BookOpen } from 'lucide-react'
import { source } from '../lib/ownerApi'
import { useLiveShared } from '../lib/useLive'
import { useTeamRole } from '../hooks/useTeamRole'
import AccessDenied from '../components/AccessDenied'

const WHERE = [['', 'الكل'], ['wordpress', 'ووردبريس'], ['blogger', 'بلوجر']]

export default function SourceFinder() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const { role, isOwner, loading } = useTeamRole()
  const [q, setQ] = useState(params.get('q') || '')
  const [where, setWhere] = useState('')
  const [res, setRes] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [copied, setCopied] = useState('')
  // Search by the customer's phone (owner 2026-11-28): any form — 05…, 5…, 9665…, +966 5…, Arabic digits.
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 1000 })
  const [phoneHits, setPhoneHits] = useState(null)

  async function run(text = q, w = where) {
    const t = String(text || '').trim()
    if (!t) return
    const tail = phoneTail(t)
    if (tail) {
      const hits = (orders || []).filter((o) => [o.clientPhone, o.phone, o.waPhone].some((p) => phoneTail(p) === tail))
      setPhoneHits({ tail, hits })
      const refs = [...new Set(hits.flatMap((o) => [o.source, o.utm, o.referrer].map((x) => (typeof x === 'string' ? x : x && (x.code || x.url || x.campaign)) || '').filter(Boolean)))]
      setParams({ q: t }, { replace: true }); setErr('')
      if (!refs.length) { setRes(null); return }
      text = refs.join(' ')
    } else setPhoneHits(null)
    setBusy(true); setErr(''); setParams({ q: t }, { replace: true })
    try { setRes(await source.search(String(text).trim(), { where: w })) } catch (e) { setErr(e?.message || 'تعذر البحث'); setRes(null) }
    setBusy(false)
  }
  useEffect(() => { if (params.get('q')) run(params.get('q')) }, [])   // opened from a code chip

  const copy = (s) => { try { navigator.clipboard.writeText(s); setCopied(s); setTimeout(() => setCopied(''), 1500) } catch { /* ignore */ } }

  if (loading) return <div className="h-full flex items-center justify-center text-slate-500 text-sm" dir="rtl">جارِ التحقق…</div>
  if (!(isOwner || ['owner', 'operations', 'supervisor'].includes(role))) return <AccessDenied need="المالك وفريق العمليات" />

  const items = res?.items || []
  return (
    <div className="h-full overflow-y-auto overscroll-contain bg-[#090c14] text-white p-3 pb-24" dir="rtl">
      <div className="flex items-center gap-2 mb-3">
        <button onClick={() => navigate(-1)} className="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <h1 className="flex-1 font-black">🧭 كاشف المصادر والمقالات</h1>
      </div>
      <p className="text-[12px] text-slate-400 mb-2 leading-6">الصق الكود اللي في رسالة العميل (زي <span dir="ltr" className="font-mono text-cyan-300">#oxygen/a26</span> أو «أكسجين /A26»)، أو الرسالة كلها، أو كلمات من عنوان المقالة — يطلعلك المقالة الأصلية وموقعها وهل هي ووردبريس ولا بلوجر.</p>
      <form onSubmit={(e) => { e.preventDefault(); run() }} className="grid gap-2">
        <textarea rows={3} value={q} onChange={(e) => setQ(e.target.value)} placeholder="#oxygen/a26 — أو الصق رسالة الواتساب — أو رقم جوال العميل 05xxxxxxxx — أو اكتب: تكييف مركزي مكة"
          className="w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2 text-sm outline-none focus:border-cyan-400" />
        <div className="flex gap-2 items-center">
          {WHERE.map(([id, label]) => <button type="button" key={id} onClick={() => { setWhere(id); if (q.trim()) run(q, id) }} className={`rounded-full px-3 py-1 text-xs font-bold border ${where === id ? 'bg-cyan-600 border-cyan-500' : 'border-white/15 text-slate-300'}`}>{label}</button>)}
          <button type="submit" disabled={busy || !q.trim()} className="mr-auto flex items-center gap-1 bg-primary text-black font-black rounded-xl px-4 py-2 text-sm disabled:opacity-50">{busy ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />}ابحث</button>
        </div>
      </form>
      {err && <p className="mt-3 text-sm text-rose-300">{err}</p>}
      {phoneHits && (
        <div className="mt-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-3">
          <p className="text-xs font-black text-emerald-200">📱 بحث برقم العميل ({phoneHits.hits.length ? `${phoneHits.hits.length} طلب` : 'مفيش طلبات بالرقم ده'})</p>
          {phoneHits.hits.slice(0, 30).map((o) => (
            <button key={o.id} onClick={() => navigate(`/staff/order/${o.id}`)} className="w-full text-right flex items-center gap-2 border-t border-white/10 py-2 mt-1 text-xs">
              <span className="font-bold flex-1">{o.clientName || 'عميل'} — {o.serviceName || o.specialtyLabel || o.specialtyId || 'طلب'}</span>
              <span className="text-slate-400">{String(o.createdAt || '').slice(0, 10)}</span>
              <span className="text-cyan-300">{(typeof o.source === 'string' && o.source) || o.utm?.campaign || o.referrer || 'بدون مصدر'}</span>
            </button>
          ))}
          {phoneHits.hits.length > 0 && !res && <p className="text-[11px] text-slate-400 mt-1">الطلبات دي مفيهاش كود مقالة مسجّل، فمش هيظهر مصدر مقالة.</p>}
        </div>
      )}
      {res && (
        <div className="mt-4 grid gap-2">
          <p className="text-[11px] text-slate-500">{items.length ? `${items.length} نتيجة` : 'مفيش نتايج'}{res.codes?.length ? ` · الكود: ${res.codes.join('، ')}` : ''}{res.words ? ` · كلمات: «${res.words}»` : ''}</p>
          {items.map((it) => (
            <div key={it.code + it.url} className={`rounded-2xl border p-3 ${it.found === false ? 'border-rose-500/30 bg-rose-500/5' : 'border-white/10 bg-white/[0.04]'}`}>
              <div className="flex items-center gap-2 mb-1">
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-black ${it.platform === 'blogger' ? 'bg-orange-500/20 text-orange-200' : 'bg-sky-500/20 text-sky-200'}`}>{it.platform === 'blogger' ? <><BookOpen size={10} className="inline" /> بلوجر</> : <><Globe size={10} className="inline" /> ووردبريس</>}</span>
                <span className="text-xs font-black">{it.site}</span>
                <span className="text-[10px] text-slate-500" dir="ltr">{it.domain}</span>
                {it.match === 'code' && <span className="text-[10px] text-emerald-300 font-bold">مطابق للكود</span>}
              </div>
              {it.found === false
                ? <p className="text-sm text-rose-200">الكود ده مش لاقي مقالة منشورة (اتمسحت أو اتغيّرت أو الموقع مش بيرد).</p>
                : <p className="text-sm font-bold leading-6">{it.title || '(بدون عنوان)'}</p>}
              <div className="flex flex-wrap items-center gap-2 mt-2">
                <button onClick={() => copy(it.code)} className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-mono" dir="ltr"><Copy size={11} />{copied === it.code ? 'اتنسخ' : it.code}</button>
                {it.type && <span className="text-[10px] text-slate-400">{it.type === 'page' ? 'صفحة' : 'مقالة'}{it.date ? ` · ${String(it.date).slice(0, 10)}` : ''}</span>}
                {it.url && <a href={it.url} target="_blank" rel="noopener noreferrer" className="mr-auto inline-flex items-center gap-1 text-xs font-bold text-cyan-300"><ExternalLink size={13} />افتح المقالة</a>}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// Last 9 digits of a Saudi mobile in any written form; '' when the text is not just a phone number.
function phoneTail(v) {
  const d = String(v || '').replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (c) => String(c.charCodeAt(0) - 0x06f0))
  if (/[^\d\s+\-()]/.test(d)) return ''
  const n = d.replace(/\D/g, '').replace(/^00/, '')
  if (n.length < 9 || n.length > 13) return ''
  return n.slice(-9)
}
