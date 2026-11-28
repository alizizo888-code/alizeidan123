import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import QRCode from 'qrcode'
import { ChevronRight, Loader2, RefreshCw, Smartphone, Plus, Trash2, Copy, Check, QrCode, KeyRound, ShieldCheck, Activity, Settings2, RotateCw, MonitorSmartphone, X } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { waApi } from '../hooks/useWa'
import { errText } from '../lib/_api'
import AccessDenied from '../components/AccessDenied'

// «أجهزة وأرقام الواتساب»: every desk device can be registered as a WhatsApp management device. It runs the
// WhatsApp Web gateway with its own key, carries its own numbers, and each number has a job (customers,
// technicians, complaints, marketing, reports, articles, site technician). Numbers are added from here: the
// device opens them on its next check-in and the QR shows up here to be scanned (server: Mutqan_WA_Fleet).

const DEV_KEY = 'mq_wa_device'
const ls = { get: () => { try { return JSON.parse(localStorage.getItem(DEV_KEY) || 'null') } catch { return null } }, set: (v) => { try { localStorage.setItem(DEV_KEY, JSON.stringify(v)) } catch { /* private */ } } }
const inp = 'w-full bg-white/10 border-2 border-white/20 rounded-lg px-3 py-2 text-sm font-bold text-white focus:outline-none focus:border-emerald-400'
const ST = {
  open: ['متصل', 'bg-emerald-500/20 text-emerald-300'], qr: ['امسح الـQR', 'bg-amber-500/20 text-amber-300'], code: ['اكتب كود الربط', 'bg-amber-500/20 text-amber-300'],
  connecting: ['بيتصل…', 'bg-sky-500/20 text-sky-300'], pending: ['بانتظار الجهاز', 'bg-sky-500/20 text-sky-300'], offline: ['الجهاز مقفول', 'bg-rose-500/20 text-rose-300'],
  closed: ['انقطع', 'bg-rose-500/20 text-rose-300'], stopped: ['متوقف', 'bg-rose-500/20 text-rose-300'], logged_out: ['اتفصل — اربطه تاني', 'bg-rose-500/20 text-rose-300'],
}
const ago = (s) => (s == null ? 'لم يتصل بعد' : s < 60 ? 'الآن' : s < 3600 ? `من ${Math.floor(s / 60)} د` : s < 86400 ? `من ${Math.floor(s / 3600)} س` : `من ${Math.floor(s / 86400)} يوم`)
const Box = ({ title, hint, icon: Icon, children, right }) => (
  <section className="bg-white/5 border-2 border-white/15 rounded-2xl p-4 flex flex-col gap-3">
    <div className="flex items-start gap-2">{Icon && <Icon size={16} className="text-emerald-300 mt-0.5 shrink-0" />}<div className="flex-1"><h2 className="font-display font-black text-sm text-emerald-300">{title}</h2>{hint && <p className="text-[11px] text-white/60 mt-0.5 leading-relaxed">{hint}</p>}</div>{right}</div>
    {children}
  </section>
)

function useCopy() {
  const [done, setDone] = useState('')
  return [done, async (k, text) => { try { await navigator.clipboard.writeText(text); setDone(k); setTimeout(() => setDone(''), 1800) } catch { /* no clipboard */ } }]
}

export default function WaFleet() {
  const nav = useNavigate()
  const { role, isOwner, loading } = useTeamRole()
  const allowed = isOwner || role === 'operations'
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [mine, setMine] = useState(ls.get)
  const [setup, setSetup] = useState(null)       // { id, name, setup }
  const [adding, setAdding] = useState(null)     // device id
  const [settings, setSettings] = useState(false)

  const load = useCallback(async () => {
    try { setData(await waApi('fleet')); setErr('') } catch (e) { setErr(errText(e, 'تعذر تحميل الأجهزة')) }
  }, [])
  useEffect(() => { if (allowed) load() }, [allowed, load])
  // Quick while a number waits to be scanned or a device has orders pending; slow otherwise.
  const waiting = (data?.numbers || []).some((n) => ['qr', 'code', 'pending', 'connecting'].includes(n.status)) || (data?.devices || []).some((d) => d.pending)
  useEffect(() => { if (!allowed) return undefined; const t = setInterval(load, waiting ? 4000 : 20000); return () => clearInterval(t) }, [allowed, waiting, load])

  const act = async (op, body = {}, after) => {
    setBusy(true); setErr('')
    try { const r = await waApi(op, body); after?.(r); await load() } catch (e) { setErr(errText(e, 'ما اتنفذش')) }
    setBusy(false)
  }

  if (loading) return <div className="h-full flex items-center justify-center text-slate-400">جارِ التحميل…</div>
  if (!allowed) return <AccessDenied need="المالك ومشرف العمليات" />

  const devices = data?.devices || []
  const numbers = data?.numbers || []
  const roles = data?.roles || {}
  const devName = (id) => devices.find((d) => d.id === id)?.name || (id ? 'جهاز قديم' : 'الوسيط الأساسي')

  return (
    <div className="h-full overflow-y-auto pb-24 text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><h1 className="font-display font-black text-base">أجهزة وأرقام الواتساب</h1><p className="text-[11px] text-white/60 truncate">السيرفر بيشيل كل الأرقام، وكل رقم له دوره وموقعه</p></div>
        {isOwner && <button onClick={() => setSettings((v) => !v)} aria-label="الإعدادات" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><Settings2 size={16} /></button>}
        <button onClick={load} aria-label="تحديث" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center">{busy ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />}</button>
      </header>
      {err && <p className="mx-4 mt-3 bg-rose-600/90 text-white text-[13px] font-black rounded-xl p-3">{err}</p>}
      {!data ? <p className="p-6 text-sm text-white/60">{err ? '' : 'جارِ التحميل…'}</p> : (
        <div className="p-4 max-w-5xl mx-auto grid gap-3">
          {settings && isOwner && <FleetSettings s={data.settings} onSave={(st) => act('fleet_save', { settings: st }, () => setSettings(false))} busy={busy} />}

          {/* Server only: phones (Termux) kept dropping, so devices are registered as servers. */}

          {isOwner && (
            <div className="rounded-2xl border border-sky-400/30 bg-sky-500/10 p-3 flex flex-wrap items-center gap-3">
              <div className="flex-1 min-w-[220px]"><p className="font-black text-[14px]">🖥️ سيرفر VPS (هستنجر — Ubuntu 24.04)</p><p className="text-[11px] text-white/70">الأفضل لعشرات الأرقام: شغال 24 ساعة، من غير موبايل ولا بطارية. أمر واحد يتلصق في Terminal السيرفر.</p></div>
              <button disabled={busy} onClick={() => act('fleet_register', { name: 'سيرفر VPS', kind: 'vps' }, setSetup)} className="rounded-xl bg-sky-500 text-black font-black text-[12px] px-3 py-2 disabled:opacity-50">سجّل السيرفر وهات الأمر</button>
            </div>
          )}
          {setup && <SetupCard s={setup} onClose={() => setSetup(null)} />}

          <Box title={`الأجهزة (${devices.length})`} hint="الجهاز يتصل بالموقع كل ثواني: يبعت رسايل أرقامه ويستلم الأوامر (إضافة رقم، فصل، إعادة تشغيل)" icon={MonitorSmartphone}>
            {!devices.length && <p className="text-[12px] text-white/60">لسه مفيش سيرفر مسجّل. اضغط «سجّل السيرفر وهات الأمر» من فوق.</p>}
            <div className="grid md:grid-cols-2 gap-2">
              {devices.map((d) => (
                <div key={d.id} className="rounded-xl border border-white/15 bg-black/20 p-3 flex flex-col gap-2">
                  <div className="flex items-center gap-2">
                    <span className={`w-2.5 h-2.5 rounded-full ${d.online ? 'bg-emerald-400' : 'bg-rose-400'}`} />
                    <p className="font-black text-[14px] flex-1 truncate">🖥️ {d.name}</p>
                    <span className="text-[10px] text-white/50" dir="ltr">{d.id}</span>
                  </div>
                  <p className="text-[11px] text-white/70">{d.online ? 'شغال' : 'مش متصل'} · آخر اتصال {ago(d.seenAgo)} · {d.numbers} رقم{d.pending ? ` · ${d.pending} أمر بانتظاره` : ''}</p>
                  <div className="flex flex-wrap gap-1.5">
                    <button onClick={() => setAdding(adding === d.id ? null : d.id)} className="flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-[12px] font-black"><Plus size={13} />إضافة رقم واتساب</button>
                    <button onClick={() => act('fleet_device', { id: d.id }, setSetup)} className="flex items-center gap-1 rounded-lg bg-white/10 px-2.5 py-1.5 text-[12px] font-black"><KeyRound size={13} />أمر التشغيل</button>
                    <button onClick={() => act('fleet_rotate', { id: d.id }, setSetup)} title="لو المفتاح اتشاف في صورة أو اتبعت لحد" className="flex items-center gap-1 rounded-lg bg-amber-500/80 text-black px-2.5 py-1.5 text-[12px] font-black"><RefreshCw size={13} />مفتاح جديد</button>
                    <button onClick={() => { if (confirm(`إلغاء «${d.name}»؟ أرقامه هتقف لحد ما تتسجل على جهاز تاني.`)) act('fleet_revoke', { id: d.id }) }} className="flex items-center gap-1 rounded-lg bg-rose-600/70 px-2.5 py-1.5 text-[12px] font-black"><Trash2 size={13} />إلغاء</button>
                  </div>
                  {adding === d.id && <AddNumber roles={roles} busy={busy} onCancel={() => setAdding(null)} onAdd={(b) => act('fleet_add', { device: d.id, ...b }, () => setAdding(null))} />}
                </div>
              ))}
            </div>
          </Box>

          <Box title={`الأرقام (${numbers.length})`} hint="كل رسالة أوتوماتيك بتطلع من الرقم اللي دوره كده: الطلبات من خط العملاء، كروت الشغل من خط الفنيين، التقارير من خط التقارير. الرقم على جهاز مقفول ما بيتبعتش منه حاجة" icon={Smartphone}>
            {!numbers.length && <p className="text-[12px] text-white/60">مفيش أرقام لسه. اضغط «إضافة رقم واتساب» على أي جهاز.</p>}
            <div className="grid md:grid-cols-2 gap-2">
              {numbers.map((n) => {
                const [lab, cls] = ST[n.status] || [n.status || '—', 'bg-white/10 text-white/70']
                return (
                  <div key={n.id} className="rounded-xl border border-white/15 bg-black/20 p-3 flex flex-col gap-2" data-testid="wa-number">
                    <div className="flex items-center gap-2">
                      <span className="text-lg">{roles[n.role]?.icon || '💬'}</span>
                      <div className="flex-1 min-w-0"><p className="font-black text-[14px] truncate">{n.label}</p><p className="text-[11px] text-white/60" dir="ltr">{n.phone ? '+' + n.phone : '—'}</p></div>
                      <span className={`text-[11px] font-black rounded-full px-2 py-0.5 ${cls}`}>{lab}</span>
                    </div>
                    <div className="grid grid-cols-2 gap-2 items-center">
                      <label className="text-[11px] font-black text-white/70 flex flex-col gap-1">الدور
                        <select className={inp} value={n.role} onChange={(e) => act('fleet_role', { id: n.id, role: e.target.value })}>{Object.entries(roles).map(([k, r]) => <option key={k} value={k} className="text-slate-900">{r.icon} {r.label}</option>)}</select>
                      </label>
                      <p className="text-[11px] text-white/60">على: <b className="text-white/80">{devName(n.device)}</b></p>
                    </div>
                    {n.qr && (
                      <div className="rounded-xl bg-white p-3 flex flex-col items-center gap-1.5">
                        <img src={n.qr} alt={`QR لربط ${n.label}`} className="w-56 h-56" />
                        <p className="text-[12px] font-black text-slate-800 text-center">من موبايل الرقم ده: واتساب ← الأجهزة المرتبطة ← ربط جهاز ← امسح الكود</p>
                      </div>
                    )}
                    {n.pairingCode && <p className="rounded-xl bg-amber-500/15 border border-amber-400/40 p-2 text-center"><span className="text-[11px] block text-amber-100">كود الربط (واتساب ← الأجهزة المرتبطة ← الربط برقم الهاتف)</span><b className="text-xl tracking-widest" dir="ltr">{n.pairingCode}</b></p>}
                    {n.status === 'pending' && <p className="text-[11px] text-sky-200">الأمر وصل للجهاز وبيستنى أول اتصال منه — الـQR هيظهر هنا خلال ثواني من تشغيل الوسيط.</p>}
                    <div className="flex gap-1.5">
                      {n.device && <button onClick={() => act('fleet_restart', { id: n.id })} className="flex items-center gap-1 rounded-lg bg-white/10 px-2.5 py-1.5 text-[12px] font-black"><RotateCw size={13} />إعادة ربط</button>}
                      <button onClick={() => { if (confirm(`فصل «${n.label}»؟`)) act('fleet_remove', { id: n.id }) }} className="flex items-center gap-1 rounded-lg bg-rose-600/70 px-2.5 py-1.5 text-[12px] font-black"><Trash2 size={13} />فصل</button>
                    </div>
                  </div>
                )
              })}
            </div>
          </Box>

          <Box title="الفحص والتتبع" hint="كل ساعة السيرفر بيفحص الأجهزة والأرقام، وبيأرشف المحادثات الخاملة، ويبلغ الفريق لو جهاز وقف أو رقم اتفصل" icon={Activity}
            right={<button onClick={() => act('fleet_check')} className="rounded-lg bg-white/10 px-2.5 py-1.5 text-[11px] font-black">افحص الآن</button>}>
            {(data.health?.issues || []).length ? data.health.issues.map((x, i) => <p key={i} className="text-[12px] bg-white/5 rounded-lg p-2">• {x}</p>) : <p className="text-[12px] text-white/60">لسه ما اتعملش فحص.</p>}
            {data.health?.at && <p className="text-[10px] text-white/40">آخر فحص: {String(data.health.at).slice(0, 16).replace('T', ' ')}</p>}
            <div className="grid sm:grid-cols-2 gap-1.5">{Object.entries(roles).map(([k, r]) => { const on = numbers.filter((n) => n.role === k && n.status === 'open').length; return <p key={k} className={`text-[11px] rounded-lg px-2 py-1.5 ${on ? 'bg-emerald-500/10 text-emerald-100' : 'bg-white/5 text-white/50'}`}>{r.icon} {r.label}: {on ? `${on} رقم متصل` : 'مفيش — بيطلع من خط العملاء أو العام'}</p> })}</div>
          </Box>
        </div>
      )}
    </div>
  )
}

function SetupCard({ s, onClose }) {
  const [qr, setQr] = useState('')
  const [done, copy] = useCopy()
  useEffect(() => { QRCode.toDataURL(s.setup || '', { margin: 1, width: 360, errorCorrectionLevel: 'L' }).then(setQr).catch(() => setQr('')) }, [s.setup])
  return (
    <Box title={`أمر تشغيل «${s.name}»`} hint="الأمر فيه مفتاح السيرفر — ما تبعتهوش لحد وما تصوّرهوش. الصقه مرة واحدة في Terminal السيرفر (root)، وبعدها أضف الأرقام من هنا. لو المفتاح اتشاف، اضغط «مفتاح جديد» والصق الأمر الجديد." icon={KeyRound}
      right={<button onClick={onClose} aria-label="إغلاق" className="text-white/70"><X size={16} /></button>}>
      <ol className="text-[12px] text-white/80 list-decimal pr-5 grid gap-1">
          <li>هستنجر hPanel ← VPS ← <b>Browser terminal</b> (أو SSH كـ root).</li>
          <li>انسخ الأمر كله والصقه واضغط Enter — بيثبّت Node 20 والوسيط كخدمة بترجع لوحدها بعد أي إعادة تشغيل.</li>
          <li>النقطة جنب «سيرفر VPS» تخضر خلال ثواني. بعدها: الواتساب ← الأرقام ← <b>ربط رقم جديد</b> وامسح الـQR.</li>
        </ol>
      <pre className="text-[11px] leading-relaxed whitespace-pre-wrap break-all bg-black/40 rounded-lg p-3 max-h-56 overflow-y-auto" dir="ltr">{s.setup}</pre>
      <div className="flex flex-wrap gap-2 items-start">
        <button onClick={() => copy('setup', s.setup)} className="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-[12px] font-black">{done === 'setup' ? <Check size={14} /> : <Copy size={14} />}{done === 'setup' ? 'اتنسخ' : 'نسخ الأمر'}</button>

      </div>
    </Box>
  )
}

function AddNumber({ roles, busy, onAdd, onCancel }) {
  const [f, setF] = useState({ role: 'customers', label: '', mode: 'qr', phone: '' })
  const set = (k, v) => setF((x) => ({ ...x, [k]: v }))
  return (
    <div className="rounded-xl border border-emerald-400/30 bg-emerald-500/5 p-3 grid gap-2">
      <div className="grid grid-cols-2 gap-2">
        <label className="text-[11px] font-black flex flex-col gap-1">دور الرقم<select className={inp} value={f.role} onChange={(e) => set('role', e.target.value)}>{Object.entries(roles).map(([k, r]) => <option key={k} value={k} className="text-slate-900">{r.icon} {r.label}</option>)}</select></label>
        <label className="text-[11px] font-black flex flex-col gap-1">اسم يظهر لكم<input className={inp} value={f.label} onChange={(e) => set('label', e.target.value)} placeholder={roles[f.role]?.label} maxLength={40} /></label>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <button onClick={() => set('mode', 'qr')} className={`rounded-lg py-2 text-[12px] font-black border ${f.mode === 'qr' ? 'bg-emerald-600 border-emerald-400' : 'border-white/15 text-white/70'}`}>صورة QR</button>
        <button onClick={() => set('mode', 'code')} className={`rounded-lg py-2 text-[12px] font-black border ${f.mode === 'code' ? 'bg-emerald-600 border-emerald-400' : 'border-white/15 text-white/70'}`}>كود ربط برقم الهاتف</button>
      </div>
      {f.mode === 'code' && <input className={inp} dir="ltr" value={f.phone} onChange={(e) => set('phone', e.target.value)} placeholder="05xxxxxxxx" inputMode="tel" />}
      <div className="flex gap-2">
        <button onClick={() => onAdd(f)} disabled={busy || (f.mode === 'code' && f.phone.replace(/\D/g, '').length < 9)} className="flex-1 rounded-lg bg-emerald-600 py-2 text-sm font-black disabled:opacity-50">إضافة</button>
        <button onClick={onCancel} className="rounded-lg bg-white/10 px-4 text-sm font-black">إلغاء</button>
      </div>
    </div>
  )
}

function FleetSettings({ s, onSave, busy }) {
  const [f, setF] = useState({ ...s, reportTo: (s.reportTo || []).join('، ') })
  const set = (k, v) => setF((x) => ({ ...x, [k]: v }))
  return (
    <Box title="إعدادات الأجهزة والأرقام" icon={Settings2}>
      <label className="text-[11px] font-black flex flex-col gap-1">التقارير اليومية تتبعت على واتساب لـ (أرقام مفصولة بفاصلة)<input className={inp} dir="ltr" value={f.reportTo} onChange={(e) => set('reportTo', e.target.value)} placeholder="05xxxxxxxx, 05xxxxxxxx" /></label>
      <div className="grid grid-cols-2 gap-2">
        <label className="text-[11px] font-black flex flex-col gap-1">أرشفة المحادثة بعد (يوم خمول)<input type="number" min="3" className={inp} value={f.archiveDays} onChange={(e) => set('archiveDays', e.target.value)} /></label>
        <label className="text-[11px] font-black flex flex-col gap-1">حد الإرسال اليومي لكل رقم<input type="number" min="20" className={inp} value={f.dailyCap} onChange={(e) => set('dailyCap', e.target.value)} /></label>
      </div>
      <div className="flex flex-wrap gap-3 text-[12px] font-black">
        {[['complaintAlert', 'تنبيه فوري للعمليات عند أي شكوى'], ['healthAlerts', 'تنبيه لو جهاز وقف أو رقم اتفصل'], ['aiTagging', 'تصنيف المحادثات بالذكاء الاصطناعي (يستهلك من الحصة)']].map(([k, l]) => <label key={k} className="flex items-center gap-1.5"><input type="checkbox" checked={!!f[k]} onChange={(e) => set(k, e.target.checked ? 1 : 0)} />{l}</label>)}
      </div>
      <details className="text-[11px] text-white/70"><summary className="cursor-pointer font-black">متقدم: مصدر الوسيط</summary>
        <div className="grid sm:grid-cols-3 gap-2 mt-2">
          <input className={inp} dir="ltr" value={f.repo} onChange={(e) => set('repo', e.target.value)} placeholder="https://github.com/…git" />
          <input className={inp} dir="ltr" value={f.branch} onChange={(e) => set('branch', e.target.value)} placeholder="branch" />
          <input className={inp} dir="ltr" value={f.subdir} onChange={(e) => set('subdir', e.target.value)} placeholder="subdir (اختياري)" />
        </div>
      </details>
      <button onClick={() => onSave({ ...f, reportTo: f.reportTo })} disabled={busy} className="self-start rounded-lg bg-emerald-600 px-4 py-2 text-sm font-black">حفظ</button>
    </Box>
  )
}
