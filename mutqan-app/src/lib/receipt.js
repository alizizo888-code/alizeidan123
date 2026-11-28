import { BN } from './netBrand'
import { orderFacts } from './orderFacts'

// A printable receipt for a finished order: opens in a new tab and asks the browser to print it —
// «حفظ كـ PDF» from the print dialog gives the customer a PDF on any phone, with no library and no server.
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])

export function printReceipt(order, extra = {}) {
  const f = orderFacts(order) || {}
  const num = order.number || String(order.id || '').slice(-5)
  const done = order.completedAt || order.updatedAt || order.createdAt
  const day = done ? new Date(done).toLocaleDateString('ar-SA-u-nu-latn', { year: 'numeric', month: 'long', day: 'numeric' }) : ''
  const rows = [
    ['رقم الطلب', '#' + num],
    ['التاريخ', day],
    ['الخدمة', [f.service, f.kind, f.units].filter(Boolean).join(' — ')],
    ['العميل', order.clientName],
    ['الجوال', order.clientPhone],
    ['العنوان', order.address],
    ['الفني', order.technicianName],
    ['تقرير الفني', order.report],
    ['قطع الغيار', order.spareParts],
    ['الضمان', extra.warranty],
  ].filter(([, v]) => v)
  const price = order.price ? `${order.price} ر.س` : '—'
  const html = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>إيصال ${esc(num)}</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui,Tahoma,Arial,sans-serif;margin:24px;color:#0f172a}h1{font-size:20px;margin:0 0 4px}p.s{color:#64748b;margin:0 0 16px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:14px}td{padding:8px 6px;border-bottom:1px solid #e2e8f0;vertical-align:top}td:first-child{color:#64748b;width:32%}
.t{display:flex;justify-content:space-between;font-size:18px;font-weight:800;margin-top:14px;padding-top:10px;border-top:2px solid #0f172a}
.n{margin-top:22px;font-size:12px;color:#64748b}@media print{button{display:none}}</style></head><body>
<h1>${esc(BN)} — إيصال خدمة</h1><p class="s">إيصال إنجاز خدمة صيانة</p>
<table>${rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join('')}</table>
<div class="t"><span>الإجمالي</span><span>${esc(price)}</span></div>
<p class="n">احتفظ بهذا الإيصال لأي مطالبة ضمان.</p>
<button onclick="print()" style="margin-top:16px;padding:10px 16px;font-size:15px">طباعة / حفظ PDF</button>
<script>setTimeout(function(){try{print()}catch(e){}},400)</script></body></html>`
  const w = window.open('', '_blank')
  if (!w) return false
  w.document.open(); w.document.write(html); w.document.close()
  return true
}
