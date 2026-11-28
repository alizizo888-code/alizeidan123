import { Suspense, useState, useEffect } from 'react'
import { SwipeToClose } from '../components/Gestures'
import { HashRouter, Routes, Route, Navigate, useParams, NavLink } from 'react-router-dom'
import { lazyRetry } from '../lib/lazy'
import StaffLayout from '../components/StaffLayout'
import PushToaster from '../components/PushToaster'
import { ThemeBoot } from '../lib/appTheme'
import Notifications from '../pages/Notifications'
import { getToken, setSession, onUser } from '../lib/_api'
import FreshStartButton from '../components/FreshStartButton'

const StaffGate = lazyRetry(() => import('../pages/StaffGate'))
const OrderDetailStaff = lazyRetry(() => import('../pages/OrderDetailStaff'))
const OperationsRoom = lazyRetry(() => import('../pages/OperationsRoom'))
const OrdersBoard = lazyRetry(() => import('../pages/OrdersBoard'))
const GrowthSettings = lazyRetry(() => import('../pages/GrowthSettings'))
const TaskSchedule = lazyRetry(() => import('../pages/TaskSchedule'))
const TechRadar = lazyRetry(() => import('../pages/TechRadar'))
const FieldMap = lazyRetry(() => import('../pages/FieldMap'))
const HandoffDetail = lazyRetry(() => import('../pages/HandoffDetail'))
const AiSettings = lazyRetry(() => import('../pages/AiSettings'))
const PricingRules = lazyRetry(() => import('../pages/PricingRules'))
const AcAdmin = lazyRetry(() => import('../pages/AcAdmin'))
const UsedAcAdmin = lazyRetry(() => import('../pages/UsedAcAdmin'))
const ScheduleAdmin = lazyRetry(() => import('../pages/ScheduleAdmin'))
const Inventory = lazyRetry(() => import('../pages/Inventory'))
const Accounts = lazyRetry(() => import('../pages/Accounts'))
const BusinessSettings = lazyRetry(() => import('../pages/BusinessSettings'))
const SecuritySettings = lazyRetry(() => import('../pages/SecuritySettings'))
const ThemeSettings = lazyRetry(() => import('../pages/ThemeSettings'))
const PayLinks = lazyRetry(() => import('../pages/PayLinks'))
const AiCommandCenter = lazyRetry(() => import('../pages/AiCommandCenter'))
const ControlAssistant = lazyRetry(() => import('../pages/ControlAssistant'))
const OpsManager = lazyRetry(() => import('../pages/OpsManager'))
const AdminHub = lazyRetry(() => import('../pages/AdminHub'))
// 2026-11-22: these owner pages were linked from the menu and the admin hub but had no route in the desk, so the
// click fell through to «*» and bounced back home — the «button that opens nothing».
const AdminList = lazyRetry(() => import('../pages/AdminList'))
const TechStatement = lazyRetry(() => import('../pages/TechStatement'))
const PayAccounts = lazyRetry(() => import('../pages/client/PayAccounts'))
const SourceFinder = lazyRetry(() => import('../pages/SourceFinder'))
const OwnerControls = lazyRetry(() => import('../pages/OwnerControls'))
const NumbersCampaigns = lazyRetry(() => import('../pages/NumbersCampaigns'))
const Reports = lazyRetry(() => import('../pages/Reports'))
const PaymentMethods = lazyRetry(() => import('../pages/PaymentMethods'))
const AiOwnerControl = lazyRetry(() => import('../pages/AiOwnerControl'))
const AiTrainer = lazyRetry(() => import('../pages/AiTrainer'))
const AppsHub = lazyRetry(() => import('../pages/AppsHub'))
const NetworkSites = lazyRetry(() => import('../pages/NetworkSites'))
const WaHub = lazyRetry(() => import('../pages/WaHub'))
const SalesBrain = lazyRetry(() => import('../pages/SalesBrain'))
const Warranties = lazyRetry(() => import('../pages/Warranties'))
const TechRequests = lazyRetry(() => import('../pages/TechRequests'))
const NotifySettings = lazyRetry(() => import('../pages/NotifySettings'))
const WpHub = lazyRetry(() => import('../pages/WpHub'))
const HotlineLink = lazyRetry(() => import('../pages/HotlineLink'))
const Backups = lazyRetry(() => import('../pages/Backups'))

const BRANDS = {
  oxygen: { name: 'المركز', host: 'https://oxygen11.com', accent: '#38bdf8' },
  mutqan: { name: 'المركز', host: 'https://oxygen11.com', accent: '#12a39a' },   // same install as the hub: one address for every staff call
  tech: { name: 'المركز', host: 'https://oxygen11.com', accent: '#12a39a' },     // the technicians' app: same site, its own session store
}
// ONE app = ONE site (fixed by the page it was opened from): the operations app is Mutqan, the control app is Oxygen. There is no switching.
const KIND = window.__DESK_KIND__ || 'control'
const brand = window.__DESK_BRAND__ || (KIND === 'ops' ? 'mutqan' : KIND === 'tech' ? 'tech' : 'oxygen')
const B = BRANDS[brand]
const APP_NAME = KIND === 'ops' ? 'غرفة العمليات' : KIND === 'tech' ? 'تطبيق الفني' : 'تطبيق المالك'
const HOME = KIND === 'ops' ? '/staff/operations' : KIND === 'wa' ? '/staff/wa-hub' : '/staff'
// Owner lands where this app is for; everyone else on /staff, which shows each role its own home (operations room / technician radar).
const homeFor = (role) => (role === 'owner' || KIND === 'wa' ? HOME : '/staff')
const STAFF_ROLES = ['owner', 'operations', 'supervisor', 'technician']
const BUILD = window.__DESK_BUILD__ || 'dev'   // stamped by the build; shown on the entry / diagnostics screens so a screenshot tells which version is running
const API = `${B.host}/wp-json/mutqan/v1`

// The PIN is exchanged for an owner session of THIS site; it is kept on this device only, and a session the server forgets is renewed silently.
const PIN_KEY = 'desk_pin_' + brand
const ls = { get: (k) => { try { return localStorage.getItem(k) } catch { return null } }, set: (k, v) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } }, del: (k) => { try { localStorage.removeItem(k) } catch { /* ignore */ } } }
// Same rule as the server: digits of any script → 0-9, no spaces / invisible direction marks (an Arabic keyboard types ٧٨٩ where a Western one types 789).
const foldPin = (s) => String(s || '').replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0)).replace(/[\s​-‏‪-‮⁦-⁩﻿ ]+/g, '')
const savedPin = () => ls.get(PIN_KEY) || ''
const storedSession = () => { try { const s = JSON.parse(ls.get('desk_sess_' + brand) || 'null'); return s && s.token ? s : null } catch { return null } }

// Tell the site what this device sees (no PIN, no token): the owner can read it in wp-admin, so a failure is never a guess. Best effort, throttled.
const sentAt = {}
function report(step, info) {
  const k = step; if (sentAt[k] && Date.now() - sentAt[k] < 30000) return; sentAt[k] = Date.now()
  try {
    const ctl = new AbortController(); setTimeout(() => ctl.abort(), 8000)
    fetch(`${API}/desk`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'diag', app: KIND, build: BUILD, step, info: String(info || '').slice(0, 200), ua: (navigator.userAgent || '').slice(0, 160) }) }).catch(() => {})
  } catch { /* ignore */ }
}

class DeskErr extends Error { constructor(kind, message) { super(message); this.kind = kind } }
// A reason a person can act on (what the server said, in plain words, and where to fix it).
function explain(kind, raw) {
  const n = B.name
  if (kind === 'net') return `تعذّر الوصول إلى موقع ${n} (${B.host.replace('https://', '')}) — تأكد من الإنترنت ثم أعد المحاولة.`
  if (kind === 'pin') return `موقع ${n} رفض الرمز: الرمز المضبوط على ${n} يختلف عمّا كتبته. افتح ووردبريس ${n} ← أكسجين ← المكتب السري، اضبط الرمز ثم جرّبه بزر «اختبار».`
  if (kind === 'unset') return `لم يُضبط رمز دخول على موقع ${n} بعد — اضبطه من ووردبريس ${n} ← أكسجين ← المكتب السري.`
  if (kind === 'limit') return `موقع ${n} أوقف المحاولات مؤقتًا لكثرة المحاولات الخاطئة — انتظر ١٥ دقيقة ثم أعد المحاولة.`
  if (kind === 'role') return `موقع ${n} لم يُرجع حساب المالك (الدور: ${raw}) — حدّث الإضافة على ${n}.`
  return `موقع ${n}: ${raw || 'تعذّر الدخول'}`
}
// Exchanges the PIN for an owner session of this site (stored as desk_sess_<brand>). Throws DeskErr.
async function enter(pin) {
  let r, j
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 20000)
  try {
    r = await fetch(`${API}/auth`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'desk', token: pin, app: KIND, build: BUILD }) })
    j = await r.json().catch(() => ({}))
  } catch { throw new DeskErr('net', explain('net')) } finally { clearTimeout(timer) }
  const d = (j && j.data) || j || {}
  if (!r.ok || !d.token) {
    const msg = String((j && j.error && (j.error.message || j.error)) || (j && j.message) || '')
    const code = j && j.error && j.error.code
    const kind = r.status === 429 || code === 'rate_limited' ? 'limit' : /لم يُضبط/.test(msg) ? 'unset' : /الرمز غير صحيح/.test(msg) ? 'pin' : 'other'
    throw new DeskErr(kind, explain(kind, kind === 'other' ? (msg || 'HTTP ' + r.status) : msg))
  }
  if (!d.user || d.user.role !== 'owner') throw new DeskErr('role', explain('role', d.user && d.user.role || 'غير معروف'))
  ls.set('desk_sess_' + brand, JSON.stringify({ token: d.token, user: d.user || null }))
  return d
}
// Operations (shared password + own name) and technicians (own phone + password) — same session store as the owner's PIN.
async function signIn(path, body) {
  let r, j
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 20000)
  try {
    r = await fetch(`${API}/${path}`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    j = await r.json().catch(() => ({}))
  } catch { throw new DeskErr('net', explain('net')) } finally { clearTimeout(timer) }
  const d = (j && j.data) || j || {}
  if (!r.ok || !d.token) {
    const msg = String((j && j.error && (j.error.message || j.error)) || (j && j.message) || '')
    throw new DeskErr(r.status === 429 ? 'limit' : 'other', msg || 'تعذّر الدخول (HTTP ' + r.status + ')')
  }
  if (!d.user || !STAFF_ROLES.includes(d.user.role)) throw new DeskErr('role', 'هذا الحساب ليس ضمن فريق العمل.')
  ls.set('desk_sess_' + brand, JSON.stringify({ token: d.token, user: d.user }))
  return d
}
// This phone's id for one-device activation links: random, made once, kept on the device only.
function deviceId() {
  let d = ls.get('mq_device_id')
  if (!d || !/^[A-Za-z0-9_-]{16,80}$/.test(d)) {
    const a = new Uint8Array(16); (window.crypto || {}).getRandomValues ? window.crypto.getRandomValues(a) : a.forEach((_, i) => { a[i] = Math.random() * 256 })
    d = Array.from(a, (b) => b.toString(16).padStart(2, '0')).join(''); ls.set('mq_device_id', d)
  }
  return d
}
// Activation link / code → a session for the person the owner named, on THIS device only.
async function activate(body) {
  const d = await signIn('devices', { op: 'activate', deviceId: deviceId(), ua: navigator.userAgent || '', ...body })
  ls.set('desk_door', 'code')
  return d
}

// Re-issues the session from the saved PIN. Never asks the server again within a minute for a PIN it already refused. Resolves { ok, err }.
let lastFail = null
async function reauth() {
  const pin = savedPin(); if (!pin) return { ok: false, err: '' }
  // A PIN on this device renews only an owner's session: an operations or technician session that ended goes back to the door, never up to owner.
  const was = storedSession(); if (was && was.user && was.user.role && was.user.role !== 'owner') return { ok: false, err: '' }
  if (lastFail && lastFail.kind !== 'net' && Date.now() - lastFail.at < 60000) return { ok: false, err: lastFail.err }
  try { const d = await enter(pin); lastFail = null; setSession(d.token, d.user); return { ok: true, err: '' } }
  catch (e) { lastFail = { at: Date.now(), kind: e.kind, err: e.message }; report('reauth_' + e.kind, e.message); return { ok: false, err: e.message } }
}
// The shared API layer (send/verifySession) calls this when the server stops recognising the session; it must resolve to a boolean.
if (typeof window !== 'undefined') window.__DESK_REAUTH__ = async () => (await reauth()).ok
// Asks the site what a token is: 'ok' (owner), 'bad' (unknown / not the owner) or 'offline' (cannot tell → keep working).
async function verify(t) {
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 9000)
  try {
    const r = await fetch(`${API}/auth`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json', 'X-Mutqan-Token': t }, body: JSON.stringify({ op: 'me' }) })
    // A 401 is the site saying it does not know this session — not «offline». Treating it as offline kept a dead
    // session on screen: pages opened, but every save came back «forbidden» and WhatsApp said «سجّل الدخول».
    if (r.status === 401 || r.status === 403) return 'bad'
    const j = await r.json(); if (!(r.ok && j && j.data)) return 'offline'
    const u = j.data.user; return u && STAFF_ROLES.includes(u.role) && u.status === 'active' ? 'ok' : 'bad'
  } catch { return 'offline' } finally { clearTimeout(timer) }
}
const wipe = () => { ls.del('desk_sess_' + brand); ls.del(brand + ':mutqan_token'); ls.del(brand + ':mutqan_user') }

// A running app notices a newer build (published as desk-version.json) and reloads itself: nobody has to clear caches or reinstall.
async function checkBuild() {
  try {
    const r = await fetch('./desk-version.json?t=' + Date.now(), { cache: 'no-store' }); const j = await r.json()
    if (j && j.v && window.__DESK_BUILD__ && j.v !== window.__DESK_BUILD__) {
      const u = new URL(location.href); if (u.searchParams.get('v') !== j.v) { u.searchParams.set('v', j.v); location.replace(u.toString()) }
    }
  } catch { /* offline */ }
}

function Center({ children }) { return <div dir="rtl" className="h-full min-h-screen flex items-center justify-center p-6 text-center text-white bg-[#0b1220] text-sm leading-loose">{children}</div> }

function Badge() {
  return <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-black text-black" style={{ background: B.accent }}>{APP_NAME} · شبكة المواقع</span>
}

// No session on this device yet. Three doors, one app: operations (shared password + your name), technician (your own account), owner (the PIN).
const DOORS = KIND === 'tech'
  ? [{ id: 'code', label: 'كود التفعيل' }, { id: 'tech', label: 'دخول بكلمة السر' }]
  : [{ id: 'ops', label: 'العمليات' }, { id: 'tech', label: 'فني' }, { id: 'owner', label: 'المالك' }, { id: 'code', label: 'كود تفعيل' }]
const field = 'w-full rounded-xl bg-white/10 border border-white/15 p-3.5 text-center text-base text-white outline-none'
function Gate({ initialErr = '' }) {
  const [door, setDoor] = useState(() => { const d = ls.get('desk_door'); return DOORS.some((x) => x.id === d) ? d : DOORS[0].id === 'code' ? 'code' : (KIND === 'ops' ? 'ops' : 'owner') })
  const [v, setV] = useState('')
  const [name, setName] = useState(() => ls.get('desk_ops_name') || '')
  const [handle, setHandle] = useState('')
  const [err, setErr] = useState(initialErr)
  const [busy, setBusy] = useState(false)
  const ready = door === 'owner' ? v.trim() : door === 'code' ? foldPin(v).length === 6 : door === 'ops' ? v && name.trim().length >= 2 : v && handle.trim()
  async function go() {
    if (!ready || busy) return
    setBusy(true); setErr('')
    try {
      let d
      if (door === 'owner') { const pin = foldPin(v); d = await enter(pin); ls.set(PIN_KEY, pin) }
      else if (door === 'code') { d = await activate({ code: foldPin(v) }) }
      else if (door === 'ops') { d = await signIn('auth', { op: 'ops', name: name.trim(), password: v }); ls.set('desk_ops_name', name.trim()) }
      else d = await signIn('team', { op: 'login', handle: foldPin(handle), password: v })
      ls.set('desk_door', door)
      setSession(d.token, d.user); location.hash = '#' + homeFor(d.user.role); location.reload()
    } catch (e) { setErr(e.message); report('gate_' + door + '_' + e.kind, e.message); setBusy(false) }
  }
  const onKey = (e) => e.key === 'Enter' && go()
  const hint = door === 'code' ? 'اكتب كود التفعيل (6 أرقام) اللي وصلك من المالك على واتساب. الكود بيفعّل الجهاز ده بس.'
    : door === 'owner' ? `اكتب الرمز السري لموقع ${B.name} مرة واحدة على هذا الجهاز.`
    : door === 'ops' ? 'كلمة سر العمليات الموحّدة + اسمك. اسمك بيظهر على كل إجراء تعمله.'
    : 'رقم جوالك (أو اسم المستخدم) وكلمة المرور اللي أعطاها لك المكتب.'
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm">
        <div className="text-center mb-4"><Badge /></div>
        <p className="text-xl font-black text-center mb-3">🔒 دخول فريق العمل</p>
        <div role="tablist" className={`grid ${DOORS.length === 4 ? 'grid-cols-4' : DOORS.length === 2 ? 'grid-cols-2' : 'grid-cols-3'} gap-1 rounded-xl bg-white/5 p-1 mb-3`}>
          {DOORS.map((d) => (
            <button key={d.id} role="tab" aria-selected={door === d.id} onClick={() => { setDoor(d.id); setV(''); setErr('') }} className={'rounded-lg py-2 text-sm font-black ' + (door === d.id ? 'text-black' : 'text-white/70')} style={door === d.id ? { background: B.accent } : undefined}>{d.label}</button>
          ))}
        </div>
        <p className="text-xs text-white/60 text-center mb-3 leading-relaxed">{hint}</p>
        <div className="grid gap-2">
          {door === 'ops' && <input aria-label="اسمك" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} onKeyDown={onKey} placeholder="اسمك" className={field} />}
          {door === 'tech' && <input aria-label="رقم الجوال أو اسم المستخدم" dir="ltr" inputMode="tel" autoComplete="username" value={handle} onChange={(e) => setHandle(e.target.value)} onKeyDown={onKey} placeholder="05xxxxxxxx" className={field} />}
          <input aria-label={door === 'owner' ? 'الرمز السري' : door === 'code' ? 'كود التفعيل' : 'كلمة السر'} type={door === 'code' ? 'text' : 'password'} inputMode={door === 'owner' || door === 'code' ? 'numeric' : undefined} autoComplete={door === 'tech' ? 'current-password' : 'off'} value={v} onChange={(e) => setV(e.target.value)} onKeyDown={onKey} dir="ltr" placeholder={door === 'owner' ? 'الرمز السري' : door === 'code' ? '— — — — — —' : door === 'ops' ? 'كلمة سر العمليات' : 'كلمة المرور'} className={field + (door === 'owner' || door === 'code' ? ' tracking-widest' : '')} />
        </div>
        {err && <p role="alert" className="text-rose-300 text-xs mt-2 leading-relaxed whitespace-pre-line">{err}</p>}
        <button disabled={busy || !ready} onClick={go} className="w-full mt-3 rounded-xl font-black py-3 text-black disabled:opacity-50" style={{ background: B.accent }}>{busy ? 'جارِ التحقق…' : 'دخول'}</button>
        <p className="text-[10px] text-white/30 text-center mt-5">إصدار {BUILD} · <a className="underline" href="#/diag">تشخيص</a></p>
        <div className="flex justify-center mt-2"><FreshStartButton signedIn={false} className="text-[11px] text-white/50 underline flex items-center gap-1" /></div>
      </div>
    </div>
  )
}

// #/activate/<token> — the page the owner's WhatsApp link opens: who it is for, the Android app to download,
// and «فعّل هنا» for using it straight from the browser. Inside the installed app the person types the code instead.
function Activate({ token }) {
  const [info, setInfo] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const inApp = /\bwv\b|Capacitor/i.test(navigator.userAgent || '') || !!window.Capacitor
  useEffect(() => {
    fetch(`${API}/devices`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'peek', token }) })
      .then((r) => r.json()).then((j) => { if (j && j.data) setInfo(j.data); else setErr((j && j.error && j.error.message) || 'الرابط غير صحيح.') })
      .catch(() => setErr(explain('net')))
  }, [token])
  const go = async () => {
    setBusy(true); setErr('')
    try { const d = await activate({ token }); setSession(d.token, d.user); location.hash = '#' + homeFor(d.user.role); location.reload() }
    catch (e) { setErr(e.message); setBusy(false) }
  }
  const st = info && info.status
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm grid gap-3 text-center">
        <div><Badge /></div>
        {!info && !err && <p className="text-sm text-white/70">جارِ فتح الرابط…</p>}
        {info && <>
          <p className="text-xl font-black">أهلاً {info.name} 👋</p>
          <p className="text-sm text-white/70">{info.appLabel} — {info.role}</p>
          {st === 'pending' && <>
            {!inApp && <a href={info.apk} download className="rounded-xl font-black py-3 text-black" style={{ background: B.accent }}>⬇ تنزيل التطبيق (أندرويد)</a>}
            <div className="rounded-2xl bg-white/5 border border-white/10 p-3 text-sm leading-loose">
              <p>بعد التثبيت افتح التطبيق واختار «كود التفعيل» واكتب:</p>
              <p dir="ltr" className="text-3xl font-black tracking-[0.3em] my-1" style={{ color: B.accent }}>{info.code}</p>
              <p className="text-[11px] text-white/50">الكود لجهاز واحد بس، وأول جهاز يفعّله هو اللي هيشتغل.</p>
            </div>
            <button onClick={go} disabled={busy} className="rounded-xl bg-white/10 border border-white/15 py-3 text-sm font-black disabled:opacity-50">{busy ? 'جارِ التفعيل…' : inApp ? 'فعّل التطبيق على الجهاز ده' : 'أو استخدمه من المتصفح على الجهاز ده'}</button>
            {!inApp && <p className="text-[11px] text-white/50 leading-relaxed">لو التنزيل اتمنع: الإعدادات ← «تثبيت تطبيقات غير معروفة» ← اسمح لكروم. افتح الرابط من كروم مش من جوه واتساب.</p>}
          </>}
          {st === 'active' && <><p className="text-sm text-emerald-300">الرابط ده متفعّل على جهاز.</p><button onClick={go} disabled={busy} className="rounded-xl bg-white/10 py-3 text-sm font-black">لو ده نفس الجهاز: ادخل</button></>}
          {(st === 'revoked' || st === 'expired') && <p className="text-sm text-rose-300">{st === 'revoked' ? 'الرابط ده اتلغى.' : 'الرابط ده انتهت صلاحيته.'} اطلب رابط جديد من المالك.</p>}
        </>}
        {err && <p role="alert" className="text-rose-300 text-xs leading-relaxed">{err}</p>}
      </div>
    </div>
  )
}
// «واتساب الإدارة»: three doors always in reach — the conversations, the AI, the numbers and the server.
function WaDock() {
  const items = [['/staff/wa-hub', '💬', 'مركز الواتساب'], ['/staff/assistant', '🤖', 'الذكاء الاصطناعي']]
  return (
    <nav dir="rtl" className="fixed z-[60] left-1/2 -translate-x-1/2 flex gap-1 rounded-2xl bg-[#075e54]/95 backdrop-blur px-1.5 py-1.5 shadow-2xl border border-white/15" style={{ bottom: 'calc(var(--sab, 0px) + 10px)' }}>
      {items.map(([to, ic, label]) => (
        <NavLink key={to} to={to} className={({ isActive }) => 'flex items-center gap-1 rounded-xl px-3 py-1.5 text-[12px] font-black ' + (isActive ? 'bg-white text-[#075e54]' : 'text-white/90')}>
          <span>{ic}</span><span>{label}</span>
        </NavLink>
      ))}
    </nav>
  )
}

function ActivateRoute() { const { token } = useParams(); return <Activate token={token} /> }

// #/diag — shows exactly what the site says about this device (role, status) and whether the saved PIN is accepted, so a permission problem is never a guess.
function Diag() {
  const [lines, setLines] = useState(['جارِ الفحص…'])
  const [pinRes, setPinRes] = useState(null)
  useEffect(() => {
    (async () => {
      const out = [`التطبيق: ${B.name} — ${APP_NAME}`, `الإصدار: ${BUILD}`, `الموقع: ${B.host}`]
      const s = storedSession(); const p = savedPin()
      out.push(`جلسة محفوظة: ${s ? 'نعم' : 'لا'} — رمز محفوظ: ${p ? 'نعم' : 'لا'}`)
      const t0 = Date.now()
      try {
        const r = await fetch(`${API}/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(s ? { 'X-Mutqan-Token': s.token } : {}) }, body: JSON.stringify({ op: 'me' }) })
        const j = await r.json().catch(() => ({})); const u = j.data && j.data.user
        out.push(`اتصال بالموقع: ✅ (${Date.now() - t0}ms — HTTP ${r.status})`, u ? `الدور: ${u.role || 'بلا دور'} — الحالة: ${u.status}` : 'الموقع لا يعرف هذه الجلسة', u && STAFF_ROLES.includes(u.role) ? `✅ مسجّل ضمن الفريق (${u.displayName || u.role}).` : '❌ لا توجد جلسة فريق — اضغط «إعادة تسجيل الجهاز» وادخل من جديد.')
        report('diag_open', `me:${r.status}:${u ? u.role : 'none'}`)
      } catch (e) { out.push('❌ تعذر الاتصال بالموقع: ' + e.message) }
      setLines(out)
    })()
  }, [])
  const testPin = async () => {
    const p = savedPin()
    if (!p) { setPinRes({ ok: false, msg: `لا يوجد رمز محفوظ على هذا الجهاز لموقع ${B.name}.` }); return }
    try { await enter(p); setPinRes({ ok: true }) } catch (e) { setPinRes({ ok: false, msg: e.message }) }
  }
  const reset = () => { wipe(); ls.del(PIN_KEY); ls.del('desk_pin'); location.hash = '#/'; location.reload() }
  return (
    <div dir="rtl" className="min-h-screen p-5 bg-[#0b1220] text-white">
      <pre className="whitespace-pre-wrap text-sm leading-loose">{lines.join('\n')}</pre>
      <div className="mt-4 flex flex-wrap gap-2">
        <button className="rounded-xl text-black px-4 py-3 text-sm font-black" style={{ background: B.accent }} onClick={testPin}>اختبار الرمز المحفوظ</button>
        <button className="rounded-xl bg-white/10 px-4 py-3 text-sm font-black" onClick={reset}>إعادة تسجيل الجهاز</button>
        <FreshStartButton className="rounded-xl bg-white/10 px-4 py-3 text-sm font-black flex items-center gap-2" />
        <button className="rounded-xl bg-white/10 px-4 py-3 text-sm font-black" onClick={() => { location.hash = '#/'; location.reload() }}>رجوع للتطبيق</button>
      </div>
      {pinRes && <div className={'mt-3 rounded-xl border p-3 text-xs leading-relaxed ' + (pinRes.ok ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-100' : 'border-rose-400/40 bg-rose-400/10 text-rose-100')}>{pinRes.ok ? `✅ ${B.name} — تم الدخول.` : `❌ ${pinRes.msg}`}</div>}
    </div>
  )
}

export default function DeskApp() {
  // checking → ok | gate. Owner screens are only ever rendered for a session the SERVER confirmed (a stale token used to show owner pages whose saves were then refused).
  const [phase, setPhase] = useState(() => (getToken() || savedPin() ? 'checking' : 'gate'))
  const [gateErr, setGateErr] = useState('')
  useEffect(() => {
    let alive = true
    const run = async (first) => {
      if (location.hash.startsWith('#/diag') || location.hash.startsWith('#/activate/')) { if (alive && first) setPhase('ok'); return }
      const t = getToken()
      if (t) { const v = await verify(t); if (v !== 'bad') { if (alive) setPhase('ok'); return } }
      const r = await reauth()
      if (!alive) return
      if (r.ok) { setPhase('ok'); return }
      wipe(); setGateErr(r.err); setPhase('gate')
    }
    run(true)
    checkBuild()
    const onVis = () => { if (document.visibilityState === 'visible') { checkBuild(); if (getToken()) run(false) } }
    document.addEventListener('visibilitychange', onVis)
    const tick = setInterval(checkBuild, 5 * 60 * 1000)
    // The shared auth layer signed the session out (server answered "no user"): renew from the PIN or go back to the Gate.
    const off = onUser((u) => { if (!u) run(false) })
    return () => { alive = false; clearInterval(tick); document.removeEventListener('visibilitychange', onVis); off() }
  }, [])
  const signed = phase === 'ok'
  if (phase === 'checking') return <Center>جارِ التحقق من الجلسة…</Center>
  return (
    <HashRouter>
      <SwipeToClose />
      <PushToaster />
      {KIND === 'wa' && signed && <WaDock />}
      <ThemeBoot />
      <Suspense fallback={<Center>جارِ التحميل…</Center>}>
        <Routes>
          <Route path="/diag" element={<Diag />} />
          <Route path="/activate/:token" element={<ActivateRoute />} />
          {!signed ? <Route path="*" element={<Gate initialErr={gateErr} />} /> : (
            <Route element={<StaffLayout />}>
              <Route path="/" element={<Navigate to={HOME} replace />} />
              <Route path="/notifications" element={<Notifications />} />
              <Route path="/staff" element={<StaffGate />} />
              <Route path="/staff/order/:id" element={<OrderDetailStaff />} />
              <Route path="/staff/operations" element={<OperationsRoom />} />
              <Route path="/staff/orders-board" element={<OrdersBoard />} />
              <Route path="/staff/growth" element={<GrowthSettings />} />
              <Route path="/staff/tasks" element={<TaskSchedule />} />
              <Route path="/staff/radar" element={<TechRadar />} />
              <Route path="/staff/map" element={<FieldMap />} />
              <Route path="/staff/handoff/:id" element={<HandoffDetail />} />
              <Route path="/staff/ai-settings" element={<AiSettings />} />
              <Route path="/staff/pricing" element={<PricingRules />} />
              <Route path="/staff/ac" element={<AcAdmin />} />
              <Route path="/staff/used-ac" element={<UsedAcAdmin />} />
              <Route path="/staff/schedule" element={<ScheduleAdmin />} />
              <Route path="/staff/business" element={<BusinessSettings />} />
              <Route path="/staff/contact" element={<BusinessSettings focus="contact" />} />
              <Route path="/staff/security" element={<SecuritySettings />} />
              <Route path="/staff/theme" element={<ThemeSettings staff />} />
              <Route path="/staff/pay-links" element={<PayLinks />} />
              <Route path="/staff/ai-command" element={<AiCommandCenter />} />
              <Route path="/staff/assistant" element={<ControlAssistant />} />
              <Route path="/staff/ops-manager" element={<OpsManager />} />
              <Route path="/staff/apps" element={<AppsHub />} />
              <Route path="/staff/network" element={<NetworkSites />} />
              <Route path="/staff/admin" element={<AdminHub />} />
              <Route path="/staff/admin/list/:kind" element={<AdminList />} />
              <Route path="/pay" element={<PayAccounts />} />
        <Route path="/staff/statement" element={<TechStatement />} />
              <Route path="/staff/sources" element={<SourceFinder />} />
              <Route path="/staff/owner-delete" element={<OwnerControls />} />
              <Route path="/staff/numbers" element={<NumbersCampaigns />} />
              <Route path="/staff/reports" element={<Reports />} />
              <Route path="/staff/inventory" element={<Inventory />} />
        <Route path="/staff/accounts" element={<Accounts />} />
        <Route path="/staff/payment-methods" element={<PaymentMethods />} />
              <Route path="/staff/ai-owner" element={<AiOwnerControl />} />
              <Route path="/staff/ai-trainer" element={<AiTrainer />} />
              <Route path="/staff/wa-hub" element={<WaHub />} />
              {["/staff/wa-inbox", "/staff/wa-fleet", "/staff/whatsapp"].map((p) => <Route key={p} path={p} element={<Navigate to="/staff/wa-hub" replace />} />)}
              <Route path="/staff/sales-brain" element={<SalesBrain />} />
              <Route path="/staff/warranties" element={<Warranties />} />
              <Route path="/staff/tech-requests" element={<TechRequests />} />
              <Route path="/staff/notify-settings" element={<NotifySettings />} />
              <Route path="/staff/wp" element={<WpHub />} />
              <Route path="/staff/link" element={<HotlineLink />} />
              <Route path="/staff/backups" element={<Backups />} />
              <Route path="*" element={<Navigate to={HOME} replace />} />
            </Route>
          )}
        </Routes>
      </Suspense>
    </HashRouter>
  )
}
