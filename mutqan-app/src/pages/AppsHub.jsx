import { useCallback, useEffect, useState } from 'react'
import NativeSetup from '../components/NativeSetup'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Smartphone, Send, Copy, Check, Ban, RefreshCw, Download, ExternalLink, Loader2, UserPlus } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { post, errText } from '../lib/_api'

// «التطبيقات والأجهزة»: the owner's one place for the apps. Every app with its download and address, a one-device
// activation link for a technician or operations (sent on WhatsApp), and every device that activated — revocable.
const STATUS = {
  pending: ['بانتظار التفعيل', 'text-amber-200 border-amber-400/40 bg-amber-400/10'],
  active: ['مفعّل', 'text-emerald-200 border-emerald-400/40 bg-emerald-400/10'],
  revoked: ['ملغي', 'text-rose-200 border-rose-400/40 bg-rose-400/10'],
  expired: ['انتهى', 'text-white/60 border-white/15 bg-white/5'],
}
const ROLE_OF = { tech: [['technician', 'فني']], ops: [['operations', 'عمليات'], ['supervisor', 'مشرف']] }
const when = (iso) => { try { return iso ? new Date(iso).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' }) : '' } catch { return '' } }

export default function AppsHub() {
  const nav = useNavigate()
  const { isOwner, loading } = useTeamRole()
  const [apps, setApps] = useState([])
  const [links, setLinks] = useState(null)
  const [app, setApp] = useState('tech')
  const [role, setRole] = useState('technician')
  const [members, setMembers] = useState([])
  const [who, setWho] = useState('')            // userId, or '' = new person
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [made, setMade] = useState(null)
  const [copied, setCopied] = useState('')

  const loadLinks = useCallback(async () => { try { setLinks((await post('/devices', { op: 'list' })).links || []) } catch (e) { setErr(errText(e, 'تعذر التحميل')) } }, [])
  useEffect(() => {
    if (!isOwner) return
    post('/devices', { op: 'catalog' }).then((r) => setApps(r.apps || [])).catch(() => {})
    loadLinks()
  }, [isOwner, loadLinks])
  useEffect(() => {
    if (!isOwner) return
    const r = ROLE_OF[app][0][0]; setRole(r); setWho('')
  }, [app, isOwner])
  useEffect(() => {
    if (!isOwner) return
    post('/devices', { op: 'members', role }).then((r) => setMembers(r.members || [])).catch(() => setMembers([]))
  }, [role, isOwner])

  const create = async () => {
    setBusy(true); setErr(''); setMade(null)
    try {
      const r = await post('/devices', who ? { op: 'create', app, userId: who } : { op: 'create', app, role, name: name.trim(), phone: phone.trim() })
      setMade(r); setName(''); setPhone(''); loadLinks()
    } catch (e) { setErr(errText(e, 'ما اتعملش الرابط')) }
    setBusy(false)
  }
  const revoke = async (id) => {
    if (!window.confirm('إلغاء الجهاز ده؟ الشخص هيخرج من التطبيق فوراً ومحتاج رابط جديد.')) return
    try { await post('/devices', { op: 'revoke', id }); loadLinks() } catch (e) { setErr(errText(e, 'ما اتلغاش')) }
  }
  const copy = async (k, t) => { try { await navigator.clipboard.writeText(t); setCopied(k); setTimeout(() => setCopied(''), 1500) } catch { /* ignore */ } }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!isOwner) return <p className="p-6 text-white text-sm font-bold" dir="rtl">الصفحة دي للمالك فقط.</p>

  const ready = who || name.trim().length >= 2
  const field = 'w-full rounded-xl bg-white/5 border border-white/15 px-3 py-2.5 text-[14px] text-white placeholder:text-white/40'
  return (
    <div className="h-full overflow-y-auto text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">التطبيقات والأجهزة</h1><p className="text-[11px] text-white/60 truncate">ابعت رابط تفعيل لجهاز واحد، وتابع كل جهاز</p></div>
      </header>

      <main className="max-w-3xl mx-auto p-4 grid gap-5 pb-24">
        <NativeSetup />
        <section aria-labelledby="apps-h" className="grid gap-2">
          <h2 id="apps-h" className="text-[13px] font-black text-white/80">التطبيقات</h2>
          <div className="grid sm:grid-cols-2 gap-2">
            {apps.map((a) => (
              <div key={a.app} className="rounded-2xl bg-white/5 border border-white/10 p-3 grid gap-2">
                <p className="font-black text-[14px] flex items-center gap-2"><Smartphone size={16} className="text-primary" />{a.label}</p>
                <div className="flex gap-2 text-[12px] font-bold">
                  <a href={a.apk} className="flex-1 rounded-lg bg-white/10 py-2 flex items-center justify-center gap-1"><Download size={13} />APK</a>
                  <a href={a.url} target="_blank" rel="noreferrer" className="flex-1 rounded-lg bg-white/10 py-2 flex items-center justify-center gap-1"><ExternalLink size={13} />فتح</a>
                  <button onClick={() => copy('a' + a.app, a.linkable ? a.url : a.apk)} className="rounded-lg bg-white/10 px-3 flex items-center" aria-label="نسخ الرابط">{copied === 'a' + a.app ? <Check size={13} /> : <Copy size={13} />}</button>
                </div>
              </div>
            ))}
          </div>
        </section>

        <section aria-labelledby="link-h" className="rounded-2xl bg-white/5 border border-primary/30 p-4 grid gap-3">
          <h2 id="link-h" className="font-black text-[15px] flex items-center gap-2"><Send size={16} className="text-primary" />رابط تفعيل لجهاز واحد</h2>
          <div role="tablist" className="grid grid-cols-2 gap-1 rounded-xl bg-white/5 p-1">
            {[['tech', 'تطبيق الفني'], ['ops', 'تطبيق العمليات']].map(([k, l]) => <button key={k} role="tab" aria-selected={app === k} onClick={() => { setApp(k); setMade(null) }} className={`rounded-lg py-2 text-[13px] font-black ${app === k ? 'bg-primary text-black' : 'text-white/70'}`}>{l}</button>)}
          </div>
          {ROLE_OF[app].length > 1 && (
            <div className="flex gap-2">{ROLE_OF[app].map(([r, l]) => <button key={r} onClick={() => setRole(r)} aria-pressed={role === r} className={`rounded-full px-3 py-1 text-[12px] font-bold border ${role === r ? 'bg-white text-black border-white' : 'border-white/20 text-white/80'}`}>{l}</button>)}</div>
          )}
          <label className="grid gap-1 text-[12px] text-white/70">الشخص
            <select value={who} onChange={(e) => setWho(e.target.value)} className={field}>
              <option value="">➕ شخص جديد</option>
              {members.map((m) => <option key={m.id} value={m.id}>{m.name}{m.phone ? ` — ${m.phone}` : ''}</option>)}
            </select>
          </label>
          {!who && (
            <div className="grid sm:grid-cols-2 gap-2">
              <input aria-label="الاسم" value={name} onChange={(e) => setName(e.target.value)} placeholder="الاسم" className={field} />
              <input aria-label="الجوال" dir="ltr" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="05xxxxxxxx" className={field} />
            </div>
          )}
          <button onClick={create} disabled={busy || !ready} className="rounded-xl bg-primary text-black font-black py-3 flex items-center justify-center gap-2 disabled:opacity-50">{busy ? <Loader2 size={16} className="animate-spin" /> : <UserPlus size={16} />}اعمل الرابط</button>
          {made && (
            <div className="rounded-xl bg-emerald-400/10 border border-emerald-400/30 p-3 grid gap-2 text-[13px]">
              <p className="font-black">جاهز لـ {made.link.name} — كود التفعيل: <span dir="ltr" className="tracking-widest text-emerald-200">{made.code}</span></p>
              <p dir="ltr" className="text-[11px] text-white/60 break-all">{made.url}</p>
              <div className="grid grid-cols-2 gap-2">
                <a href={made.whatsapp} target="_blank" rel="noreferrer" className="rounded-lg bg-[#25D366] text-black font-black py-2 text-center">ابعته واتساب</a>
                <button onClick={() => copy('m', made.message)} className="rounded-lg bg-white/10 font-bold py-2 flex items-center justify-center gap-1">{copied === 'm' ? <Check size={14} /> : <Copy size={14} />}نسخ الرسالة</button>
              </div>
              <p className="text-[11px] text-white/60">الرابط صالح 3 أيام ولجهاز واحد. أول جهاز يفعّله هو اللي يشتغل، وأي جهاز تاني بيترفض.</p>
            </div>
          )}
          {err && <p role="alert" className="text-rose-300 text-xs font-bold">{err}</p>}
        </section>

        <section aria-labelledby="dev-h" className="grid gap-2">
          <div className="flex items-center"><h2 id="dev-h" className="flex-1 text-[13px] font-black text-white/80">الروابط والأجهزة</h2><button onClick={loadLinks} aria-label="تحديث" className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center"><RefreshCw size={14} /></button></div>
          {links === null && <p className="text-[12px] text-white/50">جارِ التحميل…</p>}
          {links && !links.length && <p className="text-[12px] text-white/50">لسه مفيش روابط.</p>}
          {(links || []).map((l) => {
            const [label, cls] = STATUS[l.status] || STATUS.expired
            return (
              <div key={l.id} className="rounded-xl bg-white/5 border border-white/10 p-3 flex items-start gap-3">
                <div className="flex-1 min-w-0 text-[13px] leading-relaxed">
                  <p className="font-black truncate">{l.name} <span className="text-white/50 font-bold">· {l.role} · {l.appLabel}</span></p>
                  {l.status === 'active' && <p className="text-white/70 text-[12px]">📱 {l.device} — من {when(l.activatedAt)}</p>}
                  {l.status === 'pending' && <p className="text-white/70 text-[12px]">الكود <span dir="ltr" className="tracking-widest">{l.code}</span> — ينتهي {when(l.expiresAt)}</p>}
                </div>
                <span className={`shrink-0 text-[11px] font-bold rounded-full px-2 py-0.5 border ${cls}`}>{label}</span>
                {(l.status === 'active' || l.status === 'pending') && <button onClick={() => revoke(l.id)} aria-label="إلغاء" title="إلغاء" className="shrink-0 w-8 h-8 rounded-lg bg-rose-500/15 text-rose-200 flex items-center justify-center"><Ban size={14} /></button>}
              </div>
            )
          })}
        </section>
      </main>
    </div>
  )
}
