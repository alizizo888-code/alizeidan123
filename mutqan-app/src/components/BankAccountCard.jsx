import { useState } from 'react'
import { Copy, Check, Download, Share2, QrCode } from 'lucide-react'

// One transfer account, the way a customer or a technician uses it on the spot (owner order 2026-11-22):
// every field copies with one tap (IBAN without spaces, so the bank app accepts it), the QR opens full size,
// saves to the phone's photos/downloads, or shares straight into WhatsApp / the bank app.
export const SA_BANKS = [
  { id: 'rajhi', name: 'مصرف الراجحي', color: '#1e3a8a' },
  { id: 'riyad', name: 'بنك الرياض', color: '#0f766e' },
  { id: 'snb', name: 'البنك الأهلي السعودي', color: '#047857' },
  { id: 'alinma', name: 'مصرف الإنماء', color: '#92400e' },
  { id: 'albilad', name: 'بنك البلاد', color: '#b45309' },
  { id: 'stcpay', name: 'STC Bank / STC Pay', color: '#6d28d9' },
  { id: 'barq', name: 'برق', color: '#0369a1' },
  { id: 'urpay', name: 'urpay', color: '#be185d' },
  { id: 'other', name: 'بنك / محفظة أخرى', color: '#334155' },
]
export const bankMeta = (id) => SA_BANKS.find((b) => b.id === id) || SA_BANKS[SA_BANKS.length - 1]
const spaced = (iban) => String(iban || '').replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim()

async function copyText(t) {
  try { await navigator.clipboard.writeText(t); return true } catch { /* old browsers */ }
  try {
    const a = document.createElement('textarea'); a.value = t; a.setAttribute('readonly', ''); a.style.position = 'fixed'; a.style.opacity = '0'
    document.body.appendChild(a); a.select(); const ok = document.execCommand('copy'); a.remove(); return ok
  } catch { return false }
}

function Row({ label, value, copyValue, ltr }) {
  const [done, setDone] = useState(false)
  if (!value) return null
  return (
    <button type="button" onClick={async () => { if (await copyText(copyValue ?? value)) { setDone(true); setTimeout(() => setDone(false), 1500) } }}
      className="w-full flex items-center gap-2 rounded-xl bg-black/20 hover:bg-black/30 active:scale-[.99] transition px-3 py-2 text-right">
      <div className="flex-1 min-w-0">
        <p className="text-[10px] text-white/60 font-bold">{label}</p>
        <p className={`text-sm font-black text-white break-all ${ltr ? 'font-mono tracking-wide' : ''}`} dir={ltr ? 'ltr' : 'rtl'} style={ltr ? { textAlign: 'left' } : undefined}>{value}</p>
      </div>
      <span className={`shrink-0 flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-black ${done ? 'bg-emerald-400 text-black' : 'bg-white/15 text-white'}`}>
        {done ? <><Check size={12} /> اتنسخ</> : <><Copy size={12} /> نسخ</>}
      </span>
    </button>
  )
}

async function saveQr(url, name) {
  try {
    const r = await fetch(url, { mode: 'cors' }); const b = await r.blob()
    const file = new File([b], `${name || 'qr'}.png`, { type: b.type || 'image/png' })
    const a = document.createElement('a'); a.href = URL.createObjectURL(file); a.download = file.name
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000)
  } catch { window.open(url, '_blank', 'noopener') } // long-press → «حفظ الصورة» works on every phone
}

async function shareQr(m) {
  const text = [m.name, m.accountHolder && `باسم: ${m.accountHolder}`, m.iban && `IBAN: ${String(m.iban).replace(/\s+/g, '')}`, m.accountNumber && `رقم الحساب: ${m.accountNumber}`].filter(Boolean).join('\n')
  try {
    if (m.qrUrl && navigator.canShare) {
      const b = await (await fetch(m.qrUrl, { mode: 'cors' })).blob()
      const f = new File([b], 'qr.png', { type: b.type || 'image/png' })
      if (navigator.canShare({ files: [f] })) { await navigator.share({ files: [f], text }); return }
    }
    if (navigator.share) { await navigator.share({ text }); return }
  } catch (e) { if (e?.name === 'AbortError') return }
  if (await copyText(text)) alert('اتنسخت بيانات الحساب — الصقها في الواتساب')
}

export default function BankAccountCard({ m: m0, compact = false, bigQr = false }) {
  const [big, setBig] = useState(false)
  // «إظهار الـQR» off in the settings (owner, 2026-11-24): the account shows without its code
  const m = m0.showQr === false ? { ...m0, qrUrl: '' } : m0
  const b = bankMeta(m.bank)
  const iban = String(m.iban || '').replace(/\s+/g, '').toUpperCase()
  return (
    <div className="rounded-2xl p-3 text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${b.color}, #0b1220)` }} dir="rtl">
      <div className="flex items-center gap-2 mb-2">
        <span className="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center text-lg">🏦</span>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-black truncate">{m.bankName || b.name}</p>
          {m.name && m.name !== (m.bankName || b.name) && <p className="text-[10px] text-white/70 truncate">{m.name}</p>}
        </div>
        <button type="button" onClick={() => shareQr(m)} className="w-9 h-9 rounded-xl bg-white/15 hover:bg-white/25 flex items-center justify-center" aria-label="مشاركة"><Share2 size={16} /></button>
      </div>
      <div className={`grid gap-2 ${m.qrUrl && !compact && !bigQr ? 'md:grid-cols-[1fr_auto]' : ''}`}>
        <div className="flex flex-col gap-1.5">
          <Row label="اسم صاحب الحساب" value={m.accountHolder} />
          <Row label="الآيبان IBAN" value={iban ? spaced(iban) : ''} copyValue={iban} ltr />
          <Row label="رقم الحساب" value={m.accountNumber} copyValue={String(m.accountNumber || '').replace(/\s+/g, '')} ltr />
          <Row label="رقم الجوال (للمحفظة)" value={m.walletPhone} ltr />
        </div>
        {m.qrUrl && (
          <div className="flex flex-col items-center gap-1.5">
            <button type="button" onClick={() => setBig(true)} className="rounded-xl bg-white p-1.5" aria-label="تكبير الكود">
              <img src={m.qrUrl} alt="QR" className={bigQr ? 'w-56 h-56 object-contain' : compact ? 'w-24 h-24 object-contain' : 'w-32 h-32 object-contain'} loading="lazy" />
            </button>
            <div className="flex gap-1.5">
              <button type="button" onClick={() => saveQr(m.qrUrl, `qr-${m.bank || 'bank'}`)} className="flex items-center gap-1 rounded-lg bg-white/15 px-2 py-1 text-[10px] font-black"><Download size={12} /> تحميل الكود</button>
              <button type="button" onClick={() => setBig(true)} className="flex items-center gap-1 rounded-lg bg-white/15 px-2 py-1 text-[10px] font-black"><QrCode size={12} /> تكبير</button>
            </div>
          </div>
        )}
      </div>
      {m.instructions && <p className="text-[11px] text-white/75 mt-2 leading-relaxed">{m.instructions}</p>}
      {big && (
        <div className="fixed inset-0 z-[80] bg-black/85 flex flex-col items-center justify-center p-6 gap-4" onClick={() => setBig(false)}>
          <img src={m.qrUrl} alt="QR" className="w-full max-w-sm rounded-2xl bg-white p-3" />
          <p className="text-white text-sm font-bold text-center">امسح الكود من تطبيق البنك — أو اضغط مطوّلًا على الصورة وحفظها</p>
          <div className="flex gap-2" onClick={(e) => e.stopPropagation()}>
            <button type="button" onClick={() => saveQr(m.qrUrl, `qr-${m.bank || 'bank'}`)} className="rounded-xl bg-white text-black px-4 py-2 text-sm font-black flex items-center gap-1"><Download size={14} /> حفظ الصورة</button>
            <button type="button" onClick={() => setBig(false)} className="rounded-xl bg-white/15 text-white px-4 py-2 text-sm font-black">إغلاق</button>
          </div>
        </div>
      )}
    </div>
  )
}

/** Transfer methods only (bank / wallet), enabled and not deleted. */
export const transferAccounts = (methods) => (methods || []).filter((m) => m.enabled && !m.deleted && ['bank_transfer', 'wallet_app'].includes(m.type) && (m.iban || m.accountNumber || m.qrUrl || m.walletPhone))
