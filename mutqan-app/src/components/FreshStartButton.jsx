import { useState } from 'react'
import { RotateCcw, X, Loader2 } from 'lucide-react'
import { freshStart } from '../lib/freshStart'

// «بداية جديدة»: one button that clears old updates, cached files, saved screens and notifications so the app opens
// fresh on the newest version. Asks first; the sign-in stays unless «اخرج من الحساب كمان» is ticked.
export default function FreshStartButton({ className = '', signedIn = true }) {
  const [open, setOpen] = useState(false)
  const [notes, setNotes] = useState(true)
  const [out, setOut] = useState(false)
  const [busy, setBusy] = useState(false)
  const go = async () => { setBusy(true); await freshStart({ readNotifications: signedIn && notes, signOut: out }) }
  return (
    <>
      <button onClick={() => setOpen(true)} className={className || 'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-300 hover:bg-white/5'}>
        <RotateCcw size={18} />بداية جديدة (مسح الكاش)
      </button>
      {open && (
        <div className="fixed inset-0 z-[70] bg-black/60 flex items-end sm:items-center justify-center" onClick={() => !busy && setOpen(false)}>
          <div dir="rtl" role="dialog" aria-modal="true" aria-label="بداية جديدة" onClick={(e) => e.stopPropagation()}
            className="w-full max-w-sm bg-[#0d1220] border border-white/10 rounded-t-3xl sm:rounded-3xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-white grid gap-3">
            <div className="flex items-center"><p className="flex-1 font-black">🧹 بداية جديدة</p><button onClick={() => setOpen(false)} disabled={busy} aria-label="إغلاق" className="text-slate-400"><X size={18} /></button></div>
            <p className="text-[13px] text-white/75 leading-relaxed">هيمسح من الجهاز ده: التحديثات القديمة والملفات المخزّنة، المحادثات والشاشات المحفوظة، والإشعارات اللي على الشاشة — ويفتح التطبيق من جديد على آخر نسخة.</p>
            <p className="text-[12px] text-emerald-200/90 leading-relaxed">مش هيمسح: الطلبات والبيانات على السيرفر، ولا تفعيل الجهاز، ولا التعديلات اللي لسه ما اتبعتتش.</p>
            {signedIn && <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={notes} onChange={(e) => setNotes(e.target.checked)} className="w-4 h-4" />علّم كل الإشعارات كمقروءة</label>}
            {signedIn && <label className="flex items-center gap-2 text-[13px] text-rose-200"><input type="checkbox" checked={out} onChange={(e) => setOut(e.target.checked)} className="w-4 h-4" />اخرج من الحساب كمان (هتحتاج تدخل تاني)</label>}
            <button onClick={go} disabled={busy} className="rounded-xl bg-primary text-black font-black py-3 flex items-center justify-center gap-2 disabled:opacity-60">{busy ? <><Loader2 size={16} className="animate-spin" />جارِ التنظيف…</> : 'امسح وابدأ من جديد'}</button>
          </div>
        </div>
      )}
    </>
  )
}
