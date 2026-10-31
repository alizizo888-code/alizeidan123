import { Suspense, useState, useEffect } from 'react'
import { HashRouter, Routes, Route, Navigate, useNavigate, useParams } from 'react-router-dom'
import { lazyRetry } from '../lib/lazy'
import StaffLayout from '../components/StaffLayout'
import PushToaster from '../components/PushToaster'
import { ThemeBoot } from '../lib/appTheme'
import Notifications from '../pages/Notifications'
import { getToken } from '../lib/_api'

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
  mutqan: { name: 'متقن', host: 'https://mutqan.oxygen11.com' },
}
const KIND = window.__DESK_KIND__ || 'control'
const brand = window.__DESK_BRAND__ || 'oxygen'
const HOME = KIND === 'ops' ? '/staff/operations' : '/staff'

// Remembers this device for the brand: the secret link is exchanged ONCE for a long owner session (180 days), stored on this device only.
async function enter(b, secret) {
  const r = await fetch(`${BRANDS[b].host}/wp-json/mutqan/v1/auth`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'desk', token: secret }) })
  const j = await r.json().catch(() => ({}))
  const d = j.data || j
  if (!r.ok || !d.token) throw new Error((j.error && (j.error.message || j.error)) || j.message || 'الرابط السري غير صحيح')
  localStorage.setItem('desk_sess_' + b, JSON.stringify({ token: d.token, user: d.user || null }))
  localStorage.setItem('desk_brand', b)
}
const parseSecret = (t) => { const s = String(t || '').trim(); const m = s.match(/oxy-desk\/[oc]\/([A-Za-z0-9_-]{20,})/); return m ? m[1] : s.replace(/[^A-Za-z0-9_-]/g, '') }

function Enter() {
  const { brand: b, secret } = useParams()
  const [msg, setMsg] = useState('جارِ تسجيل هذا الجهاز…')
  useEffect(() => {
    if (!BRANDS[b]) { setMsg('رابط غير صحيح'); return }
    enter(b, secret).then(() => { location.replace(location.pathname + location.search + '#' + HOME); location.reload() }).catch((e) => setMsg(e.message))
  }, [b, secret])
  return <Center>{msg}</Center>
}

function Center({ children }) { return <div dir="rtl" className="h-full min-h-screen flex items-center justify-center p-6 text-center text-white bg-[#0b1220] text-sm leading-loose">{children}</div> }

// No session on this device yet: paste the secret link once (or open it directly).
function Gate() {
  const [v, setV] = useState('')
  const [b, setB] = useState(brand)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  async function go() {
    setBusy(true); setErr('')
    try { await enter(b, parseSecret(v)); location.hash = '#' + HOME; location.reload() } catch (e) { setErr(e.message); setBusy(false) }
  }
  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center p-5 bg-[#0b1220] text-white">
      <div className="w-full max-w-sm">
        <p className="text-xl font-black text-center mb-1">🔒 {KIND === 'ops' ? 'غرفة العمليات' : 'التحكم والإعدادات'}</p>
        <p className="text-xs text-white/60 text-center mb-5">ألصق الرابط السري أو الرمز مرة واحدة على هذا الجهاز — بدون كلمة مرور.</p>
        <div className="flex gap-2 mb-3">{Object.entries(BRANDS).map(([k, x]) => <button key={k} onClick={() => setB(k)} className={`flex-1 rounded-xl py-2 text-sm font-black border ${b === k ? 'bg-sky-400 text-black border-sky-400' : 'border-white/20 text-white/80'}`}>{x.name}</button>)}</div>
        <textarea value={v} onChange={(e) => setV(e.target.value)} dir="ltr" rows={3} placeholder="https://…/oxy-desk/c/…" className="w-full rounded-xl bg-white/10 border border-white/15 p-3 text-sm text-white outline-none" />
        {err && <p className="text-rose-300 text-xs mt-2">{err}</p>}
        <button disabled={busy || !v.trim()} onClick={go} className="w-full mt-3 rounded-xl bg-sky-400 text-black font-black py-3 disabled:opacity-50">{busy ? 'جارِ التحقق…' : 'تسجيل هذا الجهاز'}</button>
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

export default function DeskApp() {
  const signed = !!getToken()
  return (
    <HashRouter>
      <PushToaster />
      <ThemeBoot />
      <Suspense fallback={<Center>جارِ التحميل…</Center>}>
        <Routes>
          <Route path="/enter/:brand/:secret" element={<Enter />} />
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
