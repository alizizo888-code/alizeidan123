import { useEffect, useRef, useState } from 'react'
import { Loader2, Download, CheckCircle2 } from 'lucide-react'
import { post } from '../lib/_api'

// The transfer receipt the customer sent on WhatsApp (owner, 2026-11-24). The server keeps the original in a private
// folder (Mutqan_Proof); here it is drawn with the order written on it — number, client, service, date, appointment —
// because the browser shapes Arabic, then the stamped copy is saved back. «تأكيد الدفع» records the payment.
export default function ReceiptProof({ order, methods = [], onConfirm, busy }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [png, setPng] = useState('')
  const [method, setMethod] = useState('')
  const saved = useRef(false)

  useEffect(() => {
    let live = true
    post('/proof', { op: 'get', id: order.id }).then((d) => { if (live) setData(d) }).catch((e) => live && setErr(e?.message || 'تعذر فتح الإيصال'))
    return () => { live = false }
  }, [order.id, order.paymentProof?.at])

  useEffect(() => {
    if (!data) return
    if (data.stamped) { setPng(data.stamped); return }
    if (!data.image || !data.image.startsWith('data:image/')) return
    const img = new Image()
    img.onload = () => {
      const f = data.facts || {}
      const W = Math.min(1400, Math.max(720, img.width))
      const scale = W / img.width
      const H = Math.round(img.height * scale)
      const lines = [
        `طلب #${f.number || ''} — ${f.client || ''} ${f.phone ? '· ' + f.phone : ''}`,
        `الخدمة: ${f.service || '—'}${f.amount ? ' · المبلغ: ' + f.amount + ' ر.س' : ''}`,
        `تاريخ الطلب: ${f.date || '—'}${f.appointment ? ' · الموعد: ' + f.appointment : ''}`,
        `وصل الإيصال: ${f.received || '—'}${f.tech ? ' · الفني: ' + f.tech : ''}`,
      ]
      const fs = Math.round(W / 34), lh = Math.round(fs * 1.55), pad = Math.round(fs * 0.8)
      const band = pad * 2 + lh * lines.length
      const c = document.createElement('canvas'); c.width = W; c.height = H + band
      const x = c.getContext('2d')
      x.fillStyle = '#fff'; x.fillRect(0, 0, W, H + band)
      x.drawImage(img, 0, band, W, H)
      x.fillStyle = '#0f172a'; x.fillRect(0, 0, W, band)
      x.fillStyle = '#10b981'; x.fillRect(0, band - 6, W, 6)
      x.direction = 'rtl'; x.textAlign = 'right'; x.textBaseline = 'top'
      lines.forEach((t, i) => { x.fillStyle = i === 0 ? '#fbbf24' : '#e2e8f0'; x.font = `${i === 0 ? 900 : 700} ${fs}px Tajawal, Cairo, sans-serif`; x.fillText(t, W - pad, pad + i * lh) })
      const out = c.toDataURL('image/jpeg', 0.86)
      setPng(out)
      if (!saved.current) { saved.current = true; post('/proof', { op: 'stamped', id: order.id, png: out }).catch(() => {}) }
    }
    img.src = data.image
  }, [data, order.id])

  const transfer = methods.filter((m) => m.enabled && ['bank_transfer', 'wallet_app'].includes(m.type))
  const pick = method || order.paymentChoice?.methodId || transfer[0]?.id || ''

  return (
    <div className="mb-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-3" data-receipt-proof>
      <p className="text-amber-300 text-xs font-black mb-2">🧾 العميل بعت إيصال التحويل — شيّك إن المبلغ وصل حسابك وبعدين أكّد</p>
      {err && <p className="text-rose-400 text-[11px]">{err}</p>}
      {!data && !err && <p className="text-slate-400 text-[11px] flex items-center gap-1"><Loader2 size={12} className="animate-spin" /> بفتح الإيصال…</p>}
      {data && !png && !data.image && <p className="text-slate-400 text-[11px]">الصورة نفسها في محادثة العميل على الواتساب (ملف مش صورة أو ماتحفظش).</p>}
      {png && (
        <>
          <img src={png} alt="الإيصال" className="w-full rounded-xl border border-white/10" />
          <a href={png} download={`receipt-${data?.facts?.number || order.id}.jpg`} className="mt-2 inline-flex items-center gap-1 text-[11px] font-bold text-sky-300"><Download size={12} /> تحميل الإيصال المختوم</a>
        </>
      )}
      {transfer.length > 1 && (
        <select value={pick} onChange={(e) => setMethod(e.target.value)} className="mt-2 w-full bg-white/5 border border-white/10 rounded-xl px-2 py-2 text-xs text-white">
          {transfer.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      )}
      <button disabled={busy || !pick} onClick={() => onConfirm(pick)} data-confirm-pay className="mt-2 w-full bg-emerald-500 text-black font-black rounded-xl py-2.5 text-sm disabled:opacity-50 flex items-center justify-center gap-1">
        <CheckCircle2 size={16} /> تأكيد الدفع — التحويل وصل
      </button>
    </div>
  )
}
