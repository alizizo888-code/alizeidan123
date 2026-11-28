import { useState } from 'react'
import { db } from '../lib/db'
import { post, errText } from '../lib/_api'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'
import BankAccountCard from './BankAccountCard'
import { storage } from '../lib/storage'

// The technician's side of «إدارة الفني»: a freeze notice, his prepaid credit, the account his earnings go to,
// and his contract with a «موافق» button (shown only when the owner turned the contract on and sent it).
export default function TechTerms({ myId, wallet, acc, lines, displayName, acting }) {
  const w = wallet || {}
  const c = w.contract || {}
  const { data: acks } = useLiveShared('tech_contract_acks', { filters: { techId: myId || '__none__' }, order: '-at', limit: 5 })
  const ack = (acks || []).find((a) => a.version === c.version)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const p = w.payout || {}
  const { data: reqs } = useLiveShared('payout_requests', { filters: { techId: myId || '__none__' }, order: '-createdAt', limit: 5 })
  const [calc, setCalc] = useState(false)
  const [amt, setAmt] = useState('')
  const [pmsg, setPmsg] = useState('')
  const due = !acc ? 0 : acc.mode === 'prepaid' ? Math.max(0, acc.credit - (acc.min || 0)) : Math.max(0, -acc.balance)
  const pending = (reqs || []).find((r) => r.status === 'pending')
  const last = (reqs || [])[0]
  const [top, setTop] = useState({ open: false, amount: '', url: '', up: false })
  const [obj, setObj] = useState({ id: '', text: '' })
  const recentPen = (lines || []).filter((s) => s.kind === 'penalty' && Date.now() - Date.parse(s.at) < 7 * 86400000).sort((a, b) => String(b.at).localeCompare(String(a.at)))
  async function sendTopup() {
    if (!(Number(top.amount) > 0) || !top.url) { setPmsg('اكتب المبلغ وارفع صورة الإيصال'); return }
    setBusy(true); setPmsg('')
    try { await post('/field', { op: 'topup', amount: Number(top.amount), receiptUrl: top.url }); setTop({ open: false, amount: '', url: '', up: false }); setPmsg('✓ الإيصال وصل للإدارة — الرصيد يتضاف بعد الاعتماد') } catch (e) { setPmsg(errText(e, 'تعذّر')) } finally { setBusy(false) }
  }
  async function sendObjection() {
    if (!obj.text.trim()) return
    setBusy(true)
    try { await post('/field', { op: 'objection', id: obj.id, text: obj.text }); setObj({ id: '', text: '' }); setPmsg('✓ اعتراضك وصل للإدارة') } catch (e) { setPmsg(errText(e, 'تعذّر')) } finally { setBusy(false) }
  }
  async function askPayout() {
    const n = Number(amt || due)
    if (!(n > 0)) { setPmsg('مفيش مستحقات متاحة للتحويل دلوقتي'); return }
    setBusy(true); setPmsg('')
    try {
      const r = await post('/field', { op: 'payout', amount: n, sum: { mode: acc.mode, jobs: acc.jobs, sales: acc.sales, commission: acc.commission, cash: acc.cash, balance: acc.balance, credit: acc.credit, due } })
      setPmsg(`✓ طلبك وصل — التحويل ${r?.eta || 'خلال يوم عمل'} على حسابك المسجل.`); setCalc(false)
    } catch (e) { setPmsg(errText(e, 'تعذّر الإرسال')) } finally { setBusy(false) }
  }

  async function accept() {
    setBusy(true); setErr('')
    try {
      const gid = await team.groupId()
      await db.insertShared('tech_contract_acks', { techId: myId, version: c.version, name: displayName || '', at: new Date().toISOString(), ua: navigator.userAgent.slice(0, 160), text: c.text }, undefined, { groupId: gid, visibleTo: 'group' })
      setOpen(false)
    } catch (e) { setErr((e && e.message) || 'تعذّر الحفظ') } finally { setBusy(false) }
  }

  return (
    <>
      {w.frozen && <div className="rounded-2xl bg-rose-500/15 border border-rose-400/30 p-3 text-xs font-black text-rose-100">⛔ حسابك موقوف عن استلام طلبات جديدة{w.frozenReason ? ` — ${w.frozenReason}` : ''}. كلّم الإدارة.</div>}
      {acc?.mode === 'prepaid' && (
        <div className={`rounded-2xl p-3 border text-xs font-black ${acc.low ? 'bg-rose-500/15 border-rose-400/30 text-rose-100' : 'bg-sky-500/10 border-sky-400/20 text-sky-100'}`}>
          💳 رصيدك: {Math.round(acc.credit)} ر.س{acc.min ? ` · الحد الأدنى ${acc.min} ر.س` : ''}
          {acc.low && <p className="font-bold mt-1">رصيدك أقل من الحد — اشحن عشان توصلك طلبات. حوّل على حسابات الشركة وابعت الإيصال للإدارة.</p>}
          <a href="#/pay" className="block mt-2 text-center rounded-lg bg-white/10 py-1.5">حسابات الشركة للشحن ←</a>
        </div>
      )}
      {acc?.capped && <div className="rounded-2xl bg-rose-500/15 border border-rose-400/30 p-3 text-xs font-black text-rose-100">💵 معاك {Math.round(acc.balance)} ر.س للشركة — أكتر من الحد ({acc.cap} ر.س). سلّمهم للإدارة عشان توصلك طلبات تاني.</div>}
      {acc?.mode === 'prepaid' && !acting && (
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
          <button onClick={() => setTop((t) => ({ ...t, open: !t.open }))} className="rounded-xl bg-sky-500/20 border border-sky-400/30 text-sky-100 font-black py-2.5 text-sm">💳 شحن رصيد بإيصال تحويل</button>
          {top.open && (
            <div className="grid gap-2">
              <input value={top.amount} onChange={(e) => setTop((t) => ({ ...t, amount: e.target.value }))} inputMode="decimal" dir="ltr" placeholder="المبلغ اللي حوّلته" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
              <label className="rounded-lg bg-white/10 text-white text-xs font-black py-2 text-center cursor-pointer">{top.up ? 'جارِ الرفع…' : top.url ? '✓ الإيصال اترفع — تغيير' : '📷 ارفع صورة الإيصال'}<input type="file" accept="image/*,application/pdf" className="hidden" onChange={async (e) => { const f = e.target.files?.[0]; e.target.value = ''; if (!f) return; setTop((t) => ({ ...t, up: true })); try { const { url } = await storage.upload(f, `topup-${Date.now()}-${f.name}`); setTop((t) => ({ ...t, url, up: false })) } catch (x) { setPmsg(errText(x, 'تعذّر الرفع')); setTop((t) => ({ ...t, up: false })) } }} /></label>
              <button onClick={sendTopup} disabled={busy || top.up} className="rounded-lg bg-emerald-500 text-black font-black py-2 text-sm">إرسال للاعتماد</button>
            </div>
          )}
        </section>
      )}
      {recentPen.length > 0 && !acting && (
        <section className="rounded-2xl bg-rose-500/5 border border-rose-400/20 p-3 grid gap-1.5">
          <p className="text-xs font-black text-rose-200">⚠️ خصومات آخر أسبوع</p>
          {recentPen.map((s) => (
            <div key={s.id} className="text-[11px] text-slate-200 bg-black/20 rounded-lg p-2">
              <p>{s.note} — <b>{Number(s.amount)} ر.س</b></p>
              {s.objection ? <p className="text-amber-200 mt-0.5">✋ اعترضت: {s.objection.text} {s.objection.status === 'accepted' ? '— اتقبل ✓' : s.objection.status === 'rejected' ? '— اترفض' : '— تحت المراجعة'}</p>
                : Date.now() - Date.parse(s.at) < 48 * 3600000 && (obj.id === s.id
                  ? <div className="flex gap-1.5 mt-1"><input value={obj.text} onChange={(e) => setObj({ id: s.id, text: e.target.value })} placeholder="سبب اعتراضك" className="flex-1 rounded bg-white/10 px-2 py-1 text-white" /><button onClick={sendObjection} disabled={busy} className="rounded bg-amber-400 text-black font-black px-2">إرسال</button></div>
                  : <button onClick={() => setObj({ id: s.id, text: '' })} className="mt-1 rounded bg-white/10 text-white font-black px-2 py-0.5">اعتراض (خلال 48 ساعة)</button>)}
            </div>
          ))}
        </section>
      )}
      {!acting && <a href={`#/staff/statement?month=${new Date().toISOString().slice(0, 7)}`} className="rounded-2xl bg-white/[0.04] border border-white/10 px-3 py-2.5 text-xs font-black text-white flex items-center">📄 كشف حسابي الشهري (PDF)<span className="mr-auto text-slate-400">←</span></a>}
      {acc && acc.jobs > 0 && acc.share > 0 && acc.cashShare !== acc.share && (
        <div className="rounded-2xl bg-emerald-500/10 border border-emerald-400/20 p-3 text-[11px] font-bold text-emerald-100">💡 نصيبك لو العميل حوّل على حساب الشركة أو دفع بالشبكة: <b>{acc.share}%</b> — ولو دفع كاش: <b>{acc.cashShare}%</b>. خلّي العميل يحوّل وكسبك يزيد.</div>
      )}
      {acc && !acting && (
        <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
          <button onClick={() => { setCalc((x) => !x); setAmt(due ? String(Math.floor(due)) : '') }} disabled={!!pending} className="rounded-xl bg-amber-400 text-black font-black py-2.5 text-sm disabled:opacity-60">{pending ? `⏳ طلب تحويل ${Number(pending.amount)} ر.س تحت التنفيذ` : '💸 اطلب تحويل مستحقاتي'}</button>
          {last && last.status === 'paid' && <p className="text-[11px] text-emerald-300 font-bold">✓ آخر تحويل: {Number(last.amount)} ر.س يوم {new Date(last.paidAt).toLocaleDateString('ar-SA')}{last.receiptUrl && <a href={last.receiptUrl} target="_blank" rel="noreferrer" className="underline mr-1">الإيصال</a>}</p>}
          {last && last.status === 'rejected' && <p className="text-[11px] text-rose-300 font-bold">آخر طلب اترفض{last.reason ? `: ${last.reason}` : ''}</p>}
          {calc && (
            <div className="rounded-xl bg-black/30 p-3 grid gap-1.5 text-[11px] text-slate-300">
              <p className="font-black text-white text-xs">🧮 حسابك</p>
              <p>طلبات مكتملة: <b className="text-white">{acc.jobs}</b> · مبيعات: <b className="text-white">{Math.round(acc.sales)} ر.س</b></p>
              <p>نصيبك من الشغل: <b className="text-amber-300">{Math.round(acc.commission)} ر.س</b></p>
              {acc.mode === 'prepaid' ? <p>رصيدك: <b className="text-white">{Math.round(acc.credit)} ر.س</b>{acc.min ? ` − الحد الأدنى ${acc.min}` : ''}</p>
                : <p>نقد معاك: <b className="text-white">{Math.round(acc.cash)} ر.س</b> · اتسوّى: <b className="text-white">{Math.round(acc.settledIn - acc.settledOut)} ر.س</b>{acc.penalty ? ` · خصومات ${Math.round(acc.penalty)}` : ''}{acc.bonus ? ` · مكافآت ${Math.round(acc.bonus)}` : ''}</p>}
              <p className="text-sm font-black text-emerald-300">المتاح للتحويل: {Math.round(due)} ر.س</p>
              <input value={amt} onChange={(e) => setAmt(e.target.value)} inputMode="decimal" dir="ltr" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" placeholder="المبلغ" />
              {!(p.iban || p.accountNumber || p.walletPhone) && <p className="text-rose-300 font-bold">بلّغ الإدارة ببيانات حسابك البنكي عشان يتحوّل عليه.</p>}
              <button onClick={askPayout} disabled={busy} className="rounded-lg bg-emerald-500 text-black font-black py-2 text-sm">{busy ? 'جارِ الإرسال…' : 'تأكيد طلب التحويل'}</button>
            </div>
          )}
          {pmsg && <p className="text-[11px] font-black text-amber-200">{pmsg}</p>}
        </section>
      )}
      {(p.iban || p.accountNumber || p.walletPhone) && (
        <details className="rounded-2xl bg-white/[0.04] border border-white/10 p-3">
          <summary className="text-xs font-black text-white cursor-pointer">🏦 الحساب اللي بتوصلك عليه أرباحك</summary>
          <div className="mt-2"><BankAccountCard m={{ ...p, name: 'حسابك', enabled: true }} compact /></div>
        </details>
      )}
      {c.enabled && c.sentAt && (
        <div className={`rounded-2xl p-3 border ${ack ? 'bg-emerald-500/10 border-emerald-400/20' : 'bg-amber-500/10 border-amber-400/30'}`}>
          <div className="flex items-center gap-2">
            <p className="flex-1 text-xs font-black text-white">📄 عقد التعاون {ack ? `— وافقت يوم ${new Date(ack.at).toLocaleDateString('ar-SA')}` : '— محتاج موافقتك'}</p>
            <button onClick={() => setOpen(true)} className="rounded-lg bg-white/10 text-white text-[11px] font-black px-3 py-1">{ack ? 'نسختي' : 'اقرأ ووافق'}</button>
          </div>
        </div>
      )}
      {open && (
        <div className="fixed inset-0 z-50 bg-black/70 flex items-end md:items-center justify-center" dir="rtl" onClick={() => setOpen(false)}>
          <div className="w-full md:max-w-2xl bg-[#0b1020] rounded-t-3xl md:rounded-3xl border border-white/10 p-4 flex flex-col" style={{ maxHeight: 'calc(var(--visual-height, 100dvh) - 2rem)' }} onClick={(e) => e.stopPropagation()}>
            <p className="font-black text-white mb-2">عقد التعاون</p>
            <pre className="flex-1 overflow-y-auto whitespace-pre-wrap text-[12px] leading-relaxed text-slate-200 font-sans bg-white/[0.03] rounded-xl p-3">{(ack && ack.text) || c.text}</pre>
            {err && <p className="text-xs text-rose-300 font-bold mt-2">{err}</p>}
            <div className="grid grid-cols-2 gap-2 mt-3 pb-[env(safe-area-inset-bottom,0px)]">
              <button onClick={() => window.print()} className="rounded-xl bg-white/10 text-white font-black py-2.5 text-sm">حفظ / طباعة</button>
              {ack ? <button onClick={() => setOpen(false)} className="rounded-xl bg-emerald-500 text-black font-black py-2.5 text-sm">✓ موافق عليه</button>
                : <button onClick={accept} disabled={busy || acting} className="rounded-xl bg-primary text-black font-black py-2.5 text-sm disabled:opacity-40">{acting ? 'الفني بس يوافق' : busy ? 'جارِ الحفظ…' : 'موافق'}</button>}
            </div>
          </div>
        </div>
      )}
    </>
  )
}
