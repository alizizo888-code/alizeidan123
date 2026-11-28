import AccessDenied from '../components/AccessDenied'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Send, Plus, QrCode, KeyRound, RefreshCw, LogOut, Bot, MessageCircle, Settings2, Loader2, Search, ArrowRight, Copy, Trash2, CheckCheck, UserRound } from 'lucide-react'
import { useLiveShared } from '../lib/useLive'
import { db } from '../lib/db'
import { useTeamRole } from '../hooks/useTeamRole'
import { waApi, useWaSessions, DEFAULT_BOT, saveBot } from '../hooks/useWa'
import { auth } from '../lib/auth'
import { StickyNote, Zap, CheckCircle2, Tag, Clock, Siren, Wrench, ClipboardPlus, Phone, MapPin } from 'lucide-react'
import ManualOrderModal from '../components/ManualOrderModal'
import Ago from '../components/Ago'
import InvoiceModal from '../components/InvoiceModal'
import { post } from '../lib/_api'
import { STATUS_META } from '../constants'
import { orderNo } from '../lib/orderNo'

const STATUS = {
  open: ['متصل', 'bg-emerald-500/20 text-emerald-300'],
  qr: ['بانتظار مسح QR', 'bg-amber-500/20 text-amber-300'],
  code: ['بانتظار إدخال الكود', 'bg-amber-500/20 text-amber-300'],
  connecting: ['جارِ الاتصال…', 'bg-sky-500/20 text-sky-300'],
  closed: ['منقطع — يعيد المحاولة', 'bg-rose-500/20 text-rose-300'],
  logged_out: ['تم تسجيل الخروج', 'bg-rose-500/20 text-rose-300'],
  stopped: ['متوقف', 'bg-slate-500/20 text-slate-300'],
}
// Emergency detection (free, keyword based) so urgent chats float to the top.
const SOS_RE = /طوار[ئي]|عاجل|ضروري|حريق|شرار|ماس كهرب|ماس|تسريب|يقطر|غرق|فاض|انفجار|دخان|رائحة حرق|مولع|تماس/
// The real person: WhatsApp profile picture (copied to the site), else the first letter of the name in a colour of its own.
const COLORS = ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#a855f7', '#14b8a6', '#ec4899', '#6366f1']
function Avatar({ th, size = 40 }) {
  const [bad, setBad] = useState(false)
  const name = (th?.name || th?.phone || '?').trim()
  const c = COLORS[[...String(th?.phone || name)].reduce((a, ch) => a + ch.charCodeAt(0), 0) % COLORS.length]
  if (th?.avatar && !bad) return <img src={th.avatar} alt="" onError={() => setBad(true)} className="rounded-full object-cover shrink-0 bg-white/10" style={{ width: size, height: size }} />
  return <div className="rounded-full flex items-center justify-center shrink-0 text-white font-black" style={{ width: size, height: size, background: c, fontSize: size * 0.42 }}>{name.replace(/^\+?\d+$/, '#').slice(0, 1)}</div>
}
const KIND_LABEL = { image: '📷 صورة', video: '🎬 فيديو', voice: '🎤 رسالة صوتية', audio: '🎵 ملف صوتي', document: '📄 ملف', sticker: '🙂 ملصق', location: '📍 موقع', contact: '👤 جهة اتصال', other: 'رسالة' }
// What the customer actually sent: the picture itself, the voice note playable, the file downloadable.
function Media({ m }) {
  const src = m.mediaUrl || m.thumb
  if (!src) return null
  const mime = String(m.mime || '')
  if (m.mediaUrl && (mime.startsWith('audio/') || m.kind === 'voice' || m.kind === 'audio')) return <audio controls src={m.mediaUrl} className="max-w-full my-1" />
  if (m.mediaUrl && mime.startsWith('video/')) return <video controls src={m.mediaUrl} className="max-w-full rounded-xl my-1" />
  if (m.mediaUrl && mime === 'application/pdf') return <a href={m.mediaUrl} target="_blank" rel="noreferrer" className="underline block my-1">📄 فتح الملف</a>
  return <a href={m.mediaUrl || src} target="_blank" rel="noreferrer" className="block my-1"><img src={src} alt="" loading="lazy" className={`rounded-xl max-h-64 object-cover ${m.mediaUrl ? '' : 'blur-[1px] opacity-90'}`} /></a>
}

export const isSos = (t) => (t.labels || []).includes('طوارئ') || (t.lastDir === 'in' && t.status !== 'done' && SOS_RE.test(t.lastText || ''))
const isRouted = (t) => (t.labels || []).includes('محوّلة لفني')
const stat = (s) => STATUS[s] || STATUS.stopped
const inputCls = 'w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2.5 text-sm text-white placeholder:text-slate-500 outline-none focus:border-primary'
const hhmm = (iso) => { try { return new Date(iso).toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' }) } catch { return '' } }
const dayLabel = (iso) => { try { return new Date(iso).toLocaleDateString('ar-SA', { day: 'numeric', month: 'short' }) } catch { return '' } }

export default function WaInbox() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const [tab, setTab] = useState('inbox')
  const sess = useWaSessions(tab === 'numbers' ? 3000 : 15000)
  const { data: threads } = useLiveShared('wa_threads', { order: '-lastAt', limit: 300 })
  const unread = useMemo(() => (threads || []).reduce((a, t) => a + (t.unread || 0), 0), [threads])
  const allowed = isOwner || role === 'owner' || role === 'operations'
  if (loading) return <Centered>جارِ التحقق…</Centered>
  if (!allowed) return <AccessDenied refresh={refresh} />

  return (
    <div className="h-full flex flex-col bg-[#090c14]" dir="rtl">
      <header className="shrink-0 border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))] bg-[#090c14]/95">
        <div className="max-w-6xl mx-auto w-full px-4 py-3 flex items-center gap-3">
          <button onClick={() => navigate('/staff/operations')} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} className="text-slate-300" /></button>
          <div className="flex-1 min-w-0">
            <p className="text-[11px] text-emerald-400 font-bold tracking-widest">WHATSAPP HUB</p>
            <h1 className="font-display text-lg font-black text-white">صندوق الواتساب الموحّد</h1>
          </div>
        </div>
        <Kpis threads={threads || []} sessions={sess.sessions} ms={sess.ms} />
        <div className="max-w-6xl mx-auto w-full px-4 flex gap-1.5 pb-2">
          {[['inbox', 'المحادثات', MessageCircle, unread], ['numbers', 'الأرقام', QrCode, 0], ['central', 'السنترال', Siren, 0], ['bot', 'البوت', Bot, 0]].filter(([id]) => id !== 'central' || isOwner || role === 'operations').map(([id, label, Icon, n]) => (
            <button key={id} onClick={() => setTab(id)} className={`relative flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-black border ${tab === id ? 'bg-primary/20 border-primary text-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>
              <Icon size={14} /> {label}
              {n > 0 && <span className="min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] flex items-center justify-center px-1">{n}</span>}
            </button>
          ))}
        </div>
      </header>
      <div className="flex-1 min-h-0">
        {tab === 'inbox' && <Inbox sessions={sess.sessions} threads={threads || []} goNumbers={() => setTab('numbers')} />}
        {tab === 'numbers' && <Numbers sess={sess} isOwner={isOwner} />}
        {tab === 'bot' && <BotSettings />}
        {tab === 'central' && <Central isOwner={isOwner} />}
      </div>
    </div>
  )
}

function Kpis({ threads, sessions, ms }) {
  const k = useMemo(() => {
    const open = threads.filter((t) => t.status !== 'done')
    const waiting = open.filter((t) => t.lastDir === 'in')
    const mins = waiting.map((t) => Math.max(0, (Date.now() - new Date(t.lastAt).getTime()) / 60000))
    const avg = mins.length ? Math.round(mins.reduce((a, b) => a + b, 0) / mins.length) : 0
    const today = threads.filter((t) => new Date(t.lastAt).toDateString() === new Date().toDateString()).length
    return { open: open.length, sos: threads.filter(isSos).length, waiting: waiting.length, avg, today, on: sessions.filter((s) => s.status === 'open').length, all: sessions.length }
  }, [threads, sessions])
  const Box = ({ n, l, cls = 'text-white' }) => <div className="shrink-0 min-w-[84px] rounded-xl bg-white/5 border border-white/10 px-3 py-1.5 text-center"><p className={`text-base font-black leading-tight ${cls}`}>{n}</p><p className="text-[10px] text-slate-500">{l}</p></div>
  return (
    <div className="max-w-6xl mx-auto w-full px-4 pb-2 flex gap-2 overflow-x-auto">
      <Box n={`${k.on}/${k.all}`} l="أرقام متصلة" cls={k.on === k.all && k.all ? 'text-emerald-300' : 'text-amber-300'} />
      <Box n={ms ? `${ms}ms` : '—'} l="زمن استجابة الوسيط" cls={ms > 1500 ? 'text-amber-300' : 'text-white'} />
      <Box n={k.open} l="محادثات مفتوحة" />
      <Box n={k.waiting} l="بانتظار ردّنا" cls={k.waiting ? 'text-amber-300' : 'text-white'} />
      <Box n={k.sos} l="طوارئ SOS" cls={k.sos ? 'text-rose-400' : 'text-white'} />
      <Box n={k.avg ? `${k.avg}د` : '—'} l="متوسط الانتظار" />
      <Box n={k.today} l="نشطة اليوم" />
    </div>
  )
}

/* ------------------------------------------------------------------ inbox */
function Inbox({ sessions, threads, goNumbers }) {
  const navigate = useNavigate()
  const [acct, setAcct] = useState('all')
  const [q, setQ] = useState('')
  const [view, setView] = useState('all')   // all | unread | mine | open | done
  const [sel, setSel] = useState(null)
  const me = auth.getCurrentUser()?.id
  const [tick, setTick] = useState(0)
  useEffect(() => { const t = setInterval(() => setTick((n) => n + 1), 30000); return () => clearInterval(t) }, [])
  const label = (id) => sessions.find((s) => s.id === id)?.label || id
  const list = useMemo(() => threads.filter((t) => (acct === 'all' || t.accountId === acct)
    && (!q || `${t.name || ''} ${t.phone}`.includes(q))
    // Archived (idle, filed by the hourly round) only in «الأرشيف»; a complaint tag has its own view.
    && (view === 'archived' ? !!t.archived : !t.archived)
    && (view === 'all' || view === 'archived' || (view === 'complaint' && (t.tags || []).includes('complaint')) || (view === 'unread' && t.unread > 0) || (view === 'mine' && t.assignee?.id === me) || (view === 'open' && t.status !== 'done') || (view === 'done' && t.status === 'done') || (view === 'sos' && isSos(t)) || (view === 'routed' && isRouted(t)))).sort((a, b) => (isSos(b) - isSos(a))), [threads, acct, q, view, me])
  const counts = useMemo(() => ({ unread: threads.filter((t) => t.unread > 0).length, open: threads.filter((t) => t.status !== 'done').length }), [threads])
  const waitMin = (t) => (t.lastDir === 'in' && t.status !== 'done' ? Math.floor((Date.now() - new Date(t.lastAt).getTime()) / 60000) : 0)
  const th = threads.find((t) => t.id === sel)

  return (
    <div className="h-full min-h-0 max-w-7xl mx-auto w-full flex">
      <aside className={`${th ? 'hidden md:flex' : 'flex'} w-full md:w-[340px] shrink-0 flex-col border-l border-white/10 min-h-0`}>
        <div className="p-3 flex flex-col gap-2 shrink-0">
          <div className="relative">
            <Search size={14} className="absolute top-3 right-3 text-slate-500" />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث بالاسم أو الرقم" className={`${inputCls} pr-9`} />
          </div>
          <div className="flex gap-1.5 overflow-x-auto">
            {[['all', 'الكل'], ['unread', `غير مقروء (${counts.unread})`], ['mine', 'محادثاتي'], ['open', `مفتوحة (${counts.open})`], ['sos', `🚨 طوارئ (${threads.filter(isSos).length})`], ['routed', 'محوّلة لفني'], ['complaint', `⚠️ شكاوى (${threads.filter((t) => !t.archived && (t.tags || []).includes('complaint')).length})`], ['done', 'منتهية'], ['archived', 'الأرشيف']].map(([id, l]) => <Chip key={id} on={view === id} onClick={() => setView(id)}>{l}</Chip>)}
          </div>
          <div className="flex gap-1.5 overflow-x-auto pb-1">
            <Chip on={acct === 'all'} onClick={() => setAcct('all')}>كل الأرقام</Chip>
            {sessions.map((s) => <Chip key={s.id} on={acct === s.id} onClick={() => setAcct(s.id)}><span className={`inline-block w-1.5 h-1.5 rounded-full ml-1 ${s.status === 'open' ? 'bg-emerald-400' : 'bg-rose-400'}`} />{s.label}</Chip>)}
          </div>
        </div>
        <div className="flex-1 min-h-0 overflow-y-auto">
          {list.length === 0 ? (
            <div className="text-center text-slate-500 text-xs py-10 px-6">
              لا توجد محادثات بعد.
              {sessions.length === 0 && <button onClick={goNumbers} className="block mx-auto mt-3 text-primary font-bold">اربط أول رقم ←</button>}
            </div>
          ) : list.map((t) => (
            <button key={t.id} onClick={() => { setSel(t.id); const pu = Math.max(Number(t.bot?.pausedUntil) || 0, Math.floor(Date.now() / 1000) + 900); db.updateShared('wa_threads', t.id, { ...(t.unread ? { unread: 0 } : {}), bot: { ...(t.bot || {}), pausedUntil: pu } }).catch(() => {}) }} className={`w-full text-right px-4 py-3 border-b border-white/5 flex gap-3 items-center ${sel === t.id ? 'bg-white/[0.07]' : 'hover:bg-white/[0.03]'}`}>
              <Avatar th={t} />
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2"><p className="text-sm font-bold text-white truncate flex-1">{isSos(t) && <span className="text-rose-400 font-black text-[10px] bg-rose-500/15 rounded px-1 ml-1">SOS</span>}{t.name || t.phone}</p>{t.card?.site && t.card.brand && <span className="shrink-0 text-[9px] font-black rounded px-1.5 py-0.5 text-white" style={{ background: t.card.color || '#64748b' }}>{t.card.site}</span>}<span className="text-[10px] text-slate-500 shrink-0" title={hhmm(t.lastAt)}><Ago at={t.lastAt} /></span></div>
                <p className="text-[11px] text-slate-400 truncate">{t.lastDir === 'out' ? '↩ ' : ''}{t.lastText}</p>
                <p className="text-[10px] text-slate-600 mt-0.5 flex items-center gap-1.5 flex-wrap">{label(t.accountId)}{t.assignee?.name && <span className="text-sky-400">· {t.assignee.name}</span>}{(t.labels || []).slice(0, 2).map((l) => <span key={l} className="bg-white/10 text-slate-300 rounded px-1">{l}</span>)}{t.status === 'done' && <span className="text-emerald-400">· منتهية</span>}{waitMin(t) >= 10 && <span className={`flex items-center gap-0.5 font-bold ${waitMin(t) >= 30 ? 'text-rose-400' : 'text-amber-400'}`}><Clock size={9} />{waitMin(t) >= 60 ? `${Math.floor(waitMin(t) / 60)}س` : `${waitMin(t)}د`} بلا رد</span>}</p>
              </div>
              {t.unread > 0 && <span className="min-w-[20px] h-5 rounded-full bg-emerald-500 text-black text-[11px] font-black flex items-center justify-center px-1.5">{t.unread}</span>}
            </button>
          ))}
        </div>
      </aside>
      <section className={`${th ? 'flex' : 'hidden md:flex'} flex-1 min-w-0 flex-col min-h-0`}>
        {th ? <Conversation key={th.id} th={th} me={auth.getCurrentUser()} acctLabel={label(th.accountId)} online={sessions.find((s) => s.id === th.accountId)?.status === 'open'} onBack={() => setSel(null)} navigate={navigate} />
          : <div className="m-auto text-slate-600 text-sm">اختر محادثة</div>}
      </section>
    </div>
  )
}

function Conversation({ th, me, acctLabel, online, onBack, navigate }) {
  const { data: msgs } = useLiveShared('wa_msgs', { filters: { threadId: th.id }, order: 'at', limit: 300 })
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 300 })
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const { data: botRows } = useLiveShared('wa_bot', { limit: 20 })
  const quick = (botRows?.find((r) => r.id === 'cfg')?.quick) || DEFAULT_BOT.quick
  const [noteMode, setNoteMode] = useState(false)
  const [showQuick, setShowQuick] = useState(false)
  const LABELS = ['جديد', 'عميل حالي', 'مهم', 'شكوى', 'متابعة', 'فني']
  const patch = (p) => db.updateShared('wa_threads', th.id, p).catch(() => {})
  const toggleLabel = (l) => patch({ labels: (th.labels || []).includes(l) ? th.labels.filter((x) => x !== l) : [...(th.labels || []), l] })
  const fillQuick = (t) => { setText(t.text.replace('{name}', th.name || '')); setShowQuick(false); setNoteMode(false) }
  const end = useRef(null)
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [msgs?.length])
  const tail = th.phone.slice(-9)
  const mine = (orders || []).filter((o) => String(o.clientPhone || '').replace(/\D/g, '').endsWith(tail)).slice(0, 3)
  const paused = (th.bot?.pausedUntil || 0) * 1000 > Date.now()

  async function send() {
    const t = text.trim()
    if (!t || busy) return
    setBusy(true); setErr('')
    try {
      if (noteMode) {
        await db.insertShared('wa_msgs', { threadId: th.id, accountId: th.accountId, phone: th.phone, dir: 'note', text: t, kind: 'note', at: new Date().toISOString(), via: 'staff', by: me?.displayName || me?.name || '' }, undefined, { visibleTo: 'creator-and-admin', writableBy: 'admins' })
      } else {
        await waApi('send', { accountId: th.accountId, to: th.phone, text: t })
        if (th.status === 'done') patch({ status: 'open' })
      }
      setText('')
    }
    catch (e) { setErr(e?.message || 'تعذر الإرسال') }
    finally { setBusy(false) }
  }
  const toggleBot = () => db.updateShared('wa_threads', th.id, { bot: { ...(th.bot || {}), pausedUntil: paused ? 0 : Math.floor(Date.now() / 1000) + 12 * 3600 } }).catch(() => {})

  const [orderModal, setOrderModal] = useState(false)
  const lastIn = [...(msgs || [])].reverse().find((m) => m.dir === 'in' && m.text)?.text || ''
  async function afterOrder(order) {
    patch({ labels: [...new Set([...(th.labels || []), 'محوّلة لفني'])], status: 'open' })
    db.insertShared('wa_msgs', { threadId: th.id, accountId: th.accountId, phone: th.phone, dir: 'note', text: `تم إنشاء طلب #${orderNo(order)} وإرساله للتوزيع على الفنيين.`, kind: 'note', at: new Date().toISOString(), via: 'staff', by: me?.displayName || me?.name || '' }, undefined, { visibleTo: 'creator-and-admin', writableBy: 'admins' }).catch(() => {})
    if (online) waApi('send', { accountId: th.accountId, to: th.phone, text: `أهلًا ${th.name || ''} 👋\nتم استلام طلبك رقم #${orderNo(order)} وجارٍ إسناده لأقرب فني متاح، وسنوافيك بموعد الوصول قريبًا.` }).catch(() => {})
  }
  let lastDay = ''
  return (
    <div className="flex h-full min-h-0 w-full">
    <div className="flex-1 min-w-0 flex flex-col min-h-0">
      <div className="shrink-0 px-4 py-3 border-b border-white/10 flex items-center gap-3">
        <button onClick={onBack} className="md:hidden w-8 h-8 rounded-full bg-white/5 flex items-center justify-center"><ArrowRight size={16} className="text-slate-300" /></button>
        <Avatar th={th} size={36} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-black text-white truncate">{th.name || th.phone}</p>
          <p className="text-[10px] text-slate-500" dir="ltr">+{th.phone} · {acctLabel}{online ? '' : ' (غير متصل)'}</p>
          {th.card && (
            <p className="mt-1 flex flex-wrap items-center gap-1.5 text-[10.5px]" title="كارت التعريف: جاي منين وعلى أنهي خط">
              <span className="rounded px-1.5 py-0.5 font-black text-white" style={{ background: th.card.color || '#64748b' }}>🌐 {th.card.site}</span>
              {th.card.domain && <span className="text-slate-400" dir="ltr">{th.card.domain}</span>}
              <span className="text-slate-400">· خط: {th.card.line?.label || '—'}</span>
            </p>
          )}
        </div>
        <button onClick={toggleBot} className={`shrink-0 flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-[11px] font-bold border ${paused ? 'border-amber-400/40 text-amber-300 bg-amber-500/10' : 'border-emerald-400/30 text-emerald-300 bg-emerald-500/10'}`}><Bot size={13} /> {paused ? 'البوت متوقف' : 'البوت يعمل'}</button>
        <a href={`https://wa.me/${th.phone}`} target="_blank" rel="noopener" className="shrink-0 text-[11px] font-bold text-slate-400 hidden sm:block">فتح في واتساب</a>
      </div>
      <div className="shrink-0 px-4 py-2 border-b border-white/5 flex items-center gap-2 overflow-x-auto">
        {th.assignee?.id === me?.id
          ? <button onClick={() => patch({ assignee: null })} className="shrink-0 text-[11px] font-bold rounded-full px-3 py-1 bg-sky-500/20 border border-sky-400/40 text-sky-200">محادثتي ✓ (إلغاء)</button>
          : <button onClick={() => patch({ assignee: { id: me?.id, name: me?.displayName || me?.name || 'موظف' } })} className="shrink-0 text-[11px] font-bold rounded-full px-3 py-1 bg-white/5 border border-white/10 text-slate-200">{th.assignee?.name ? `عند: ${th.assignee.name} — استلام` : 'استلام المحادثة'}</button>}
        <button onClick={() => patch({ status: th.status === 'done' ? 'open' : 'done' })} className={`shrink-0 flex items-center gap-1 text-[11px] font-bold rounded-full px-3 py-1 border ${th.status === 'done' ? 'bg-emerald-500/20 border-emerald-400/40 text-emerald-200' : 'bg-white/5 border-white/10 text-slate-200'}`}><CheckCircle2 size={12} /> {th.status === 'done' ? 'منتهية — إعادة فتح' : 'تم الحل'}</button>
        <span className="shrink-0 text-slate-600"><Tag size={12} /></span>
        {LABELS.map((l) => <button key={l} onClick={() => toggleLabel(l)} className={`shrink-0 text-[11px] font-bold rounded-full px-2.5 py-1 border ${(th.labels || []).includes(l) ? 'bg-amber-500/20 border-amber-400/50 text-amber-200' : 'bg-white/5 border-white/10 text-slate-400'}`}>{l}</button>)}
      </div>
      {mine.length > 0 && (
        <div className="shrink-0 px-4 py-2 border-b border-white/5 flex gap-2 overflow-x-auto">
          {mine.map((o) => <button key={o.id} onClick={() => navigate(`/staff/order/${o.id}`)} className="shrink-0 text-[11px] font-bold bg-sky-500/10 border border-sky-400/30 text-sky-200 rounded-full px-3 py-1">طلب #{orderNo(o)}</button>)}
        </div>
      )}
      <div className="flex-1 overflow-y-auto px-4 py-3 flex flex-col gap-1.5">
        {(msgs || []).map((m) => {
          const d = dayLabel(m.at); const head = d !== lastDay; lastDay = d
          const out = m.dir === 'out'
          if (m.dir === 'note') return <div key={m.id} className="self-center max-w-[90%] bg-amber-400/15 border border-amber-400/30 text-amber-100 rounded-xl px-3 py-1.5 text-xs flex gap-1.5"><StickyNote size={12} className="mt-0.5 shrink-0" /><span className="whitespace-pre-wrap">{m.text}<span className="block text-[9px] opacity-60">ملاحظة داخلية · {m.by} · {hhmm(m.at)}</span></span></div>
          return (
            <div key={m.id} className="flex flex-col">
              {head && <p className="self-center text-[10px] text-slate-600 my-2">{d}</p>}
              <div className={`max-w-[82%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap break-words ${out ? 'self-start bg-emerald-600/80 text-white rounded-br-sm' : 'self-end bg-white/10 text-slate-100 rounded-bl-sm'}`}>
                <Media m={m} />
                {m.text ? m.text : (!m.mediaUrl && !m.thumb && <span className="opacity-60">{KIND_LABEL[m.kind] || '[' + m.kind + ']'}</span>)}
                <span className="block text-[9px] opacity-60 mt-0.5">{out && (m.via === 'bot' ? '🤖 ' : m.via === 'phone' ? '📱 ' : '')}{hhmm(m.at)} · <Ago at={m.at} />{out && <CheckCheck size={10} className="inline mr-1" />}</span>
              </div>
            </div>
          )
        })}
        <div ref={end} />
      </div>
      <div className="shrink-0 p-3 border-t border-white/10 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        {err && <p className="text-rose-400 text-xs font-bold mb-2">{err}</p>}
        {showQuick && <div className="flex gap-1.5 overflow-x-auto pb-2">{quick.map((q, i) => <button key={i} onClick={() => fillQuick(q)} className="shrink-0 text-[11px] font-bold rounded-full px-3 py-1.5 bg-emerald-500/10 border border-emerald-400/30 text-emerald-200">{q.title}</button>)}</div>}
        <div className="flex gap-1.5 mb-2">
          <button onClick={() => setShowQuick((v) => !v)} className={`flex items-center gap-1 text-[11px] font-bold rounded-lg px-2.5 py-1 border ${showQuick ? 'border-emerald-400/50 text-emerald-200 bg-emerald-500/10' : 'border-white/10 text-slate-400'}`}><Zap size={12} /> ردود سريعة</button>
          <button onClick={() => setNoteMode((v) => !v)} className={`flex items-center gap-1 text-[11px] font-bold rounded-lg px-2.5 py-1 border ${noteMode ? 'border-amber-400/50 text-amber-200 bg-amber-500/10' : 'border-white/10 text-slate-400'}`}><StickyNote size={12} /> ملاحظة داخلية</button>
        </div>
        <div className="flex gap-2 items-end">
          <textarea value={text} onChange={(e) => setText(e.target.value)} rows={1} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }} placeholder={noteMode ? 'ملاحظة للفريق فقط — لا تُرسل للعميل' : 'اكتب ردك…'} className={`${inputCls} resize-none max-h-32`} />
          <button onClick={send} disabled={busy || !text.trim()} className="shrink-0 w-11 h-11 rounded-xl bg-emerald-500 text-black flex items-center justify-center disabled:opacity-40">{busy ? <Loader2 size={17} className="animate-spin" /> : <Send size={17} />}</button>
        </div>
        <p className="text-[10px] text-slate-600 mt-1.5">{noteMode ? 'وضع الملاحظة الداخلية: لن يراها العميل.' : 'الرد من هنا يوقف البوت في هذه المحادثة 12 ساعة. الإرسال يتم بفاصل عشوائي قصير لحماية الرقم.'}</p>
      </div>
    </div>
    <CrmPanel th={th} orders={orders || []} navigate={navigate} sos={isSos(th)} online={online} onCreate={() => setOrderModal(true)} />
    {orderModal && <ManualOrderModal by="operations" initial={{ name: th.name || '', phone: th.phone.slice(-9).replace(/^/, '0'), description: lastIn.slice(0, 300), priority: isSos(th) ? 'urgent' : 'normal', waThreadId: th.id }} onClose={() => setOrderModal(false)} onCreated={afterOrder} />}
    </div>
  )
}

// Customer / CRM side panel (wide screens): who is this, their orders, and one-tap "turn this chat into an order".
function CrmPanel({ th, orders, navigate, sos, online, onCreate }) {
  const [amt, setAmt] = useState('')
  const [pm, setPm] = useState('')
  const [busyP, setBusyP] = useState(false)
  const [invOrder, setInvOrder] = useState(null)
  const tail = th.phone.slice(-9)
  const list = orders.filter((o) => String(o.clientPhone || '').replace(/\D/g, '').endsWith(tail))
  const done = list.filter((o) => ['completed', 'closed'].includes(o.status)).length
  const active = list.find((o) => !['completed', 'closed', 'cancelled'].includes(o.status))
  return (
    <aside className="hidden xl:flex w-[300px] shrink-0 border-r border-white/10 flex-col gap-3 p-3 overflow-y-auto">
      <p className="text-xs font-black text-white flex items-center gap-1.5"><UserRound size={14} className="text-primary" /> بطاقة العميل</p>
      {sos && <div className="rounded-xl bg-rose-500/15 border border-rose-500/40 text-rose-200 text-[11px] font-bold p-2.5 flex gap-2"><Siren size={14} className="shrink-0 mt-0.5" /> رسالة طوارئ محتملة — حوّلها لطلب عاجل وسيُوزَّع أولًا.</div>}
      <div className="rounded-xl bg-white/5 border border-white/10 p-3 text-xs text-slate-300 grid gap-1.5">
        <p className="font-black text-white text-sm">{th.name || 'عميل واتساب'}</p>
        <a href={`tel:+${th.phone}`} className="flex items-center gap-1.5" dir="ltr"><Phone size={12} /> +{th.phone}</a>
        {active?.address && <p className="flex items-center gap-1.5"><MapPin size={12} /> {active.address}</p>}
        <p className="text-slate-500">{list.length ? `${list.length} طلب سابق · ${done} مكتمل` : 'عميل جديد — لا طلبات سابقة'}</p>
      </div>
      <button onClick={onCreate} className="rounded-xl bg-emerald-500 text-black font-black text-sm py-3 flex items-center justify-center gap-2 active:scale-95"><ClipboardPlus size={16} /> تحويل المحادثة إلى طلب</button>
      <p className="text-[10px] text-slate-500 -mt-1">يُنشأ الطلب بآخر رسالة للعميل، يدخل قائمة التوزيع (آلي/يدوي)، وتصله رسالة تأكيد تلقائية.</p>
      <div className="rounded-xl bg-white/5 border border-white/10 p-3 grid gap-2">
        <p className="text-xs font-black text-white">💳 رابط دفع للعميل</p>
        <input value={amt} onChange={(e) => setAmt(e.target.value)} inputMode="decimal" placeholder={active?.agreedPrice || active?.price ? `المبلغ (الطلب: ${active.agreedPrice || active.price})` : 'المبلغ (ر.س)'} className={inputCls} />
        <button disabled={busyP || !online || !Number(amt || active?.agreedPrice || active?.price)} onClick={async () => {
          setBusyP(true); setPm('')
          try {
            const amount = Number(amt || active?.agreedPrice || active?.price)
            const r = await post('/shop', { op: 'pay_create', amount, desc: active?.description || 'خدمة صيانة', client: th.name || '', orderId: active?.id || '' })
            await waApi('send', { accountId: th.accountId, to: th.phone, text: `رابط الدفع (${amount} ر.س):\n${r.url}\nيمكنك الدفع بالتحويل أو المحفظة أو البطاقة.` })
            setPm('أُرسل الرابط للعميل ✓'); setAmt('')
          } catch (e) { setPm(e?.message || 'تعذر الإرسال') } finally { setBusyP(false) }
        }} className="rounded-lg bg-sky-500 text-black font-black text-xs py-2 disabled:opacity-40">إنشاء الرابط وإرساله في المحادثة</button>
        {pm && <p className="text-[11px] text-amber-300 font-bold">{pm}</p>}
        {!online && <p className="text-[10px] text-slate-500">الرقم غير متصل — لا يمكن الإرسال.</p>}
      </div>
      {(() => { const inv = list.find((o) => o.invoiceItems?.length) || active; return inv ? <button onClick={() => setInvOrder(inv)} className="rounded-xl bg-white/10 border border-white/10 text-white font-black text-xs py-2.5">🧾 فاتورة ضريبية للطلب #{orderNo(inv)}</button> : null })()}
      {invOrder && <InvoiceModal order={invOrder} canEdit wa={{ accountId: th.accountId, phone: th.phone }} onClose={() => setInvOrder(null)} />}
      {list.length > 0 && <p className="text-xs font-black text-white mt-1 flex items-center gap-1.5"><Wrench size={13} className="text-primary" /> سجل الطلبات</p>}
      {list.slice(0, 6).map((o) => (
        <button key={o.id} onClick={() => navigate(`/staff/order/${o.id}`)} className="text-right rounded-xl bg-white/5 border border-white/10 p-2.5 text-[11px]">
          <p className="font-bold text-white flex items-center gap-2">#{orderNo(o)}<span className="mr-auto text-[10px] text-sky-300">{STATUS_META?.[o.status]?.label || o.status}</span></p>
          <p className="text-slate-400 truncate">{o.description || o.serviceDetails || '—'}</p>
          {o.technicianName && <p className="text-emerald-300/80">🧰 {o.technicianName}</p>}
        </button>
      ))}
    </aside>
  )
}

const Chip = ({ on, onClick, children }) => (
  <button onClick={onClick} className={`shrink-0 rounded-full px-3 py-1 text-[11px] font-bold border ${on ? 'bg-primary/20 border-primary text-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}>{children}</button>
)

/* ------------------------------------------------------------------ numbers */
function Numbers({ sess, isOwner }) {
  const navigate = useNavigate()
  const [adding, setAdding] = useState(false)
  const [linking, setLinking] = useState(null)        // {id, label} — the live QR window
  const [tapErr, setTapErr] = useState('')
  const [cfgOpen, setCfgOpen] = useState(false)
  const notSet = /الوسيط أولًا|gw_not_set/.test(sess.error || '')
  // This tab already refreshes every three seconds, which is fast enough for a QR that lives about
  // a minute — a second timer on top of it would only double the requests to the hub.
  const onPhone = sess.sessions?.some?.((x) => x.via === 'phone')
  const { data: botRows } = useLiveShared('wa_bot', { limit: 50 })
  const { data: threads } = useLiveShared('wa_threads', { order: '-lastAt', limit: 300 })
  const botOn = (id) => botRows?.find((r) => r.id === `acct:${id}`)?.botEnabled !== false
  const setBot = (id, v) => db.upsertShared('wa_bot', { botEnabled: v }, `acct:${id}`, { writableBy: 'admins' }).catch(() => {})
  const stat2 = (id) => { const l = (threads || []).filter((t) => t.accountId === id); return { n: l.length, u: l.reduce((a, t) => a + (t.unread || 0), 0), w: l.filter((t) => t.lastDir === 'in' && t.status !== 'done').length } }
  async function act(op, id) {
    if (op === 'logout' && !confirm('فصل هذا الرقم وحذف جلسته؟')) return
    try { await waApi(op, { id }); sess.reload() } catch (e) { alert(e?.message || 'تعذر التنفيذ') }
  }
  // Tap a number that is not connected → ask its device for a fresh QR and show it big, live.
  async function tapNumber(s) {
    setTapErr('')
    if (s.status === 'open') return
    setLinking({ id: s.id, label: s.label, phone: s.phone })
    if (s.qr || s.pairingCode) return
    try { await waApi('relink', { id: s.id, mode: 'qr' }); sess.reload() } catch (e) { setTapErr(e?.message || 'تعذر طلب الرمز'); setLinking(null) }
  }
  return (
    <div className="h-full overflow-y-auto"><div className="max-w-3xl mx-auto w-full px-4 py-4 flex flex-col gap-3">
      <div className="flex gap-2">
        <button onClick={() => setAdding(true)} className="flex-1 flex items-center justify-center gap-2 bg-emerald-500 text-black rounded-xl py-3 text-sm font-black"><Plus size={16} /> ربط رقم جديد</button>
        {isOwner && <button onClick={() => setCfgOpen((v) => !v)} className="flex items-center gap-1.5 bg-white/5 border border-white/10 rounded-xl px-4 text-xs font-bold text-slate-200"><Settings2 size={14} /> إعداد الوسيط</button>}
      </div>
      {(cfgOpen || notSet) && isOwner && <GatewayConfig onSaved={sess.reload} />}
      {sess.error && !notSet && <p className="text-rose-400 text-xs font-bold bg-rose-500/10 border border-rose-500/30 rounded-xl p-3">{sess.error}</p>}
      {tapErr && <p className="text-rose-300 text-xs font-bold bg-rose-500/10 border border-rose-500/30 rounded-xl p-3 leading-relaxed">{tapErr}{/جهاز/.test(tapErr) && <button onClick={() => navigate('/staff/wa-fleet')} className="block mt-2 bg-white/10 rounded-lg px-3 py-1.5 text-white">افتح «أجهزة الواتساب»</button>}</p>}
      <p className="text-[11px] text-slate-500 flex items-center gap-1.5"><RefreshCw size={11} className="animate-spin [animation-duration:3s]" />بيتحدّث كل 3 ثواني · اضغط على أي رقم مش متصل يظهرلك الـQR فورًا</p>
      {sess.loading && <p className="text-slate-500 text-xs text-center py-6">جارِ التحميل…</p>}
      {sess.sessions.map((s) => {
        const [lab, cls] = stat(s.status)
        return (
          <div key={s.id} className={`rounded-2xl border bg-white/[0.04] p-4 ${s.status === 'open' ? 'border-white/10' : 'border-amber-400/40'}`}>
            <button type="button" onClick={() => tapNumber(s)} disabled={s.status === 'open'} className="w-full text-right flex items-center gap-3 disabled:cursor-default">
              {s.status !== 'open' && <span className="w-10 h-10 shrink-0 rounded-xl bg-amber-400/15 border border-amber-400/40 flex items-center justify-center text-amber-200"><QrCode size={20} /></span>}
              <div className="min-w-0 flex-1">
                <p className="text-sm font-black text-white truncate">{s.label}</p>
                <p className="text-[11px] text-slate-500" dir="ltr">{s.phone ? `+${s.phone}` : '—'} {s.name ? `· ${s.name}` : ''}</p>
                {s.status !== 'open' && <p className="text-[11px] text-amber-200 font-bold mt-0.5">اضغط هنا لعرض QR الربط</p>}
                {s.seenAgo != null && <p className="text-[10px] text-slate-500 mt-0.5">آخر تحديث من الجهاز: <Ago at={Date.now() - s.seenAgo * 1000} /></p>}
              </div>
              <span className={`text-[10px] font-bold rounded-full px-2.5 py-1 ${cls}`}>{lab}</span>
            </button>
            {s.qr && (
              <div className="mt-3 flex flex-col items-center gap-2 bg-white rounded-xl p-3">
                <img src={s.qr} alt="QR" className="w-56 h-56" />
                <p className="text-[11px] text-slate-700 text-center">واتساب ← الأجهزة المرتبطة ← ربط جهاز ← امسح الصورة (تتجدد تلقائيًا)</p>
                {s.via === 'phone' && <p className="text-[10px] text-slate-500 text-center">الرمز جاي من الوسيط على الموبايل — لو وقف عن التجدد، تأكد إنه شغال هناك.</p>}
              </div>
            )}
            {s.pairingCode && (
              <div className="mt-3 text-center bg-amber-500/10 border border-amber-400/30 rounded-xl p-4">
                <p className="text-3xl font-black tracking-[0.3em] text-amber-200" dir="ltr">{s.pairingCode}</p>
                <p className="text-[11px] text-slate-300 mt-2">واتساب ← الأجهزة المرتبطة ← ربط جهاز ← «الربط برقم الهاتف بدلًا من ذلك» ← أدخل الكود</p>
              </div>
            )}
            {(() => { const x = stat2(s.id); return (
              <div className="grid grid-cols-3 gap-2 mt-3 text-center">
                <div className="rounded-xl bg-white/5 py-2"><p className="text-base font-black text-white">{x.n}</p><p className="text-[10px] text-slate-500">محادثات</p></div>
                <div className="rounded-xl bg-white/5 py-2"><p className="text-base font-black text-emerald-300">{x.u}</p><p className="text-[10px] text-slate-500">غير مقروءة</p></div>
                <div className="rounded-xl bg-white/5 py-2"><p className={`text-base font-black ${x.w ? 'text-amber-300' : 'text-white'}`}>{x.w}</p><p className="text-[10px] text-slate-500">بانتظار رد</p></div>
              </div>) })()}
            <div className="flex items-center gap-3 mt-3">
              <p className="flex-1 text-xs font-bold text-slate-300">بوت الاستقبال على هذا الرقم</p>
              <button onClick={() => setBot(s.id, !botOn(s.id))} className={`w-12 h-7 rounded-full relative transition-colors ${botOn(s.id) ? 'bg-primary' : 'bg-white/15'}`} aria-pressed={botOn(s.id)}><span className={`absolute top-1 w-5 h-5 rounded-full bg-white transition-all ${botOn(s.id) ? 'right-1' : 'right-6'}`} /></button>
            </div>
            <div className="flex items-center gap-2 mt-3 text-[11px] text-slate-500">
              <span>أُرسل اليوم: {s.sentToday}/{s.dailyCap}</span>{sess.ms ? <span>· الوسيط {sess.ms}ms</span> : null}
              <span className="mr-auto" />
              <button onClick={() => act('restart', s.id)} className="flex items-center gap-1 font-bold text-slate-300 bg-white/5 rounded-lg px-2.5 py-1.5"><RefreshCw size={12} /> إعادة اتصال</button>
              <button onClick={() => act('logout', s.id)} className="flex items-center gap-1 font-bold text-rose-300 bg-rose-500/10 rounded-lg px-2.5 py-1.5"><LogOut size={12} /> فصل</button>
            </div>
          </div>
        )
      })}
      {!sess.loading && !sess.error && sess.sessions.length === 0 && (
        <div className="text-center text-xs py-8 flex flex-col gap-2">
          <p className="text-slate-400 font-bold">لا توجد أرقام مربوطة.</p>
          <p className="text-slate-500 leading-6">اضغط «ربط رقم جديد»، اكتب الرقم، وهيظهرلك كود تكتبه في واتساب نفس الموبايل.</p>
        </div>
      )}
      {adding && <AddNumber onClose={() => setAdding(false)} onDone={(r, phone) => { setAdding(false); sess.reload(); if (r?.id) setLinking({ id: r.id, label: phone ? `+${phone}` : 'رقم جديد', phone, device: r.deviceName, deviceOnline: r.deviceOnline, mode: r.mode }) }} />}
      {linking && <LinkWindow link={linking} sess={sess} onClose={() => setLinking(null)} />}
    </div></div>
  )
}

// One field: the WhatsApp number. The server picks the device (or the gateway server) and the QR opens right away.
function AddNumber({ onClose, onDone }) {
  const [phone, setPhone] = useState('')
  const [role, setRole] = useState('customers')
  const [how, setHow] = useState('code')            // «كود بالرقم»: no second phone, no camera — the easy default
  const [brand, setBrand] = useState('')
  const [sites, setSites] = useState([])
  useEffect(() => { post('/network', { op: 'brands' }).then((r) => setSites(r.sites || [])).catch(() => {}) }, [])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const navigate = useNavigate()
  const clean = phone.replace(/[^0-9٠-٩+]/g, '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
  async function go() {
    setBusy(true); setErr('')
    if (how === 'code' && clean.replace(/\D/g, '').length < 9) { setErr('اكتب رقم الواتساب اللي هيترَبط عشان نطلعلك الكود.'); return }
    try { const r = await waApi('link', { phone: clean, mode: how, role, brand }); onDone({ ...r, mode: how }, clean.replace(/^\+/, '')) }
    catch (e) { setErr(e?.message || 'تعذر الربط') } finally { setBusy(false) }
  }
  return (
    <div className="fixed inset-0 z-50 bg-black/70 flex items-end sm:items-center justify-center p-3" onClick={onClose}>
      <div onClick={(e) => e.stopPropagation()} className="w-full max-w-md bg-[#0f1424] border border-white/10 rounded-2xl p-5 flex flex-col gap-3">
        <h3 className="font-display font-black text-white flex items-center gap-2"><QrCode size={18} className="text-emerald-300" /> ربط رقم واتساب</h3>
        <label className="text-[12px] text-slate-400 font-bold" htmlFor="wa-new-phone">رقم الواتساب اللي هيترَبط</label>
        <input id="wa-new-phone" autoFocus value={phone} onChange={(e) => setPhone(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') go() }} placeholder="05xxxxxxxx" dir="ltr" inputMode="tel" className={inputCls + ' text-lg tracking-wider text-center'} />
        <div className="flex gap-1.5 overflow-x-auto pb-1">
          {[['customers', '🛎️ العملاء'], ['technicians', '🧑‍🔧 الفنيين'], ['complaints', '⚠️ الشكاوى'], ['marketing', '📣 الدعاية'], ['reports', '📊 التقارير'], ['general', '💬 عام']].map(([k, l]) => <Chip key={k} on={role === k} onClick={() => setRole(k)}>{l}</Chip>)}
        </div>
        {role === 'customers' && (
          <label className="grid gap-1 text-[12px] text-slate-400 font-bold">الخط ده تبع أنهي موقع؟
            <select value={brand} onChange={(e) => setBrand(e.target.value)} className={inputCls}>
              <option value="">كل المواقع (خط مشترك — الموقع بيتعرف من كود الرابط)</option>
              {sites.map((s) => <option key={s.id} value={s.id}>{s.name} — {s.host}</option>)}
            </select>
          </label>
        )}
        <div className="grid grid-cols-2 gap-2">
          <button onClick={() => setHow('code')} className={`rounded-xl py-2.5 text-xs font-black border flex items-center justify-center gap-1.5 ${how === 'code' ? 'bg-emerald-500/20 border-emerald-400 text-emerald-200' : 'bg-white/5 border-white/10 text-slate-300'}`}><KeyRound size={14} /> كود بالرقم (أسهل)</button>
          <button onClick={() => setHow('qr')} className={`rounded-xl py-2.5 text-xs font-black border flex items-center justify-center gap-1.5 ${how === 'qr' ? 'bg-primary/20 border-primary text-primary' : 'bg-white/5 border-white/10 text-slate-300'}`}><QrCode size={14} /> صورة QR</button>
        </div>
        <p className="text-[11px] text-slate-500 leading-relaxed">{how === 'code'
          ? 'هيظهرلك كود من 8 حروف. على نفس موبايل الرقم: واتساب ← ⋮ ← الأجهزة المرتبطة ← ربط جهاز ← «الربط برقم الهاتف بدلًا من ذلك» ← اكتب الكود. من غير موبايل تاني ولا كاميرا.'
          : 'امسح الـQR من الموبايل اللي عليه الرقم: واتساب ← الأجهزة المرتبطة ← ربط جهاز.'} يعمل مع واتساب العادي والأعمال.</p>
        {err && <div className="text-rose-300 text-xs font-bold leading-relaxed bg-rose-500/10 border border-rose-500/30 rounded-xl p-3">{err}{/جهاز/.test(err) && <button onClick={() => navigate('/staff/wa-fleet')} className="block mt-2 bg-white/10 rounded-lg px-3 py-1.5 text-white">سجّل جهاز الوسيط الآن</button>}</div>}
        <div className="flex gap-2">
          <button onClick={go} disabled={busy} className="flex-1 bg-emerald-500 text-black font-black rounded-xl py-3 text-sm disabled:opacity-50 flex items-center justify-center gap-2">{busy ? <Loader2 size={16} className="animate-spin" /> : how === 'code' ? <KeyRound size={16} /> : <QrCode size={16} />}{busy ? 'بيجهّز…' : how === 'code' ? 'هات الكود' : 'اعرض QR'}</button>
          <button onClick={onClose} className="px-5 bg-white/5 rounded-xl text-sm text-slate-300 font-bold">إلغاء</button>
        </div>
      </div>
    </div>
  )
}

// The live QR window: follows the number on the 3-second refresh, offers a pairing code when there is no second
// screen, and closes itself with a tick once WhatsApp says the number is connected.
function LinkWindow({ link, sess, onClose }) {
  const s = sess.sessions.find((x) => x.id === link.id) || {}
  const [waitS, setWaitS] = useState(0)
  const [codeMode, setCodeMode] = useState(link.mode === 'code')
  const [phone, setPhone] = useState(link.phone || '')
  const [msg, setMsg] = useState('')
  useEffect(() => { const t = setInterval(() => setWaitS((x) => x + 1), 1000); return () => clearInterval(t) }, [])
  useEffect(() => { if (s.status === 'open') { const t = setTimeout(onClose, 2500); return () => clearTimeout(t) } return undefined }, [s.status, onClose])
  async function again(mode) {
    setMsg(''); setWaitS(0)
    try { await waApi('relink', { id: link.id, mode, phone: phone.replace(/\D/g, '') }); setCodeMode(mode === 'code'); sess.reload() } catch (e) { setMsg(e?.message || 'تعذر') }
  }
  const slow = !s.qr && !s.pairingCode && s.status !== 'open' && waitS > 20
  return (
    <div className="fixed inset-0 z-[70] bg-black/85 flex items-center justify-center p-3" onClick={onClose}>
      <div onClick={(e) => e.stopPropagation()} className="w-full max-w-sm bg-[#0f1424] border border-white/10 rounded-3xl p-5 flex flex-col items-center gap-3 text-center">
        <p className="font-black text-white">{link.label}</p>
        {s.status === 'open' ? (
          <div className="py-10 flex flex-col items-center gap-2"><CheckCircle2 size={64} className="text-emerald-400" /><p className="text-emerald-300 font-black">اتربط ✓ {s.phone ? `+${s.phone}` : ''}</p></div>
        ) : s.qr && !codeMode ? (
          <>
            <div className="bg-white rounded-2xl p-3"><img src={s.qr} alt="رمز QR لربط واتساب" className="w-64 h-64" /></div>
            <p className="text-[12px] text-slate-300 leading-relaxed">واتساب ← ⋮ ← <b>الأجهزة المرتبطة</b> ← <b>ربط جهاز</b> ← امسح الرمز. بيتجدد لوحده.</p>
          </>
        ) : s.pairingCode ? (
          <>
            <p className="text-4xl font-black tracking-[0.25em] text-amber-200 py-4 select-all" dir="ltr">{s.pairingCode}</p>
            <button onClick={() => { try { navigator.clipboard.writeText(String(s.pairingCode).replace(/-/g, '')) } catch { /* ignore */ } }} className="bg-white/10 rounded-lg px-3 py-1.5 text-[12px] font-bold text-white">نسخ الكود</button>
            <ol className="text-[12px] text-slate-300 leading-relaxed text-right list-decimal pr-5">
              <li>افتح واتساب على موبايل الرقم ← ⋮ ← <b>الأجهزة المرتبطة</b> ← <b>ربط جهاز</b></li>
              <li>اختار <b>«الربط برقم الهاتف بدلًا من ذلك»</b></li>
              <li>اكتب الكود اللي فوق — والشاشة دي هتقفل لوحدها لما يتربط ✓</li>
            </ol>
          </>
        ) : (
          <div className="py-10 flex flex-col items-center gap-3">
            <Loader2 size={40} className="animate-spin text-emerald-300" />
            <p className="text-sm text-slate-300 font-bold">الجهاز بيجهّز الرمز… {waitS}ث</p>
            <p className="text-[11px] text-slate-500">عادةً خلال 3–10 ثواني{link.device ? ` — على «${link.device}»` : ''}</p>
          </div>
        )}
        {slow && <p className="text-[11px] text-amber-200 leading-relaxed bg-amber-500/10 border border-amber-400/30 rounded-xl p-2.5">الجهاز ما ردّش لسه. اتأكد إن الوسيط شغال عليه (Termux مفتوح والنت شغال){link.deviceOnline === false ? ' — الجهاز ده ظاهر غير متصل' : ''}.</p>}
        {s.status !== 'open' && (
          <div className="w-full grid gap-2">
            {!codeMode ? (
              <div className="flex gap-2">
                <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="05xxxxxxxx" dir="ltr" inputMode="tel" className={inputCls + ' text-center'} />
                <button onClick={() => again('code')} className="shrink-0 bg-white/10 rounded-xl px-3 text-[12px] font-bold text-white flex items-center gap-1"><KeyRound size={13} />كود بدل QR</button>
              </div>
            ) : <button onClick={() => again('qr')} className="bg-white/10 rounded-xl py-2.5 text-[12px] font-bold text-white flex items-center justify-center gap-1"><QrCode size={13} />ارجع لـ QR</button>}
            <button onClick={() => again(codeMode ? 'code' : 'qr')} className="text-[12px] text-slate-400 font-bold flex items-center justify-center gap-1"><RefreshCw size={12} />رمز جديد</button>
          </div>
        )}
        {msg && <p className="text-rose-300 text-xs font-bold">{msg}</p>}
        <button onClick={onClose} className="w-full bg-white/5 rounded-xl py-2.5 text-sm text-slate-300 font-bold">إغلاق</button>
      </div>
    </div>
  )
}

function GatewayConfig({ onSaved }) {
  const [c, setC] = useState(null)
  const [url, setUrl] = useState('')
  const [token, setToken] = useState('')
  const [msg, setMsg] = useState('')
  useEffect(() => { waApi('config_get').then((r) => { setC(r); setUrl(r.url || '') }).catch(() => {}) }, [])
  async function save() {
    try { const r = await waApi('config_set', { url, token: token || undefined }); setC(r); setToken(''); setMsg('تم الحفظ ✓'); onSaved() } catch (e) { setMsg(e?.message || 'تعذر الحفظ') }
  }
  const copy = (t) => { try { navigator.clipboard.writeText(t) } catch {} }
  return (
    <div className="rounded-2xl border border-sky-500/30 bg-sky-500/5 p-4 flex flex-col gap-2.5">
      <p className="text-sm font-black text-white">إعداد الوسيط (Gateway)</p>
      <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="رابط الوسيط https://wa.example.com" dir="ltr" className={inputCls} />
      <input value={token} onChange={(e) => setToken(e.target.value)} placeholder={c?.hasToken ? 'مفتاح الوسيط محفوظ — اتركه فارغًا للإبقاء' : 'مفتاح الوسيط GATEWAY_TOKEN'} dir="ltr" type="password" className={inputCls} />
      {c && (
        <div className="text-[11px] text-slate-400 leading-relaxed bg-black/20 rounded-xl p-3">
          <p className="font-bold text-slate-300 mb-1">ضع هاتين القيمتين في إعدادات الوسيط:</p>
          <p className="flex items-center gap-2 break-all" dir="ltr">WEBHOOK_URL={c.hookUrl} <button onClick={() => copy(c.hookUrl)}><Copy size={12} /></button></p>
          <p className="flex items-center gap-2 break-all" dir="ltr">WEBHOOK_SECRET={c.secret} <button onClick={() => copy(c.secret)}><Copy size={12} /></button></p>
        </div>
      )}
      <div className="flex items-center gap-3"><button onClick={save} className="bg-primary text-black font-black rounded-xl px-5 py-2.5 text-sm">حفظ</button>{msg && <span className="text-xs text-emerald-300 font-bold">{msg}</span>}</div>
    </div>
  )
}

/* ------------------------------------------------------------------ bot */
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']

const STOP = new Set(['كم', 'ايش', 'وش', 'ممكن', 'لو', 'سمحت', 'عندكم', 'هل', 'في', 'من', 'على', 'الى', 'إلى', 'عن', 'ابغى', 'ابي', 'اريد', 'انا', 'لي', 'يا'])
const kwOf = (q) => [...new Set(String(q).replace(/[^؀-ۿa-zA-Z0-9\s]/g, ' ').split(/\s+/).filter((w) => w.length >= 3 && !STOP.has(w)))].slice(0, 4).join('، ')

// Free "learning": owner's own replies in customer chats become candidate scenarios; unanswered questions are listed.
function LearnedPanel({ rows, onApprove }) {
  const learn = rows.filter((r) => r.kind === 'learn' && r.status === 'pending').sort((a, b) => (b.count || 0) - (a.count || 0)).slice(0, 15)
  const miss = rows.filter((r) => r.kind === 'miss').sort((a, b) => (b.count || 0) - (a.count || 0)).slice(0, 10)
  const style = rows.find((r) => r.id === 'style')
  const mark = (r, status) => db.upsertShared('wa_bot', { ...r, status }, r.id, { writableBy: 'admins' })
  return (
    <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
      <p className="text-sm font-black text-white">🧠 تعلّم البوت (مجاني — يتعلم من ردودك أنت)</p>
      <p className="text-[11px] text-slate-400">كل ما ترد بنفسك على عميل، يُحفظ السؤال وردك هنا كسيناريو مقترح. اعتمده ليصير البوت يرد به، أو ارفضه. لا يُنشر شيء بدون موافقتك.</p>
      {style && style.n > 0 && <p className="text-[11px] text-slate-300">أسلوبك: متوسط طول الرد {Math.round(style.len / style.n)} حرف • إيموجي/رد {(style.emoji / style.n).toFixed(1)} • أكثر افتتاحياتك: {Object.keys(style.open || {}).slice(0, 4).join('، ') || '—'}</p>}
      {learn.length === 0 && <p className="text-[11px] text-slate-500">لا توجد سيناريوهات مقترحة بعد.</p>}
      {learn.map((r) => (
        <div key={r.id} className="bg-white/[0.03] rounded-xl p-2.5 grid gap-1.5">
          <p className="text-xs text-slate-300"><b>العميل:</b> {r.q} {r.count > 1 && <span className="text-amber-400">×{r.count}</span>}</p>
          <p className="text-xs text-emerald-300"><b>ردك:</b> {r.a}</p>
          <div className="flex gap-2">
            <button onClick={async () => { await onApprove({ keywords: kwOf(r.q), reply: r.a }); await mark(r, 'approved') }} className="flex-1 bg-primary text-black font-black rounded-lg py-1.5 text-xs">اعتماد كسيناريو</button>
            <button onClick={() => mark(r, 'rejected')} className="px-3 bg-white/10 text-slate-300 rounded-lg text-xs">رفض</button>
          </div>
        </div>
      ))}
      {miss.length > 0 && (
        <div className="grid gap-1">
          <p className="text-xs font-black text-slate-200 mt-1">أسئلة لم يفهمها البوت (رد عليها وسيتعلمها):</p>
          {miss.map((r) => <p key={r.id} className="text-[11px] text-slate-400">• {r.q} <span className="text-amber-400">×{r.count}</span></p>)}
        </div>
      )}
    </div>
  )
}

function BotSettings() {
  const { data } = useLiveShared('wa_bot', { limit: 400 })
  const [c, setC] = useState(null)
  const [saved, setSaved] = useState('')
  useEffect(() => { if (c === null && data) { const row = data.find((r) => r.id === 'cfg'); setC({ ...DEFAULT_BOT, ...(row || {}), hours: { ...DEFAULT_BOT.hours, ...(row?.hours || {}) } }) } }, [data, c])
  if (!c) return <Centered>جارِ التحميل…</Centered>
  const set = (k, v) => setC((p) => ({ ...p, [k]: v }))
  const list = (k, i, f, v) => set(k, c[k].map((x, j) => (j === i ? { ...x, [f]: v } : x)))
  async function save() {
    try { const { id, createdAt, updatedAt, ...rest } = c; await saveBot(rest); setSaved('تم الحفظ ✓') } catch { setSaved('تعذر الحفظ') }
    setTimeout(() => setSaved(''), 2500)
  }
  return (
    <div className="h-full overflow-y-auto"><div className="max-w-3xl mx-auto w-full px-4 py-4 flex flex-col gap-4 pb-24">
      <Row><label className="flex items-center gap-3 flex-1 text-sm font-black text-white"><input type="checkbox" checked={!!c.enabled} onChange={(e) => set('enabled', e.target.checked)} className="w-5 h-5 accent-emerald-500" /> تشغيل بوت الاستقبال على كل الأرقام</label></Row>
      <Row><label className="flex items-center gap-3 flex-1 text-sm font-bold text-slate-200"><input type="checkbox" checked={!!c.forceAway} onChange={(e) => set('forceAway', e.target.checked)} className="w-5 h-5 accent-amber-500" /> لا يوجد أحد متاح الآن (يسجّل البوت بيانات العميل بدل التحويل)</label></Row>
      <Field label="اسم النشاط"><input value={c.brand} onChange={(e) => set('brand', e.target.value)} className={inputCls} /></Field>
      <Field label="رسالة الترحيب (استخدم {brand} للاسم)"><textarea rows={3} value={c.welcome} onChange={(e) => set('welcome', e.target.value)} className={inputCls} /></Field>
      <Field label="قائمة الخيارات (الأرقام 1–5 ثابتة)"><textarea rows={6} value={c.menu} onChange={(e) => set('menu', e.target.value)} className={inputCls} /></Field>
      <Field label="نص الخدمات والأسعار (الخيار 1)"><textarea rows={4} value={c.services} onChange={(e) => set('services', e.target.value)} className={inputCls} /></Field>
      <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
        <p className="text-sm font-black text-white">مواعيد العمل</p>
        <div className="flex gap-2 items-center text-xs text-slate-300">من <input type="time" value={c.hours.start} onChange={(e) => set('hours', { ...c.hours, start: e.target.value })} className={`${inputCls} !w-auto`} /> إلى <input type="time" value={c.hours.end} onChange={(e) => set('hours', { ...c.hours, end: e.target.value })} className={`${inputCls} !w-auto`} /></div>
        <div className="flex flex-wrap gap-1.5">{DAYS.map((d, i) => { const on = c.hours.days.includes(i); return <Chip key={d} on={on} onClick={() => set('hours', { ...c.hours, days: on ? c.hours.days.filter((x) => x !== i) : [...c.hours.days, i].sort() })}>{d}</Chip> })}</div>
      </div>
      <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
        <div className="flex items-center"><p className="text-sm font-black text-white flex-1">روابط الفيديو (تيك توك / يوتيوب)</p><button onClick={() => set('videos', [...c.videos, { title: '', url: '', keywords: '' }])} className="text-xs font-bold text-primary flex items-center gap-1"><Plus size={13} /> إضافة</button></div>
        {c.videos.map((v, i) => (
          <div key={i} className="grid gap-1.5 bg-white/[0.03] rounded-xl p-2.5">
            <input value={v.title} onChange={(e) => list('videos', i, 'title', e.target.value)} placeholder="عنوان الفيديو (مثال: طريقة غسيل المكيف)" className={inputCls} />
            <input value={v.url} onChange={(e) => list('videos', i, 'url', e.target.value)} placeholder="https://www.tiktok.com/…" dir="ltr" className={inputCls} />
            <div className="flex gap-2"><input value={v.keywords} onChange={(e) => list('videos', i, 'keywords', e.target.value)} placeholder="كلمات تُظهره: غسيل، مكيف" className={inputCls} /><button onClick={() => set('videos', c.videos.filter((_, j) => j !== i))} className="shrink-0 text-rose-400 px-2"><Trash2 size={15} /></button></div>
          </div>
        ))}
        {c.videos.length === 0 && <p className="text-[11px] text-slate-500">أضف روابطك الحقيقية؛ يرسلها البوت عند سؤال العميل عن الشرح أو الغسيل.</p>}
      </div>
      <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
        <div className="flex items-center"><p className="text-sm font-black text-white flex-1">أسئلة وأجوبة سريعة</p><button onClick={() => set('faq', [...c.faq, { keywords: '', reply: '' }])} className="text-xs font-bold text-primary flex items-center gap-1"><Plus size={13} /> إضافة</button></div>
        {c.faq.map((f, i) => (
          <div key={i} className="grid gap-1.5 bg-white/[0.03] rounded-xl p-2.5">
            <input value={f.keywords} onChange={(e) => list('faq', i, 'keywords', e.target.value)} placeholder="كلمات السؤال: ضمان، كفالة" className={inputCls} />
            <div className="flex gap-2"><textarea rows={2} value={f.reply} onChange={(e) => list('faq', i, 'reply', e.target.value)} placeholder="الرد" className={inputCls} /><button onClick={() => set('faq', c.faq.filter((_, j) => j !== i))} className="shrink-0 text-rose-400 px-2"><Trash2 size={15} /></button></div>
          </div>
        ))}
      </div>
      <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
        <div className="flex items-center"><p className="text-sm font-black text-white flex-1">ردود سريعة للفريق (تظهر في صندوق المحادثات)</p><button onClick={() => set('quick', [...(c.quick || []), { title: '', text: '' }])} className="text-xs font-bold text-primary flex items-center gap-1"><Plus size={13} /> إضافة</button></div>
        {(c.quick || []).map((f, i) => (
          <div key={i} className="grid gap-1.5 bg-white/[0.03] rounded-xl p-2.5">
            <input value={f.title} onChange={(e) => list('quick', i, 'title', e.target.value)} placeholder="عنوان الزر" className={inputCls} />
            <div className="flex gap-2"><textarea rows={2} value={f.text} onChange={(e) => list('quick', i, 'text', e.target.value)} placeholder="نص الرد — استخدم {name} لاسم العميل" className={inputCls} /><button onClick={() => set('quick', c.quick.filter((_, j) => j !== i))} className="shrink-0 text-rose-400 px-2"><Trash2 size={15} /></button></div>
          </div>
        ))}
      </div>
      <div className="rounded-2xl border border-white/10 p-4 flex flex-col gap-2.5">
        <p className="text-sm font-black text-white">التفاوض والتغطية</p>
        <Field label="نزول السعر لكل جولة فصال (ر.س للوحدة) — الحد الأدنى يحدده «أقصى خصم» في إعدادات المكيفات"><input type="number" min="0" value={c.negStep ?? 10} onChange={(e) => set('negStep', Number(e.target.value) || 0)} className={inputCls} /></Field>
        <Field label="أحياء إضافية داخل مكة (افصل بفاصلة)"><input value={(c.extraDistricts || []).join('، ')} onChange={(e) => set('extraDistricts', e.target.value.split(/[,،]+/).map((x) => x.trim()).filter(Boolean))} className={inputCls} /></Field>
      </div>
      <LearnedPanel rows={data || []} onApprove={async (item) => { const next = { ...c, faq: [...(c.faq || []), item] }; setC(next); const { id, createdAt, updatedAt, ...rest } = next; await saveBot(rest) }} />
      <Field label="سكوت البوت بعد ما موظف يرد (دقايق) — كل رسالة من موظف بتبدأ العدّاد من جديد، والبوت يرجع بس لما الموظف يسكت المدة دي كلها">
        <select value={c.humanIdleMin ?? 120} onChange={(e) => set('humanIdleMin', Number(e.target.value))} className={inputCls}>
          {[[30, 'نص ساعة'], [60, 'ساعة'], [120, 'ساعتين (مقترح)'], [180, '3 ساعات'], [360, '6 ساعات'], [720, '12 ساعة'], [1440, 'يوم كامل']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </Field>
      <Field label="رد التحويل لموظف (أثناء الدوام)"><textarea rows={2} value={c.handoffText} onChange={(e) => set('handoffText', e.target.value)} className={inputCls} /></Field>
      <Field label="رد عدم توفر أحد (خارج الدوام)"><textarea rows={2} value={c.awayText} onChange={(e) => set('awayText', e.target.value)} className={inputCls} /></Field>
      <Field label="رد بعد تسجيل البيانات"><textarea rows={2} value={c.doneText} onChange={(e) => set('doneText', e.target.value)} className={inputCls} /></Field>
      <div className="fixed bottom-0 inset-x-0 md:right-64 z-30 bg-[#090c14]/95 border-t border-white/10 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <div className="max-w-3xl mx-auto flex items-center gap-3"><button onClick={save} className="flex-1 bg-primary text-black font-black rounded-xl py-3 text-sm">حفظ إعدادات البوت</button>{saved && <span className="text-xs font-bold text-emerald-300">{saved}</span>}</div>
      </div>
    </div></div>
  )
}
const Row = ({ children }) => <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 flex items-center">{children}</div>
const Field = ({ label, children }) => <label className="flex flex-col gap-1.5"><span className="text-[11px] font-bold text-slate-400">{label}</span>{children}</label>
const Centered = ({ children }) => <div className="h-full flex items-center justify-center text-slate-500 text-sm bg-[#090c14]" dir="rtl">{children}</div>

/* ------------------------------------------------------------------ central (master + n8n) */
// «السنترال»: the owner's one master WhatsApp, the lines and their sites, and the n8n link on the VPS.
// «جسر Chatwoot»: the team's inbox on Chatwoot's phone apps; the brain (AI, orders, journey) stays here.
function ChatwootBridge() {
  const [c, setC] = useState(null)
  const [f, setF] = useState({ url: '', account: '', token: '' })
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const load = () => waApi('chatwoot_get').then((r) => { setC(r); setF({ url: r.url || '', account: r.account || '', token: '' }) }).catch((e) => setMsg(e?.message || 'تعذر التحميل'))
  useEffect(() => { load() }, [])
  const save = async (patch, ok) => { setBusy(true); setMsg(''); try { setC(await waApi('chatwoot_set', patch)); setMsg(ok || 'اتحفظ ✓') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') } finally { setBusy(false) } }
  const test = async () => { setBusy(true); setMsg(''); try { const r = await waApi('chatwoot_test'); setMsg('متصل ✓ — ' + r.inboxes + ' صندوق في الحساب') } catch (e) { setMsg(e?.message || 'تعذر الاتصال') } finally { setBusy(false) } }
  if (!c) return null
  return (
    <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 grid gap-2">
      <p className="font-black text-white">💬 جسر Chatwoot {c.enabled ? <span className="text-emerald-400 text-xs">· شغال</span> : <span className="text-slate-500 text-xs">· مقفول</span>}</p>
      <p className="text-[12px] text-slate-400">صندوق الفريق على تطبيق Chatwoot: كل خط واتساب بيبقى صندوق هناك، كل رسالة (العميل والبوت والفريق) بتظهر في محادثته، وأي رد من Chatwoot بيطلع من نفس رقم العميل والبوت بيسكت في المحادثة دي. الذكاء والطلبات بيفضلوا هنا.</p>
      <input value={f.url} onChange={(e) => setF({ ...f, url: e.target.value })} placeholder="رابط Chatwoot (مثال: https://chat.oxygen11.com)" dir="ltr" className={inputCls} />
      <div className="flex gap-2">
        <input value={f.account} onChange={(e) => setF({ ...f, account: e.target.value })} placeholder="رقم الحساب" dir="ltr" className={inputCls + ' w-32'} />
        <input value={f.token} onChange={(e) => setF({ ...f, token: e.target.value })} placeholder={c.hasToken ? 'المفتاح محفوظ — اكتب جديد لتغييره' : 'Access Token من إعدادات ملفك في Chatwoot'} dir="ltr" type="password" className={inputCls} />
      </div>
      <div className="flex gap-2 flex-wrap">
        <button disabled={busy} onClick={() => save({ url: f.url, account: Number(f.account) || 0, ...(f.token ? { token: f.token } : {}) })} className="bg-primary text-white font-bold rounded-xl px-4 py-2 text-sm disabled:opacity-50">حفظ</button>
        <button disabled={busy || !c.hasToken} onClick={test} className="bg-white/10 text-white font-bold rounded-xl px-4 py-2 text-sm disabled:opacity-50">اختبار الاتصال</button>
        <button disabled={busy || !c.hasToken} onClick={async () => { setBusy(true); setMsg(''); try { const r = await waApi('chatwoot_sync'); setMsg('اتزامن ✓ — ' + (r.inboxes || []).map((x) => x.line + ' (' + x.members + ' عضو)').join('، ')); load() } catch (e) { setMsg(e?.message || 'تعذر') } finally { setBusy(false) } }} className="bg-white/10 text-white font-bold rounded-xl px-4 py-2 text-sm disabled:opacity-50">مزامنة الصناديق</button>
        <button disabled={busy || !c.hasToken || !c.url} onClick={() => save({ enabled: c.enabled ? 0 : 1 }, c.enabled ? 'اتقفل' : 'اشتغل ✓')} className={(c.enabled ? 'bg-rose-500/20 text-rose-200' : 'bg-emerald-600 text-white') + ' font-bold rounded-xl px-4 py-2 text-sm disabled:opacity-50'}>{c.enabled ? 'إيقاف الجسر' : 'تشغيل الجسر'}</button>
      </div>
      <label className="flex items-center gap-2 text-[12px] text-slate-300"><input type="checkbox" checked={!!c.notes} onChange={(e) => save({ notes: e.target.checked ? 1 : 0 })} />حط «كارت المحادثة» (الموقع، الخط، ملف العميل) كملاحظة خاصة أول كل محادثة</label>
      {Object.keys(c.inboxes || {}).length > 0 && <p className="text-[11px] text-slate-500">الخطوط المربوطة: {Object.keys(c.inboxes).length} صندوق</p>}
      {msg && <p className="text-[11px] text-emerald-300">{msg}</p>}
    </div>
  )
}

// «ملف العملاء»: the customer list as a file for a WhatsApp sender, and the regular follow-up messages.
function Contacts({ isOwner }) {
  const [f, setF] = useState(null)
  const [msg, setMsg] = useState('')
  useEffect(() => { waApi('followups_get').then(setF).catch(() => {}) }, [])
  const download = async () => {
    setMsg('بيتجهز…')
    try {
      const r = await waApi('contacts_export')
      const url = URL.createObjectURL(new Blob([r.csv], { type: 'text/csv;charset=utf-8' }))
      const a = document.createElement('a'); a.href = url; a.download = r.filename || 'contacts.csv'; document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 2000)
      setMsg('اتنزل ✓ ' + r.count + ' رقم')
    } catch (e) { setMsg(e?.message || 'تعذر') }
  }
  const set = async (patch) => { try { setF(await waApi('followups_set', patch)); setMsg('اتحفظ ✓') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') } }
  return (
    <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 grid gap-2">
      <p className="font-black text-white">📇 ملف العملاء والرسائل الدورية</p>
      <p className="text-[12px] text-slate-400">كل عميل متجمّع من طلباته ومحادثاته: اسمه، عدد طلباته، آخر خدمة، الضمان، تقييمه، والموقع اللي اتعامل معاه. الملف جاهز لأي برنامج إرسال واتساب (اللي كتب «إيقاف» متعلّم عليه).</p>
      <button onClick={download} className="bg-emerald-600 text-white font-bold rounded-xl px-4 py-2 text-sm w-fit">⬇️ نزّل ملف العملاء والفنيين (CSV)</button>
      {f && (
        <div className="grid gap-1.5 text-[12px] text-slate-300">
          <label className="flex items-center gap-2"><input type="checkbox" disabled={!isOwner} checked={!!f.enabled} onChange={(e) => set({ enabled: e.target.checked ? 1 : 0 })} />ابعت رسائل متابعة تلقائية: تذكير الغسيل الدوري بعد {f.maintenanceDays} يوم، وتنبيه قبل نهاية الضمان بـ {f.warrantyDays} أيام</label>
          <label className="flex items-center gap-2">أقصى عدد في اليوم: <input type="number" min="0" max="60" disabled={!isOwner} defaultValue={f.perDay} onBlur={(e) => set({ perDay: e.target.value })} className="w-16 bg-white/5 border border-white/10 rounded px-2 py-0.5" /> · الساعة <input type="number" min="0" max="23" disabled={!isOwner} defaultValue={f.hour} onBlur={(e) => set({ hour: e.target.value })} className="w-14 bg-white/5 border border-white/10 rounded px-2 py-0.5" /></label>
          <p className="text-[11px] text-slate-500">مش أكتر من رسالة لكل عميل كل 30 يوم، والعميل يوقفها بكلمة «إيقاف».</p>
        </div>
      )}
      {msg && <p className="text-[11px] text-emerald-300">{msg}</p>}
    </div>
  )
}

function Central({ isOwner }) {
  const [c, setC] = useState(null)
  const [master, setMaster] = useState('')
  const [n8n, setN8n] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [sites, setSites] = useState([])
  const load = () => waApi('central_get').then((r) => { setC(r); setMaster((r.master || []).map((m) => '+' + m).join('، ')); setN8n(r.n8nUrl || '') }).catch((e) => setMsg(e?.message || 'تعذر التحميل'))
  useEffect(() => { load(); post('/network', { op: 'brands' }).then((r) => setSites(r.sites || [])).catch(() => {}) }, [])
  const save = async (patch, ok) => { setBusy(true); setMsg(''); try { const r = await waApi('central_set', patch); setC(r); setMsg(ok || 'اتحفظ ✓') } catch (e) { setMsg(e?.message || 'تعذر الحفظ') } finally { setBusy(false) } }
  const copy = (t) => { try { navigator.clipboard.writeText(t); setMsg('اتنسخ ✓') } catch { /* ignore */ } }
  if (!c) return <p className="p-6 text-slate-400 text-sm">{msg || 'جارِ التحميل…'}</p>
  const roleName = { customers: '🛎️ عملاء', technicians: '🧑‍🔧 فنيين', complaints: '⚠️ شكاوى', marketing: '📣 دعاية', reports: '📊 تقارير/ماستر', articles: '📝 محتوى', site_tech: '🛠️ فني الموقع', general: '💬 عام' }
  const hasReports = (c.lines || []).some((l) => l.role === 'reports' && l.status === 'open')
  return (
    <div className="h-full overflow-y-auto"><div className="max-w-3xl mx-auto w-full px-4 py-4 grid gap-3">
      <div className="rounded-2xl border border-emerald-400/30 bg-emerald-500/5 p-4 grid gap-2">
        <p className="font-black text-white">📱 واتساب الماستر (في إيدك)</p>
        <p className="text-[12px] text-slate-400 leading-relaxed">كل أوردر جاهز من أي موقع يوصلك هنا رسالة واحدة منسّقة. ومنه بتأمر: <b>رد 1234 …</b> يوصل للعميل من نفس خطه، <b>اعتمد 1234</b> لأنسب فني، وكل أوامر المساعد.</p>
        <input value={master} onChange={(e) => setMaster(e.target.value)} disabled={!isOwner} placeholder="05xxxxxxxx (ممكن أكتر من رقم بفاصلة)" dir="ltr" className={inputCls} />
        {!hasReports && <p className="text-[11px] text-amber-300">محتاج خط واحد بدور «📊 تقارير» متصل عشان الرسايل تطلع منه للماستر (من الأرقام ← ربط رقم جديد ← التقارير).</p>}
        {isOwner && (
          <div className="flex flex-wrap gap-2">
            <button disabled={busy} onClick={() => save({ master })} className="bg-emerald-500 text-black font-black rounded-xl px-4 py-2 text-sm disabled:opacity-50">حفظ</button>
            <button disabled={busy || !(c.master || []).length} onClick={async () => { setBusy(true); try { const r = await waApi('central_test'); setMsg(r.sent ? 'اتبعتت رسالة تجربة للماستر ✓' : 'ما اتبعتش — اتأكد إن خط التقارير متصل') } catch (e) { setMsg(e?.message || 'تعذر') } finally { setBusy(false) } }} className="bg-white/10 text-white font-bold rounded-xl px-4 py-2 text-sm disabled:opacity-50">ابعت تجربة</button>
            <label className="flex items-center gap-2 text-[12px] text-slate-300"><input type="checkbox" checked={!!c.digest} onChange={(e) => save({ digest: e.target.checked })} />ابعت كل أوردر جاهز</label>
            <label className="flex items-center gap-2 text-[12px] text-slate-300"><input type="checkbox" checked={!!c.journey} onChange={(e) => save({ journey: e.target.checked })} />حلقة الوصل: أكّد كل طلب مع العميل على واتساب، تابعه لحد ما الفني يخلص، واطلب تقييمه</label>
          </div>
        )}
      </div>

      <Contacts isOwner={isOwner} />
      <ChatwootBridge />

      <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-4 grid gap-2">
        <p className="font-black text-white">☎️ الخطوط ومواقعها ({(c.lines || []).length})</p>
        <p className="text-[12px] text-slate-400">كل محادثة بيتحط عليها كارت: الموقع والدومين والخط. خط مشترك؟ الموقع بيتعرف من كود الرابط (#اسم-الموقع) اللي كل موقع بيحطه في زرار الواتساب.</p>
        {(c.lines || []).map((l) => (
          <div key={l.id} className="flex flex-wrap items-center gap-2 rounded-xl bg-black/20 px-3 py-2">
            <span className={`w-2 h-2 rounded-full ${l.status === 'open' ? 'bg-emerald-400' : 'bg-rose-400'}`} />
            <span className="text-[13px] font-bold text-white">{l.label}</span>
            {l.phone && <span className="text-[11px] text-slate-400" dir="ltr">+{l.phone}</span>}
            <span className="text-[11px] text-slate-300">{roleName[l.role] || l.role}</span>
            <span className="flex-1" />
            {isOwner ? (
              <select value={l.brand || ''} onChange={async (e) => { try { await waApi('fleet_brand', { id: l.id, brand: e.target.value }); load() } catch (er) { setMsg(er?.message || 'تعذر') } }} className="bg-white/5 border border-white/10 rounded-lg text-[12px] text-white px-2 py-1">
                <option value="">كل المواقع</option>
                {sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            ) : <span className="text-[12px] text-slate-300">{l.site}</span>}
          </div>
        ))}
        {!(c.lines || []).length && <p className="text-[12px] text-slate-500">لسه مفيش خطوط. من «الأرقام» ← ربط رقم جديد.</p>}
      </div>

      {isOwner && (
        <div className="rounded-2xl border border-violet-400/30 bg-violet-500/5 p-4 grid gap-2">
          <p className="font-black text-white">🔗 n8n على السيرفر</p>
          <p className="text-[12px] text-slate-400 leading-relaxed">الموقع بيبعت لـ n8n كل أوردر جاهز وكل محادثة جديدة وتقرير اليوم (موقّعة). وn8n يقدر يبعت للماستر أو للعميل من خطه. القوالب الجاهزة في مجلد <b>n8n</b> في المستودع.</p>
          <label className="text-[12px] text-slate-400 font-bold" htmlFor="n8n-url">رابط الـWebhook في n8n</label>
          <input id="n8n-url" value={n8n} onChange={(e) => setN8n(e.target.value)} placeholder="https://n8n.your-vps.com/webhook/mutqan" dir="ltr" className={inputCls} />
          <p className="text-[11px] text-slate-400">رابط الاستقبال (لـ n8n): <span dir="ltr" className="select-all text-slate-200">{c.n8nInbound}</span> <button onClick={() => copy(c.n8nInbound)} className="underline">نسخ</button></p>
          <p className="text-[11px] text-slate-400">السر (HMAC): <span dir="ltr" className="select-all text-slate-200">{c.n8nSecret}</span> <button onClick={() => copy(c.n8nSecret)} className="underline">نسخ</button></p>
          <div className="flex gap-2"><button disabled={busy} onClick={() => save({ n8nUrl: n8n })} className="bg-violet-500 text-white font-black rounded-xl px-4 py-2 text-sm disabled:opacity-50">حفظ</button>
            <button disabled={busy} onClick={() => save({ rotateSecret: true }, 'اتغيّر السر — حدّثه في n8n')} className="bg-white/10 text-white font-bold rounded-xl px-4 py-2 text-sm">سر جديد</button></div>
        </div>
      )}
      {msg && <p className="text-[12px] font-bold text-emerald-300">{msg}</p>}
    </div></div>
  )
}
