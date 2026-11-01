import IncomingCalls from './IncomingCalls'
import OpsInstall from './OpsInstall'
import OrderAlarm from './OrderAlarm'
import SoundChip from './SoundChip'
import LinkPinger from './LinkPinger'
import { brandLabel } from '../lib/brand'
import { useState, useEffect } from 'react'
import { Outlet, NavLink, useLocation, useNavigate } from 'react-router-dom'
import { Clock, LayoutDashboard, MessageCircle, Bell, Radar, Wallet, ListChecks, Briefcase, Layers, X, Map as MapIcon, Menu, ChevronDown, Settings2, Bot, Tags, Snowflake, ShieldCheck, Phone, UserPlus, Smartphone, Home, ShoppingBag, Palette, Globe, Link2 } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { auth } from '../lib/auth'
import { passkey } from '../lib/passkey'
import { useStaffTheme } from '../lib/theme'

const NAV = {
  owner: [
    ['/staff', 'الرئيسية', LayoutDashboard, true],
    ['/staff/operations', 'العمليات', Briefcase],
    ['/staff/map', 'الخريطة', MapIcon],
    ['/staff/wa-inbox', 'المحادثات', MessageCircle, false, 'wa'],
    ['/notifications', 'الإشعارات', Bell, false, 'bell'],
    ['/staff/wp', 'روابط ووردبريس', Globe],
    ['/staff/payment-methods', 'الدفع', Wallet],
  ],
  operations: [
    ['/staff', 'العمليات', Briefcase, true],
    ['/staff/wa-inbox', 'المحادثات', MessageCircle, false, 'wa'],
    ['/notifications', 'الإشعارات', Bell, false, 'bell'],
    ['/staff/radar', 'الرادار', Radar],
    ['/staff/map', 'الخريطة', MapIcon],
    ['/staff/payment-methods', 'الدفع', Wallet],
  ],
  technician: [
    ['/staff', 'المهام', ListChecks, true],
    ['/staff?tab=map', 'الخريطة', MapIcon],
    ['/notifications', 'الإشعارات', Bell, false, 'bell'],
  ],
}
NAV.supervisor = NAV.operations

// Owner only: jump into every workspace (operations room, supervisor board, technician radar, the customer app…) without logging out.
const WORKSPACES = [
  ['/staff', 'لوحة المالك (كل التحكمات)'],
  ['/staff/operations', 'غرفة العمليات'],
  ['/staff/map', 'الخريطة الميدانية'],
  ['/staff/tech-requests', 'مشرف الموقع — طلبات الفنيين'],
  ['/staff/radar', 'الفني — الرادار والمهام'],
  ['/staff/wa-inbox', 'محادثات الواتساب'],
  ['/staff/wp', 'روابط ووردبريس (تعديل الموقع)'],
  ['/staff/link', 'ربط الخط الساخن وتيليجرام'],
  ['/staff/payment-methods', 'طرق الدفع'],
  ['/', 'تطبيق العميل'],
]
function OwnerSwitcher() {
  const [open, setOpen] = useState(false)
  const nav = useNavigate()
  return (
    <>
      <button onClick={() => setOpen(true)} aria-label="تبديل الواجهة" className="shrink-0 w-9 h-9 rounded-full bg-primary text-black flex items-center justify-center shadow"><Layers size={17} /></button>
      {open && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center" onClick={() => setOpen(false)}>
          <div dir="rtl" onClick={(e) => e.stopPropagation()} className="w-full max-w-sm bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div className="flex items-center mb-3"><p className="flex-1 text-sm font-black text-white">دخول كل الواجهات (المالك)</p><button onClick={() => setOpen(false)} className="text-slate-400"><X size={18} /></button></div>
            <div className="grid gap-2">
              {WORKSPACES.map(([to, label]) => <button key={to} onClick={() => { setOpen(false); nav(to) }} className="text-right rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-sm font-bold text-slate-100">{label}</button>)}
            </div>
          </div>
        </div>
      )}
    </>
  )
}

// Management pages, shown inside the collapsible "إدارة" group of the side menu.
const MANAGE = [
  ['/staff/pricing', 'التسعير والخصومات', Tags],
  ['/staff/ac', 'خدمات المكيفات', Snowflake],
  ['/staff/used-ac', 'متجر المستعمل', ShoppingBag],
  ['/staff/schedule', 'نظام المواعيد', Clock],
  ['/staff/pay-links', 'روابط الدفع', Wallet],
  ['/staff/ai-command', 'مركز قيادة الذكاء', Bot],
  ['/staff/ai-settings', 'إعدادات البوت', Settings2],
  ['/staff/ai-owner', 'تحكم المالك بالبوت', Bot],
  ['/staff/link', 'ربط الخط الساخن وتيليجرام', Link2],
  ['/staff/wp', 'روابط ووردبريس', Globe],
  ['/staff/whatsapp', 'أرقام الواتساب', Smartphone],
  ['/staff/notify-settings', 'الإشعارات والرنين', Bell],
  ['/staff/warranties', 'الضمانات', ShieldCheck],
  ['/staff/tech-requests', 'طلبات الفنيين', UserPlus],
  ['/staff/payment-methods', 'طرق الدفع', Wallet],
  ['/staff/contact', 'أرقام الموقع: الهاتف والواتساب', Phone],
  ['/staff/business', 'إعدادات المنشأة والفاتورة', ShieldCheck],
  ['/staff/security', 'الأمان والدخول بالبصمة', ShieldCheck],
]

function SideMenu({ items, role, bell, unread, open, setOpen }) {
  const { pathname } = useLocation()
  const canManage = role === 'owner' || role === 'operations' || role === 'supervisor'
  const [manage, setManage] = useState(() => MANAGE.some((m) => pathname.startsWith(m[0])))
  useEffect(() => { setOpen(false) }, [pathname]) // eslint-disable-line
  const link = ([to, label, Icon, end, badge]) => (
    <NavLink key={to} to={to} end={!!end} className={({ isActive }) => `relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold ${isActive ? 'bg-primary/15 text-primary' : 'text-slate-300 hover:bg-white/5'}`}>
      <Icon size={18} /><span className="flex-1">{label}</span>
      {badge === 'bell' && bell > 0 && <span className="min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center px-1">{bell > 99 ? '99+' : bell}</span>}
      {badge === 'wa' && unread > 0 && <span className="min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center px-1">{unread > 99 ? '99+' : unread}</span>}
    </NavLink>
  )
  const body = (
    <nav dir="rtl" className="h-full w-64 bg-[#0d1220] border-l border-white/10 flex flex-col p-3 gap-1 overflow-y-auto pt-[max(0.75rem,env(safe-area-inset-top))] pb-[max(0.75rem,env(safe-area-inset-bottom))]" data-keep-colors>
      <p className="text-xs font-black text-primary px-3 py-2">{brandLabel()}</p>
      {items.map(link)}
      {canManage && (
        <>
          <button onClick={() => setManage((v) => !v)} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-300 hover:bg-white/5 mt-1">
            <Settings2 size={18} /><span className="flex-1 text-right">إدارة</span><ChevronDown size={16} className={`transition-transform ${manage ? 'rotate-180' : ''}`} />
          </button>
          {manage && <div className="mr-3 pr-2 border-r border-white/10 grid gap-0.5">{MANAGE.map(([to, label, Icon]) => link([to, label, Icon]))}</div>}
        </>
      )}
      <NavLink to="/staff/theme" className={({ isActive }) => `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold mt-1 ${isActive ? 'bg-primary/15 text-primary' : 'text-slate-300 hover:bg-white/5'}`}><Palette size={18} />قوالب المظهر</NavLink>
      {role === 'owner' && <NavLink to="/" className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-400 hover:bg-white/5 mt-auto"><Home size={18} />تطبيق العميل</NavLink>}
    </nav>
  )
  return (
    <>
      <div className="hidden md:block shrink-0 h-full">{body}</div>
      {open && <div className="md:hidden fixed inset-0 z-50 bg-black/60" onClick={() => setOpen(false)}><div className="absolute right-0 top-0 h-full" onClick={(e) => e.stopPropagation()}>{body}</div></div>}
    </>
  )
}

// Owner, after signing in with username + password: offer to bind fingerprint / face for next time.
function PasskeyOffer() {
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  useEffect(() => {
    let alive = true
    const dismissed = (() => { try { const v = Number(localStorage.getItem('mq_pk_no')); return v > 1 && Date.now() - v < 86400000 } catch { return false } })()   // snooze one day, then ask again
    if (dismissed || passkey.hasDeviceKey()) return
    passkey.supported().then((ok) => { if (alive && ok) setShow(true) })
    return () => { alive = false }
  }, [])
  if (!show) return null
  const enable = async () => {
    setBusy(true); setMsg('')
    try { await passkey.register(); setShow(false) } catch (e) { setMsg(e && e.name === 'NotAllowedError' ? 'تم الإلغاء.' : (e && e.message) || 'تعذر التفعيل.') } finally { setBusy(false) }
  }
  const later = () => { try { localStorage.setItem('mq_pk_no', String(Date.now())) } catch { /* ignore */ } setShow(false) }
  return (
    <div dir="rtl" className="fixed z-40 bottom-3 inset-x-3 md:right-72 md:inset-x-auto md:w-96 rounded-2xl bg-[#0d1220] border border-primary/40 p-3 shadow-2xl">
      <p className="text-sm font-black text-white">🔐 فعّل الدخول بالبصمة أو الوجه</p>
      <p className="text-xs text-slate-400 mt-1">سجّلتَ بكلمة المرور مرة واحدة، والمرة الجاية تدخل ببصمتك فقط ولن يُطلب منك اليوزر والباسورد.</p>
      {msg && <p className="text-xs text-rose-400 mt-1">{msg}</p>}
      <div className="flex gap-2 mt-2">
        <button onClick={enable} disabled={busy} className="flex-1 rounded-xl bg-primary text-black text-sm font-black py-2 disabled:opacity-60">{busy ? '…' : 'تفعيل الآن'}</button>
        <button onClick={later} className="rounded-xl border border-white/15 text-slate-300 text-sm px-3">لاحقاً</button>
      </div>
    </div>
  )
}

// Side navigation for every staff role (drawer on phones, fixed sidebar on wide screens).
function StaffThemeButton({ light, toggle }) {
  return (
    <button onClick={toggle} data-keep-colors aria-label={light ? 'الوضع الداكن' : 'تفتيح الشاشة'} title={light ? 'الوضع الداكن' : 'تفتيح الشاشة'}
      className="shrink-0 w-9 h-9 rounded-full bg-white/15 border border-white/25 flex items-center justify-center text-base active:scale-95">
      {light ? '🌙' : '☀️'}
    </button>
  )
}

// One real bar at the top of every staff screen (menu · app/site name · sound · workspaces · light/dark). Nothing floats over the page any more:
// the screens start under it, so a page's own buttons can never be hidden behind the layout's buttons.
const DESK = typeof window !== 'undefined' && window.__DESK_KIND__
const DESK_BRAND = DESK ? { mutqan: ['أكسجين', '#12a39a'], oxygen: ['أكسجين', '#38bdf8'] }[window.__DESK_BRAND__] || null : null
function TopBar({ onMenu, dot, owner, light, toggleLight }) {
  return (
    <div dir="rtl" data-keep-colors className="shrink-0 z-30 flex items-center gap-2 px-3 bg-[#0b0f1a] border-b border-white/10 h-[calc(2.9rem+env(safe-area-inset-top,0px))] pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
      <button onClick={onMenu} aria-label="القائمة" className="md:hidden relative shrink-0 w-9 h-9 rounded-full bg-white/10 border border-white/15 text-white flex items-center justify-center"><Menu size={18} />{dot && <span className="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-rose-500" />}</button>
      <div className="flex-1 min-w-0 flex items-center gap-2">
        {DESK_BRAND && <span className="shrink-0 rounded-full px-3 py-1 text-[12px] font-black text-black" style={{ background: DESK_BRAND[1] }}>{DESK_BRAND[0]}</span>}
        {DESK && <span className="truncate text-[12px] font-bold text-white/70">{window.__DESK_KIND__ === 'ops' ? 'غرفة العمليات' : 'التحكم والإعدادات'}</span>}
      </div>
      <SoundChip />
      {owner && <OwnerSwitcher />}
      <StaffThemeButton light={light} toggle={toggleLight} />
    </div>
  )
}

export default function StaffLayout() {
  const { signedIn, role, loading } = useTeamRole()
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const [light, toggleLight] = useStaffTheme()
  const items = signedIn ? NAV[role] : null
  const showWa = !!items?.some((i) => i[4] === 'wa')
  const { data: threads } = useLiveShared('wa_threads', { order: '-lastAt', limit: showWa ? 200 : 1 })
  const { data: notes } = useLiveShared('notifications', { order: '-at', limit: 60 })
  const meId = auth.getCurrentUser()?.id
  const bell = (notes || []).filter((n) => !n.read && (n.audience === 'ops' || n.userId === meId)).length
  const unread = showWa ? (threads || []).reduce((a, t) => a + (t.unread || 0), 0) : 0
  const hide = loading || !items || /\/staff\/order\//.test(pathname)
  return (
    <div className="h-full flex bg-[#090c14]" dir="rtl">
      {!hide && <SideMenu items={items} role={role} bell={bell} unread={unread} open={open} setOpen={setOpen} />}
      <div className="flex-1 min-w-0 min-h-0 flex flex-col" dir="rtl" style={hide ? undefined : { '--page-sat': '0px' }}>
        {!hide && <TopBar onMenu={() => setOpen(true)} dot={(bell + unread) > 0} owner={role === 'owner'} light={light} toggleLight={toggleLight} />}
        <div className="flex-1 min-h-0 relative">
          <Outlet />
          {!hide && role === 'owner' && <PasskeyOffer />}
          {signedIn && <IncomingCalls />}
          {signedIn && <OpsInstall />}
          {signedIn && <OrderAlarm />}
          {signedIn && (role === 'owner' || role === 'operations' || role === 'supervisor') && <LinkPinger />}
        </div>
      </div>
    </div>
  )
}
