import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2, RefreshCw, Search, ExternalLink, FileText, FilePlus2, Newspaper, PenSquare, Image as ImageIcon, MessageSquare, Menu as MenuIcon, Palette, Settings, Plug, Users, Building2, Share2, Images, KeyRound, Bot, BarChart3, Wrench } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { wp, openWp, brandName } from '../lib/link'
import { errText } from '../lib/_api'

// «روابط ووردبريس»: every control of this site's WordPress with its name, what it is for, what is inside it right now, and ONE tap that opens
// that exact wp-admin screen inside the app, already signed in (the server hands out a one-time address and a year-long cookie; no password is ever typed).
// The way back is the «◀ العودة للتطبيق» button the plugin adds to wp-admin's top bar.
const n = (v) => Number(v || 0).toLocaleString('ar-EG')
const CARDS = [
  { id: 'pages', icon: FileText, title: 'الصفحات', purpose: 'تعديل صفحات الموقع: الرئيسية، من نحن، الخدمات، التواصل…', to: 'edit.php?post_type=page', fill: (o) => `${n(o.pages?.publish)} منشورة · ${n(o.pages?.draft)} مسودة` },
  { id: 'new-page', icon: FilePlus2, title: 'صفحة جديدة', purpose: 'إنشاء صفحة جديدة بمحرر ووردبريس', to: 'post-new.php?post_type=page', fill: () => 'محرر الصفحات' },
  { id: 'posts', icon: Newspaper, title: 'المقالات', purpose: 'مراجعة المقالات اليدوية والآلية وتعديل عناوينها ونصوصها', to: 'edit.php', fill: (o) => `${n(o.posts?.publish)} منشور · ${n(o.posts?.draft)} مسودة · ${n(o.posts?.future)} مجدول` },
  { id: 'new-post', icon: PenSquare, title: 'مقال جديد', purpose: 'كتابة مقال وأرشفته في محركات البحث', to: 'post-new.php', fill: () => 'محرر المقالات' },
  { id: 'company', icon: Building2, title: 'إدارة المؤسسة', purpose: 'اسم الشركة، الأرقام، البانر، الخدمات، طرق الدفع وتعليمات المساعد', to: 'admin.php?page=oxygen-business-control', fill: () => 'بيانات الموقع الأساسية' },
  { id: 'articles', icon: Bot, title: 'المقالات الآلية وSEO', purpose: 'محرك المقالات والأرشفة اليومية وخرائط الموقع', to: 'admin.php?page=oxygen-seo', fill: (o) => `${n(o.posts?.future)} مقالة مجدولة` },
  { id: 'publisher', icon: Wrench, title: 'ناشر أكسجين', purpose: 'إعدادات النشر الآلي للمقالات والتحقق منها', to: 'admin.php?page=oxygen-ac-automation', fill: () => 'إعدادات النشر' },
  { id: 'chatbot', icon: MessageSquare, title: 'الشات بوت والطلبات', purpose: 'ربط واتساب كلاود وتنبيهات الطلبات والمساعد', to: 'admin.php?page=oxygen-chat-orders', fill: () => 'إعدادات المحادثة' },
  { id: 'social', icon: Share2, title: 'تيك توك وفيسبوك', purpose: 'روابط حساباتك وفيديوهات المعرض في الموقع', to: 'admin.php?page=oxygen-social', fill: () => 'الربط الاجتماعي' },
  { id: 'visuals', icon: Images, title: 'مكتبة المشاهد والصور', purpose: 'الصور والمشاهد المستخدمة في المقالات', to: 'admin.php?page=oxygen-visuals', fill: () => 'مكتبة الصور' },
  { id: 'report', icon: BarChart3, title: 'تقارير المقالات اليومية', purpose: 'ماذا نُشر اليوم وما الذي فشل ولماذا', to: 'admin.php?page=oxygen-report', fill: () => 'تقرير اليوم' },
  { id: 'media', icon: ImageIcon, title: 'مكتبة الوسائط', purpose: 'رفع الصور والفيديوهات وإدارتها', to: 'upload.php', fill: (o) => `${n(o.media)} ملف` },
  { id: 'comments', icon: MessageSquare, title: 'التعليقات', purpose: 'مراجعة تعليقات الزوار والموافقة عليها', to: 'edit-comments.php', fill: (o) => `${n(o.comments?.pending)} بانتظار المراجعة · ${n(o.comments?.total)} إجمالًا` },
  { id: 'menus', icon: MenuIcon, title: 'القوائم', purpose: 'ترتيب القائمة العلوية والسفلية للموقع', to: 'nav-menus.php', fill: () => 'قوائم التصفح' },
  { id: 'appearance', icon: Palette, title: 'المظهر', purpose: 'القالب والألوان والشعار وتخصيص الموقع', to: 'themes.php', fill: () => 'القوالب والتخصيص' },
  { id: 'desk', icon: KeyRound, title: 'المكتب السري ورمز الدخول', purpose: 'تغيير رمز التطبيقين وقراءة تقارير الأجهزة', to: 'admin.php?page=oxygen-desk', fill: (o) => (o.sso ? 'الدخول المباشر مفعّل' : 'الدخول المباشر متوقف') },
  { id: 'plugins', icon: Plug, title: 'الإضافات', purpose: 'تفعيل الإضافات وتحديثها', to: 'plugins.php', fill: (o) => `${n(o.plugins)} مفعّلة` },
  { id: 'users', icon: Users, title: 'المستخدمون', purpose: 'مديرو الموقع وحساباتهم', to: 'users.php', fill: (o) => `${n(o.users)} مستخدم` },
  { id: 'settings', icon: Settings, title: 'إعدادات الموقع العامة', purpose: 'اسم الموقع والعنوان واللغة والروابط الدائمة', to: 'options-general.php', fill: (o) => (o.site?.wp ? `ووردبريس ${o.site.wp}` : 'الإعدادات') },
]
const ST = { publish: 'منشور', draft: 'مسودة', pending: 'بانتظار المراجعة', private: 'خاص', future: 'مجدول' }
const dt = (s) => { try { return new Date(s).toLocaleDateString('ar-SA', { month: 'short', day: 'numeric' }) } catch { return '' } }

export default function WpHub() {
  const nav = useNavigate()
  const { isOwner, loading } = useTeamRole()
  const [ov, setOv] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState('')
  const [type, setType] = useState('page')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const [rowsErr, setRowsErr] = useState('')
  const t = useRef(0)

  const loadOv = useCallback(async () => { setErr(''); try { setOv(await wp.overview()) } catch (e) { setErr(errText(e, 'تعذر قراءة بيانات ووردبريس')) } }, [])
  useEffect(() => { if (isOwner) loadOv() }, [isOwner, loadOv])
  useEffect(() => {
    if (!isOwner) return
    clearTimeout(t.current); setRows(null); setRowsErr('')
    t.current = setTimeout(async () => { try { setRows((await wp.pages(type, q.trim())).items || []) } catch (e) { setRowsErr(errText(e, 'تعذر قراءة القائمة')); setRows([]) } }, q ? 400 : 0)
    return () => clearTimeout(t.current)
  }, [isOwner, type, q])

  const go = async (key, path) => {
    if (busy) return
    setBusy(key); setErr('')
    try { await openWp(path) } catch (e) { setErr(errText(e, 'تعذر فتح ووردبريس')); setBusy('') }
    // On success the whole window navigates to wp-admin, so there is nothing to reset.
  }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!isOwner) return <p className="p-6 text-white text-sm font-bold" dir="rtl">هذه الصفحة للمالك فقط.</p>
  const site = brandName()
  return (
    <div className="h-full overflow-y-auto pb-24 text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">روابط ووردبريس — {site}</h1><p className="text-[11px] text-white/60 truncate">{ov?.site?.url ? ov.site.url.replace(/^https?:\/\//, '') : 'افتح أي جزء من موقعك للتعديل مباشرة'}</p></div>
        <button onClick={loadOv} aria-label="تحديث" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><RefreshCw size={16} /></button>
      </header>
      <div className="p-4 max-w-3xl mx-auto grid gap-4">
        <p className="rounded-2xl bg-sky-400/10 border border-sky-400/25 p-3 text-[12px] leading-relaxed text-sky-100">الضغط على أي بطاقة يفتح صفحة التعديل في ووردبريس {site} <b>مسجّلًا دخولك تلقائيًا</b> (دخول ثابت لسنة، لا كلمة مرور). للرجوع للتطبيق اضغط <b>«◀ العودة للتطبيق»</b> في الشريط العلوي لووردبريس.</p>
        {err && <p className="rounded-xl bg-rose-500/10 border border-rose-400/30 p-3 text-xs font-bold text-rose-200 leading-relaxed">{err}</p>}

        <section aria-label="بطاقات التحكم" className="grid gap-2.5 sm:grid-cols-2">
          {CARDS.map((c) => (
            <button key={c.id} onClick={() => go(c.id, c.to)} disabled={!!busy} className="text-right rounded-2xl bg-white/5 border border-white/10 p-3.5 flex gap-3 items-start active:scale-[.99] disabled:opacity-60">
              <span className="shrink-0 w-10 h-10 rounded-xl bg-primary/15 text-primary flex items-center justify-center">{busy === c.id ? <Loader2 size={18} className="animate-spin" /> : <c.icon size={18} />}</span>
              <span className="flex-1 min-w-0 grid gap-0.5">
                <span className="font-black text-sm">{c.title}</span>
                <span className="text-[11px] text-slate-400 leading-relaxed">{c.purpose}</span>
                <span className="text-[11px] font-bold text-emerald-300 mt-0.5">{ov ? c.fill(ov) : '…'}</span>
              </span>
              <ExternalLink size={14} className="shrink-0 text-slate-500 mt-1" />
            </button>
          ))}
        </section>

        <section aria-label="صفحات ومقالات الموقع" className="rounded-2xl bg-white/5 border border-white/10 p-3.5 grid gap-3">
          <div className="flex items-center gap-2">
            <p className="font-black text-sm flex-1">{type === 'page' ? 'صفحات الموقع' : 'مقالات الموقع'} — اضغط للتعديل</p>
            <div className="flex rounded-lg bg-white/10 p-0.5 text-xs font-bold">
              {[['page', 'الصفحات'], ['post', 'المقالات']].map(([k, l]) => <button key={k} onClick={() => setType(k)} className={`px-3 py-1.5 rounded-md ${type === k ? 'bg-primary text-black' : 'text-slate-300'}`}>{l}</button>)}
            </div>
          </div>
          <label className="relative block"><Search size={15} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث بالعنوان…" className="w-full rounded-xl border border-white/10 bg-white/5 pr-9 pl-3 py-2.5 text-sm text-white placeholder:text-slate-500 outline-none focus:border-primary" /></label>
          {rowsErr && <p className="text-xs font-bold text-rose-300">{rowsErr}</p>}
          {rows === null ? <p className="text-xs text-slate-400 flex items-center gap-2"><Loader2 size={14} className="animate-spin" />جارِ التحميل…</p> : rows.length === 0 ? <p className="text-xs text-slate-400">لا نتائج.</p> : (
            <ul className="grid gap-1.5">
              {rows.map((r) => (
                <li key={r.id} className="flex items-center gap-2 rounded-xl bg-white/5 border border-white/5 px-3 py-2">
                  <div className="flex-1 min-w-0"><p className="text-sm font-bold truncate">{r.title}</p><p className="text-[10px] text-slate-400">{ST[r.status] || r.status} · {dt(r.modified)}</p></div>
                  <button onClick={() => go('r' + r.id, r.edit)} disabled={!!busy} className="shrink-0 rounded-lg bg-primary text-black text-xs font-black px-3 py-2 disabled:opacity-60 flex items-center gap-1">{busy === 'r' + r.id && <Loader2 size={12} className="animate-spin" />}تعديل</button>
                  <a href={r.view} target="_blank" rel="noopener noreferrer" className="shrink-0 rounded-lg bg-white/10 text-xs font-bold px-3 py-2 text-slate-200">عرض</a>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  )
}
