import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Search, Settings, Palette, Bot, Wallet, Users, Radio, HardHat, Map, Contact, BarChart3, Globe, Plug, ArrowLeft } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { useLiveShared } from '../lib/useLive'
import { DONE, RUNNING, isToday, isUnassigned } from '../components/ops/helpers'

// «الإدارة الشاملة»: one organised front door to every control in the system, grouped the way the owner thinks about the
// business. Every card opens the existing screen (nothing is duplicated); the header shows live numbers from the 88 sites.
const SECTIONS = [
  { id: 'general', icon: Settings, color: '#0ea5e9', title: 'الإعدادات الشاملة', hint: 'اللي بيتحكم في الموقع والتطبيق كله', items: [
    ['/staff/business', 'إعدادات المنشأة والفاتورة', 'الاسم، السجل، الضريبة، شكل الفاتورة'],
    ['/staff/contact', 'أرقام الموقع وبيانات التواصل', 'الأرقام الظاهرة للعملاء في كل المواقع'],
    ['/staff/notify-settings', 'الإشعارات والرنين', 'مين يتنبه بإيه وبأي صوت'],
    ['/staff/security', 'الأمان والدخول بالبصمة', 'كلمات المرور، البصمة، الأجهزة'],
    ['/staff/wp', 'إعدادات موقع ووردبريس', 'الصفحات، القوائم، الإضافات، الإعدادات العامة'],
  ] },
  { id: 'custom', icon: Plug, color: '#14b8a6', title: 'الإعدادات المخصصة والإضافات', hint: 'كل خدمة وكل نظام لوحده', items: [
    ['/staff/ac', 'خدمات المكيفات', 'الخدمات والأسعار والصور لكل تخصص'],
    ['/staff/pricing', 'التسعير والخصومات', 'قواعد الأسعار والعروض والكوبونات'],
    ['/staff/schedule', 'نظام المواعيد', 'الفترات المتاحة والطاقة اليومية'],
    ['/staff/used-ac', 'متجر المستعمل', 'المنتجات والطلبات'],
    ['/staff/warranties', 'الضمانات', 'مدد الضمان والمطالبات'],
    ['/staff/link', 'ربط الخط الساخن وتيليجرام', 'التكاملات والقنوات'],
  ] },
  { id: 'look', icon: Palette, color: '#ec4899', title: 'البنرات والواجهات', hint: 'شكل التطبيق والمواقع', items: [
    ['/staff/theme', 'الثيم والألوان والبنرات', 'الألوان، الشعار، صور الواجهة والبنرات'],
    ['/staff/wp', 'صفحات الموقع والبنرات', 'تعديل صفحات ووردبريس والقوائم'],
    ['/staff/ai-command', 'تصميم بالذكاء الاصطناعي', 'اطلب تعديل واجهة أو بنر بالكلام'],
  ] },
  { id: 'ai', icon: Bot, color: '#a855f7', title: 'تعليمات الذكاء الاصطناعي وتطويره', hint: 'البوت والمساعد واللي بيتعلمه', items: [
    ['/staff/ai-settings', 'إعدادات البوت', 'التعليمات، النبرة، الردود الجاهزة، المزودين'],
    ['/staff/ai-owner', 'تحكم المالك بالبوت', 'قواعد المالك وحدود البوت'],
    ['/staff/ai-command', 'مركز قيادة الذكاء', 'أوامر وتعديلات بالذكاء الاصطناعي'],
    ['/staff/assistant', 'مساعد التحكم', 'كلّمه ينفّذ في الطلبات والموقع والمدونات'],
  ] },
  { id: 'money', icon: Wallet, color: '#22c55e', title: 'الدفع والحسابات والرصيد والتحويل', hint: 'الفلوس من أولها لآخرها', items: [
    ['/staff/payment-methods', 'طرق الدفع', 'الحسابات البنكية، المحافظ، الدفع عند الإنجاز'],
    ['/staff/pay-links', 'روابط الدفع', 'روابط تحصيل للعميل'],
    ['/staff/accounts', 'الحسابات والعمولات والعهد', 'رصيد كل فني، العمولات، التحويلات والتسويات'],
    ['/staff/inventory', 'المخزون وعهدة الفنيين', 'القطع والعهدة لكل فني'],
  ] },
  { id: 'users', icon: Users, color: '#f59e0b', title: 'المستخدمون والتسجيل', hint: 'الفريق والصلاحيات والانضمام', items: [
    ['/staff/tech-requests', 'طلبات الفنيين والانضمام', 'قبول ورفض الفنيين الجدد'],
    ['/staff/security', 'الدخول والصلاحيات', 'طرق الدخول وأجهزة الفريق'],
    ['/staff/operations?tab=techs', 'فريق العمل', 'الفنيون وحالتهم وتخصصاتهم'],
  ] },
  { id: 'ops', icon: Radio, color: '#ef4444', title: 'نظام العمليات', hint: 'مهام مشرف العمليات', items: [
    ['/staff/operations?tab=intake', 'استقبال الطلبات', 'كل الطلبات الجديدة من الـ 88 موقع في مكان واحد'],
    ['/staff/operations?tab=board', 'لوحة التوجيه', 'توزيع الطلبات على الفنيين'],
    ['/staff/operations?tab=table', 'جدول الطلبات', 'بحث وفلترة وتصدير'],
    ['/staff/operations?tab=calls', 'المكالمات والمتابعة', 'سجل المكالمات واللي محتاج متابعة'],
    ['/staff/wa-inbox', 'محادثات الواتساب', 'كل المحادثات الواردة'],
    ['/staff/whatsapp', 'أرقام الواتساب', 'الأرقام والربط'],
  ] },
  { id: 'techs', icon: HardHat, color: '#f97316', title: 'نظام الفنيين', hint: 'إدارة واحدة لكل المواقع', items: [
    ['/staff/operations?tab=techs', 'جدول الفنيين', 'الحالة، الطاقة، المكان، التخصص'],
    ['/staff/radar', 'رادار الفني والمهام', 'شاشة الفني نفسه'],
    ['/staff/accounts', 'مستحقات الفنيين', 'العمولات والعهد والتسويات'],
    ['/staff/reports?tab=techs', 'تقارير الفنيين', 'إنجاز وإيراد كل فني'],
  ] },
  { id: 'map', icon: Map, color: '#0891b2', title: 'الخريطة الميدانية والتتبع', hint: 'العملاء والفنيين والمحلات على خريطة واحدة', items: [
    ['/staff/map', 'الخريطة الميدانية', 'العملاء، الطلبات الجديدة، الفنيين، محلات الصيانة'],
    ['/staff/operations?tab=map', 'خريطة غرفة العمليات', 'تتبع الفنيين لحظيًا مع الطلبات'],
  ] },
  { id: 'crm', icon: Contact, color: '#6366f1', title: 'العملاء والمتاجر والعملاء القدام', hint: 'قاعدة العملاء والمحلات', items: [
    ['/staff/reports?tab=customers', 'العملاء الجدد والقدام', 'كل عميل وعدد طلباته وآخر تعامل'],
    ['/staff/reports?tab=shops', 'المحلات والمتاجر', 'محلات الصيانة المسجلة على الخريطة'],
    ['/staff/map', 'العملاء على الخريطة', 'إضافة محل وتصنيف العملاء'],
  ] },
  { id: 'reports', icon: BarChart3, color: '#84cc16', title: 'التقارير', hint: 'أرقام كل حاجة', items: [
    ['/staff/reports', 'تقرير شامل', 'المواقع، الفنيين، العملاء، الحالات، الإيراد'],
    ['/staff/reports?tab=sites', 'تقرير المواقع الـ 88', 'الطلبات والإيراد لكل موقع'],
    ['/staff', 'لوحة المالك', 'ملخص اليوم'],
  ] },
  { id: 'network', icon: Globe, color: '#64748b', title: 'الشبكة والمواقع', hint: 'ووردبريس وهستنجر وبلوجر', items: [
    ['/staff/wp', 'روابط ووردبريس', 'لوحة الموقع والإضافات'],
    ['/staff/assistant', 'المدونات والـ DNS', 'اطلب من المساعد: مقالات، عناوين، سجلات'],
  ] },
]

export default function AdminHub() {
  const nav = useNavigate()
  const { isOwner, role, loading } = useTeamRole()
  const [q, setQ] = useState('')
  const { data: orders } = useLiveShared('orders', { order: '-createdAt', limit: 500 })
  const { data: techs } = useLiveShared('tech_profiles', { limit: 300 })
  const { data: pending } = useLiveShared('tech_applications', { filters: { status: 'pending' }, limit: 100 })

  const kpi = useMemo(() => {
    const o = orders || []
    const sites = new Set(o.map((x) => x.brand || 'mutqan'))
    return [
      ['طلبات النهارده', o.filter((x) => isToday(x.createdAt)).length],
      ['مستنية توزيع', o.filter(isUnassigned).length],
      ['شغالة دلوقتي', o.filter((x) => RUNNING.includes(x.status)).length],
      ['اتنفذت', o.filter((x) => DONE.includes(x.status)).length],
      ['فنيين', (techs || []).length],
      ['طلبات انضمام', (pending || []).length],
      ['مواقع جالها طلبات', sites.size],
    ]
  }, [orders, techs, pending])

  const shown = useMemo(() => {
    const t = q.trim()
    if (!t) return SECTIONS
    return SECTIONS.map((s) => ({ ...s, items: s.items.filter(([, a, b]) => (a + ' ' + b + ' ' + s.title).includes(t)) })).filter((s) => s.items.length)
  }, [q])

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!(isOwner || role === 'operations' || role === 'supervisor')) return <p className="p-6 text-white text-sm font-bold" dir="rtl">الإدارة الشاملة للمالك ومشرف العمليات.</p>

  return (
    <div className="min-h-full text-white pb-10" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3">
        <div className="max-w-6xl mx-auto flex items-center gap-3">
          <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
          <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">الإدارة الشاملة</h1><p className="text-[11px] text-white/60 truncate">كل الإعدادات والأنظمة في مكان واحد</p></div>
        </div>
        <div className="max-w-6xl mx-auto mt-3 relative">
          <Search size={16} className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="دوّر على إعداد… مثلاً: الدفع، البنر، الفنيين" aria-label="بحث في الإعدادات" className="w-full rounded-xl bg-white/5 border border-white/15 pr-9 pl-3 py-2.5 text-[14px] placeholder:text-white/40" />
        </div>
      </header>

      <div className="max-w-6xl mx-auto px-4">
        <div className="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-7 gap-2 mt-4">
          {kpi.map(([l, v]) => (
            <div key={l} className="rounded-xl bg-white/5 border border-white/10 px-3 py-2.5">
              <p className="text-xl font-black tabular-nums">{v}</p><p className="text-[11px] text-white/60 leading-tight">{l}</p>
            </div>
          ))}
        </div>

        {!q && (
          <nav className="flex gap-2 overflow-x-auto mt-4 pb-1" aria-label="الأقسام">
            {SECTIONS.map((s) => <a key={s.id} href={`#/staff/admin#${s.id}`} onClick={(e) => { e.preventDefault(); document.getElementById('sec-' + s.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }} className="shrink-0 rounded-full bg-white/5 border border-white/10 px-3 py-1.5 text-[12px] font-bold">{s.title}</a>)}
          </nav>
        )}

        <div className="grid gap-5 mt-4">
          {shown.map((s) => {
            const Icon = s.icon
            return (
              <section key={s.id} id={'sec-' + s.id} className="scroll-mt-32">
                <div className="flex items-center gap-2 mb-2">
                  <span className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ background: s.color + '26', color: s.color }}><Icon size={17} /></span>
                  <div><h2 className="font-black text-[15px]">{s.title}</h2><p className="text-[11px] text-white/55">{s.hint}</p></div>
                </div>
                <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                  {s.items.map(([to, a, b]) => (
                    <button key={to + a} onClick={() => nav(to)} className="text-right rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-3 flex items-center gap-2">
                      <div className="flex-1 min-w-0"><p className="font-bold text-[14px]">{a}</p><p className="text-[12px] text-white/55 truncate">{b}</p></div>
                      <ArrowLeft size={15} className="text-white/40 shrink-0" />
                    </button>
                  ))}
                </div>
              </section>
            )
          })}
          {!shown.length && <p className="text-sm text-white/60">مفيش إعداد بالاسم ده.</p>}
        </div>
      </div>
    </div>
  )
}
