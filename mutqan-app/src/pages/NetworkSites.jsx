import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Globe, Plus, RefreshCw, ExternalLink, Trash2, Loader2, CheckCircle2 } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { post, errText } from '../lib/_api'
import { registerBrands } from '../lib/netBrand'

// «مواقع الشبكة»: every site (the 22 and any linked later) feeding the ONE operations room, with the work each brings in.
// The owner links a new domain here; it then learns its name from the hub and files its orders into this same room.
const ago = (iso) => {
  const t = iso ? Date.parse(iso) : 0; if (!t) return '—'
  const m = Math.round((Date.now() - t) / 60000)
  return m < 60 ? `من ${m} د` : m < 1440 ? `من ${Math.round(m / 60)} س` : `من ${Math.round(m / 1440)} يوم`
}

export default function NetworkSites() {
  const nav = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const allowed = isOwner || role === 'operations' || role === 'supervisor'
  const [sites, setSites] = useState(null)
  const [q, setQ] = useState('')
  const [form, setForm] = useState({ name: '', host: '', color: '#0ea5e9' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [added, setAdded] = useState(null)

  const load = useCallback(async () => {
    try { const r = await post('/network', { op: 'list' }); setSites(r.sites || []); registerBrands(r.sites) } catch (e) { setErr(errText(e, 'تعذر التحميل')); setSites((s) => s || []) }
  }, [])
  useEffect(() => { if (allowed) load() }, [allowed, load])

  const add = async () => {
    setBusy(true); setErr(''); setAdded(null)
    try { const r = await post('/network', { op: 'add', ...form }); setAdded(r); setForm({ name: '', host: '', color: '#0ea5e9' }); load() } catch (e) { setErr(errText(e, 'ما اتربطش')) }
    setBusy(false)
  }
  const remove = async (s) => {
    if (!window.confirm(`فك ربط «${s.name}»؟ طلباته القديمة هتفضل في غرفة العمليات.`)) return
    try { await post('/network', { op: 'remove', id: s.id }); load() } catch (e) { setErr(errText(e, 'ما اتفكش')) }
  }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!allowed) return <p className="p-6 text-white text-sm font-bold" dir="rtl">الصفحة دي للمالك والعمليات.</p>

  const list = (sites || []).filter((s) => !q || (s.name + ' ' + s.host).includes(q))
  const tot = (sites || []).reduce((a, s) => ({ month: a.month + (s.month || 0), open: a.open + (s.open || 0), today: a.today + (s.today || 0) }), { month: 0, open: 0, today: 0 })
  const field = 'w-full rounded-xl bg-white/5 border border-white/15 px-3 py-2.5 text-[14px] text-white placeholder:text-white/40'
  return (
    <div className="h-full overflow-y-auto text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">مواقع الشبكة</h1><p className="text-[11px] text-white/60 truncate">{sites ? `${sites.length} موقع — غرفة عمليات واحدة` : '…'}</p></div>
        <button onClick={load} aria-label="تحديث" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><RefreshCw size={16} /></button>
      </header>

      <main className="max-w-3xl mx-auto p-4 grid gap-4 pb-24">
        <div className="grid grid-cols-3 gap-2 text-center">
          {[['طلبات النهارده', tot.today], ['مفتوحة دلوقتي', tot.open], ['آخر 30 يوم', tot.month]].map(([l, n]) => (
            <div key={l} className="rounded-2xl bg-white/5 border border-white/10 p-3"><p className="text-xl font-black">{n}</p><p className="text-[11px] text-white/60">{l}</p></div>
          ))}
        </div>

        <input aria-label="بحث" value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث باسم الموقع أو الدومين" className={field} />

        <ul className="grid gap-2">
          {sites === null && <li className="text-[12px] text-white/50">جارِ التحميل…</li>}
          {list.map((s) => (
            <li key={s.id} className="rounded-xl bg-white/5 border border-white/10 p-3 flex items-center gap-3">
              <span className="w-3 h-3 rounded-full shrink-0" style={{ background: s.color }} aria-hidden />
              <div className="flex-1 min-w-0">
                <p className="font-black text-[14px] truncate">{s.name}{s.core && <span className="text-[10px] text-white/50 font-bold mr-1">· أساسي</span>}{s.extra && <span className="text-[10px] text-sky-300 font-bold mr-1">· مربوط</span>}{s.unknown && <span className="text-[10px] text-amber-300 font-bold mr-1">· غير مسجّل</span>}</p>
                <p dir="ltr" className="text-[11px] text-white/50 truncate text-right">{s.host || '—'}</p>
              </div>
              <div className="text-center shrink-0 text-[11px] leading-tight"><p className="font-black text-[13px]">{s.open}</p><p className="text-white/50">مفتوح</p></div>
              <div className="text-center shrink-0 text-[11px] leading-tight w-14"><p className="font-black text-[13px]">{s.month}</p><p className="text-white/50">{ago(s.last)}</p></div>
              <button onClick={() => nav('/staff/operations?brand=' + encodeURIComponent(s.id))} className="shrink-0 text-[11px] font-bold rounded-lg bg-white/10 px-2 py-1.5">طلباته</button>
              {s.host && <a href={'https://' + s.host} target="_blank" rel="noreferrer" aria-label="فتح الموقع" className="shrink-0 w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center"><ExternalLink size={14} /></a>}
              {isOwner && s.extra && <button onClick={() => remove(s)} aria-label="فك الربط" className="shrink-0 w-8 h-8 rounded-lg bg-rose-500/15 text-rose-200 flex items-center justify-center"><Trash2 size={14} /></button>}
            </li>
          ))}
        </ul>

        {isOwner && (
          <section aria-labelledby="add-h" className="rounded-2xl bg-white/5 border border-primary/30 p-4 grid gap-3">
            <h2 id="add-h" className="font-black text-[15px] flex items-center gap-2"><Plus size={16} className="text-primary" />ربط موقع جديد بالشبكة</h2>
            <input aria-label="اسم الموقع" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="اسم الموقع (مثلاً: برودة الخليج)" className={field} />
            <div className="flex gap-2">
              <input aria-label="الدومين" dir="ltr" value={form.host} onChange={(e) => setForm({ ...form, host: e.target.value })} placeholder="example.com" className={field} />
              <input aria-label="اللون" type="color" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} className="w-12 h-11 rounded-xl bg-transparent border border-white/15 shrink-0" />
            </div>
            <button onClick={add} disabled={busy || form.name.trim().length < 2 || !form.host.trim()} className="rounded-xl bg-primary text-black font-black py-3 flex items-center justify-center gap-2 disabled:opacity-50">{busy ? <Loader2 size={16} className="animate-spin" /> : <Globe size={16} />}اربط الموقع</button>
            {added && (
              <div className="rounded-xl bg-emerald-400/10 border border-emerald-400/30 p-3 text-[13px] grid gap-1.5">
                <p className="font-black flex items-center gap-1.5"><CheckCircle2 size={15} />اتربط «{added.site.name}» ({added.site.host})</p>
                <ol className="list-decimal pr-5 text-white/80 leading-relaxed">{added.steps.map((x) => <li key={x}>{x}</li>)}</ol>
              </div>
            )}
          </section>
        )}
        {err && <p role="alert" className="text-rose-300 text-xs font-bold">{err}</p>}
      </main>
    </div>
  )
}
