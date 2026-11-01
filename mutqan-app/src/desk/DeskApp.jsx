import { Suspense, useState, useEffect, useRef } from 'react'
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

const BRANDS = {
  oxygen: { name: 'أكسجين', host: 'https://oxygen11.com' },
  mutqan: { name: 'أكسجين', host: 'https://mutqan.oxygen11.com' },
}
const KIND = window.__DESK_KIND__ || 'control'
const brand = window.__DESK_BRAND__ || 'oxygen'
const HOME = KIND === 'ops' ? '/staff/operations' : '/staff'
const BUILD = '2026-11-01 · 2'   // shown on the entry / diagnostics screens so a screenshot tells which version is running

// ONE numeric PIN opens both sites. The PIN is exchanged for an owner session per site; it is kept on this device only (per site, so two sites with different PINs still both work), and a session the server forgets is renewed silently.
const PIN_KEY = 'desk_pin'
const ls = { get: (k) => { try { return localStorage.getItem(k) } catch { return null } }, set: (k, v) => { try { localStorage.setItem(k, v) } catch { /* ignore */ } }, del: (k) => { try { localStorage.removeItem(k) } catch { /* ignore */ } } }
// Same rule as the server: digits of any script → 0-9, no spaces / invisible direction marks (an Arabic keyboard types ٧٨٩ where a Western one types 789).
const foldPin = (s) => String(s || '').replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0)).replace(/[\s​-‏‪-‮⁦-⁩﻿ ]+/g, '')
const savedPin = (b) => ls.get(PIN_KEY + '_' + (b || brand)) || ls.get(PIN_KEY) || ''
const storedSession = (b) => { try { const s = JSON.parse(ls.get('desk_sess_' + b) || 'null'); return s && s.token ? s : null } catch { return null } }

class DeskErr extends Error { constructor(kind, message) { super(message); this.kind = kind } }
// A reason a person can act on (what the server said, in plain words, and where to fix it).
function explain(b, kind, raw) {
  const n = BRANDS[b].name
  if (kind === 'net') return `تعذّر الوصول إلى موقع ${n} — تأكد من الإنترنت ثم أعد المحاولة.`
  if (kind === 'pin') return `موقع ${n} رفض الرمز: الرمز المضبوط على ${n} يختلف عمّا كتبته. افتح ووردبريس ${n} ← أكسجين ← المكتب السري، اضبط نفس الرمز ثم جرّبه بزر «اختبار».`
  if (kind === 'unset') return `لم يُضبط رمز دخول على موقع ${n} بعد — اضبطه من ووردبريس ${n} ← أكسجين ← المكتب السري.`
  if (kind === 'limit') return `موقع ${n} أوقف المحاولات مؤقتًا لكثرة المحاولات الخاطئة — انتظر ١٥ دقيقة ثم أعد المحاولة.`
  if (kind === 'role') return `موقع ${n} لم يُرجع حساب المالك (الدور: ${raw}) — حدّث الإضافة على ${n}.`
  return `موقع ${n}: ${raw || 'تعذّر الدخول'}`
}
// Exchanges the PIN for an owner session of site b (stored as desk_sess_<b>). Throws DeskErr.
async function enter(b, pin) {
  let r, j
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 20000)
  try {
    r = await fetch(`${BRANDS[b].host}/wp-json/mutqan/v1/auth`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'desk', token: pin }) })
    j = await r.json().catch(() => ({}))
  } catch { throw new DeskErr('net', explain(b, 'net')) } finally { clearTimeout(timer) }
  const d = (j && j.data) || j || {}
  if (!r.ok || !d.token) {
    const msg = String((j && j.error && (j.error.message || j.error)) || (j && j.message) || '')
    const code = j && j.error && j.error.code
    const kind = r.status === 429 || code === 'rate_limited' ? 'limit' : /لم يُضبط/.test(msg) ? 'unset' : /الرمز غير صحيح/.test(msg) ? 'pin' : 'other'
    throw new DeskErr(kind, explain(b, kind, kind === 'other' ? (msg || 'HTTP ' + r.status) : msg))
  }
  if (!d.user || d.user.role !== 'owner') throw new DeskErr('role', explain(b, 'role', d.user && d.user.role || 'غير معروف'))
  ls.set('desk_sess_' + b, JSON.stringify({ token: d.token, user: d.user || null }))
  return d
}
// Re-issues the session of the current site from the saved PIN. Never asks the server again within a minute for a PIN it already refused. Resolves { ok, err }.
const lastFail = {}
async function reauth() {
  const pin = savedPin(); if (!pin) return { ok: false, err: '' }
  const f = lastFail[brand]
  if (f && f.kind !== 'net' && Date.now() - f.at < 60000) return { ok: false, err: f.err }
  try { const d = await enter(brand, pin); delete lastFail[brand]; setSession(d.token, d.user); return { ok: true, err: '' } }
  catch (e) { lastFail[brand] = { at: Date.now(), kind: e.kind, err: e.message }; return { ok: false, err: e.message } }
}
// The shared API layer (send/verifySession) calls this when the server stops recognising the session; it must resolve to a boolean.
if (typeof window !== 'undefined') window.__DESK_REAUTH__ = async () => (await reauth()).ok
// Asks site b what a token is: 'ok' (owner), 'bad' (unknown / not the owner) or 'offline' (cannot tell → keep working).
async function verifyAt(b, t) {
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 9000)
  try {
    const r = await fetch(`${BRANDS[b].host}/wp-json/mutqan/v1/auth`, { method: 'POST', signal: ctl.signal, headers: { 'Content-Type': 'application/json', 'X-Mutqan-Token': t }, body: JSON.stringify({ op: 'me' }) })
    const j = await r.json(); if (!(r.ok && j && j.data)) return 'offline'
    const u = j.data.user; return u && u.role === 'owner' ? 'ok' : 'bad'
  } catch { return 'offline' } finally { clearTimeout(timer) }
}
const verify = (t) => verifyAt(brand, t)
// Makes sure there is a session site b accepts (used by the brand switch). Resolves { ok } or { ok:false, err, needPin }.
async function openBrand(b, typed) {
  const pin = foldPin(typed)
  if (!pin) {
    const s = storedSession(b)
    if (s) { const v = await verifyAt(b, s.token); if (v !== 'bad') return { ok: true } }   // 'offline' = cannot tell: the app re-checks on load
  }
  const use = pin || savedPin(b)
  if (!use) return { ok: false, err: `اكتب الرمز السري لموقع ${BRANDS[b].name} (لا يوجد رمز محفوظ على هذا الجهاز).`, needPin: true }
  try { await enter(b, use); ls.set(PIN_KEY + '_' + b, use); if (!ls.get(PIN_KEY)) ls.set(PIN_KEY, use); return { ok: true } }
  catch (e) { return { ok: false, err: e.message, needPin: e.kind === 'pin' || e.kind === 'unset' || !use } }
}
const wipe = () => { ls.del('desk_sess_' + brand); ls.del('mutqan_token'); ls.del('mutqan_user') }

function Center({ children }) { return <div dir="rtl" className="h-full min-h-screen flex items-center justify-center p-6 text-center text-white bg-[#0b1220] text-sm leading-loose">{children}</div> }

// Per-site result list (used after typing the PIN, and by the brand switch): never hides why a site refused.
function Results({ res }) {
  return (
    <div className="mt-3 space-y-2 text-xs leading-relaxed">
      {Object.keys(BRANDS).filter((k) => res[k]).map((k) => (
        <div key={k} className={'rounded-xl border p-3 ' + (res[k].ok ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-100' : 'border-rose-400/40 bg-rose-400/10 text-rose-100')}>
          <b>{res[k].ok ? '✅' : '❌'} {BRANDS[k].name}</b>{res[k].ok ? ' — تم الدخول.' : <span className="block mt-1 whitespace-pre-line">{res[k].msg}</span>}
        </div>
      ))}
    </div>
  )
}

// No session on this device yet: type the PIN once (it opens both sites). Both sites are tried together and each one's answer is shown.
function Gate({ initialErr = '' }) {
  const [v, setV] = useState('')
  const [err, setErr] = useState(initialErr)
  const [busy, setBusy] = useState(false)
  const [res, setRes] = useState(null)
  const [openAny, setOpenAny] = useState(false)
  const other = brand === 'oxygen' ? 'mutqan' : 'oxygen'
  const go0 = () => { location.hash = '#' + HOME; location.reload() }
  async function go() {
    setBusy(true); setErr(''); setRes(null)
    const pin = foldPin(v)
    const keys = Object.keys(BRANDS)
    const out = await Promise.all(keys.map(async (k) => { try { await enter(k, pin); return [k, { ok: true }] } catch (e) { return [k, { ok: false, msg: e.message }] } }))
    const r = Object.fromEntries(out)
    const okKeys = keys.filter((k) => r[k].ok)
    setBusy(false)
    if (!okKeys.length) { setRes(r); return }
    ls.set(PIN_KEY, pin); okKeys.forEach((k) => ls.set(PIN_KEY + '_' + k, pin))
    ls.set('desk_brand', okKeys.includes(brand) ? brand : okKeys[0])
    if (okKeys.length === keys.length) { go0(); return }
    setRes(r); setOpenAny(true)   // one site refused: show why before moving on
  }
  const otherOpen = !!storedSession(other) && !openAny
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm">
        <p className="text-xl font-black text-center mb-1">🔒 {KIND === 'ops' ? 'غرفة العمليات' : 'التحكم والإعدادات'}</p>
        <p className="text-xs text-white/60 text-center mb-5">اكتب الرمز السري مرة واحدة — يفتح أكسجين وأكسجين معًا.</p>
        <input type="password" inputMode="numeric" autoComplete="off" value={v} onChange={(e) => setV(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && v.trim() && !busy && go()} dir="ltr" placeholder="الرمز السري" className="w-full rounded-xl bg-white/10 border border-white/15 p-4 text-center text-lg tracking-widest text-white outline-none" />
        {err && <p className="text-rose-300 text-xs mt-2 leading-relaxed whitespace-pre-line">{err}</p>}
        {res && <Results res={res} />}
        {openAny
          ? <button onClick={go0} className="w-full mt-3 rounded-xl bg-emerald-400 text-black font-black py-3">متابعة إلى {BRANDS[ls.get('desk_brand')] ? BRANDS[ls.get('desk_brand')].name : ''}</button>
          : <button disabled={busy || !v.trim()} onClick={go} className="w-full mt-3 rounded-xl bg-sky-400 text-black font-black py-3 disabled:opacity-50">{busy ? 'جارِ التحقق من الموقعين…' : 'دخول'}</button>}
        {otherOpen && <button onClick={() => { ls.set('desk_brand', other); go0() }} className="w-full mt-2 rounded-xl bg-white/10 text-white font-bold py-3 text-sm">فتح {BRANDS[other].name} (جلستك فيه مفتوحة)</button>}
        <p className="text-[10px] text-white/30 text-center mt-5">إصدار {BUILD} · للتشخيص: ‎#/diag</p>
      </div>
    </div>
  )
}

// Brand switcher (top corner, big enough to hit): checks that the other site accepts this device BEFORE moving, and says why when it does not.
function BrandBar() {
  const other = brand === 'oxygen' ? 'mutqan' : 'oxygen'
  const [st, setSt] = useState({ phase: 'idle', msg: '', needPin: false })
  const [pin, setPin] = useState('')
  const go = async (typed) => {
    setSt({ phase: 'busy', msg: '', needPin: false })
    const r = await openBrand(other, typed)
    if (r.ok) { ls.set('desk_brand', other); location.hash = '#' + HOME; location.reload(); return }
    setSt({ phase: 'fail', msg: r.err, needPin: !!r.needPin })
  }
  const goRef = useRef(go); goRef.current = go
  useEffect(() => { const h = () => goRef.current(); window.addEventListener('desk-switch', h); return () => window.removeEventListener('desk-switch', h) }, [])
  return (
    <>
      <div className="fixed z-[60] top-[calc(env(safe-area-inset-top,0px)+2px)] left-1/2 -translate-x-1/2 p-2">
        <button dir="rtl" onClick={() => st.phase !== 'busy' && go()} className="rounded-full bg-sky-400 text-black text-[12px] font-black px-4 py-[6px] shadow-lg">
          {st.phase === 'busy' ? `جارِ الانتقال إلى ${BRANDS[other].name}…` : `${BRANDS[brand].name} ⇄ ${BRANDS[other].name}`}
        </button>
      </div>
      {st.phase === 'fail' && (
        <div className="fixed inset-0 z-[300] bg-black/70 flex items-end sm:items-center justify-center p-3" onClick={() => setSt({ phase: 'idle', msg: '', needPin: false })}>
          <div dir="rtl" onClick={(e) => e.stopPropagation()} className="w-full max-w-sm rounded-3xl bg-[#0d1220] border border-white/10 p-5 text-white pb-[max(1.25rem,env(safe-area-inset-bottom))]">
            <p className="font-black mb-2">تعذّر الانتقال إلى {BRANDS[other].name}</p>
            <p className="text-sm leading-loose text-rose-200 whitespace-pre-line">{st.msg}</p>
            {st.needPin && (
              <>
                <input type="password" inputMode="numeric" autoComplete="off" dir="ltr" value={pin} onChange={(e) => setPin(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && pin.trim() && go(pin)} placeholder={`رمز موقع ${BRANDS[other].name}`} className="w-full mt-3 rounded-xl bg-white/10 border border-white/15 p-3 text-center text-lg tracking-widest text-white outline-none" />
                <button disabled={!pin.trim()} onClick={() => go(pin)} className="w-full mt-2 rounded-xl bg-sky-400 text-black font-black py-3 disabled:opacity-50">جرّب بهذا الرمز</button>
              </>
            )}
            <button onClick={() => setSt({ phase: 'idle', msg: '', needPin: false })} className="w-full mt-2 rounded-xl bg-white/10 py-3 text-sm font-bold">إغلاق</button>
          </div>
        </div>
      )}
    </>
  )
}

// #/diag — shows exactly what each site says about this device (role, status) and whether the saved PIN is accepted, so a permission problem is never a guess.
function Diag() {
  const [lines, setLines] = useState(['جارِ الفحص…'])
  const [pinRes, setPinRes] = useState(null)
  useEffect(() => {
    (async () => {
      const out = [`التطبيق: ${KIND === 'ops' ? 'غرفة العمليات' : 'التحكم'} — الإصدار ${BUILD}`, `الموقع المفتوح الآن: ${BRANDS[brand].name}`]
      for (const k of Object.keys(BRANDS)) {
        const s = storedSession(k); const p = savedPin(k)
        out.push('', `— ${BRANDS[k].name} (${BRANDS[k].host})`, `جلسة محفوظة: ${s ? 'نعم' : 'لا'} — رمز محفوظ: ${p ? 'نعم' : 'لا'}`)
        if (s) {
          try {
            const r = await fetch(`${BRANDS[k].host}/wp-json/mutqan/v1/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Mutqan-Token': s.token }, body: JSON.stringify({ op: 'me' }) })
            const j = await r.json().catch(() => ({})); const u = j.data && j.data.user
            out.push(`رد الخادم: ${r.status}`, u ? `الدور: ${u.role || 'بلا دور'} — الحالة: ${u.status}` : 'الخادم لا يعرف هذه الجلسة', u && u.role === 'owner' ? '✅ مسجّل كمالك.' : '❌ ليس مالكًا — اضغط «اختبار الرمز المحفوظ» أو «إعادة تسجيل الجهاز».')
          } catch (e) { out.push('❌ تعذر الاتصال بالموقع: ' + e.message) }
        }
      }
      setLines(out)
    })()
  }, [])
  const testPins = async () => {
    setPinRes({}); const r = {}
    await Promise.all(Object.keys(BRANDS).map(async (k) => {
      const p = savedPin(k)
      if (!p) { r[k] = { ok: false, msg: `لا يوجد رمز محفوظ لموقع ${BRANDS[k].name} على هذا الجهاز.` }; return }
      try { await enter(k, p); r[k] = { ok: true } } catch (e) { r[k] = { ok: false, msg: e.message } }
    }))
    setPinRes(r)
  }
  const reset = () => { Object.keys(BRANDS).forEach((k) => { ls.del('desk_sess_' + k); ls.del(PIN_KEY + '_' + k) }); [PIN_KEY, 'desk_brand', 'mutqan_token', 'mutqan_user'].forEach(ls.del); location.hash = '#/'; location.reload() }
  return (
    <div dir="rtl" className="min-h-screen p-5 bg-[#0b1220] text-white">
      <pre className="whitespace-pre-wrap text-sm leading-loose">{lines.join('\n')}</pre>
      <div className="mt-4 flex flex-wrap gap-2">
        <button className="rounded-xl bg-sky-400 text-black px-4 py-3 text-sm font-black" onClick={testPins}>اختبار الرمز المحفوظ على الموقعين</button>
        <button className="rounded-xl bg-white/10 px-4 py-3 text-sm font-black" onClick={reset}>إعادة تسجيل الجهاز</button>
        <button className="rounded-xl bg-white/10 px-4 py-3 text-sm font-black" onClick={() => { location.hash = '#/'; location.reload() }}>رجوع للتطبيق</button>
      </div>
      {pinRes && <Results res={pinRes} />}
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
    const onVis = () => { if (document.visibilityState === 'visible' && getToken()) run(false) }
    document.addEventListener('visibilitychange', onVis)
    // The shared auth layer signed the session out (server answered "no user"): renew from the PIN or go back to the Gate.
    const off = onUser((u) => { if (!u && !getToken()) run(false) })
    return () => { alive = false; document.removeEventListener('visibilitychange', onVis); off() }
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
            <Route element={<><BrandBar /><StaffLayout /></>}>
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
              <Route path="/staff/payment-methods" element={<PaymentMethods />} />
              <Route path="/staff/ai-owner" element={<AiOwnerControl />} />
              <Route path="/staff/whatsapp" element={<WhatsappCenter />} />
              <Route path="/staff/wa-inbox" element={<WaInbox />} />
              <Route path="/staff/warranties" element={<Warranties />} />
              <Route path="/staff/tech-requests" element={<TechRequests />} />
              <Route path="/staff/notify-settings" element={<NotifySettings />} />
              <Route path="*" element={<Navigate to={HOME} replace />} />
            </Route>
          )}
        </Routes>
      </Suspense>
    </HashRouter>
  )
}
