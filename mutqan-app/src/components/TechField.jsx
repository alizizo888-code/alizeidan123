import { useEffect, useMemo, useRef, useState } from 'react'
import { Loader2, Camera, MapPin, Send, Phone, MessageCircle, Headphones, Check } from 'lucide-react'
import { post, errText } from '../lib/_api'
import { maps } from '../lib/maps'
import { storage } from '../lib/storage'
import { useCall, OutgoingCall } from './CustomerHotline'

// «الميدان» (owner order 2026-11-22) — the technician's own tools between jobs:
//  - call operations on the hotline, or a colleague (hotline / phone / WhatsApp);
//  - post to the system: a new job he found in the field (time, place, photos), a road warning, a map pin, an
//    instruction, or a reminder with a time — for himself, for operations, for one colleague or the whole team;
//  - read what was sent to him and mark it «تم». Reminders ring at their time (server push) and, while the app is
//    open, on this screen too. Operations see every post.
const KINDS = [
  ['job', '🆕 طلب جديد', 'جالك طلب وانت في الشغل — اكتب التفاصيل والموعد والمكان'],
  ['appt', '📅 موعد', 'موعد عندك الساعة كذا'],
  ['warn', '⚠️ تحذير', 'زحمة / حادث / مشكلة في منطقة'],
  ['pin', '📍 دبوس', 'علامة على الخريطة للفريق'],
  ['note', '📝 ملاحظة', 'تعليمات أو معلومة'],
  ['remind', '⏰ منبّه', 'منبه داخلي بوقت'],
  ['voice', '🎙️ صوتية', 'رسالة صوتية سريعة للفريق أو العمليات'],
]
const KL = Object.fromEntries(KINDS.map(([k, l]) => [k, l]))
const wa = (p) => { const d = String(p || '').replace(/\D/g, ''); return d.startsWith('966') ? d : d.startsWith('05') ? '966' + d.slice(1) : d }

export default function TechField({ myId, profiles }) {
  const call = useCall()
  const [posts, setPosts] = useState(null)
  const [kind, setKind] = useState('job')
  const [text, setText] = useState('')
  const [at, setAt] = useState('')
  const [to, setTo] = useState('ops')
  const [loc, setLoc] = useState(null)
  const [photos, setPhotos] = useState([])
  const [client, setClient] = useState({ name: '', phone: '' })
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const rung = useRef(new Set())
  const [audio, setAudio] = useState('')
  const [rec, setRec] = useState(null)
  const [radius, setRadius] = useState(5)
  async function record() {
    if (rec) { rec.stop(); return }
    try {
      const st = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mr = new MediaRecorder(st); const chunks = []
      mr.ondataavailable = (e) => e.data.size && chunks.push(e.data)
      mr.onstop = async () => {
        st.getTracks().forEach((t) => t.stop()); setRec(null); setBusy('au')
        try { const b = new Blob(chunks, { type: mr.mimeType || 'audio/webm' }); const ext = (mr.mimeType || '').includes('mp4') ? 'm4a' : 'webm'; const { url } = await storage.upload(new File([b], `voice-${Date.now()}.${ext}`, { type: b.type }), `voice-${Date.now()}.${ext}`); setAudio(url) } catch (x) { setMsg(errText(x, 'تعذّر رفع التسجيل')) } finally { setBusy('') }
      }
      mr.start(); setRec(mr)
      setTimeout(() => { if (mr.state === 'recording') mr.stop() }, 120000)
    } catch { setMsg('اسمح للتطبيق باستخدام المايك') }
  }
  const mates = useMemo(() => (profiles || []).filter((t) => t.userId && t.userId !== myId && !t.isSite && t.active !== false), [profiles, myId])

  const load = () => post('/field', { op: 'posts' }).then((r) => setPosts(r?.posts || [])).catch(() => setPosts((x) => x || []))
  useEffect(() => { load(); const t = setInterval(() => { if (document.visibilityState !== 'hidden') load() }, 20000); return () => clearInterval(t) }, [])
  // while the screen is open, a due reminder of mine rings here too
  useEffect(() => {
    const t = setInterval(() => {
      for (const p of posts || []) {
        if (!p.at || rung.current.has(p.id) || p.byId !== myId || p.status === 'done') continue
        const d = Date.parse(p.at); if (d <= Date.now() && Date.now() - d < 10 * 60000) {
          rung.current.add(p.id)
          try { navigator.vibrate?.([300, 150, 300]) } catch { /* no vibration */ }
          try { if ('Notification' in window && Notification.permission === 'granted') new Notification('⏰ ' + (KL[p.kind] || 'تذكير'), { body: p.text }) } catch { /* ignore */ }
          setMsg('⏰ ' + p.text)
        }
      }
    }, 15000)
    return () => clearInterval(t)
  }, [posts, myId])

  async function here() {
    setBusy('loc')
    try { const p = await maps.getCurrentLocation(); setLoc({ lat: p.lat, lng: p.lng }) } catch { setMsg('فعّل صلاحية الموقع') } finally { setBusy('') }
  }
  async function addPhotos(e) {
    const files = [...(e.target.files || [])].slice(0, 6 - photos.length); e.target.value = ''
    if (!files.length) return
    setBusy('ph')
    try { for (const f of files) { const { url } = await storage.upload(f, `field-${Date.now()}-${f.name || 'photo.jpg'}`); setPhotos((x) => [...x, url]) } } catch (x) { setMsg(errText(x, 'تعذّر رفع الصورة')) } finally { setBusy('') }
  }
  async function send() {
    if (kind === 'voice' && !audio) { setMsg('سجّل الرسالة الأول'); return }
    if (!text.trim() && !['pin', 'voice'].includes(kind)) { setMsg('اكتب التفاصيل'); return }
    if (kind === 'remind' && !at) { setMsg('حدد وقت المنبه'); return }
    setBusy('send'); setMsg('')
    try {
      await post('/field', { op: 'post', kind, text: text.trim() || KL[kind], to: kind === 'remind' && to === 'ops' ? 'self' : to, at: at ? new Date(at).toISOString() : '', lat: loc?.lat, lng: loc?.lng, photos, audio, radiusKm: kind === 'warn' ? radius : 0, clientName: client.name, clientPhone: client.phone })
      setText(''); setAt(''); setPhotos([]); setLoc(null); setAudio(''); setClient({ name: '', phone: '' })
      setMsg('اتبعت ✓'); load()
    } catch (e) { setMsg(errText(e, 'تعذّر الإرسال')) } finally { setBusy('') }
  }
  async function ack(p, close) { try { await post('/field', { op: 'ack', id: p.id, close }); load() } catch (e) { setMsg(errText(e, 'تعذّر')) } }

  return (
    <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start">
      <OutgoingCall hook={call} />
      <div className="grid gap-3">
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
          <p className="text-xs font-black text-white">📞 تواصل داخلي</p>
          <button onClick={() => call.start()} disabled={!call.enabled} className="rounded-xl bg-sky-500/20 border border-sky-400/30 text-sky-100 text-xs font-black py-2.5 flex items-center justify-center gap-1.5 disabled:opacity-40"><Headphones size={15} /> الخط الساخن — العمليات</button>
          <div className="grid gap-1.5 max-h-64 overflow-y-auto">
            {mates.map((t) => (
              <div key={t.userId} className="flex items-center gap-2 rounded-xl bg-black/20 px-2.5 py-1.5">
                <span className={`w-2 h-2 rounded-full ${t.active !== false ? 'bg-emerald-400' : 'bg-slate-500'}`} />
                <span className="flex-1 min-w-0 truncate text-xs font-bold text-white">{t.displayName || 'فني'}</span>
                <button onClick={() => call.start({ to: 'tech', toUser: t.userId, name: t.displayName })} disabled={!call.enabled} className="w-8 h-8 rounded-lg bg-violet-500/30 text-violet-100 flex items-center justify-center disabled:opacity-30" aria-label="مكالمة داخلية"><Headphones size={14} /></button>
                {t.phone && <a href={`tel:${t.phone}`} className="w-8 h-8 rounded-lg bg-emerald-600/70 text-white flex items-center justify-center" aria-label="اتصال"><Phone size={14} /></a>}
                {t.phone && <a href={`https://wa.me/${wa(t.phone)}`} target="_blank" rel="noreferrer" className="w-8 h-8 rounded-lg bg-green-500/70 text-white flex items-center justify-center" aria-label="واتساب"><MessageCircle size={14} /></a>}
              </div>
            ))}
            {!mates.length && <p className="text-[11px] text-slate-500">مفيش زملاء مسجلين.</p>}
          </div>
        </section>

        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
          <p className="text-xs font-black text-white">📣 ابعت للنظام</p>
          <div className="grid grid-cols-3 gap-1.5">
            {KINDS.map(([k, l]) => <button key={k} onClick={() => setKind(k)} className={`rounded-lg py-2 text-[11px] font-black border ${kind === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{l}</button>)}
          </div>
          <p className="text-[10px] text-slate-500">{KINDS.find((x) => x[0] === kind)?.[2]}</p>
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={3} placeholder="التفاصيل…" className="rounded-xl bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
          {kind === 'job' && (
            <div className="grid grid-cols-2 gap-1.5">
              <input value={client.name} onChange={(e) => setClient((c) => ({ ...c, name: e.target.value }))} placeholder="اسم العميل" className="rounded-lg bg-white/10 border border-white/15 px-2.5 py-2 text-xs text-white" />
              <input value={client.phone} onChange={(e) => setClient((c) => ({ ...c, phone: e.target.value }))} placeholder="جواله" inputMode="tel" dir="ltr" className="rounded-lg bg-white/10 border border-white/15 px-2.5 py-2 text-xs text-white" />
            </div>
          )}
          {kind === 'voice' && (
            <div className="flex items-center gap-2">
              <button onClick={record} className={`rounded-xl px-4 py-2.5 text-xs font-black ${rec ? 'bg-rose-500 text-white animate-pulse' : 'bg-white/10 text-white'}`}>{rec ? '⏹ إيقاف' : busy === 'au' ? 'جارِ الرفع…' : '🎙️ ابدأ التسجيل'}</button>
              {audio && <audio src={audio} controls className="h-9 flex-1" />}
            </div>
          )}
          {kind === 'warn' && (
            <label className="text-[11px] font-bold text-slate-400">يوصل للفنيين في دايرة {radius} كم من الموقع (لما تختار «كل الفريق» وترفق موقعك)
              <input type="range" min="1" max="20" value={radius} onChange={(e) => setRadius(Number(e.target.value))} className="w-full accent-amber-400" />
            </label>
          )}
          {['job', 'appt', 'remind', 'note'].includes(kind) && (
            <label className="text-[11px] font-bold text-slate-400">{kind === 'remind' ? 'وقت المنبه' : 'الموعد (اختياري)'}<input type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-2.5 py-2 text-xs text-white" /></label>
          )}
          <div className="flex flex-wrap gap-1.5">
            <button onClick={here} className={`rounded-lg px-3 py-2 text-[11px] font-black flex items-center gap-1 ${loc ? 'bg-emerald-500 text-black' : 'bg-white/10 text-white'}`}>{busy === 'loc' ? <Loader2 size={12} className="animate-spin" /> : <MapPin size={12} />}{loc ? 'الموقع اتحدد ✓' : 'أرفق موقعي'}</button>
            <label className="rounded-lg px-3 py-2 text-[11px] font-black flex items-center gap-1 bg-white/10 text-white cursor-pointer">{busy === 'ph' ? <Loader2 size={12} className="animate-spin" /> : <Camera size={12} />}صور ({photos.length})<input type="file" accept="image/*" capture="environment" multiple className="hidden" onChange={addPhotos} /></label>
          </div>
          {photos.length > 0 && <div className="flex gap-1.5 overflow-x-auto">{photos.map((u) => <img key={u} src={u} alt="" className="w-14 h-14 rounded-lg object-cover" />)}</div>}
          <label className="text-[11px] font-bold text-slate-400">لمين؟
            <select value={to} onChange={(e) => setTo(e.target.value)} className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-2.5 py-2 text-xs text-white">
              <option value="ops" className="bg-slate-900">{kind === 'remind' ? 'لنفسي' : 'العمليات'}</option>
              <option value="self" className="bg-slate-900">لنفسي بس</option>
              <option value="all" className="bg-slate-900">كل الفريق</option>
              {mates.map((t) => <option key={t.userId} value={`u:${t.userId}`} className="bg-slate-900">{t.displayName}</option>)}
            </select>
          </label>
          {msg && <p className="text-xs font-black text-amber-200">{msg}</p>}
          <button onClick={send} disabled={busy === 'send'} className="rounded-xl bg-primary text-black font-black py-2.5 text-sm flex items-center justify-center gap-1">{busy === 'send' ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}إرسال</button>
        </section>
      </div>

      <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
        <p className="text-xs font-black text-white">📬 الرسايل والمنبهات</p>
        {posts == null && <p className="text-[11px] text-slate-500">جارِ التحميل…</p>}
        {posts && !posts.length && <p className="text-[11px] text-slate-500">مفيش حاجة لسه.</p>}
        {(posts || []).map((p) => (
          <div key={p.id} className={`rounded-xl p-2.5 border ${p.kind === 'warn' ? 'border-amber-400/40 bg-amber-500/10' : p.status === 'done' ? 'border-white/5 bg-white/[0.02] opacity-60' : 'border-white/10 bg-black/20'}`}>
            <div className="flex items-center gap-2 text-[11px]">
              <b className="text-white">{KL[p.kind] || p.kind}</b><span className="text-slate-400 flex-1 truncate">— {p.byId === myId ? 'أنت' : p.byName}{p.to === 'all' ? ' · للكل' : p.to === 'self' ? ' · لنفسك' : ''}</span>
              <span className="text-slate-500">{new Date(p.createdAt).toLocaleTimeString('ar-SA', { hour: 'numeric', minute: '2-digit' })}</span>
            </div>
            <p className="text-xs text-slate-200 mt-1 whitespace-pre-wrap">{p.text}</p>
            {p.audio && <audio src={p.audio} controls preload="none" className="w-full h-9 mt-1" />}
            {p.at && <p className="text-[11px] text-sky-300 mt-1">🕒 {new Date(p.at).toLocaleString('ar-SA', { weekday: 'short', hour: 'numeric', minute: '2-digit' })}</p>}
            {p.clientName && <p className="text-[11px] text-slate-300 mt-0.5">👤 {p.clientName} {p.clientPhone && <a href={`tel:${p.clientPhone}`} className="text-emerald-300" dir="ltr">{p.clientPhone}</a>}</p>}
            {p.lat != null && <a href={`https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}`} target="_blank" rel="noreferrer" className="text-[11px] text-emerald-300 mt-0.5 inline-block">📍 افتح الموقع</a>}
            {p.photos?.length > 0 && <div className="flex gap-1.5 mt-1.5 overflow-x-auto">{p.photos.map((u) => <a key={u} href={u} target="_blank" rel="noreferrer"><img src={u} alt="" className="w-16 h-16 rounded-lg object-cover" /></a>)}</div>}
            {p.status !== 'done' && (
              <div className="flex gap-1.5 mt-2">
                {!(p.acks || {})[myId] && p.byId !== myId && <button onClick={() => ack(p, false)} className="rounded-lg bg-white/10 text-white text-[11px] font-black px-2.5 py-1 flex items-center gap-1"><Check size={11} />شفته</button>}
                {p.byId === myId && <button onClick={() => ack(p, true)} className="rounded-lg bg-emerald-500/30 text-emerald-100 text-[11px] font-black px-2.5 py-1">تم ✓</button>}
                {Object.keys(p.acks || {}).length > 0 && <span className="text-[10px] text-slate-500 self-center">شافه {Object.values(p.acks).map((a) => a.name).join('، ')}</span>}
              </div>
            )}
          </div>
        ))}
      </section>
    </div>
  )
}
