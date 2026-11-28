import { errText } from '../lib/_api'
import PreviewEye, { customerUrl } from '../components/PreviewEye'
import AccessDenied from '../components/AccessDenied'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Plus, Save, Loader2, Trash2, Check, AlertTriangle, Wallet, X } from 'lucide-react'
import { payments } from '../lib/payments'
import { useTeamRole } from '../hooks/useTeamRole'
import { resolvePayUrl } from '../payment'
import { usePaymentMethods, saveMethod, removeMethod, METHOD_TYPES, PAY_LINK_PRESETS, typeMeta } from '../hooks/usePaymentMethods'
import BankAccountCard, { SA_BANKS, bankMeta } from '../components/BankAccountCard'
import { storage } from '../lib/storage'

export default function PaymentMethods() {
  const navigate = useNavigate()
  const { role, isOwner, loading, refresh } = useTeamRole()
  const { methods, loading: loadingMethods } = usePaymentMethods()
  const [editing, setEditing] = useState(null)
  const [provider, setProvider] = useState(null)

  useEffect(() => { payments.status().then(setProvider).catch(() => setProvider({ available: false })) }, [])

  const allowed = isOwner || role === 'operations'
  if (loading) return <Centered>جارِ التحقق…</Centered>
  if (!allowed) return <AccessDenied refresh={refresh} />

  const connected = !!provider?.available
  const testMode = (provider?.connections || []).some((c) => c.status === 'active' && c.mode === 'test')

  async function toggle(m) {
    try { await saveMethod({ ...m, enabled: !m.enabled }) } catch (e) { alert(errText(e, 'تعذر الحفظ — تأكد من صلاحياتك')) }
  }

  return (
    <div className="h-full overflow-y-auto pb-24 bg-[#090c14]" dir="rtl">
      <header className="sticky top-0 z-20 bg-[#090c14]/90 backdrop-blur border-b border-white/10 pt-[var(--page-sat,env(safe-area-inset-top,0px))]">
        <div className="max-w-5xl mx-auto w-full px-4 md:px-8 py-4 flex items-center gap-3">
          <button onClick={() => navigate('/staff/operations')} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center hover:bg-white/10">
            <ChevronRight size={18} className="text-slate-300" />
          </button>
          <div className="flex-1 min-w-0">
            <p className="text-[11px] text-primary font-bold tracking-widest">PAYMENT METHODS</p>
            <h1 className="font-display text-lg font-black text-white">طرق الدفع</h1>
          </div>
          <PreviewEye url={customerUrl('/pay?preview=1')} title="صفحة حسابات التحويل زي ما العميل شايفها بعد تعديلك" />
          <button onClick={() => setEditing({ type: 'custom', name: '', enabled: true, instructions: '', fee: 0, sort: methods.length + 1 })} className="shrink-0 flex items-center gap-1.5 bg-primary text-black rounded-xl px-3 py-2 text-xs font-black hover:opacity-90">
            <Plus size={14} /> طريقة جديدة
          </button>
          <button onClick={() => setEditing({ type: 'bank_transfer', bank: 'rajhi', name: 'تحويل — مصرف الراجحي', bankName: 'مصرف الراجحي', enabled: true, instructions: 'بعد التحويل ابعت صورة الإيصال.', fee: 0, sort: methods.length + 1 })} className="shrink-0 flex items-center gap-1.5 bg-white/10 text-white rounded-xl px-3 py-2 text-xs font-black hover:bg-white/15">
            🏦 حساب بنكي
          </button>
        </div>
      </header>

      <main className="max-w-5xl mx-auto w-full px-4 md:px-8 py-5 flex flex-col gap-4">
        <section className={`rounded-2xl border p-4 flex items-start gap-3 fade-up ${connected ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5'}`}>
          {connected ? <Check size={18} className="text-emerald-400 shrink-0 mt-0.5" /> : <AlertTriangle size={18} className="text-amber-400 shrink-0 mt-0.5" />}
          <div className="text-xs leading-relaxed">
            <p className="font-black text-white">مزود الدفع الإلكتروني: {provider === null ? 'جارِ الفحص…' : connected ? `متصل${testMode ? ' (وضع تجريبي — لا تتحرك أموال حقيقية)' : ''}` : 'SETUP REQUIRED — غير متصل'}</p>
            <p className="text-slate-400 mt-0.5">{connected ? 'روابط الدفع تعمل، والمبالغ تصل مباشرة لحسابك.' : 'الطريقة الإلكترونية لن تظهر للعملاء حتى يكمل المالك ربط حساب الدفع من بطاقة الربط في محادثة البناء.'}</p>
          </div>
        </section>

        {loadingMethods ? (
          <p className="text-slate-500 text-xs text-center py-8">جارِ التحميل…</p>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {methods.map((m, i) => {
              const t = typeMeta(m.type)
              const setup = m.type === 'online' && !connected
              return (
                <div key={m.id} className="bg-white/[0.04] border border-white/10 rounded-2xl p-4 fade-up hover:bg-white/[0.06] transition-colors" style={{ animationDelay: `${i * 40}ms` }}>
                  <div className="flex items-start gap-3">
                    <span className="text-2xl">{t.icon}</span>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-black text-white truncate">{m.name}</p>
                      <p className="text-[10px] text-slate-500">{t.label}{m.fee ? ` · رسوم ${m.fee} ر.س` : ''}</p>
                    </div>
                    <button onClick={() => toggle(m)} className={`w-11 h-6 rounded-full p-0.5 shrink-0 transition-colors ${m.enabled ? 'bg-primary' : 'bg-slate-600'}`} aria-label="تفعيل">
                      <span className={`block w-5 h-5 rounded-full bg-white shadow transition-transform ${m.enabled ? '-translate-x-5' : ''}`} />
                    </button>
                  </div>
                  {m.instructions && <p className="text-[11px] text-slate-400 mt-2 leading-relaxed">{m.instructions}</p>}
                  {m.payUrl && <p className="text-[11px] text-sky-300 mt-1 truncate" dir="ltr">{m.payUrl}</p>}
                  {['bank_transfer', 'wallet_app'].includes(m.type) && (m.iban || m.accountNumber || m.qrUrl) ? <div className="mt-2"><BankAccountCard m={{ ...m, enabled: true }} compact /></div> : m.iban && <p className="text-[11px] text-slate-300 mt-1" dir="ltr">IBAN: {m.iban}</p>}
                  {setup && m.enabled && <p className="text-[10px] font-black text-amber-400 mt-2">SETUP REQUIRED — مخفية عن العملاء حتى ربط مزود الدفع</p>}
                  <div className="flex gap-2 mt-3">
                    <button onClick={() => setEditing(m)} className="flex-1 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl py-2 text-xs font-bold text-slate-200">تعديل</button>
                    {!m.builtin && (
                      <button onClick={async () => { if (confirm(`حذف «${m.name}»؟`)) { try { await removeMethod(m) } catch (e) { alert(errText(e, 'تعذر الحذف')) } } }} className="w-10 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center hover:bg-rose-500/20">
                        <Trash2 size={14} />
                      </button>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        )}

        <p className="text-[11px] text-slate-500 leading-relaxed">
          الطرق المفعّلة تظهر للعميل في صفحة طلبه، ويقرأها MUTQAN AI عند سؤال «أدفع إزاي؟». المساعد لا يعلن نجاح أي دفع إلا بعد تأكيد النظام، ولا يستطيع تعديل هذه الطرق.
        </p>
      </main>

      {editing && <Editor method={editing} onClose={() => setEditing(null)} />}
    </div>
  )
}

function Editor({ method, onClose }) {
  const [f, setF] = useState({ ...method })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e.target.value }))

  async function save() {
    if (!f.name?.trim()) return setErr('اكتب اسم طريقة الدفع')
    if (f.type === 'pay_link' && !/^https:\/\//i.test(f.payUrl || '')) return setErr('أدخل رابط دفع يبدأ بـ https://')
    setSaving(true); setErr('')
    try {
      await saveMethod({ ...f, name: f.name.trim(), fee: Math.max(0, Number(f.fee) || 0), sort: Number(f.sort) || 99 })
      onClose()
    } catch (e) { setErr(errText(e, 'تعذر الحفظ — تأكد من صلاحياتك')) } finally { setSaving(false) }
  }

  return (
    <div className="fixed inset-0 z-30 bg-black/60 flex items-end md:items-center justify-center" style={{ height: 'var(--visual-height, 100dvh)' }} dir="rtl">
      <div className="w-full max-w-lg bg-[#0f1422] border border-white/10 rounded-t-3xl md:rounded-3xl p-5 overflow-y-auto pb-[calc(env(safe-area-inset-bottom,0px)+1.25rem)]" style={{ maxHeight: 'calc(var(--visual-height, 100dvh) - 2rem)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-display font-black text-white flex items-center gap-2"><Wallet size={18} className="text-primary" /> {method.id ? 'تعديل طريقة دفع' : 'طريقة دفع جديدة'}</h2>
          <button onClick={onClose} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><X size={16} className="text-slate-300" /></button>
        </div>
        <label className={lbl}>النوع</label>
        <select value={f.type} onChange={set('type')} className={inp} disabled={!!method.builtin}>
          {METHOD_TYPES.map((t) => <option key={t.id} value={t.id} className="bg-slate-900">{t.icon} {t.label}</option>)}
        </select>
        <label className={lbl}>الاسم الظاهر للعميل</label>
        <input value={f.name} onChange={set('name')} className={inp} placeholder="مثال: STC Pay" />
        <label className={lbl}>تعليمات للعميل</label>
        <textarea value={f.instructions || ''} onChange={set('instructions')} rows={2} className={`${inp} resize-none`} placeholder="كيف يدفع العميل بهذه الطريقة" />
        {f.type === 'pay_link' && (
          <>
            <label className={lbl}>قالب سريع</label>
            <select value="" onChange={(e) => { const p = PAY_LINK_PRESETS[Number(e.target.value)]; if (p) setF((x) => ({ ...x, name: x.name || p.name, payUrl: p.url || x.payUrl, instructions: x.instructions || p.hint })) }} className={inp}>
              <option value="" className="bg-slate-900">اختر مزوّدًا…</option>
              {PAY_LINK_PRESETS.map((p, i) => <option key={p.name} value={i} className="bg-slate-900">{p.name}</option>)}
            </select>
            <label className={lbl}>رابط الدفع (https فقط)</label>
            <input value={f.payUrl || ''} onChange={set('payUrl')} className={inp} dir="ltr" placeholder="https://…/pay?amount={amount}&ref={order}" />
            <p className="text-[10px] text-slate-500 mt-1 leading-relaxed">يمكنك استخدام {'{amount}'} المبلغ، {'{order}'} رقم الطلب، {'{name}'} اسم العميل، {'{phone}'} الجوال — تُملأ تلقائيًا لكل طلب. الرابط وسيلة للدفع فقط، ولا يُعتبر الطلب مدفوعًا إلا بعد تأكيد فريقك (زر «استلمت» في تفاصيل الطلب).</p>
            {f.payUrl && (/^https:\/\//i.test(f.payUrl)
              ? <p className="text-[10px] text-emerald-400 mt-1 break-all" dir="ltr">معاينة: {resolvePayUrl(f.payUrl, { id: 'ORDER1234', priceQuote: { total: 250 }, clientName: 'عميل', clientPhone: '05xxxxxxxx' })}</p>
              : <p className="text-[10px] text-rose-400 mt-1">يجب أن يبدأ الرابط بـ https://</p>)}
          </>
        )}
        {['bank_transfer', 'wallet_app', 'custom'].includes(f.type) && (
          <>
            {f.type !== 'custom' && (
              <>
                <label className={lbl}>البنك / المحفظة</label>
                <div className="grid grid-cols-3 gap-1.5">
                  {SA_BANKS.map((b) => (
                    <button key={b.id} type="button" onClick={() => setF((x) => ({ ...x, bank: b.id, bankName: b.id === 'other' ? (x.bankName || '') : b.name, name: !x.name || /^تحويل — /.test(x.name) ? `تحويل — ${b.name}` : x.name, type: ['stcpay', 'barq', 'urpay'].includes(b.id) ? 'wallet_app' : 'bank_transfer' }))}
                      className={`rounded-lg px-2 py-1.5 text-[11px] font-bold border ${f.bank === b.id ? 'border-primary text-white' : 'border-white/10 text-slate-300'}`} style={{ background: f.bank === b.id ? b.color : 'transparent' }}>{b.name}</button>
                  ))}
                </div>
              </>
            )}
            <label className={lbl}>{f.type === 'bank_transfer' ? 'اسم البنك' : 'الجهة / التطبيق'}</label>
            <input value={f.bankName || ''} onChange={set('bankName')} className={inp} />
            <label className={lbl}>اسم صاحب الحساب</label>
            <input value={f.accountHolder || ''} onChange={set('accountHolder')} className={inp} />
            <label className={lbl}>رقم الآيبان (IBAN)</label>
            <input value={f.iban || ''} onChange={(e) => setF((x) => ({ ...x, iban: e.target.value.toUpperCase() }))} className={inp} dir="ltr" placeholder="SA00 0000 0000 0000 0000 0000" />
            {f.iban && !/^SA\d{22}$/.test(String(f.iban).replace(/\s+/g, '')) && <p className="text-[10px] text-amber-400 mt-1">الآيبان السعودي: SA و22 رقم (24 خانة) — راجع الرقم</p>}
            <label className={lbl}>رقم الحساب</label>
            <input value={f.accountNumber || ''} onChange={set('accountNumber')} className={inp} dir="ltr" />
            {f.type === 'wallet_app' && (<><label className={lbl}>رقم الجوال المسجّل بالمحفظة</label><input value={f.walletPhone || ''} onChange={set('walletPhone')} className={inp} dir="ltr" placeholder="05xxxxxxxx" /></>)}
            <label className={lbl}>صورة الـQR (من تطبيق البنك)</label>
            <QrUpload value={f.qrUrl} onChange={(u) => setF((x) => ({ ...x, qrUrl: u }))} />
            {f.qrUrl && (
              <button type="button" onClick={() => setF((x) => ({ ...x, showQr: x.showQr === false }))} className="mt-2 w-full flex items-center justify-between bg-white/5 border border-white/10 rounded-xl px-3 py-2.5" data-qr-toggle>
                <span className="text-xs font-bold text-white">📷 إظهار الـQR للعميل والفني (تكبير + تحميل + سكان)</span>
                <span className={`w-10 h-6 rounded-full p-0.5 ${f.showQr !== false ? 'bg-primary' : 'bg-slate-600'}`}><span className={`block w-5 h-5 rounded-full bg-white transition-transform ${f.showQr !== false ? '-translate-x-4' : ''}`} /></span>
              </button>
            )}
          </>
        )}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={lbl}>رسوم إضافية (ر.س)</label>
            <input type="number" value={f.fee ?? 0} onChange={set('fee')} className={inp} dir="ltr" />
          </div>
          <div>
            <label className={lbl}>الترتيب</label>
            <input type="number" value={f.sort ?? 99} onChange={set('sort')} className={inp} dir="ltr" />
          </div>
        </div>
        <button type="button" onClick={() => setF((x) => ({ ...x, enabled: !x.enabled }))} className="mt-3 w-full flex items-center justify-between bg-white/5 border border-white/10 rounded-xl px-3 py-2.5">
          <span className="text-xs font-bold text-white">مفعّلة للعملاء</span>
          <span className={`w-10 h-6 rounded-full p-0.5 ${f.enabled ? 'bg-primary' : 'bg-slate-600'}`}><span className={`block w-5 h-5 rounded-full bg-white transition-transform ${f.enabled ? '-translate-x-4' : ''}`} /></span>
        </button>
        {f.type === 'online' && <p className="text-[11px] text-amber-400/90 mt-2">الدفع الإلكتروني يعمل فقط عبر مزود الدفع المربوط بحساب المالك. لا تُدخل هنا أي أرقام بطاقات أو مفاتيح سرية.</p>}
        {err && <p className="text-rose-400 text-xs font-bold mt-3">{err}</p>}
        <button onClick={save} disabled={saving} className="mt-4 w-full flex items-center justify-center gap-2 bg-primary text-black font-black rounded-xl py-3 disabled:opacity-50">
          {saving ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} حفظ
        </button>
      </div>
    </div>
  )
}

function QrUpload({ value, onChange }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function pick(e) {
    const file = e.target.files?.[0]; e.target.value = ''
    if (!file) return
    setBusy(true); setErr('')
    try { const { url } = await storage.upload(file, `qr-${Date.now()}.${(file.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`); onChange(url) } catch (x) { setErr(errText(x, 'تعذّر رفع الصورة')) } finally { setBusy(false) }
  }
  return (
    <div className="flex items-center gap-3">
      {value ? <img src={value} alt="QR" className="w-20 h-20 rounded-lg bg-white object-contain p-1" /> : <div className="w-20 h-20 rounded-lg border border-dashed border-white/20 flex items-center justify-center text-[10px] text-slate-500">لا يوجد</div>}
      <div className="flex flex-col gap-1.5">
        <label className="cursor-pointer rounded-xl bg-white/10 hover:bg-white/15 px-3 py-2 text-xs font-bold text-white text-center">{busy ? 'جارِ الرفع…' : value ? 'تغيير الصورة' : 'ارفع صورة الكود'}<input type="file" accept="image/*" className="hidden" onChange={pick} disabled={busy} /></label>
        {value && <button type="button" onClick={() => onChange('')} className="text-[11px] text-rose-300">إزالة</button>}
        {err && <p className="text-[10px] text-rose-400">{err}</p>}
      </div>
    </div>
  )
}

const lbl = 'block text-[11px] font-bold text-slate-400 mt-3 mb-1'
const inp = 'w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2.5 text-sm text-white focus:outline-none focus:border-primary disabled:opacity-60'

function Centered({ children }) {
  return <div className="h-full flex items-center justify-center text-slate-500 text-sm bg-[#090c14]" dir="rtl">{children}</div>
}
