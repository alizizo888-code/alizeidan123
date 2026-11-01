import { Suspense, useState, useEffect } from 'react'
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom'
import { lazyRetry } from '../lib/lazy'
import StaffLayout from '../components/StaffLayout'
import PushToaster from '../components/PushToaster'
import { ThemeBoot } from '../lib/appTheme'
import Notifications from '../pages/Notifications'
import { getToken, setSession, onUser } from '../lib/_api'

const StaffGate = lazyRetry(() => import('../pages/StaffGate'))
const OrderDetailStaff = lazyRetry(() => import('../pages/OrderDetailStaff'))
const OperationsRoom = lazyRetry(() => import('../pages/OperationsRoom'))
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
const PaymentMethods = lazyRetry(() => import('../pages/PaymentMethods'))
const AiOwnerControl = lazyRetry(() => import('../pages/AiOwnerControl'))
const WhatsappCenter = lazyRetry(() => import('../pages/WhatsappCenter'))
const WaInbox = lazyRetry(() => import('../pages/WaInbox'))
const Warranties = lazyRetry(() => import('../pages/Warranties'))
const TechRequests = lazyRetry(() => import('../pages/TechRequests'))
const NotifySettings = lazyRetry(() => import('../pages/NotifySettings'))
const WpHub = lazyRetry(() => import('../pages/WpHub'))
const HotlineLink = lazyRetry(() => import('../pages/HotlineLink'))

const BRANDS = {
  oxygen: { name: 'أكسجين', host: 'https://oxygen11.com', accent: '#38bdf8' },
  mutqan: { name: 'أكسجين', host: 'https://mutqan.oxygen11.com', accent: '#12a39a' },
}
// ONE app = ONE site (fixed by the page it was opened from): the operations app is Mutqan, the control app is Oxygen. There is no switching.
const KIND = window.__DESK_KIND__ || 'control'
const brand = window.__DESK_BRAND__ || (KIND === 'ops' ? 'mutqan' : 'oxygen')
const B = BRANDS[brand]
const APP_NAME = KIND === 'ops' ? 'غرفة العمليات' : 'التحكم والإعدادات'
const HOME = KIND === 'ops' ? '/staff/operations' : '/staff'
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
// Re-issues the session from the saved PIN. Never asks the server again within a minute for a PIN it already refused. Resolves { ok, err }.
let lastFail = null
async function reauth() {
  const pin = savedPin(); if (!pin) return { ok: false, err: '' }
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
    const j = await r.json(); if (!(r.ok && j && j.data)) return 'offline'
    const u = j.data.user; return u && u.role === 'owner' ? 'ok' : 'bad'
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
  return <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-black text-black" style={{ background: B.accent }}>{B.name} · {APP_NAME}</span>
}

// No session on this device yet: type the PIN once.
function Gate({ initialErr = '' }) {
  const [v, setV] = useState('')
  const [err, setErr] = useState(initialErr)
  const [busy, setBusy] = useState(false)
  async function go() {
    setBusy(true); setErr('')
    const pin = foldPin(v)
    try { const d = await enter(pin); ls.set(PIN_KEY, pin); setSession(d.token, d.user); location.hash = '#' + HOME; location.reload() }
    catch (e) { setErr(e.message); report('gate_' + e.kind, e.message); setBusy(false) }
  }
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm">
        <div className="text-center mb-4"><Badge /></div>
        <p className="text-xl font-black text-center mb-1">🔒 {APP_NAME}</p>
        <p className="text-xs text-white/60 text-center mb-5">اكتب الرمز السري لموقع {B.name} مرة واحدة على هذا الجهاز.</p>
        <input type="password" inputMode="numeric" autoComplete="off" value={v} onChange={(e) => setV(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && v.trim() && !busy && go()} dir="ltr" placeholder="الرمز السري" className="w-full rounded-xl bg-white/10 border border-white/15 p-4 text-center text-lg tracking-widest text-white outline-none" />
        {err && <p className="text-rose-300 text-xs mt-2 leading-relaxed whitespace-pre-line">{err}</p>}
        <button disabled={busy || !v.trim()} onClick={go} className="w-full mt-3 rounded-xl font-black py-3 text-black disabled:opacity-50" style={{ background: B.accent }}>{busy ? `جارِ التحقق من موقع ${B.name}…` : 'دخول'}</button>
        <p className="text-[10px] text-white/30 text-center mt-5">إصدار {BUILD} · <a className="underline" href="#/diag">تشخيص</a></p>
      </div>
    </div>
  )
}

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
        out.push(`اتصال بالموقع: ✅ (${Date.now() - t0}ms — HTTP ${r.status})`, u ? `الدور: ${u.role || 'بلا دور'} — الحالة: ${u.status}` : 'الموقع لا يعرف هذه الجلسة', u && u.role === 'owner' ? '✅ مسجّل كمالك.' : '❌ ليس مالكًا — اضغط «اختبار الرمز المحفوظ» أو «إعادة تسجيل الجهاز».')
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
      if (location.hash.startsWith('#/diag')) { if (alive && first) setPhase('ok'); return }
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
    const off = onUser((u) => { if (!u && !getToken()) run(false) })
    return () => { alive = false; clearInterval(tick); document.removeEventListener('visibilitychange', onVis); off() }
  }, [])
  const signed = phase === 'ok'
  if (phase === 'checking') return <Center>جارِ التحقق من الجلسة…</Center>
  return (
    <HashRouter>
      <PushToaster />
      <ThemeBoot />
      <Suspense fallback={<Center>جارِ التحميل…</Center>}>
        <Routes>
          <Route path="/diag" element={<Diag />} />
          {!signed ? <Route path="*" element={<Gate initialErr={gateErr} />} /> : (
            <Route element={<StaffLayout />}>
              <Route path="/" element={<Navigate to={HOME} replace />} />
              <Route path="/notifications" element={<Notifications />} />
              <Route path="/staff" element={<StaffGate />} />
              <Route path="/staff/order/:id" element={<OrderDetailStaff />} />
              <Route path="/staff/operations" element={<OperationsRoom />} />
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
              <Route path="/staff/inventory" element={<Inventory />} />
        <Route path="/staff/accounts" element={<Accounts />} />
        <Route path="/staff/payment-methods" element={<PaymentMethods />} />
              <Route path="/staff/ai-owner" element={<AiOwnerControl />} />
              <Route path="/staff/whatsapp" element={<WhatsappCenter />} />
              <Route path="/staff/wa-inbox" element={<WaInbox />} />
              <Route path="/staff/warranties" element={<Warranties />} />
              <Route path="/staff/tech-requests" element={<TechRequests />} />
              <Route path="/staff/notify-settings" element={<NotifySettings />} />
              <Route path="/staff/wp" element={<WpHub />} />
              <Route path="/staff/link" element={<HotlineLink />} />
              <Route path="*" element={<Navigate to={HOME} replace />} />
            </Route>
          )}
        </Routes>
      </Suspense>
    </HashRouter>
  )
}
