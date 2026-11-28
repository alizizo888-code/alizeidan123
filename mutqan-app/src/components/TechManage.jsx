import { useMemo, useState } from 'react'
import { X, Loader2, Save, Plus, LogIn, MessageCircle } from 'lucide-react'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { useLiveShared } from '../lib/useLive'
import { custody, KINDS, kindOf } from '../lib/custody'
import { SA_BANKS } from './BankAccountCard'
import { SALARY_DEFAULTS, parseTiers, tiersText } from '../lib/salary'
import BankAccountCard from './BankAccountCard'
import WalletCard from './WalletCard'
import TechIdCard from './TechIdCard'
import { suggest, suggestBonus, RULES, BONUS_RULES } from '../lib/penalties'
import { regulations } from '../lib/regulations'
import { storage } from '../lib/storage'
import { TechPerfCard } from './ops/TechPerformance'

// «إدارة الفني» (owner order 2026-11-22) — everything about one technician in one panel:
// his balance and every money line, how the company works with him (share % or prepaid credit), freeze, a push in
// dispatch, where his earnings are sent, his contract, and «ادخل مكانه». A full-screen sheet on a phone, a side
// panel on a tablet or a computer.
const money = (n) => `${Math.round((Number(n) || 0) * 100) / 100} ر.س`
export const walletId = (techId) => `tw-${techId}`
const CONTRACT_TPL = (name, brand) => `عقد تعاون فني

الطرف الأول: ${brand || 'الشركة'} (يُشار إليها بالشركة).
الطرف الثاني: ${name || 'الفني'} (يُشار إليه بالفني).

1. يقوم الفني بتنفيذ أعمال الصيانة التي تسندها إليه الشركة عبر التطبيق بإتقان وفي المواعيد المتفق عليها مع العميل.
2. يلتزم الفني بالأسعار المعتمدة في التطبيق، ولا يتقاضى من العميل أي مبلغ خارج الفاتورة.
3. يحصل الفني على نسبته المتفق عليها من قيمة كل خدمة مكتملة، وتُسوّى المستحقات حسب نظام الحساب المعتمد له (نسبة أو رصيد مسبق).
4. يلتزم الفني بالضمان على أعماله، ويعالج أي ملاحظة خلال مدة الضمان دون مقابل إذا كانت من عمله.
5. يحق للشركة إيقاف الإسناد أو تطبيق خصومات عند الإخلال بالمواعيد أو الأسعار أو حسن التعامل أو تكرار شكاوى العملاء.
6. يحافظ الفني على سرية بيانات العملاء ولا يتواصل معهم لأعمال خارج الشركة.
7. مدة العقد سنة من تاريخ الموافقة، وتتجدد تلقائيًا ما لم يُخطر أحد الطرفين الآخر.

بالضغط على «موافق» في التطبيق يقرّ الفني بقراءة هذا العقد والموافقة عليه.`

export default function TechManage({ tech, orders, settlements, sharePct, cashSharePct = 0, cashCap = 0, payRules = null, brand, onClose }) {
  const id = tech.userId || tech.id
  const { data: wRows } = useLiveShared('tech_wallet', { filters: { techId: id }, limit: 1 })
  const { data: acks } = useLiveShared('tech_contract_acks', { filters: { techId: id }, order: '-at', limit: 5 })
  const w = wRows?.[0] || {}
  const acc = useMemo(() => custody(id, orders, settlements, sharePct, w, cashSharePct, cashCap), [id, orders, settlements, sharePct, w, cashSharePct, cashCap])
  const lines = useMemo(() => (settlements || []).filter((s) => s.techId === id).sort((a, b) => String(b.at).localeCompare(String(a.at))), [settlements, id])
  const { data: payReqs } = useLiveShared('payout_requests', { filters: { techId: id }, order: '-createdAt', limit: 10 })
  const sugg = useMemo(() => [...suggestBonus(id, orders, settlements, w, payRules), ...suggest(id, orders, settlements, w, payRules)], [id, orders, settlements, w, payRules])
  const [tab, setTab] = useState('money')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function saveWallet(patch) {
    setBusy(true); setErr('')
    try {
      const gid = await team.groupId()
      const next = { ...w, ...patch, techId: id, techName: tech.displayName || w.techName || '', updatedAt: new Date().toISOString() }
      delete next.id
      await db.upsertShared('tech_wallet', next, walletId(id), { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
      // dispatch in the browser reads these two from the profile (the server reads tech_wallet itself)
      if ('scoreBoost' in patch || 'frozen' in patch) {
        await db.updateShared('tech_profiles', id, { scoreBoost: Number(next.scoreBoost) || 0, walletFrozen: !!next.frozen }).catch(() => {})
      }
    } catch (e) { setErr((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }

  const tabs = [['id', '🪪 الهوية'], ['money', '💰 الرصيد'], ['perf', '📊 الأداء'], ['area', '🗺️ المنطقة والمواعيد'], ['terms', '⚙️ الشروط'], ['payout', '🏦 تحويل أرباحه'], ['contract', '📄 العقد']]
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/60" dir="rtl" onClick={onClose}>
      <div className="h-full w-full md:w-[560px] lg:w-[620px] bg-[#0b1020] border-r border-white/10 flex flex-col shadow-2xl" onClick={(e) => e.stopPropagation()} style={{ height: 'var(--visual-height, 100dvh)' }}>
        <header className="shrink-0 px-4 pt-[calc(env(safe-area-inset-top,0px)+0.75rem)] pb-3 border-b border-white/10">
          <div className="flex items-center gap-2">
            <div className="flex-1 min-w-0">
              <p className="text-[10px] text-primary font-black tracking-widest">إدارة الفني</p>
              <h2 className="text-white font-black text-lg truncate">{tech.displayName || 'فني'}</h2>
              <p className="text-[11px] text-slate-400">{acc.mode === 'prepaid' ? 'نظام رصيد مسبق' : `نظام نسبة · نصيبه ${acc.share}%`}{w.frozen ? ' · ⛔ موقوف' : ''}{Number(w.scoreBoost) ? ` · سكور ${w.scoreBoost > 0 ? '+' : ''}${w.scoreBoost}` : ''}</p>
            </div>
            <a href={`#/staff/radar?as=${encodeURIComponent(id)}`} onClick={onClose} className="rounded-xl bg-amber-400 text-black px-3 py-2 text-xs font-black flex items-center gap-1"><LogIn size={14} /> ادخل مكانه</a>
            {tech.phone && <a href={`https://wa.me/${String(tech.phone).replace(/\D/g, '').replace(/^0/, '966')}`} target="_blank" rel="noreferrer" className="w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-300 flex items-center justify-center" aria-label="واتساب"><MessageCircle size={16} /></a>}
            <button onClick={onClose} className="w-9 h-9 rounded-xl bg-white/10 text-white flex items-center justify-center" aria-label="إغلاق"><X size={16} /></button>
          </div>
          <div className="flex gap-1.5 mt-3 overflow-x-auto no-scrollbar">
            {tabs.map(([k, l]) => <button key={k} onClick={() => setTab(k)} className={`shrink-0 rounded-full px-3 py-1.5 text-xs font-bold border ${tab === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{l}</button>)}
          </div>
        </header>
        <div className="flex-1 overflow-y-auto p-4 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)] grid gap-3 content-start">
          {err && <p className="text-xs font-bold text-rose-300">{err}</p>}
          {tab === 'money' && <><PayoutRequests reqs={payReqs} tech={tech} id={id} acc={acc} w={w} /><Suggestions list={sugg} tech={tech} id={id} w={w} save={saveWallet} /><WalletCard acc={acc} lines={lines} /><MoneyTab tech={tech} id={id} acc={acc} lines={lines} /></>}
          {tab === 'id' && <TechIdCard techId={id} staff />}
          {tab === 'perf' && <TechPerfCard tech={tech} orders={orders} />}
          {tab === 'area' && <AreaTab tech={tech} id={id} />}
          {tab === 'terms' && <TermsTab w={w} acc={acc} busy={busy} save={saveWallet} />}
          {tab === 'payout' && <PayoutTab w={w} busy={busy} save={saveWallet} />}
          {tab === 'contract' && <ContractTab w={w} tech={tech} brand={brand} acks={acks} busy={busy} save={saveWallet} rules={payRules} />}
        </div>
      </div>
    </div>
  )
}

function PayoutRequests({ reqs, tech, id, acc, w }) {
  const pend = (reqs || []).filter((r) => r.status === 'pending')
  const [busy, setBusy] = useState('')
  if (!pend.length) return null
  async function pay(r, receipt) {
    setBusy(r.id)
    try {
      const gid = await team.groupId()
      const top = r.type === 'topup'
      await db.insertShared('settlements', { techId: id, techName: tech.displayName || '', amount: Number(r.amount), kind: top ? 'topup' : 'out', dir: top ? 'in' : 'out', note: top ? `شحن رصيد بإيصال (${new Date(r.createdAt).toLocaleDateString('ar-SA')})` : `تحويل مستحقات (طلب ${new Date(r.createdAt).toLocaleDateString('ar-SA')})`, receiptUrl: (top ? r.receiptUrl : receipt) || '', payoutId: r.id, at: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
      await db.updateShared('payout_requests', r.id, { status: 'paid', paidAt: new Date().toISOString(), receiptUrl: receipt || '', to: w.payout || null })
    } catch (e) { alert((e && e.message) || 'تعذر الحفظ') } finally { setBusy('') }
  }
  async function reject(r) { const why = prompt('سبب الرفض (يظهر للفني)'); if (why == null) return; await db.updateShared('payout_requests', r.id, { status: 'rejected', reason: why, at2: new Date().toISOString() }).catch(() => {}) }
  return (
    <section className="rounded-2xl bg-amber-500/10 border border-amber-400/30 p-3 grid gap-2">
      <p className="text-xs font-black text-amber-200">💸 طلبات معلّقة (تحويل مستحقات / شحن رصيد)</p>
      {pend.map((r) => (
        r.type === 'topup' ? (
          <div key={r.id} className="rounded-xl bg-black/20 p-2.5 grid gap-2">
            <p className="text-sm font-black text-white">💳 شحن رصيد {Number(r.amount)} ر.س <span className="text-[11px] text-slate-400 font-bold">— {new Date(r.createdAt).toLocaleString('ar-SA')}</span></p>
            {r.receiptUrl && <a href={r.receiptUrl} target="_blank" rel="noreferrer"><img src={r.receiptUrl} alt="الإيصال" className="max-h-48 rounded-lg bg-white" /></a>}
            {r.note && <p className="text-[11px] text-slate-300">{r.note}</p>}
            <div className="grid grid-cols-2 gap-1.5">
              <button onClick={() => pay(r, '')} disabled={busy === r.id} className="rounded-lg bg-emerald-500 text-black text-[11px] font-black py-2">اعتماد الشحن</button>
              <button onClick={() => reject(r)} className="rounded-lg bg-rose-500/20 text-rose-200 text-[11px] font-black py-2">رفض</button>
            </div>
          </div>
        ) :
        <div key={r.id} className="rounded-xl bg-black/20 p-2.5 grid gap-2">
          <p className="text-sm font-black text-white">{Number(r.amount)} ر.س <span className="text-[11px] text-slate-400 font-bold">— {new Date(r.createdAt).toLocaleString('ar-SA')}</span></p>
          <p className="text-[11px] text-slate-300">حسابه في النظام: {acc.mode === 'prepaid' ? `رصيد ${Math.round(acc.credit)} ر.س` : acc.balance < 0 ? `له ${Math.round(-acc.balance)} ر.س` : `عليه ${Math.round(acc.balance)} ر.س`}{r.note ? ` · ${r.note}` : ''}</p>
          {w.payout && (w.payout.iban || w.payout.accountNumber || w.payout.walletPhone) ? <BankAccountCard m={{ ...w.payout, name: 'حوّل على', enabled: true }} compact /> : <p className="text-[11px] text-rose-300 font-bold">مفيش بيانات تحويل للفني — ضيفها من «تحويل أرباحه»</p>}
          <div className="grid grid-cols-3 gap-1.5">
            <label className="rounded-lg bg-emerald-500 text-black text-[11px] font-black py-2 text-center cursor-pointer">{busy === r.id ? '…' : 'حوّلت + إيصال'}<input type="file" accept="image/*,application/pdf" className="hidden" onChange={async (e) => { const f = e.target.files?.[0]; e.target.value = ''; if (!f) return; try { const { url } = await storage.upload(f, `payout-${Date.now()}-${f.name}`); await pay(r, url) } catch (x) { alert((x && x.message) || 'تعذّر رفع الإيصال') } }} /></label>
            <button onClick={() => pay(r, '')} disabled={busy === r.id} className="rounded-lg bg-emerald-500/30 text-emerald-100 text-[11px] font-black py-2">حوّلت</button>
            <button onClick={() => reject(r)} className="rounded-lg bg-rose-500/20 text-rose-200 text-[11px] font-black py-2">رفض</button>
          </div>
        </div>
      ))}
    </section>
  )
}

function Suggestions({ list, tech, id, w, save }) {
  const [busy, setBusy] = useState('')
  if (!list.length) return null
  async function approve(sg) {
    setBusy(sg.ref)
    try {
      const gid = await team.groupId()
      await db.insertShared('settlements', { techId: id, techName: tech.displayName || '', amount: sg.amount, kind: sg.bonus ? 'bonus' : 'penalty', dir: 'in', note: `${([...RULES, ...BONUS_RULES].find((r) => r.id === sg.rule) || {}).label || 'خصم'} — ${sg.text}`, ruleRef: sg.ref, orderId: sg.orderId || '', at: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
    } catch (e) { alert((e && e.message) || 'تعذر الحفظ') } finally { setBusy('') }
  }
  return (
    <section className="rounded-2xl bg-rose-500/10 border border-rose-400/25 p-3 grid gap-1.5">
      <p className="text-xs font-black text-rose-200">⚖️ خصومات ومكافآت مقترحة حسب اللائحة — محتاجة موافقتك</p>
      {list.slice(0, 20).map((sg) => (
        <div key={sg.ref} className="flex items-center gap-2 text-[11px] bg-black/20 rounded-lg px-2 py-1.5">
          <span className="flex-1 min-w-0 text-slate-200">{sg.bonus ? '🎁 ' : '⚠️ '}{sg.text}</span><b className={sg.bonus ? 'text-emerald-300' : 'text-white'}>{sg.bonus ? '+' : '−'}{sg.amount} ر.س</b>
          <button onClick={() => approve(sg)} disabled={busy === sg.ref} className="rounded bg-rose-500 text-white font-black px-2 py-1">اعتماد</button>
          <button onClick={() => save({ dismissed: [...((w && w.dismissed) || []), sg.ref].slice(-500) })} className="rounded bg-white/10 text-slate-200 font-black px-2 py-1">تجاهل</button>
        </div>
      ))}
    </section>
  )
}

function Objection({ s, tech, id }) {
  const o = s.objection
  async function decide(ok) {
    try {
      if (ok) {
        const gid = await team.groupId()
        await db.insertShared('settlements', { techId: id, techName: tech.displayName || '', amount: Number(s.amount), kind: 'adjust', dir: 'in', note: `إلغاء خصم بعد اعتراض — ${s.note || ''}`.slice(0, 200), ruleRef: `cancel:${s.id}`, at: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
      }
      await db.updateShared('settlements', s.id, { objection: { ...o, status: ok ? 'accepted' : 'rejected', decidedAt: new Date().toISOString() } })
    } catch (e) { alert((e && e.message) || 'تعذّر') }
  }
  return (
    <div className={`mt-1 rounded-lg px-2 py-1.5 ${o.status === 'open' ? 'bg-amber-500/15 text-amber-100' : 'bg-white/5 text-slate-400'}`}>
      ✋ اعتراض: {o.text} {o.status === 'accepted' ? '— اتقبل واتلغى الخصم' : o.status === 'rejected' ? '— اترفض' : ''}
      {o.status === 'open' && <span className="flex gap-1.5 mt-1"><button onClick={() => decide(true)} className="rounded bg-emerald-500 text-black font-black px-2 py-0.5">قبول وإلغاء الخصم</button><button onClick={() => decide(false)} className="rounded bg-rose-500/30 text-rose-100 font-black px-2 py-0.5">رفض</button></span>}
    </div>
  )
}

function Stat({ label, value, tone = 'text-white' }) {
  return <div className="rounded-xl bg-white/[0.05] border border-white/10 p-2.5 text-center"><p className="text-[10px] text-slate-400 font-bold">{label}</p><p className={`text-sm font-black ${tone}`}>{value}</p></div>
}

function MoneyTab({ tech, id, acc, lines }) {
  const [kind, setKind] = useState(acc.mode === 'prepaid' ? 'topup' : 'in')
  const [amt, setAmt] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function add() {
    const n = Number(amt)
    if (!n || (kind !== 'adjust' && n < 0)) { setErr('اكتب مبلغًا صحيحًا'); return }
    if (['penalty', 'adjust'].includes(kind) && !note.trim()) { setErr('اكتب السبب — بيظهر للفني'); return }
    setBusy(true); setErr('')
    try {
      const gid = await team.groupId()
      await db.insertShared('settlements', { techId: id, techName: tech.displayName || '', amount: n, kind, dir: kind === 'out' ? 'out' : 'in', note: note.trim(), at: new Date().toISOString() }, undefined, { groupId: gid, visibleTo: 'group', writableBy: 'admins' })
      setAmt(''); setNote('')
    } catch (e) { setErr((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }
  const pre = acc.mode === 'prepaid'
  return (
    <>
      <section className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <Stat label="طلبات مكتملة" value={acc.jobs} />
        <Stat label="المبيعات" value={money(acc.sales)} />
        <Stat label="نصيبه" value={money(acc.commission)} tone="text-amber-300" />
        <Stat label="نقد حصّله" value={money(acc.cash)} />
      </section>
      {pre ? (
        <div className={`rounded-2xl p-3 text-center font-black ${acc.low ? 'bg-rose-500/15 text-rose-200' : 'bg-emerald-500/15 text-emerald-200'}`}>
          رصيده الحالي: {money(acc.credit)}{acc.min ? ` · الحد الأدنى ${money(acc.min)}` : ''}
          {acc.low && <p className="text-[11px] font-bold mt-1">تحت الحد — مش هيستلم طلبات جديدة لحد ما يشحن</p>}
        </div>
      ) : (
        <div className={`rounded-2xl p-3 text-center font-black ${acc.capped ? 'ring-2 ring-rose-400 ' : ''}${acc.balance > 0.5 ? 'bg-rose-500/15 text-rose-200' : acc.balance < -0.5 ? 'bg-emerald-500/15 text-emerald-200' : 'bg-white/5 text-slate-200'}`}>
          {acc.balance > 0.5 ? `عليه للشركة ${money(acc.balance)}` : acc.balance < -0.5 ? `له عند الشركة ${money(-acc.balance)}` : 'الحساب متزن'}{acc.capped && <p className="text-[11px] font-bold mt-1">فوق حد النقدية ({money(acc.cap)}) — موقوف عن الطلبات لحد ما يسلّم</p>}
        </div>
      )}
      <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3 grid gap-2">
        <p className="text-xs font-black text-white">حركة جديدة</p>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
          {Object.entries(KINDS).map(([k, m]) => <button key={k} onClick={() => setKind(k)} className={`rounded-lg px-2 py-2 text-[11px] font-bold border ${kind === k ? 'bg-primary text-black border-primary' : 'border-white/10 text-slate-300'}`}>{m.icon} {m.label}</button>)}
        </div>
        <input value={amt} onChange={(e) => setAmt(e.target.value)} inputMode="decimal" dir="ltr" placeholder={kind === 'adjust' ? 'المبلغ (سالب = عليه)' : 'المبلغ ر.س'} className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={['penalty', 'adjust'].includes(kind) ? 'السبب (إجباري، يظهر للفني)' : 'ملاحظة / رقم التحويل'} className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />
        {err && <p className="text-xs font-bold text-rose-300">{err}</p>}
        <button onClick={add} disabled={busy} className="rounded-lg bg-emerald-500 text-black font-black py-2 text-sm flex items-center justify-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}تسجيل</button>
      </section>
      <section className="rounded-2xl bg-white/[0.04] border border-white/10 p-3">
        <p className="text-xs font-black text-white mb-2">كشف الحساب</p>
        {!lines.length && <p className="text-[11px] text-slate-500">لا توجد حركات بعد.</p>}
        {lines.slice(0, 60).map((s) => { const k = KINDS[kindOf(s)]; return (
          <div key={s.id} className="py-1.5 border-b border-white/5 text-[11px]">
            <div className="flex items-center gap-2">
              <span>{k.icon}</span><span className="text-slate-300 flex-1 min-w-0 truncate">{k.label}{s.note ? ` — ${s.note}` : ''}</span>
              {s.receiptUrl && <a href={s.receiptUrl} target="_blank" rel="noreferrer" className="text-sky-300">إيصال</a>}
              <b className="text-white" dir="ltr">{money(s.amount)}</b><span className="text-slate-500">{s.at ? new Date(s.at).toLocaleDateString('ar-SA') : ''}</span>
            </div>
            {s.objection && <Objection s={s} tech={tech} id={id} />}
          </div>) })}
      </section>
    </>
  )
}

function TermsTab({ w, acc, busy, save }) {
  const [f, setF] = useState({ mode: w.mode || 'commission', sharePct: w.sharePct ?? '', cashSharePct: w.cashSharePct ?? '', cashCap: w.cashCap ?? '', minBalance: w.minBalance ?? 0, scoreBoost: w.scoreBoost ?? 0, frozen: !!w.frozen, frozenReason: w.frozenReason || '', salary: w.salary ?? '', orderBonus: w.orderBonus ?? SALARY_DEFAULTS.orderBonus, maxBonus: w.maxBonus ?? SALARY_DEFAULTS.maxBonus, tiersTxt: tiersText(w.tiers) })
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e.target.value }))
  const submit = () => save({ ...f, tiersTxt: undefined, salary: Math.max(0, Number(f.salary) || 0), orderBonus: Math.max(0, Math.min(50, Number(f.orderBonus) || 0)), maxBonus: Math.max(0, Number(f.maxBonus) || 0), tiers: parseTiers(f.tiersTxt), sharePct: f.sharePct === '' ? '' : Math.max(0, Math.min(100, Number(f.sharePct) || 0)), cashSharePct: f.cashSharePct === '' ? '' : Math.max(0, Math.min(100, Number(f.cashSharePct) || 0)), cashCap: f.cashCap === '' ? '' : Math.max(0, Number(f.cashCap) || 0), minBalance: Number(f.minBalance) || 0, scoreBoost: Math.max(-30, Math.min(30, Number(f.scoreBoost) || 0)), modeSince: f.mode === 'prepaid' && w.mode !== 'prepaid' ? new Date().toISOString() : (w.modeSince || '') })
  return (
    <section className="grid gap-3">
      <div className="grid grid-cols-3 gap-2">
        {[['commission', 'نسبة', 'بيحصّل من العميل ويسلّم نسبة الشركة'], ['prepaid', 'رصيد مسبق', 'يحوّل مبلغ الأول ويتخصم منه نسبة الشركة من كل طلب'], ['salary', 'راتب', 'راتب شهري ثابت + مكافآت صغيرة على الطلبات − خصومات. مش بيشوف أي نسبة']].map(([k, l, d]) => (
          <button key={k} onClick={() => setF((x) => ({ ...x, mode: k }))} className={`rounded-2xl p-3 text-right border ${f.mode === k ? 'border-primary bg-primary/10' : 'border-white/10 bg-white/[0.03]'}`}>
            <p className="text-sm font-black text-white">{l}</p><p className="text-[10px] text-slate-400 mt-0.5 leading-relaxed">{d}</p>
          </button>))}
      </div>
      {f.mode === 'salary' && (
        <div className="grid gap-2 rounded-2xl border border-emerald-400/30 bg-emerald-500/5 p-3" data-salary-terms>
          <label className="text-[11px] font-bold text-slate-400">الراتب الشهري المتفق عليه (ر.س)<input value={f.salary} onChange={set('salary')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
          <label className="text-[11px] font-bold text-slate-400">مكافأة كل طلب تمام (ر.س — من غير شكوى ولا تقييم ضعيف)<input value={f.orderBonus} onChange={set('orderBonus')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
          <label className="text-[11px] font-bold text-slate-400">مكافأة الشهر حسب عدد الطلبات (عدد:مكافأة)<input value={f.tiersTxt} onChange={set('tiersTxt')} dir="ltr" placeholder="30:100, 50:200, 70:300" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
          <label className="text-[11px] font-bold text-slate-400">أقصى مكافآت تلقائية في الشهر (ر.س)<input value={f.maxBonus} onChange={set('maxBonus')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
          <p className="text-[10px] text-slate-400 leading-relaxed">الخصومات والعقوبات والمكافآت اليدوية بتتسجل من «الحركات» وبتطلع في كشف الشهر. الكاش اللي بيحصّله كله للشركة وبيسلّمه.</p>
        </div>
      )}
      {f.mode !== 'salary' && <label className="text-[11px] font-bold text-slate-400">نصيب الفني % (فاضي = النسبة العامة {acc.share && !w.sharePct ? `${acc.share}%` : ''})<input value={f.sharePct} onChange={set('sharePct')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>}
      {f.mode !== 'salary' && <label className="text-[11px] font-bold text-slate-400">نصيبه % لو العميل دفع كاش (فاضي = العام{acc.cashShare && !w.cashSharePct ? ` ${acc.cashShare}%` : ''})<input value={f.cashSharePct} onChange={set('cashSharePct')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>}
      {f.mode !== 'prepaid' && <label className="text-[11px] font-bold text-slate-400">أقصى نقدية للشركة معاه قبل ما يتوقف (ر.س — فاضي = العام{acc.cap && !w.cashCap ? ` ${acc.cap}` : ''})<input value={f.cashCap} onChange={set('cashCap')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>}
      {f.mode === 'prepaid' && <label className="text-[11px] font-bold text-slate-400">أقل رصيد يستلم بيه طلبات (ر.س)<input value={f.minBalance} onChange={set('minBalance')} inputMode="decimal" dir="ltr" className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>}
      <label className="text-[11px] font-bold text-slate-400">سكور إضافي في التوزيع: <b className="text-white">{f.scoreBoost > 0 ? '+' : ''}{f.scoreBoost}</b>
        <input type="range" min="-30" max="30" step="5" value={f.scoreBoost} onChange={set('scoreBoost')} className="w-full mt-2 accent-amber-400" />
        <span className="flex justify-between text-[10px] text-slate-500"><span>أقل أولوية</span><span>عادي</span><span>أولوية أعلى</span></span>
      </label>
      <button onClick={() => setF((x) => ({ ...x, frozen: !x.frozen }))} className={`rounded-xl px-3 py-2.5 flex items-center justify-between border ${f.frozen ? 'border-rose-400 bg-rose-500/15' : 'border-white/10 bg-white/5'}`}>
        <span className="text-xs font-black text-white">{f.frozen ? '⛔ موقوف عن استلام الطلبات' : 'يستلم طلبات عادي'}</span><span className="text-[11px] text-slate-300">اضغط للتغيير</span>
      </button>
      {f.frozen && <input value={f.frozenReason} onChange={set('frozenReason')} placeholder="سبب الإيقاف (يظهر للفني)" className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" />}
      <button onClick={submit} disabled={busy} className="rounded-xl bg-primary text-black font-black py-3 text-sm flex items-center justify-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}حفظ الشروط</button>
    </section>
  )
}

function PayoutTab({ w, busy, save }) {
  const p = w.payout || {}
  const [f, setF] = useState({ bank: p.bank || 'rajhi', bankName: p.bankName || '', accountHolder: p.accountHolder || '', iban: p.iban || '', accountNumber: p.accountNumber || '', walletPhone: p.walletPhone || '' })
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e.target.value }))
  return (
    <section className="grid gap-2">
      <p className="text-[11px] text-slate-400">الحساب اللي بتحوّل عليه أرباحه — الفني بيشوفه في صفحته، وإنت تنسخه بضغطة وقت التحويل.</p>
      {(p.iban || p.accountNumber || p.walletPhone) && <BankAccountCard m={{ ...p, name: 'حساب الفني', enabled: true }} compact />}
      <div className="grid grid-cols-3 gap-1.5">
        {SA_BANKS.map((b) => <button key={b.id} onClick={() => setF((x) => ({ ...x, bank: b.id, bankName: b.id === 'other' ? x.bankName : b.name }))} className={`rounded-lg px-2 py-1.5 text-[11px] font-bold border ${f.bank === b.id ? 'border-primary text-white' : 'border-white/10 text-slate-300'}`} style={{ background: f.bank === b.id ? b.color : 'transparent' }}>{b.name}</button>)}
      </div>
      {[['bankName', 'اسم البنك'], ['accountHolder', 'اسم صاحب الحساب'], ['iban', 'الآيبان', 'ltr'], ['accountNumber', 'رقم الحساب', 'ltr'], ['walletPhone', 'جوال المحفظة (STC Pay / برق)', 'ltr']].map(([k, l, d]) => (
        <label key={k} className="text-[11px] font-bold text-slate-400">{l}<input value={f[k]} onChange={k === 'iban' ? (e) => setF((x) => ({ ...x, iban: e.target.value.toUpperCase() })) : set(k)} dir={d || 'rtl'} className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
      ))}
      <button onClick={() => save({ payout: { ...f, iban: f.iban.replace(/\s+/g, '') } })} disabled={busy} className="rounded-xl bg-primary text-black font-black py-3 text-sm flex items-center justify-center gap-1">{busy ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}حفظ بيانات التحويل</button>
    </section>
  )
}

function ContractTab({ w, tech, brand, acks, busy, save, rules }) {
  const c = w.contract || {}
  const [f, setF] = useState({ enabled: !!c.enabled, kind: c.kind || 'annual', start: c.start || new Date().toISOString().slice(0, 10), text: c.text || `${CONTRACT_TPL(tech.displayName, brand)}\n\n${regulations(rules, brand)}` })
  const ack = (acks || []).find((a) => a.version === c.version)
  const sendNew = () => save({ contract: { ...f, version: `v${Date.now().toString(36)}`, sentAt: new Date().toISOString() } })
  return (
    <section className="grid gap-2">
      <button onClick={() => setF((x) => ({ ...x, enabled: !x.enabled }))} className={`rounded-xl px-3 py-2.5 flex items-center justify-between border ${f.enabled ? 'border-primary bg-primary/10' : 'border-white/10 bg-white/5'}`}>
        <span className="text-xs font-black text-white">{f.enabled ? 'العقد ظاهر للفني' : 'من غير عقد (مخفي) — مناسب للفني المؤقت أو التابع'}</span><span className="text-[11px] text-slate-300">تغيير</span>
      </button>
      {c.sentAt && <p className={`text-[11px] font-black ${ack ? 'text-emerald-300' : 'text-amber-300'}`}>{ack ? `✓ وافق ${ack.name || ''} يوم ${new Date(ack.at).toLocaleString('ar-SA')}` : `اتبعت يوم ${new Date(c.sentAt).toLocaleDateString('ar-SA')} — لسه ما وافقش`}</p>}
      {f.enabled && (
        <>
          <div className="grid grid-cols-2 gap-2">
            <label className="text-[11px] font-bold text-slate-400">النوع<select value={f.kind} onChange={(e) => setF((x) => ({ ...x, kind: e.target.value }))} className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white"><option value="annual" className="bg-slate-900">سنوي</option><option value="season" className="bg-slate-900">موسمي</option><option value="project" className="bg-slate-900">مشروع محدد</option></select></label>
            <label className="text-[11px] font-bold text-slate-400">يبدأ من<input type="date" value={f.start} onChange={(e) => setF((x) => ({ ...x, start: e.target.value }))} className="mt-1 w-full rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-sm text-white" /></label>
          </div>
          <textarea value={f.text} onChange={(e) => setF((x) => ({ ...x, text: e.target.value }))} rows={14} className="rounded-lg bg-white/10 border border-white/15 px-3 py-2 text-[12px] leading-relaxed text-white" />
        </>
      )}
      <div className="grid grid-cols-2 gap-2">
        <button onClick={() => save({ contract: { ...c, ...f } })} disabled={busy} className="rounded-xl bg-white/10 text-white font-black py-3 text-sm">حفظ</button>
        <button onClick={sendNew} disabled={busy || !f.enabled} className="rounded-xl bg-primary text-black font-black py-3 text-sm disabled:opacity-40">إرسال للفني للموافقة</button>
      </div>
      <p className="text-[10px] text-slate-500 leading-relaxed">«إرسال» يعمل نسخة جديدة من العقد؛ الفني يشوفها في صفحته ويضغط «موافق»، والموافقة بتتحفظ باسمه ووقتها ومتتمسحش. يقدر يفتح نسخته في أي وقت.</p>
    </section>
  )
}

// Coverage area + working hours (owner, 2026-11-23 — ideas 6 and 7). Read by automatic distribution in the room
// (assignment.js inZone / onShift) and on the server (Mutqan_Dispatch::in_zone / on_shift).
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
function AreaTab({ tech, id }) {
  const [zones, setZones] = useState((tech.zones || []).join('، '))
  const sh = tech.shift || {}
  const [on, setOn] = useState(!!tech.shift)
  const [from, setFrom] = useState(sh.from || '08:00')
  const [to, setTo] = useState(sh.to || '23:00')
  const [off, setOff] = useState(Array.isArray(sh.off) ? sh.off.map(Number) : [])
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  async function save() {
    setBusy(true); setMsg('')
    try {
      const list = zones.split(/[،,\n]+/).map((z) => z.trim()).filter(Boolean).slice(0, 40)
      await db.updateShared('tech_profiles', id, { zones: list, shift: on ? { from, to, off } : null })
      setMsg('اتحفظ ✅ — التوزيع الآلي هيلتزم بيه من دلوقتي')
    } catch (e) { setMsg((e && e.message) || 'تعذر الحفظ') } finally { setBusy(false) }
  }
  return (
    <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-3 grid gap-3" data-tech-area>
      <label className="grid gap-1 text-xs text-slate-300 font-bold">🗺️ منطقة التغطية — المدن أو الأحياء اللي بيشتغل فيها (افصل بفاصلة)
        <textarea value={zones} onChange={(e) => setZones(e.target.value)} rows={3} placeholder="مثال: مكة، العزيزية، الشوقية — أو: جدة" className="rounded-lg bg-white/5 border border-white/10 px-2 py-1.5 text-xs text-white outline-none" data-tech-zones />
        <span className="text-[10px] text-slate-500 font-normal">فاضي = بياخد من كل مكان. الطلب اللي عنوانه مش فيه أي اسم منهم مش بيروحله في التوزيع الآلي.</span>
      </label>
      <div className="grid gap-2">
        <label className="flex items-center gap-2 text-xs text-slate-300 font-bold"><input type="checkbox" checked={on} onChange={(e) => setOn(e.target.checked)} data-tech-shift-on /> ⏰ ساعات شغل محددة (بتوقيت مكة)</label>
        {on && (
          <>
            <div className="flex items-center gap-2 text-xs text-slate-300">من <input type="time" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-lg bg-white/10 border border-white/15 px-2 py-1 text-white" data-tech-from /> لحد <input type="time" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-lg bg-white/10 border border-white/15 px-2 py-1 text-white" data-tech-to /></div>
            <div className="flex flex-wrap gap-1.5">{DAYS.map((d, i) => <button key={d} type="button" onClick={() => setOff((x) => (x.includes(i) ? x.filter((y) => y !== i) : [...x, i]))} className={`rounded-full px-2.5 py-1 text-[11px] font-bold border ${off.includes(i) ? 'bg-rose-500/20 border-rose-400/40 text-rose-200' : 'border-white/10 text-slate-300'}`}>{off.includes(i) ? '⛔ ' : ''}{d}</button>)}</div>
            <p className="text-[10px] text-slate-500">الأيام الحمرا = إجازة. لو الشغل بيعدّي نص الليل (مثلًا 18:00 لـ 02:00) بيتحسب صح.</p>
          </>
        )}
      </div>
      <button onClick={save} disabled={busy} className="rounded-xl bg-primary text-black text-xs font-black py-2.5 disabled:opacity-50" data-tech-area-save>{busy ? 'جارِ الحفظ…' : 'حفظ المنطقة والمواعيد'}</button>
      {msg && <p className="text-[11px] font-bold text-emerald-300">{msg}</p>}
    </section>
  )
}
