import { Suspense } from 'react'
import { lazyRetry } from './lib/lazy'
import { SwipeToClose } from './components/Gestures'
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom'
import ClientShell from './components/ClientShell'
import Home from './pages/client/Home'
import Services from './pages/client/Services'
import Offers from './pages/client/Offers'
import Notifications from './pages/Notifications'
const FieldMap = lazyRetry(() => import('./pages/FieldMap'))
const SourceFinder = lazyRetry(() => import('./pages/SourceFinder'))
const DigitalTwin = lazyRetry(() => import('./pages/DigitalTwin'))
const Changelog = lazyRetry(() => import('./pages/Changelog'))
const OwnerControls = lazyRetry(() => import('./pages/OwnerControls'))
const NumbersCampaigns = lazyRetry(() => import('./pages/NumbersCampaigns'))
const NotifySettings = lazyRetry(() => import('./pages/NotifySettings'))
const WpHub = lazyRetry(() => import('./pages/WpHub'))
const HotlineLink = lazyRetry(() => import('./pages/HotlineLink'))
const Backups = lazyRetry(() => import('./pages/Backups'))
import Store from './pages/client/Store'
import MyOrders from './pages/client/MyOrders'
import Account from './pages/client/Account'
import Calculator from './pages/client/Calculator'
import More from './pages/client/More'
const AiChat = lazyRetry(() => import('./pages/client/AiChat'))
const NewOrder = lazyRetry(() => import('./pages/NewOrder'))
const WaStart = lazyRetry(() => import('./pages/client/WaStart'))
const ClientOrderDetail = lazyRetry(() => import('./pages/ClientOrderDetail'))
const StaffGate = lazyRetry(() => import('./pages/StaffGate'))
const OrderDetailStaff = lazyRetry(() => import('./pages/OrderDetailStaff'))
const OperationsRoom = lazyRetry(() => import('./pages/OperationsRoom'))
const OrdersBoard = lazyRetry(() => import('./pages/OrdersBoard'))
const GrowthSettings = lazyRetry(() => import('./pages/GrowthSettings'))
const TaskSchedule = lazyRetry(() => import('./pages/TaskSchedule'))
const TechRadar = lazyRetry(() => import('./pages/TechRadar'))
const HandoffDetail = lazyRetry(() => import('./pages/HandoffDetail'))
const AiSettings = lazyRetry(() => import('./pages/AiSettings'))
const PricingRules = lazyRetry(() => import('./pages/PricingRules'))
const AcAdmin = lazyRetry(() => import('./pages/AcAdmin'))
const UsedAcAdmin = lazyRetry(() => import('./pages/UsedAcAdmin'))
const Articles = lazyRetry(() => import('./pages/client/Articles'))
const ScheduleAdmin = lazyRetry(() => import('./pages/ScheduleAdmin'))
const PayLinks = lazyRetry(() => import('./pages/PayLinks'))
const AiCommandCenter = lazyRetry(() => import('./pages/AiCommandCenter'))
const ControlAssistant = lazyRetry(() => import('./pages/ControlAssistant'))
const OpsManager = lazyRetry(() => import('./pages/OpsManager'))
const AppsHub = lazyRetry(() => import('./pages/AppsHub'))
const NetworkSites = lazyRetry(() => import('./pages/NetworkSites'))
const AdminHub = lazyRetry(() => import('./pages/AdminHub'))
const AdminList = lazyRetry(() => import('./pages/AdminList'))
const Reports = lazyRetry(() => import('./pages/Reports'))
const PaymentMethods = lazyRetry(() => import('./pages/PaymentMethods'))
const AiOwnerControl = lazyRetry(() => import('./pages/AiOwnerControl'))
const AiTrainer = lazyRetry(() => import('./pages/AiTrainer'))
const SalesBrain = lazyRetry(() => import('./pages/SalesBrain'))
const Privacy = lazyRetry(() => import('./pages/Privacy'))
const Contact = lazyRetry(() => import('./pages/client/Contact'))
const TechStatement = lazyRetry(() => import('./pages/TechStatement'))
const PayAccounts = lazyRetry(() => import('./pages/client/PayAccounts'))
const CallUs = lazyRetry(() => import('./pages/client/CallUs'))
const MyWarranties = lazyRetry(() => import('./pages/client/MyWarranties'))
const WaHub = lazyRetry(() => import('./pages/WaHub'))
const InstallApp = lazyRetry(() => import('./pages/InstallApp'))
import StaffLayout from './components/StaffLayout'
import PushToaster from './components/PushToaster'
import { ThemeBoot } from './lib/appTheme'
import { AdEntry } from './components/AdLanding'
import HomeFab from './components/HomeFab'
import TitleBoot from './components/TitleBoot'
import NetRebrand from './components/NetRebrand'
import GlobalContact from './components/GlobalContact'
import MaintenanceGate from './components/MaintenanceGate'
import PersonalCalls from './components/PersonalCalls'
import StaffRedirect from './components/StaffRedirect'
const Inventory = lazyRetry(() => import('./pages/Inventory'))
const Accounts = lazyRetry(() => import('./pages/Accounts'))
const BusinessSettings = lazyRetry(() => import('./pages/BusinessSettings'))
const SecuritySettings = lazyRetry(() => import('./pages/SecuritySettings'))
const ThemeSettings = lazyRetry(() => import('./pages/ThemeSettings'))
const Warranties = lazyRetry(() => import('./pages/Warranties'))
const Invoices = lazyRetry(() => import('./pages/Invoices'))
const Quotes = lazyRetry(() => import('./pages/Quotes'))
const Customers = lazyRetry(() => import('./pages/Customers'))
const TechJoin = lazyRetry(() => import('./pages/TechJoin'))
const TechRequests = lazyRetry(() => import('./pages/TechRequests'))

export default function App() {
  return (
    <HashRouter>
      <SwipeToClose />
      <StaffRedirect />
      <PushToaster />
      <ThemeBoot />
      <HomeFab />
      <TitleBoot />
      <NetRebrand />
      <GlobalContact />
      <MaintenanceGate />
      <PersonalCalls />
      <Suspense fallback={<div className="h-full flex items-center justify-center text-slate-400 text-sm" dir="rtl">جارِ التحميل…</div>}>
      <Routes>
        {/* واجهة الزائر = واجهة العميل: يستقبل الزوار مباشرة على الرئيسية */}
        <Route path="/" element={<ClientShell />}>
          <Route index element={<Home />} />
          <Route path="services" element={<Services />} />
          <Route path="offers" element={<Offers />} />
          <Route path="store" element={<Store />} />
          <Route path="orders" element={<MyOrders />} />
          <Route path="account" element={<Account />} />
          <Route path="calculator" element={<Calculator />} />
          <Route path="more" element={<More />} />
          <Route path="articles" element={<Articles />} />
        </Route>

        {/* تدفق الطلب وتفاصيله — شاشات مركّزة بدون شريط تبويب */}
        <Route path="/ai" element={<AiChat />} />
        <Route path="/staff/notify-settings" element={<NotifySettings />} />
        <Route path="/staff/wp" element={<WpHub />} />
        <Route path="/staff/link" element={<HotlineLink />} />
        <Route path="/s/:spec" element={<AdEntry />} />
        <Route path="/theme" element={<ThemeSettings />} />
        <Route path="/new" element={<NewOrder />} />
        <Route path="/wa" element={<WaStart />} />
        <Route path="/install" element={<InstallApp />} />
        <Route path="/privacy" element={<Privacy />} />
        <Route path="/contact" element={<Contact />} />
        <Route path="/pay" element={<PayAccounts />} />
        <Route path="/call" element={<CallUs />} />
        <Route path="/staff/statement" element={<TechStatement />} />
        <Route path="/warranty" element={<MyWarranties />} />
        <Route path="/order/:id" element={<ClientOrderDetail />} />
        <Route path="/join-tech" element={<TechJoin />} />

        {/* فريق العمل */}
        <Route element={<StaffLayout />}>
        <Route path="/notifications" element={<Notifications />} />
        <Route path="/staff" element={<StaffGate />} />
        <Route path="/staff/order/:id" element={<OrderDetailStaff />} />
        <Route path="/staff/operations" element={<OperationsRoom />} />
        <Route path="/staff/orders-board" element={<OrdersBoard />} />
        <Route path="/staff/growth" element={<GrowthSettings />} />
        <Route path="/staff/tasks" element={<TaskSchedule />} />
        <Route path="/staff/radar" element={<TechRadar />} />
        <Route path="/staff/map" element={<FieldMap />} />
        <Route path="/staff/sources" element={<SourceFinder />} />
        <Route path="/staff/twin" element={<DigitalTwin />} />
        <Route path="/staff/changelog" element={<Changelog />} />
        <Route path="/staff/owner-delete" element={<OwnerControls />} />
        <Route path="/staff/numbers" element={<NumbersCampaigns />} />
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
        <Route path="/staff/reports" element={<Reports />} />
        <Route path="/staff/inventory" element={<Inventory />} />
        <Route path="/staff/accounts" element={<Accounts />} />
        <Route path="/staff/backups" element={<Backups />} />
        <Route path="/staff/payment-methods" element={<PaymentMethods />} />
        <Route path="/staff/ai-owner" element={<AiOwnerControl />} />
        <Route path="/staff/ai-trainer" element={<AiTrainer />} />
        <Route path="/staff/sales-brain" element={<SalesBrain />} />
        <Route path="/staff/wa-hub" element={<WaHub />} />
        {["/staff/wa-inbox", "/staff/wa-fleet", "/staff/whatsapp"].map((p) => <Route key={p} path={p} element={<Navigate to="/staff/wa-hub" replace />} />)}
        <Route path="/staff/warranties" element={<Warranties />} />
        <Route path="/staff/invoices" element={<Invoices />} />
        <Route path="/staff/quotes" element={<Quotes />} />
        <Route path="/staff/customers" element={<Customers />} />
        <Route path="/staff/tech-requests" element={<TechRequests />} />
        </Route>
      </Routes>
      </Suspense>
    </HashRouter>
  )
}
