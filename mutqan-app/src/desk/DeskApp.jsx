import { Suspense, useState, useEffect } from 'react'
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom'
import { lazyRetry } from '../lib/lazy'
import StaffLayout from '../components/StaffLayout'
import PushToaster from '../components/PushToaster'
import { ThemeBoot } from '../lib/appTheme'
import Notifications from '../pages/Notifications'
import { getToken, setSession } from '../lib/_api'

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

// ONE numeric PIN opens both sites. The PIN is exchanged for an owner session per site; it is kept on this device only, so a session the server forgets is renewed silently (no more "enters then exits").
const PIN_KEY = 'desk_pin'
async function enter(b, pin) {
  const r = await fetch(`${BRANDS[b].host}/wp-json/mutqan/v1/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'desk', token: pin }) })
  const j = await r.json().catch(() => ({}))
  const d = j.data || j
  if (!r.ok || !d.token) throw new Error((j.error && (j.error.message || j.error)) || j.message || 'الرمز غير صحيح')
  if (!d.user || d.user.role !== 'owner') throw new Error('الخادم لم يُرجع حساب مالك (الدور: ' + (d.user && d.user.role || 'غير معروف') + ') — حدّث الإضافة على ' + BRANDS[b].name)
  localStorage.setItem('desk_sess_' + b, JSON.stringify({ token: d.token, user: d.user || null }))
  return d
}
const savedPin = () => { try { return localStorage.getItem(PIN_KEY) || '' } catch { return '' } }
// Re-issues the session of the current site from the saved PIN. Resolves true when a fresh owner session is in place.
async function reauth() {
  const pin = savedPin(); if (!pin) return false
  try { const d = await enter(brand, pin); setSession(d.token, d.user); return true } catch { return false }
}
if (typeof window !== 'undefined') window.__DESK_REAUTH__ = reauth
const parseSecret = (t) => String(t || '').trim()

function Center({ children }) { return <div dir="rtl" className="h-full min-h-screen flex items-center justify-center p-6 text-center text-white bg-[#0b1220] text-sm leading-loose">{children}</div> }

// No session on this device yet: type the PIN once (it opens both sites).
function Gate() {
  const [v, setV] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  async function go() {
    setBusy(true); setErr('')
    const pin = parseSecret(v)
    const done = []; let firstErr = ''
    for (const k of Object.keys(BRANDS)) {
      try { await enter(k, pin); done.push(k) } catch (e) { if (!firstErr) firstErr = BRANDS[k].name + ': ' + e.message }
    }
    if (!done.length) { setErr(firstErr || 'الرمز غير صحيح'); setBusy(false); return }
    try { localStorage.setItem(PIN_KEY, pin); localStorage.setItem('desk_brand', done.includes(brand) ? brand : done[0]) } catch { /* ignore */ }
    location.hash = '#' + HOME; location.reload()
  }
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm">
        <p className="text-xl font-black text-center mb-1">🔒 {KIND === 'ops' ? 'غرفة العمليات' : 'التحكم والإعدادات'}</p>
        <p className="text-xs text-white/60 text-center mb-5">اكتب الرمز السري مرة واحدة — يفتح أكسجين وأكسجين معًا.</p>
        <input type="password" inputMode="numeric" autoComplete="off" value={v} onChange={(e) => setV(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && v.trim() && go()} dir="ltr" placeholder="الرمز السري" className="w-full rounded-xl bg-white/10 border border-white/15 p-4 text-center text-lg tracking-widest text-white outline-none" />
        {err && <p className="text-rose-300 text-xs mt-2 leading-relaxed">{err}</p>}
        <button disabled={busy || !v.trim()} onClick={go} className="w-full mt-3 rounded-xl bg-sky-400 text-black font-black py-3 disabled:opacity-50">{busy ? 'جارِ التحقق…' : 'دخول'}</button>
      </div>
    </div>
  )
}

// Brand switcher (top corner): keeps one session per brand on this device.
function BrandBar() {
  const other = brand === 'oxygen' ? 'mutqan' : 'oxygen'
  return (
    <button dir="rtl" onClick={() => { localStorage.setItem('desk_brand', other); location.hash = '#' + HOME; location.reload() }}
      className="fixed z-[60] top-[2px] left-1/2 -translate-x-1/2 rounded-full bg-sky-400 text-black text-[11px] font-black px-3 py-[2px] shadow">
      {BRANDS[brand].name} ⇄ {BRANDS[other].name}
    </button>
  )
}

// #/diag — shows exactly what the server says about this device (role, status) so a permission problem is never a guess.
function Diag() {
  const [out, setOut] = useState('جارِ الفحص…')
  useEffect(() => {
    (async () => {
      const t = getToken(); const lines = [`التطبيق: ${KIND === 'ops' ? 'غرفة العمليات' : 'التحكم'}`, `الموقع: ${BRANDS[brand].name} (${BRANDS[brand].host})`, `جلسة محفوظة: ${t ? 'نعم' : 'لا'}`]
      if (t) {
        try {
          const r = await fetch(`${BRANDS[brand].host}/wp-json/mutqan/v1/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Mutqan-Token': t }, body: JSON.stringify({ op: 'me' }) })
          const j = await r.json().catch(() => ({})); const u = j.data && j.data.user
          lines.push(`رد الخادم: ${r.status}`, u ? `الدور: ${u.role || 'بلا دور'} — الحالة: ${u.status}` : `لا يوجد مستخدم: ${(j.error && j.error.message) || (j.message) || ''}`)
          lines.push(u && u.role === 'owner' ? '✅ الجهاز مسجّل كمالك.' : '❌ ليس مالكًا — اضغط «إعادة تسجيل الجهاز» واكتب الرمز من جديد.')
        } catch (e) { lines.push('تعذر الاتصال بالموقع: ' + e.message) }
      }
      setOut(lines.join('\n'))
    })()
  }, [])
  return (
    <div dir="rtl" className="min-h-screen p-5 bg-[#0b1220] text-white">
      <pre className="whitespace-pre-wrap text-sm leading-loose">{out}</pre>
      <button className="mt-4 rounded-xl bg-white/10 px-4 py-3 text-sm font-black" onClick={() => { try { localStorage.removeItem('desk_sess_' + brand); localStorage.removeItem('mutqan_token'); localStorage.removeItem('mutqan_user') } catch { /* ignore */ } location.hash = '#/'; location.reload() }}>إعادة تسجيل الجهاز</button>
    </div>
  )
}

export default function DeskApp() {
  const signed = !!getToken()
  // No session for this site yet but a PIN is saved on this device (e.g. after switching site): sign in silently.
  useEffect(() => { if (!signed && savedPin()) reauth().then((ok) => { if (ok) location.reload() }) }, [])
  // Keep the owner session alive: if the server no longer knows it (or it is not the owner), renew it from the PIN saved on this device; only when that fails ask for the PIN again.
  useEffect(() => {
    const check = async () => {
      const t = getToken(); if (!t || location.hash.startsWith('#/diag')) return
      try {
        const r = await fetch(`${BRANDS[brand].host}/wp-json/mutqan/v1/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Mutqan-Token': t }, body: JSON.stringify({ op: 'me' }) })
        const j = await r.json(); const u = j && j.data && j.data.user
        if (!(r.ok && j && j.data) || (u && u.role === 'owner')) return
      } catch { return }                                       // offline: keep the session
      if (await reauth()) return
      try { localStorage.removeItem('desk_sess_' + brand); localStorage.removeItem('mutqan_token'); localStorage.removeItem('mutqan_user'); localStorage.removeItem(PIN_KEY) } catch { /* ignore */ }
      location.hash = '#/'; location.reload()
    }
    check()
    const onVis = () => { if (document.visibilityState === 'visible') check() }
    document.addEventListener('visibilitychange', onVis)
    return () => document.removeEventListener('visibilitychange', onVis)
  }, [])
  return (
    <HashRouter>
      <PushToaster />
      <ThemeBoot />
      <Suspense fallback={<Center>جارِ التحميل…</Center>}>
        <Routes>
          <Route path="/diag" element={<Diag />} />
          {!signed ? <Route path="*" element={<Gate />} /> : (
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
