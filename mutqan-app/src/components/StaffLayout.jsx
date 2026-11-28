import IncomingCalls from './IncomingCalls'
import EnableAlertsBar from './EnableAlertsBar'
import OpsInstall from './OpsInstall'
import OrderAlarm from './OrderAlarm'
import SoundChip from './SoundChip'
import LinkPinger from './LinkPinger'
import FieldPosPinger from './FieldPosPinger'
import { nativeStart } from '../lib/native'
import FreshStartButton from './FreshStartButton'
import VoiceAssistant from './VoiceAssistant'
import WakeListener from './WakeListener'
import { openAssistant } from '../lib/voice'
import { registerBrands } from '../lib/netBrand'
import { post } from '../lib/_api'

const ASSIST = ['owner', 'operations', 'supervisor', 'technician']
import { brandLabel } from '../lib/brand'
import { Building2, History } from 'lucide-react'
import { useState, useEffect } from 'react'
import { Outlet, NavLink, useLocation, useNavigate } from 'react-router-dom'
import { HardHat as HardHatIcon, Clock, LayoutDashboard, MessageCircle, Bell, Radar, Wallet, ListChecks, Briefcase, Layers, X, Map as MapIcon, Menu, ChevronDown, Settings2, Bot, Tags, Snowflake, ShieldCheck, Phone, UserPlus, Smartphone, Home, ShoppingBag, Palette, Globe, Link2, LayoutGrid, BarChart3, Activity, Sparkles, Search, Trash2, Rocket, DatabaseBackup } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { auth } from '../lib/auth'
import { passkey } from '../lib/passkey'
import LongPressMenu from './LongPressMenu'
import SwipeActions from './SwipeActions'
import { useStaffTheme } from '../lib/theme'

// The side menu (Ali, 18 Nov): three parts only — «الإدارة», «غرفة العمليات», «الفني». Inside the operations room
// the menu holds the daily work only (operations, orders, map, chats, notifications); every setting, link, account,
// bot, app, site, theme and cache button lives under «الإدارة».
const OPS_NAV = [
  ['/staff/operations', 'العمليات', Briefcase, true],
  ['/staff/orders-board', 'لوحة الطلبات', ListChecks],
  ['/staff/map', 'الخريطة', MapIcon],
  ['/staff/twin', 'المقر ثلاثي الأبعاد', Building2],
  ['/staff/wa-hub', 'المحادثات', MessageCircle],
  ['/notifications', 'الإشعارات', Bell, false, 'bell'],
  ['/staff/changelog', 'سجل التطورات', History],
]
const TECH_NAV = [['/staff/radar', 'الرادار والمهام', Radar]]
const NAV = {
  owner: OPS_NAV,
  // Operations also controls the technicians' system (salary / commission / collection), the price table and AC prices.
  operations: [...OPS_NAV.map((x) => (x[0] === '/staff/operations' ? ['/staff', 'العمليات', Briefcase, true] : x)),
    ['/staff/accounts', 'نظام الفنيين: راتب / نسبة / التحصيل', Wallet],
    ['/staff/pricing', 'جدول الأسعار', Tags],
    ['/staff/ac', 'خدمات وأسعار المكيفات', Snowflake],
    ['/staff/sources', 'كاشف المصادر', Search]],
  technician: [
    ['/staff', 'المهام', ListChecks, true],
    ['/staff?tab=map', 'الخريطة', MapIcon],
    ['/notifications', 'الإشعارات', Bell, false, 'bell'],
  ],
}
NAV.supervisor = [...NAV.operations, ['/staff/tech-requests', 'طلبات الفنيين', UserPlus]]
const OPS_PATHS = ['/staff/operations', '/staff/orders-board', '/staff/map', '/staff/wa-hub', '/notifications']
const sectionOf = (path) => (OPS_PATHS.some((p) => path.startsWith(p)) ? 'ops' : path.startsWith('/staff/radar') ? 'tech' : 'admin')
const SECTIONS = [['admin', 'الإدارة', Settings2, '/staff'], ['ops', 'غرفة العمليات', Briefcase, '/staff/operations'], ['tech', 'الفني', HardHatIcon, '/staff/radar']]

// Owner only: jump into every workspace (operations room, supervisor board, technician radar, the customer app…) without logging out.
const WORKSPACES = [
  ['/staff', 'لوحة المالك (كل التحكمات)'],
  ['/staff/admin', 'الإدارة الشاملة (كل الإعدادات)'],
  ['/staff/reports', 'التقارير'],
  ['/staff/operations', 'غرفة العمليات'],
  ['/staff/map', 'الخريطة الميدانية'],
  ['/staff/sources', 'كاشف المصادر والمقالات'],
  ['/staff/owner-delete', 'تحكمات المالك — الحذف'],
  ['/staff/backups', 'النسخ الاحتياطية (رجوع / استيراد / تصدير)'],
  ['/staff/tech-requests', 'مشرف الموقع — طلبات الفنيين'],
  ['/staff/radar', 'الفني — الرادار والمهام'],
  ['/staff/wa-hub', 'مركز الواتساب'],
  ['/staff/numbers', 'الأرقام والحملات (الأدوار والرسايل)'],
  ['/staff/wp', 'روابط ووردبريس (تعديل الموقع)'],
  ['/staff/link', 'ربط الخط الساخن وتيليجرام'],
  ['/staff/accounts', 'الحسابات والعمولات والعهد'],
  ['/staff/inventory', 'المخزون وعهدة الفنيين'],
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

// «الإدارة»: everything that is not the day's operations, in drop-down groups.
const ADMIN_TOP = [
  ['/staff', 'لوحة المالك', LayoutDashboard, true],
  ['/staff/admin', 'الإدارة الشاملة', LayoutGrid],
  ['/staff/reports', 'التقارير', BarChart3],
  ['/staff/sources', 'كاشف المصادر والمقالات', Search],
  ['/staff/owner-delete', 'تحكمات المالك — الحذف', Trash2],
  ['/staff/backups', 'النسخ الاحتياطية', DatabaseBackup],
]
const ADMIN_GROUPS = [
  ['إدارة المواقع', Globe, [
    ['/staff/network', 'مواقع الشبكة', Globe],
    ['/staff/wp', 'روابط ووردبريس', Globe],
    ['/staff/contact', 'أرقام الموقع: الهاتف والواتساب', Phone],
    ['/staff/theme', 'قوالب المظهر', Palette],
    ['/staff/used-ac', 'متجر المستعمل', ShoppingBag],
  ]],
  ['التطبيقات والأجهزة', Smartphone, [
    ['/staff/apps', 'تطبيقات أندرويد وأجهزة الفريق', Smartphone],
    ['/staff/link', 'ربط الخط الساخن وتيليجرام', Link2],
  ]],
  ['الواتساب والبوتات والذكاء', Bot, [
    ['/staff/wa-hub', 'ربط الواتسابات (مركز الواتساب)', MessageCircle],
    ['/staff/numbers', 'الأرقام والحملات', Phone],
    ['/staff/ai-command', 'مركز قيادة الذكاء', Bot],
    ['/staff/ai-settings', 'إعدادات البوت', Settings2],
    ['/staff/ai-owner', 'تحكم المالك بالبوت', Bot],
    ['/staff/ai-trainer', 'ذاكرة المساعد وتطوره', Bot],
    ['/staff/sales-brain', 'العقل التجاري', Bot],
    ['/staff/assistant', 'مساعد التحكم', Bot],
  ]],
  ['جدول المهام', Clock, [
    ['/staff/tasks', '📅 الطلبات المحجوزة لأيام جاية وسجل التنبيهات', Clock],
  ]],
  ['جلب العملاء', Rocket, [
    ['/staff/growth', 'تقييم جوجل، تذكير موسمي، عقود، ترشيح', Rocket],
  ]],
  ['الأسعار والخدمات', Tags, [
    ['/staff/pricing', 'التسعير والخصومات', Tags],
    ['/staff/ac', 'خدمات وأسعار المكيفات', Snowflake],
    ['/staff/schedule', 'نظام المواعيد', Clock],
    ['/staff/warranties', 'الضمانات', ShieldCheck],
  ]],
  ['الحسابات والدفع', Wallet, [
    ['/staff/invoices', 'الفواتير (عادية وضريبية)', Wallet],
    ['/staff/quotes', 'عروض الأسعار', Wallet],
    ['/staff/customers', 'العملاء والشركات', Wallet],
    ['/staff/payment-methods', 'طرق الدفع', Wallet],
    ['/staff/pay-links', 'روابط الدفع', Wallet],
    ['/staff/accounts', 'الحسابات والعمولات والعهد', Wallet],
    ['/staff/inventory', 'المخزون وعهدة الفنيين', ShoppingBag],
  ]],
  ['إعدادات العمليات والفريق', Activity, [
    ['/staff/operations?settings=1', 'استقبال الطلبات وتوجيهها', Radar],
    ['/staff/operations?tab=techs', 'الفنيون', UserPlus],
    ['/staff/tech-requests', 'طلبات الفنيين', UserPlus],
    ['/staff/ops-manager', 'مدير العمليات', Activity],
    ['/staff/operations?tab=calls', 'سجل المكالمات', Phone],
    ['/staff/operations?tab=alerts', 'محادثات وتنبيهات العمليات', Bell],
    ['/staff/operations?tab=map', 'الخريطة الميدانية', MapIcon],
    ['/staff/operations?tab=table', 'جدول الطلبات القديم', ListChecks],
  ]],
  ['الإعدادات', ShieldCheck, [
    ['/staff/business', 'المنشأة والفاتورة', ShieldCheck],
    ['/staff/security', 'الأمان والأجهزة', ShieldCheck],
    ['/staff/notify-settings', 'الإشعارات والرنين', Bell],
  ]],
]
const MANAGE = [...ADMIN_TOP, ...ADMIN_GROUPS.flatMap((g) => g[2])]

function SideMenu({ items, role, bell, unread, open, setOpen }) {
  const { pathname, search } = useLocation()
  const owner = role === 'owner'
  const section = owner ? sectionOf(pathname) : 'ops'
  const here = pathname + (search || '')
  const [group, setGroup] = useState(() => (ADMIN_GROUPS.find((g) => g[2].some((m) => here === m[0] || (!m[0].includes('?') && pathname.startsWith(m[0])))) || [])[0] || '')
  useEffect(() => { setOpen(false) }, [pathname, search]) // eslint-disable-line
  useEffect(() => { const g = ADMIN_GROUPS.find((x) => x[2].some((m) => here === m[0] || (!m[0].includes('?') && pathname.startsWith(m[0])))); if (g) setGroup(g[0]) }, [pathname, search]) // eslint-disable-line
  const link = ([to, label, Icon, end, badge]) => (
    <NavLink key={to} to={to} end={!!end || to.includes('?')} className={({ isActive }) => `relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold ${(to.includes('?') ? here === to : isActive) ? 'bg-primary/15 text-primary' : 'text-slate-300 hover:bg-white/5'}`}>
      <Icon size={18} /><span className="flex-1">{label}</span>
      {badge === 'bell' && bell > 0 && <span className="min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center px-1">{bell > 99 ? '99+' : bell}</span>}
      {badge === 'wa' && unread > 0 && <span className="min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center px-1">{unread > 99 ? '99+' : unread}</span>}
    </NavLink>
  )
  const body = (
    <nav dir="rtl" data-side-menu className="h-full w-64 bg-[#0d1220] border-l border-white/10 flex flex-col p-3 gap-1 overflow-y-auto pt-[max(0.75rem,env(safe-area-inset-top))] pb-[max(0.75rem,env(safe-area-inset-bottom))]" data-keep-colors>
      <p className="text-xs font-black text-primary px-3 py-2">{brandLabel()}</p>
      {owner && (
        <div className="grid grid-cols-3 gap-1 mb-2" data-sections>
          {SECTIONS.map(([id, label, Icon, to]) => (
            <NavLink key={id} to={to} end className={`flex flex-col items-center gap-1 rounded-xl py-2 text-[11px] font-black border ${section === id ? 'bg-primary text-black border-primary' : 'bg-white/5 text-slate-300 border-white/10'}`}><Icon size={17} />{label}</NavLink>
          ))}
        </div>
      )}
      {section === 'ops' && items.map(link)}
      {section === 'tech' && TECH_NAV.map(link)}
      {section === 'admin' && (
        <>
          {ADMIN_TOP.map(link)}
          {ADMIN_GROUPS.map(([name, Icon, list]) => (
            <div key={name}>
              <button onClick={() => setGroup((g) => (g === name ? '' : name))} className="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-300 hover:bg-white/5">
                <Icon size={18} /><span className="flex-1 text-right">{name}</span><ChevronDown size={16} className={`transition-transform ${group === name ? 'rotate-180' : ''}`} />
              </button>
              {group === name && <div className="mr-3 pr-2 border-r border-white/10 grid gap-0.5">{list.map(link)}</div>}
            </div>
          ))}
          <FreshStartButton />
          <NavLink to="/" className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-400 hover:bg-white/5 mt-auto"><Home size={18} />تطبيق العميل</NavLink>
        </>
      )}
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
const DESK_BRAND = DESK ? ['شبكة المواقع', { mutqan: '#12a39a', oxygen: '#38bdf8', tech: '#12a39a' }[window.__DESK_BRAND__] || '#38bdf8'] : null   // one network, not one brand
// The assistant, one button on every staff screen for every role (owner, operations, supervisor, technician): text, voice,
// hands-free conversation and the wake call «يا مساعد». What it may do depends on the role (see VoiceAssistant).
function AssistantButton() {
  return (
    <button onClick={() => openAssistant()} aria-label="المساعد" title="المساعد — اضغط أو قول «يا مساعد»"
      className="shrink-0 h-9 rounded-full px-2.5 sm:px-3 flex items-center gap-1.5 text-[12px] font-black bg-primary text-black border border-primary active:scale-95">
      <Sparkles size={16} /><span className="hidden min-[380px]:inline">المساعد</span>
    </button>
  )
}
function TopBar({ onMenu, dot, owner, role, light, toggleLight }) {
  return (
    <div dir="rtl" data-keep-colors className="shrink-0 z-30 flex items-center gap-2 px-3 bg-[#0b0f1a] border-b border-white/10 h-[calc(2.9rem+env(safe-area-inset-top,0px))] pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
      <button onClick={onMenu} aria-label="القائمة" className="md:hidden relative shrink-0 w-9 h-9 rounded-full bg-white/10 border border-white/15 text-white flex items-center justify-center"><Menu size={18} />{dot && <span className="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-rose-500" />}</button>
      <div className="flex-1 min-w-0 flex items-center gap-2">
        {DESK_BRAND && <span className="shrink-0 rounded-full px-3 py-1 text-[12px] font-black text-black" style={{ background: DESK_BRAND[1] }}>{DESK_BRAND[0]}</span>}
        {DESK && <span className="truncate text-[12px] font-bold text-white/70">{{ ops: 'غرفة العمليات', tech: 'تطبيق الفني' }[window.__DESK_KIND__] || 'تطبيق المالك'}</span>}
      </div>
      {ASSIST.includes(role) && <AssistantButton />}
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
  const [assist, setAssist] = useState(false)
  // Names and colours of sites the owner linked later, so their orders show by name on every screen.
  useEffect(() => { if (signedIn) post('/network', { op: 'brands' }).then((r) => registerBrands(r.sites)).catch(() => {}) }, [signedIn])
  // App separation on the desk: the operations app always shows the operations menu (even to the owner),
  // the technician app only technician screens. The full owner control stays in «تطبيق المالك».
  const menuRole = DESK === 'ops' && role === 'owner' ? 'operations' : DESK === 'tech' && role !== 'technician' ? 'technician' : role
  const items = signedIn ? NAV[menuRole] : null
  const { data: notes } = useLiveShared('notifications', { order: '-at', limit: 60 })
  const meId = auth.getCurrentUser()?.id
  const bell = (notes || []).filter((n) => !n.read && (n.audience === 'ops' || n.userId === meId)).length
  const unread = 0   // WhatsApp lives in «مركز الواتساب» on the server now: no live inbox kept open on every screen
  const hide = loading || !items || /\/staff\/order\//.test(pathname)
  return (
    <div className="h-full flex bg-[#090c14]" dir="rtl">
      {!hide && <SideMenu items={items} role={menuRole} bell={bell} unread={unread} open={open} setOpen={setOpen} />}
      <div className="flex-1 min-w-0 min-h-0 flex flex-col" dir="rtl" style={hide ? undefined : { '--page-sat': '0px' }}>
        {!hide && <TopBar onMenu={() => setOpen(true)} dot={(bell + unread) > 0} owner={menuRole === 'owner'} role={role} light={light} toggleLight={toggleLight} />}
        {signedIn && <EnableAlertsBar />}
        {/* The page area scrolls by itself (owner, 2026-11-22: «الإدارة الشاملة» and other pages built with min-h-full
            filled the phone screen and could not be dragged down). Pages that scroll on their own (h-full overflow-y-auto)
            fill it exactly and keep doing so. */}
        <div className="flex-1 min-h-0 relative overflow-y-auto overflow-x-hidden overscroll-contain" style={{ WebkitOverflowScrolling: 'touch' }} data-page-scroll>
          <Outlet />
          {/* biometric offer removed (owner, 2026-11-22) */}
          {signedIn && <LongPressMenu role={role} />}
          {signedIn && <SwipeActions role={role} />}
          {signedIn && <IncomingCalls />}
          {signedIn && <OpsInstall />}
          {signedIn && <OrderAlarm />}
          {signedIn && (role === 'owner' || role === 'operations' || role === 'supervisor') && <LinkPinger />}
          {signedIn && (role === 'technician' || role === 'supervisor') && <FieldPosPinger role={role} />}
          {signedIn && role && <NativeBoot />}
          {signedIn && ASSIST.includes(role) && <WakeListener />}
          {signedIn && ASSIST.includes(role) && <VoiceAssistant key={role} role={role} open={assist} setOpen={setAssist} />}
        </div>
      </div>
    </div>
  )
}

// Inside the installed app: hands the native layer the session and starts background GPS for field roles.
function NativeBoot() { useEffect(() => { nativeStart() }, []); return null }
