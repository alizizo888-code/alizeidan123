import { useState } from 'react'
import { QrCode, X } from 'lucide-react'
import { usePaymentMethods } from '../hooks/usePaymentMethods'
import BankAccountCard, { transferAccounts } from './BankAccountCard'

// «اسكان التحويل» (owner, 2026-11-24): the technician hands his phone to the customer — the transfer accounts full
// screen, each with its QR big enough to scan from the bank app, the IBAN one tap to copy, and the code to download.
export default function TransferSheet({ amount }) {
  const [open, setOpen] = useState(false)
  const { methods } = usePaymentMethods()
  const list = transferAccounts(methods)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} data-transfer-sheet
        className="w-full mt-2 flex items-center justify-center gap-2 rounded-xl bg-sky-500/15 border border-sky-400/30 text-sky-200 py-2.5 text-sm font-black">
        <QrCode size={16} /> اسكان التحويل — اعرض الحسابات للعميل
      </button>
      {open && (
        <div className="fixed inset-0 z-[70] bg-[#060a12] overflow-y-auto" dir="rtl" style={{ height: 'var(--visual-height, 100dvh)' }}>
          <div className="sticky top-0 bg-[#060a12]/95 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-3">
            <div className="flex-1">
              <p className="text-white font-black">حوّل على أي حساب 👇</p>
              {amount > 0 && <p className="text-emerald-300 text-sm font-black">المبلغ: {amount} ر.س</p>}
            </div>
            <button onClick={() => setOpen(false)} className="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center" aria-label="إغلاق"><X size={18} className="text-white" /></button>
          </div>
          <div className="max-w-md mx-auto p-4 grid gap-4">
            {!list.length && <p className="text-center text-slate-400 text-sm py-10">مفيش حسابات تحويل شغالة — الإدارة تضيفها من «طرق الدفع».</p>}
            {list.map((m) => <BankAccountCard key={m.id} m={m} bigQr />)}
            <p className="text-center text-slate-400 text-xs leading-relaxed">امسح الكود من تطبيق البنك، أو اضغط «نسخ» على الآيبان. بعد التحويل ابعت صورة الإيصال على الواتساب.</p>
          </div>
        </div>
      )}
    </>
  )
}
