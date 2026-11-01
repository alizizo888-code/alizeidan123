import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Loader2, Link2, Send, Copy, Check, Unplug, Smartphone, Headphones, Phone, Video } from 'lucide-react'
import { useTeamRole } from '../hooks/useTeamRole'
import { hotlink, telegram, brandName } from '../lib/link'
import { errText } from '../lib/_api'

const inp = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-slate-500 outline-none focus:border-primary'
const btn = 'rounded-xl font-black text-sm px-4 py-2.5 disabled:opacity-50 flex items-center justify-center gap-2'
const ago = (iso) => { const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000); return s < 90 ? 'الآن' : s < 3600 ? `قبل ${Math.round(s / 60)} د` : s < 86400 ? `قبل ${Math.round(s / 3600)} س` : `قبل ${Math.round(s / 86400)} يوم` }

// «ربط الخط الساخن»: a secret link code pairs the site's hotline button with the devices that answer it (like a TeamViewer partner code),
// plus a free Telegram bot so orders and calls ring on the phone even when the app is closed, and customers can chat with no registration.
// The code and the bot token are typed here, sent straight to the server and kept there only (the code as a hash, the token encrypted).
export default function HotlineLink() {
  const nav = useNavigate()
  const { isOwner, role, loading } = useTeamRole()
  const [st, setSt] = useState(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState('')
  const [code, setCode] = useState('')
  const [tok, setTok] = useState('')
  const [pairUrl, setPairUrl] = useState('')
  const [copied, setCopied] = useState('')
  const staff = isOwner || role === 'operations' || role === 'supervisor'

  const load = useCallback(async () => { try { setSt(await hotlink.status()); setErr('') } catch (e) { setErr(errText(e, 'تعذر قراءة حالة الربط')) } }, [])
  useEffect(() => { if (staff) { load(); const t = setInterval(load, 20000); return () => clearInterval(t) } }, [staff, load])
  const run = (key, fn, ok) => async () => { setBusy(key); setErr(''); setMsg(''); try { const r = await fn(); if (ok) setMsg(ok); return r } catch (e) { setErr(errText(e, 'تعذر التنفيذ')) } finally { setBusy('') } }
  const copy = (v) => { try { navigator.clipboard.writeText(v) } catch { /* ignore */ } setCopied(v); setTimeout(() => setCopied(''), 1800) }

  if (loading) return <p className="p-6 text-slate-400 text-sm" dir="rtl">جارِ التحقق…</p>
  if (!staff) return <p className="p-6 text-white text-sm font-bold" dir="rtl">هذه الصفحة للإدارة والعمليات فقط.</p>
  const tg = st?.tg
  const site = brandName()
  return (
    <div className="h-full overflow-y-auto pb-24 text-white" dir="rtl">
      <header className="sticky top-0 z-20 bg-slate-950/90 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
        <button onClick={() => nav(-1)} aria-label="رجوع" className="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1"><h1 className="font-display font-black text-base">ربط الخط الساخن — {site}</h1><p className="text-[11px] text-white/60">رمز ربط + تنبيهات تيليجرام ودردشة العملاء</p></div>
      </header>
      <div className="p-4 max-w-xl mx-auto grid gap-4">
        {err && <p className="rounded-xl bg-rose-500/10 border border-rose-400/30 p-3 text-xs font-bold text-rose-200 leading-relaxed">{err}</p>}
        {msg && <p className="rounded-xl bg-emerald-500/10 border border-emerald-400/30 p-3 text-xs font-bold text-emerald-200">{msg}</p>}

        <section className="rounded-2xl bg-white/5 border border-white/10 p-4 grid gap-3">
          <p className="font-black text-sm flex items-center gap-2"><Link2 size={16} className="text-primary" />رمز الربط بين زر الخط الساخن في الموقع والتطبيق</p>
          <p className="text-[12px] leading-relaxed text-slate-300">تكتب الرمز <b>بنفسك</b> هنا مرة واحدة؛ يُحفظ في موقع {site} مشفّرًا (لا يُعرض ولا يُسجَّل). كل جهاز يُربط بالرمز يظهر «متصلًا» للعملاء ما دام التطبيق مفتوحًا عليه، فإذا لم يكن أحد متصلًا يُسجَّل طلب العميل برمزه ويصلكم تنبيه بدل أن يرنّ في الفراغ.</p>
          <p className="text-[12px] font-bold">{st == null ? '…' : st.set ? <>الحالة: <span className={st.online ? 'text-emerald-300' : 'text-amber-300'}>{st.online ? 'الخط متصل الآن ✓' : 'الخط مربوط لكن لا أحد متصل الآن'}</span> · هذا الجهاز: <span className={st.linkedHere ? 'text-emerald-300' : 'text-slate-400'}>{st.linkedHere ? 'مربوط ✓' : 'غير مربوط'}</span></> : <span className="text-amber-300">لم يُضبط رمز الربط بعد{isOwner ? ' — اكتبه ثم اضغط «تعيين الرمز».' : ' — يضبطه المالك أولًا.'}</span>}</p>
          <input type="password" autoComplete="new-password" dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} placeholder="رمز الربط (٦ إلى ٦٤ خانة)" className={inp} />
          <div className="flex flex-wrap gap-2">
            {st?.set && <button disabled={!!busy || code.trim().length < 6} onClick={run('link', async () => { await hotlink.link(code.trim()); setCode(''); await load() }, 'تم ربط هذا الجهاز ✓')} className={`${btn} bg-primary text-black`}>{busy === 'link' && <Loader2 size={14} className="animate-spin" />}ربط هذا الجهاز</button>}
            {isOwner && <button disabled={!!busy || code.trim().length < 6} onClick={run('set', async () => { await hotlink.set(code.trim()); setCode(''); await load() }, st?.set ? 'تم تغيير الرمز: فُصلت بقية الأجهزة وربط هذا الجهاز ✓' : 'تم تعيين الرمز وربط هذا الجهاز ✓')} className={`${btn} ${st?.set ? 'bg-white/10 text-white' : 'bg-primary text-black'}`}>{busy === 'set' && <Loader2 size={14} className="animate-spin" />}{st?.set ? 'تغيير الرمز (يفصل بقية الأجهزة)' : 'تعيين الرمز وربط هذا الجهاز'}</button>}
          </div>
          {st?.set && st.linkedHere && <button disabled={!!busy} onClick={run('unlink', async () => { await hotlink.unlink(); await load() }, 'تم فصل هذا الجهاز')} className="self-start text-xs font-bold text-slate-400 underline flex items-center gap-1"><Unplug size={13} />فصل هذا الجهاز</button>}
          {st?.devices?.length > 0 && (
            <ul className="grid gap-1.5 mt-1">
              {st.devices.map((d) => (
                <li key={d.id} className="flex items-center gap-2 rounded-xl bg-white/5 border border-white/5 px-3 py-2">
                  <span className={`w-2.5 h-2.5 rounded-full shrink-0 ${d.online ? 'bg-emerald-400' : 'bg-slate-600'}`} />
                  <span className="flex-1 min-w-0"><span className="block text-sm font-bold truncate">{d.label || 'جهاز'}{d.me ? ' (هذا الجهاز)' : ''}</span><span className="block text-[10px] text-slate-400">{d.online ? 'متصل' : 'آخر ظهور ' + ago(d.seenAt)}</span></span>
                  {isOwner && !d.me && <button onClick={run('rm' + d.id, async () => { await hotlink.unlink(d.id); await load() })} className="text-[11px] font-bold text-rose-300">فصل</button>}
                </li>
              ))}
            </ul>
          )}
          {isOwner && st?.set && <button disabled={!!busy} onClick={run('clear', async () => { if (confirm('إلغاء الربط نهائيًا وفصل كل الأجهزة؟')) { await hotlink.clear(); await load() } })} className="self-start text-xs font-bold text-rose-300 underline">إلغاء الربط نهائيًا</button>}
        </section>

        <section className="rounded-2xl bg-white/5 border border-white/10 p-4 grid gap-3">
          <p className="font-black text-sm flex items-center gap-2"><Send size={16} className="text-sky-300" />تيليجرام — تنبيهات فورية ودردشة العملاء (مجاني)</p>
          <p className="text-[12px] leading-relaxed text-slate-300">كل طلب جديد أو مكالمة أو رسالة عميل تصلك على تيليجرام فورًا ولو كان التطبيق مغلقًا (بصوت تيليجرام نفسه). والعميل يكتب لبوتكم دون تسجيل أي حساب؛ وأنت ترد بالضغط على «رد» على رسالته.</p>
          {!tg ? <p className="text-xs text-slate-400">…</p> : tg.on ? (
            <>
              <p className="text-[12px] font-bold text-emerald-300">البوت يعمل: @{tg.bot} · {tg.chats} محادثة فريق مربوطة{tg.mine ? ' (منها محادثتك ✓)' : ''}</p>
              <div className="flex flex-wrap gap-2">
                <button disabled={!!busy} onClick={run('pair', async () => { const r = await telegram.pair(); setPairUrl(r.link); await load() })} className={`${btn} bg-sky-400 text-black`}>{busy === 'pair' && <Loader2 size={14} className="animate-spin" />}اربط تيليجرام بجهازي</button>
                {tg.mine && <button disabled={!!busy} onClick={run('unpair', async () => { await telegram.unpair(); setPairUrl(''); await load() }, 'تم إيقاف تنبيهاتي على تيليجرام')} className={`${btn} bg-white/10 text-white`}>إيقاف تنبيهاتي</button>}
                {isOwner && <button disabled={!!busy} onClick={run('test', async () => { const r = await telegram.test(); return r }, 'أُرسلت رسالة تجربة لكل المحادثات المربوطة')} className={`${btn} bg-white/10 text-white`}>رسالة تجربة</button>}
              </div>
              {pairUrl && <a href={pairUrl} target="_blank" rel="noopener noreferrer" className="rounded-xl bg-sky-400/15 border border-sky-400/40 p-3 text-xs font-bold text-sky-100 leading-relaxed block">① اضغط هنا لفتح تيليجرام ② اضغط <b>Start</b> ← يتم الربط فورًا (الرابط صالح ١٠ دقائق ويعمل مرة واحدة).</a>}
              <div className="rounded-xl bg-white/5 border border-white/10 p-3 grid gap-1.5">
                <p className="text-[11px] font-bold text-slate-300">رابط دردشة العملاء (ضعه حيث شئت — يظهر تلقائيًا في زر الخط الساخن بالموقع):</p>
                <div className="flex items-center gap-2"><code dir="ltr" className="flex-1 text-[11px] text-sky-200 truncate">{tg.link}</code><button onClick={() => copy(tg.link)} aria-label="نسخ" className="shrink-0 w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center">{copied === tg.link ? <Check size={14} className="text-emerald-300" /> : <Copy size={14} />}</button></div>
              </div>
              {isOwner && <button disabled={!!busy} onClick={run('off', async () => { if (confirm('إيقاف بوت تيليجرام نهائيًا؟')) { await telegram.off(); setPairUrl(''); await load() } })} className="self-start text-xs font-bold text-rose-300 underline">إيقاف البوت</button>}
            </>
          ) : isOwner ? (
            <>
              <ol className="text-[12px] leading-loose text-slate-200 list-decimal pr-5">
                <li>افتح تيليجرام وابحث عن <b>@BotFather</b> واكتب له <b dir="ltr">/newbot</b>.</li>
                <li>اختر اسمًا للبوت (مثلًا «{site}») ثم اسم مستخدم ينتهي بـ bot.</li>
                <li>سيعطيك «رمزًا» طويلًا شكله <b dir="ltr">123456:ABC…</b> — انسخه والصقه هنا.</li>
              </ol>
              <input type="password" autoComplete="off" dir="ltr" value={tok} onChange={(e) => setTok(e.target.value)} placeholder="رمز البوت من BotFather" className={inp} />
              <button disabled={!!busy || tok.trim().length < 20} onClick={run('save', async () => { await telegram.save(tok.trim()); setTok(''); await load() }, 'تم تفعيل البوت ✓ — اضغط الآن «اربط تيليجرام بجهازي».')} className={`${btn} bg-sky-400 text-black`}>{busy === 'save' && <Loader2 size={14} className="animate-spin" />}تفعيل البوت</button>
            </>
          ) : <p className="text-xs text-amber-300">بوت تيليجرام غير مفعّل بعد — يفعّله المالك.</p>}
        </section>

        <section className="rounded-2xl bg-white/5 border border-white/10 p-4 grid gap-2.5">
          <p className="font-black text-sm">طرق ربط أخرى سهلة ومجانية</p>
          {[
            [Smartphone, 'تيليجرام (مفعّل أعلاه)', 'أسرع حل: تنبيه فوري لهاتفك + دردشة مع العميل بالرد على رسالته، بلا تسجيل ولا كلمات مرور.'],
            [Headphones, 'مكالمة داخل التطبيق (تعمل الآن)', 'العميل يضغط سماعة الخط الساخن فيرنّ جهازك المربوط ويتحدثان صوتيًا مجانًا. تحتاج التطبيق مفتوحًا.'],
            [Phone, 'واتساب والاتصال العادي', 'أرقام الموقع في «أرقام الموقع» تظهر كأزرار واتساب واتصال في كل صفحة.'],
            [Video, 'Jitsi Meet (اقتراح)', 'غرفة فيديو/صوت بلا حساب عبر meet.jit.si تُرسل رابطها للعميل في الدردشة، تصلح للمعاينة عن بُعد. لم تُفعَّل بعد — قل لي إن أردتها.'],
          ].map(([I, t, d]) => (
            <div key={t} className="flex gap-3 items-start"><span className="shrink-0 w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-primary"><I size={15} /></span><div><p className="text-[13px] font-black">{t}</p><p className="text-[11px] text-slate-400 leading-relaxed">{d}</p></div></div>
          ))}
        </section>
      </div>
    </div>
  )
}
