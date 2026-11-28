// Invoices — plain and tax — in four templates (owner, 2026-11-22):
//   official : his tax-invoice template (blue band, bilingual table, ZATCA QR, stamp)
//   classic  : his receipt («سند») — company lines, «وصلنا من السادة», amount in words, «أي سند لا يحمل ختم المؤسسة يعتبر لاغي»
//   modern   : a clean red-accent page
//   thermal  : an 80 mm slip for the small printers the technicians carry
// Every template prints both types; the amounts and the QR come from the server (Mutqan_Invoice), never computed here
// for a saved invoice. calcInvoice() mirrors the server only for the live preview while typing.

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
export const money = (n) => (Math.round((Number(n) || 0) * 100) / 100).toFixed(2)
const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100

export const TEMPLATES = { official: 'الأزرق الرسمي', classic: 'السند الكلاسيكي', modern: 'الحديث', thermal: 'الحراري 80مم' }
export const PAYS = { cash: 'نقدي', transfer: 'تحويل بنكي', card: 'شبكة / مدى', cheque: 'شيك', credit: 'آجل' }

/** Same rule as the server: inclusive prices hold the VAT (120 → 104.35 + 15.65). */
export function calcInvoice(items, tax, rate = 15, inclusive = true) {
  const k = 1 + rate / 100
  const T = { total: 0, discount: 0, afterDiscount: 0, vat: 0, net: 0 }
  const rows = (items || []).filter((it) => String(it.name || '').trim()).map((it, i) => {
    const qty = Math.max(0, Number(it.qty ?? 1) || 0), price = Math.max(0, Number(it.price) || 0)
    const gross = r2(qty * price), disc = Math.min(Math.max(0, Number(it.discount) || 0), gross)
    let row
    if (!tax) row = { total: gross, discount: disc, afterDiscount: r2(gross - disc), vat: 0, net: r2(gross - disc) }
    else if (inclusive) { const net = r2(gross - disc), before = r2(net / k); row = { total: r2(gross / k), discount: r2(disc / k), afterDiscount: before, vat: r2(net - before), net } }
    else { const after = r2(gross - disc), vat = r2(after * rate / 100); row = { total: gross, discount: disc, afterDiscount: after, vat, net: r2(after + vat) } }
    for (const f in T) T[f] = r2(T[f] + row[f])
    return { no: i + 1, code: it.code || '', name: it.name, unit: it.unit || 'عدد', qty, price: r2(price), ...row }
  })
  return { rows, totals: T }
}

/* ------------------------------------------------------------------ the amount in words */

const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر']
const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون']
const HUNDS = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة']
function ar999(n) {
  const h = Math.floor(n / 100), r = n % 100, p = []
  if (h) p.push(HUNDS[h])
  if (r) p.push(r < 20 ? ONES[r] : (r % 10 ? ONES[r % 10] + ' و' + TENS[Math.floor(r / 10)] : TENS[Math.floor(r / 10)]))
  return p.join(' و')
}
function arScale(n, one, two, few, many) {
  if (n === 1) return one
  if (n === 2) return two
  if (n >= 3 && n <= 10) return ar999(n) + ' ' + few
  return ar999(n) + ' ' + many
}
export function arWords(num) {
  let n = Math.floor(Math.abs(Number(num) || 0))
  if (n === 0) return 'صفر'
  const parts = []
  const m = Math.floor(n / 1e6); n %= 1e6
  const th = Math.floor(n / 1000); n %= 1000
  if (m) parts.push(arScale(m, 'مليون', 'مليونان', 'ملايين', 'مليون'))
  if (th) parts.push(arScale(th, 'ألف', 'ألفان', 'آلاف', 'ألف'))
  if (n) parts.push(ar999(n))
  return parts.join(' و')
}
/** «مئة وعشرون ريال سعودي فقط لا غير.» — with the halalas when there are any. */
export function arAmount(v) {
  const t = Math.round((Number(v) || 0) * 100), sr = Math.floor(t / 100), h = t % 100
  return arWords(sr) + ' ريال سعودي' + (h ? ' و' + arWords(h) + ' هللة' : '') + ' فقط لا غير.'
}
const E1 = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen']
const E10 = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety']
function en999(n) {
  const h = Math.floor(n / 100), r = n % 100, p = []
  if (h) p.push(E1[h] + ' hundred')
  if (r) p.push((h ? 'and ' : '') + (r < 20 ? E1[r] : E10[Math.floor(r / 10)] + (r % 10 ? '-' + E1[r % 10].toLowerCase() : '')))
  return p.join(' ')
}
export function enWords(num) {
  let n = Math.floor(Math.abs(Number(num) || 0))
  if (n === 0) return 'Zero'
  const p = []
  for (const [d, w] of [[1e6, 'million'], [1e3, 'thousand']]) { const q = Math.floor(n / d); if (q) { p.push(en999(q) + ' ' + w); n %= d } }
  if (n) p.push(en999(n))
  const s = p.join(' ').replace(/\s+/g, ' ').trim()
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase()
}
export function enAmount(v) {
  const t = Math.round((Number(v) || 0) * 100), sr = Math.floor(t / 100), h = t % 100
  return enWords(sr) + ' Saudi Riyal' + (h ? ' and ' + enWords(h).toLowerCase() + ' halala' : '') + ' Only.'
}

/* ------------------------------------------------------------------ shared pieces */

const pad = (n) => String(n).padStart(2, '0')
function when(iso) {
  const d = new Date(iso || Date.now())
  const z = new Date(d.getTime() + 3 * 3600000)   // Riyadh time, whatever the device says
  return { date: `${z.getUTCFullYear()}-${pad(z.getUTCMonth() + 1)}-${pad(z.getUTCDate())}`, time: `${pad(z.getUTCHours())}:${pad(z.getUTCMinutes())}`, dmy: `${pad(z.getUTCDate())}-${pad(z.getUTCMonth() + 1)}-${z.getUTCFullYear()}` }
}
const isTax = (inv) => inv.type === 'tax' || (inv.type === 'credit' && !!inv.tax)
const title = (inv) => (inv.type === 'credit'
  ? (inv.tax ? ['إشعار دائن (مرتجع)', 'Credit Note'] : ['مرتجع', 'Return'])
  : isTax(inv) ? (inv.client?.vatNo ? ['فاتورة ضريبية', 'Tax Invoice'] : ['فاتورة ضريبية مبسطة', 'Simplified Tax Invoice']) : ['فاتورة', 'Invoice'])
// a return names the invoice it reverses and why
const refBlock = (inv) => (inv.type === 'credit' && inv.ref ? `<div class="refb">↩️ مرتجع على ${inv.ref.type === 'tax' ? 'الفاتورة الضريبية' : 'الفاتورة'} رقم <b>${esc(inv.ref.number)}</b> بتاريخ ${when(inv.ref.at).date}${inv.reason ? ` — السبب: ${esc(inv.reason)}` : ''}</div>` : '')
const REF_CSS = '.refb{margin:10px 18px 0;padding:8px 12px;border:1.5px dashed #dc2626;border-radius:8px;color:#991b1b;font-size:12.5px;background:#fef2f2}'
const sar = (n) => `${money(n)} ر.س`
const voidMark = (inv) => (inv.status === 'void' ? `<div class="void">ملغاة${inv.voidReason ? ' — ' + esc(inv.voidReason) : ''}</div>` : '')
const stampImg = (inv, a) => (inv.stamped ? `<img class="stamp" src="${esc(a.stamp)}" alt="ختم المؤسسة">` : '')
const qrImg = (a) => (a.qr ? `<div class="qrw"><img class="qr" src="${a.qr}" alt="QR" title="اضغط للتكبير" onclick="this.classList.toggle('big')"><small>QR هيئة الزكاة</small></div>` : '')
// the second QR: any camera opens the invoice's details page (issuer, customer, items, prices, status)
const qr2Img = (a) => (a.qr2 ? `<div class="qrw"><img class="qr" src="${a.qr2}" alt="تفاصيل الفاتورة" title="اضغط للتكبير" onclick="this.classList.toggle('big')"><small>امسح لعرض التفاصيل</small></div>` : '')
// Readable QR: white quiet zone built into the picture, sharp squares (no blur when scaled), never smaller than 30 mm, and a clear gap so a camera sees one code at a time
const QR_CSS = '.qrw{display:inline-flex;flex-direction:column;align-items:center;gap:2px;background:#fff}.qrw small{font-size:9.5px;color:#475569}.qrs{display:flex;gap:7mm;align-items:flex-start;justify-content:center;flex-wrap:wrap}img.qr{image-rendering:pixelated;image-rendering:crisp-edges;background:#fff;min-width:36mm;max-width:none;height:auto;opacity:1!important;filter:none!important;cursor:zoom-in}img.qr.big{width:90mm!important;max-width:92vw!important;position:relative;z-index:20;cursor:zoom-out}@media print{img.qr.big{width:40mm!important}}'
// the signature (when he chose it) and the receiver's name (unless he hid it)
const signImg = (inv, a) => (inv.signed && a.signature ? `<div class="sgn"><img src="${esc(a.signature)}" alt="التوقيع"><span>${esc(inv.company?.signer || 'التوقيع')}</span></div>` : '')
const receiverLine = (inv) => (inv.hideReceiver ? '' : `<div class="rcv">المستلم: <b>${esc(inv.receiver || '..................')}</b></div>`)
const SIGN_CSS = '.sgn{display:inline-flex;flex-direction:column;align-items:center;font-size:12px}.sgn img{height:18mm;max-width:50mm;object-fit:contain}.rcv{font-size:13px}.marks{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin:10px 18px 0}'

const BASE_CSS = `*{box-sizing:border-box}body{margin:0;background:#eef1f6;font-family:Tajawal,'IBM Plex Sans Arabic','Noto Sans Arabic','Segoe UI',Tahoma,Arial,sans-serif;color:#1e293b;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{background:#fff;margin:16px auto;position:relative;overflow:hidden}.void{position:absolute;top:40%;left:-10%;right:-10%;text-align:center;transform:rotate(-18deg);font-size:64px;font-weight:900;color:rgba(220,38,38,.18);border:6px solid rgba(220,38,38,.18);padding:6px;z-index:5;pointer-events:none}
.bar{position:sticky;top:0;z-index:9;display:flex;gap:8px;justify-content:center;padding:10px;background:#0f172a}.bar button{font:inherit;font-weight:800;border:0;border-radius:10px;padding:9px 18px;cursor:pointer}
@media print{body{background:#fff}.bar{display:none}.page{margin:0;box-shadow:none}}`

/* ------------------------------------------------------------------ 1. official (his tax template) */

function official(inv, a) {
  const c = inv.company || {}, cl = inv.client || {}, T = inv.totals || {}, w = when(inv.at), tax = isTax(inv), [tAr, tEn] = title(inv)
  const cols = tax
    ? [['م', 'NO.'], ['الصنف', 'PRODUCT'], ['الوحدة', 'UNIT'], ['الكمية', 'QTY'], ['السعر', 'PRICE'], ['إجمالي', 'TOTAL'], ['إجمالي بعد الخصم', 'TOTAL AFTER DISCOUNT'], [`الضريبة`, `VAT ${inv.vatRate}%`], ['الصافي', 'NET.']]
    : [['م', 'NO.'], ['البند', 'ITEM'], ['الوحدة', 'UNIT'], ['الكمية', 'QTY'], ['السعر', 'PRICE'], ['الخصم', 'DISCOUNT'], ['الإجمالي', 'TOTAL']]
  const row = (r) => (tax
    ? [r.no, esc(r.name), esc(r.unit), r.qty, money(r.price), money(r.total), money(r.afterDiscount), money(r.vat), money(r.net)]
    : [r.no, esc(r.name), esc(r.unit), r.qty, money(r.price), money(r.discount), money(r.net)])
  const cell = (ar, en, v) => `<div class="f"><div class="k">${ar}${en ? ` / <span>${en}</span>` : ''}</div><div class="v">${v || '--'}</div></div>`
  const addr = tax && [cl.city, cl.district, cl.province, cl.zip, cl.street, cl.building, cl.addNo].some(Boolean)
  return `<style>@page{size:A4;margin:0}.page{width:210mm;min-height:297mm;padding:0 0 14mm}.top{display:flex;align-items:stretch;background:#f1f4fb}
.logo{width:34%;display:flex;align-items:center;justify-content:flex-start;padding:10px 18px}.logo .ring{width:118px;height:118px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px #e2e8f0}.logo img{width:92px}
.co{flex:1;text-align:center;padding:16px 6px;line-height:1.5}.co b{font-size:17px;display:block}.co small{font-size:13px;display:block;color:#1e293b;font-weight:700}.co .city{font-size:17px;margin-top:8px}
.tb{width:36%;background:#3f6ae0;color:#fff;padding:12px 16px;display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;align-content:start;font-size:12.5px}.tb h1{grid-column:1/-1;margin:0 0 4px;text-align:center;font-size:17px}.tb .v{font-size:14px;font-weight:700;text-decoration:underline}
.grid{display:grid;grid-template-columns:2.2fr 1fr 1.2fr 1fr .8fr;border-bottom:1px solid #cbd5e1;margin:10px 18px 0}.f{padding:6px 8px;border-left:1px solid #cbd5e1;font-size:11.5px}.f:last-child{border-left:0}.f .k{color:#334155}.f .k span{color:#64748b}.f .v{font-weight:800;font-size:12.5px;margin-top:2px}
.addr{display:grid;grid-template-columns:repeat(4,1fr);gap:2px 12px;margin:0 18px;padding:8px;border-bottom:1px solid #cbd5e1;font-size:11.5px}.addr span{color:#64748b}
.tbl{width:calc(100% - 36px);margin:14px 18px 0;border-collapse:collapse;font-size:11.5px}.tbl th{background:#dfe6fb;color:#2f56c9;padding:7px 4px;font-weight:800}.tbl th small{display:block;font-size:9.5px}.tbl td{padding:7px 4px;text-align:center;border-bottom:1px solid #e2e8f0}.tbl td:nth-child(2){text-align:right}.tbl{border-bottom:2px solid #3f6ae0}
.sum{display:flex;gap:18px;margin:12px 18px 0;align-items:flex-start}.tot{flex:1;border-collapse:collapse;font-size:12.5px}.tot td{padding:8px 6px;border-bottom:1px solid #e2e8f0;font-weight:700}.tot td.e{direction:ltr;text-align:left;color:#334155}.tot tr.n td{font-size:14px;color:#2f56c9}
.qrbox{width:${a.qr && a.qr2 ? 94 : 48}mm;padding:6px;border:1px solid #e2e8f0}.qrbox .qr{width:${a.qr && a.qr2 ? 40 : 42}mm;display:block}${QR_CSS}
.words{display:flex;justify-content:space-between;gap:12px;margin:16px 18px 0;padding:12px;background:#eef1f6;font-size:12.5px;font-weight:700}.words .e{direction:ltr}
.extra{margin:10px 18px 0;font-size:12px;display:flex;gap:24px;flex-wrap:wrap}.extra b{color:#2f56c9}
.stamp{display:block;width:52mm;opacity:.92}${SIGN_CSS}${REF_CSS}.foot{position:absolute;bottom:6mm;left:18px;right:18px;border-top:1px solid #cbd5e1;padding-top:6px;display:flex;justify-content:space-between;font-size:11px;color:#475569}</style>
<div class="page">${voidMark(inv)}
<div class="top"><div class="tb"><h1>${tAr} / ${tEn}</h1>
<div>التاريخ / Date</div><div>${tax ? 'الرقم الضريبي / VAT No' : 'رقم الفاتورة / No'}</div><div class="v">${w.date}</div><div class="v">${tax ? esc(c.vatNo) : inv.number}</div>
<div>الوقت / Time</div><div>رقم السجل التجاري / CR</div><div class="v">${w.time}</div><div class="v">${esc(c.crNo)}</div></div>
<div class="co"><b>${esc(c.nameAr)}</b><small>${esc(c.nameEn)}</small><div class="city">${esc(c.cityLine)}</div></div>
<div class="logo"><div class="ring"><img src="${esc(a.logo)}" alt=""></div></div></div>
<div class="grid">${cell('اسم العميل', 'Client Name', esc(cl.name))}${cell('رقم العميل', 'Client Number', esc(cl.number))}${cell('الرقم الضريبي', 'Vat No', esc(cl.vatNo))}${cell('رقم الفاتورة', 'Invoice No', inv.number)}${cell('نوع', 'Type', esc(PAYS[inv.pay] || 'نقدي'))}</div>
${addr ? `<div class="addr"><div><span>المدينة / City:</span> ${esc(cl.city)}</div><div><span>الحي / District:</span> ${esc(cl.district)}</div><div><span>المنطقة / Province:</span> ${esc(cl.province)}</div><div><span>الرمز البريدي / Zip:</span> ${esc(cl.zip)}</div><div><span>الشارع / Street:</span> ${esc(cl.street)}</div><div><span>رقم المبنى / B. No:</span> ${esc(cl.building)}</div><div><span>الرقم الإضافي / Add. No:</span> ${esc(cl.addNo)}</div><div><span>الهاتف / Phone:</span> ${esc(cl.phone)}</div></div>`
    : `<div class="addr" style="grid-template-columns:1fr"><div><span>رقم هاتف العميل / Client Phone:</span> ${esc(cl.phone) || '--'}</div></div>`}
${refBlock(inv)}<table class="tbl"><thead><tr>${cols.map(([ar, en]) => `<th>${ar}<small>${en}</small></th>`).join('')}</tr></thead><tbody>${(inv.rows || []).map((r) => `<tr>${row(r).map((v) => `<td>${v}</td>`).join('')}</tr>`).join('')}</tbody></table>
<div class="sum"><table class="tot">
${tax ? `<tr><td>إجمالي الفاتورة قبل الضريبة</td><td>${sar(T.afterDiscount)}</td><td class="e">Invoice Total Before Tax</td></tr><tr><td>الضريبة ${inv.vatRate}%</td><td>${sar(T.vat)}</td><td class="e">Total VAT ${inv.vatRate}%</td></tr>` : (T.discount ? `<tr><td>الإجمالي قبل الخصم</td><td>${sar(T.total)}</td><td class="e">Total</td></tr><tr><td>الخصم</td><td>${sar(T.discount)}</td><td class="e">Discount</td></tr>` : '')}
<tr class="n"><td>${tax ? 'اجمالي الفاتورة بعد الضريبة' : 'إجمالي الفاتورة'}</td><td>${sar(T.net)}</td><td class="e">${tax ? 'Invoice Net After Tax' : 'Invoice Total'}</td></tr></table>${tax || a.qr2 ? `<div class="qrbox"><div class="qrs">${tax ? qrImg(a) : ''}${qr2Img(a)}</div></div>` : ''}</div>
<div class="words"><span>${esc(arAmount(T.net))}</span><span class="e">${esc(enAmount(T.net))}</span></div>
<div class="extra">${inv.tech?.name ? `<span><b>الفني المنفذ:</b> ${esc(inv.tech.name)}</span>` : ''}${inv.note ? `<span><b>ملاحظات:</b> ${esc(inv.note)}</span>` : ''}</div>
<div class="marks">${receiverLine(inv)}${signImg(inv, a)}${stampImg(inv, a)}</div>
<div class="foot"><span>تاريخ الطباعة: ${when().dmy} &nbsp; وقت الطباعة: ${when().time}</span><span>1/1</span><span>${esc((c.phones || []).join(' — '))}</span></div></div>`
}

/* ------------------------------------------------------------------ 2. classic (his receipt) */

function classic(inv, a) {
  const c = inv.company || {}, cl = inv.client || {}, T = inv.totals || {}, w = when(inv.at), tax = isTax(inv), [tAr] = title(inv)
  const dots = (v) => `<span class="dv">${v ? esc(v) : ''}</span>`
  const what = (inv.rows || []).map((r) => r.name + (r.qty > 1 ? ' × ' + r.qty : '')).join(' — ')
  const pay = (k) => `<span class="pm${inv.pay === k ? ' on' : ''}">${PAYS[k]}</span>`
  return `<style>.page{width:210mm;min-height:148mm;padding:12mm 16mm 10mm;font-size:14px}.hd{display:flex;justify-content:space-between;align-items:center}.hd .co{line-height:1.7}.hd .co b{font-size:21px;display:block}.hd .co .l2{font-size:19px}.hd .co .cr{font-size:18px;font-weight:800}.hd img{width:150px}
.tt{text-align:center;margin:6px 0 10px}.tt span{display:inline-block;border:2px solid #0f172a;border-radius:10px;padding:3px 22px;font-weight:900;font-size:17px}
.ln{display:flex;gap:10px;align-items:baseline;margin:9px 0}.ln .k{white-space:nowrap;color:#334155}.dv{flex:1;border-bottom:1.5px dotted #64748b;font-weight:800;min-height:20px;padding:0 6px}
.pms{display:flex;gap:16px;align-items:center}.pm{padding:1px 8px;border-radius:4px}.pm.on{background:#fde047;font-weight:900}
.items{width:100%;border-collapse:collapse;margin:8px 0 4px;font-size:13px}.items th{background:#f1f5f9;padding:6px;border:1px solid #cbd5e1}.items td{padding:6px;border:1px solid #cbd5e1;text-align:center}.items td:nth-child(2){text-align:right}
.row2{display:flex;gap:26px;align-items:center;margin:10px 0}.box{border:2px solid #0f172a;border-radius:10px;padding:3px 18px;font-weight:900;font-size:17px}
.warn{font-weight:900;font-size:16px;margin:10px 0 4px}.sig{display:flex;justify-content:space-between;align-items:flex-end;margin-top:6px}.sig .s{width:36%}
.stamp{width:42mm;opacity:.9}.qr{width:40mm}${SIGN_CSS}${REF_CSS}${QR_CSS}.ft{border-top:2px solid #0f172a;margin-top:12px;padding-top:6px;display:flex;justify-content:space-between;font-size:16px;font-weight:700}.ft .ph{direction:ltr}
.vt{font-size:13px;margin:4px 0;display:flex;gap:18px;flex-wrap:wrap}.vt b{font-weight:900}</style>
<div class="page">${voidMark(inv)}
<div class="hd"><div class="co"><b>${esc(c.nameAr)}</b>${tax ? `<div class="l2">الرقم الضريبي: ${esc(c.vatNo)}</div>` : ''}<div class="cr">السجل التجاري: ${esc(c.crNo)}</div></div><img src="${esc(a.logo)}" alt=""></div>
<div class="tt"><span>${tAr} رقم ${inv.number}</span></div>${refBlock(inv)}
<div class="ln"><span class="k">التاريخ:</span>${dots(w.date + '  ' + w.time)}<span class="k">رقم الفاتورة:</span>${dots(inv.number)}</div>
<div class="ln"><span class="k">وصلنا من السادة:</span>${dots(cl.name + (cl.vatNo ? ' — الرقم الضريبي ' + cl.vatNo : ''))}</div>
<div class="ln"><span class="k">مبلغ وقدره:</span>${dots('#' + arAmount(T.net) + '#')}</div>
<div class="ln pms">${pay('cash')}${pay('cheque')}${pay('transfer')}${pay('card')}${inv.pay === 'credit' ? pay('credit') : ''}</div>
<div class="ln"><span class="k">وذلك مقابل:</span>${dots(what)}</div>
${(inv.rows || []).length > 1 || tax ? `<table class="items"><thead><tr><th>م</th><th>البند</th><th>العدد</th><th>السعر</th>${tax ? '<th>الضريبة</th>' : ''}<th>الإجمالي</th></tr></thead><tbody>${(inv.rows || []).map((r) => `<tr><td>${r.no}</td><td>${esc(r.name)}</td><td>${r.qty}</td><td>${money(r.price)}</td>${tax ? `<td>${money(r.vat)}</td>` : ''}<td>${money(r.net)}</td></tr>`).join('')}</tbody></table>` : ''}
${tax ? `<div class="vt"><span>الإجمالي قبل الضريبة: <b>${sar(T.afterDiscount)}</b></span><span>الضريبة ${inv.vatRate}%: <b>${sar(T.vat)}</b></span></div>` : ''}
<div class="row2"><span>إجمالي المبلغ</span><span class="box">#${money(T.net)}#</span>${inv.tech?.name ? `<span>الفني المنفذ: <b>${esc(inv.tech.name)}</b></span>` : ''}</div>
${inv.note ? `<div class="ln"><span class="k">ملاحظات:</span>${dots(inv.note)}</div>` : ''}
<div class="warn">${esc(c.note || 'أي سند لا يحمل ختم المؤسسة يعتبر لاغي')}</div>
<div class="sig"><div class="s">المحاسب: ..................</div><div class="qrs">${tax ? qrImg(a) : ''}${qr2Img(a)}</div>${signImg(inv, a)}${stampImg(inv, a)}${inv.hideReceiver ? '<div class="s"></div>' : `<div class="s">المستلم: ${esc(inv.receiver || '..................')}</div>`}</div>
<div class="ft"><span>${esc(c.address)}</span><span class="ph">${(c.phones || []).map((p) => '📱 ' + esc(p)).join('  ')}</span></div></div>`
}

/* ------------------------------------------------------------------ 3. modern */

function modern(inv, a) {
  const c = inv.company || {}, cl = inv.client || {}, T = inv.totals || {}, w = when(inv.at), tax = isTax(inv), [tAr, tEn] = title(inv)
  return `<style>@page{size:A4;margin:0}.page{width:210mm;min-height:297mm;padding:14mm 14mm 16mm}.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:4px solid #e8423f;padding-bottom:10px}
.hd img{width:120px}.hd .co b{font-size:18px;display:block}.hd .co div{font-size:12px;color:#475569;line-height:1.7}.badge{text-align:left}.badge h1{margin:0;font-size:26px;color:#e8423f;font-weight:900}.badge small{display:block;color:#64748b;letter-spacing:2px}
.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}.m{background:#f8fafc;border-radius:10px;padding:8px 10px}.m span{display:block;font-size:11px;color:#64748b}.m b{font-size:14px}
.to{display:flex;gap:12px}.to .card{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.8}.to .card h3{margin:0 0 4px;font-size:12px;color:#e8423f}
table{width:100%;border-collapse:collapse;margin-top:14px;font-size:12.5px}th{background:#1e293b;color:#fff;padding:8px 6px;font-weight:700}th:first-child{border-radius:0 8px 0 0}th:last-child{border-radius:8px 0 0 0}td{padding:8px 6px;border-bottom:1px solid #eef2f7;text-align:center}td:nth-child(2){text-align:right;font-weight:700}tbody tr:nth-child(even){background:#fafbfd}
.bot{display:flex;justify-content:space-between;gap:16px;margin-top:14px;align-items:flex-start}.tot{width:46%}.tot div{display:flex;justify-content:space-between;padding:7px 10px;font-size:13px}.tot .n{background:#e8423f;color:#fff;border-radius:10px;font-size:16px;font-weight:900}
.wd{margin-top:12px;font-size:13px;font-weight:700;color:#334155}.wd span{display:block;direction:ltr;text-align:left;color:#64748b;font-weight:600}.side{display:flex;flex-direction:column;align-items:center;gap:6px}.qr{width:40mm}.stamp{width:44mm;opacity:.9}${SIGN_CSS}${REF_CSS}${QR_CSS}
.ft{position:absolute;bottom:8mm;left:14mm;right:14mm;display:flex;justify-content:space-between;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:6px}</style>
<div class="page">${voidMark(inv)}
<div class="hd"><div class="co"><b>${esc(c.nameAr)}</b><div>${esc(c.nameEn)}</div><div>${tax ? 'الرقم الضريبي: ' + esc(c.vatNo) + ' · ' : ''}السجل التجاري: ${esc(c.crNo)}</div><div>${esc(c.cityLine)}</div></div>
<img src="${esc(a.logo)}" alt=""><div class="badge"><h1>${tAr}</h1><small>${tEn.toUpperCase()}</small></div></div>
<div class="meta"><div class="m"><span>رقم الفاتورة</span><b>#${inv.number}</b></div><div class="m"><span>التاريخ</span><b>${w.date}</b></div><div class="m"><span>الوقت</span><b>${w.time}</b></div><div class="m"><span>طريقة الدفع</span><b>${esc(PAYS[inv.pay] || 'نقدي')}</b></div></div>
${refBlock(inv)}<div class="to"><div class="card"><h3>العميل</h3><b>${esc(cl.name)}</b>${cl.phone ? `<br>📞 ${esc(cl.phone)}` : ''}${cl.vatNo ? `<br>الرقم الضريبي: ${esc(cl.vatNo)}` : ''}${[cl.street, cl.district, cl.city].some(Boolean) ? `<br>${esc([cl.building, cl.street, cl.district, cl.city, cl.zip].filter(Boolean).join('، '))}` : ''}</div>
${inv.tech?.name || inv.note ? `<div class="card"><h3>التنفيذ</h3>${inv.tech?.name ? `الفني المنفذ: <b>${esc(inv.tech.name)}</b>` : ''}${inv.note ? `<br>${esc(inv.note)}` : ''}</div>` : ''}</div>
<table><thead><tr><th>#</th><th>البند</th><th>الوحدة</th><th>الكمية</th><th>السعر</th>${tax ? '<th>قبل الضريبة</th><th>الضريبة</th>' : '<th>الخصم</th>'}<th>الإجمالي</th></tr></thead>
<tbody>${(inv.rows || []).map((r) => `<tr><td>${r.no}</td><td>${esc(r.name)}${r.code ? ` <small style="color:#94a3b8">(${esc(r.code)})</small>` : ''}</td><td>${esc(r.unit)}</td><td>${r.qty}</td><td>${money(r.price)}</td>${tax ? `<td>${money(r.afterDiscount)}</td><td>${money(r.vat)}</td>` : `<td>${money(r.discount)}</td>`}<td>${money(r.net)}</td></tr>`).join('')}</tbody></table>
<div class="bot"><div class="side"><div class="qrs">${tax ? qrImg(a) : ''}${qr2Img(a)}</div>${stampImg(inv, a)}${signImg(inv, a)}${receiverLine(inv)}</div><div class="tot">
${tax ? `<div><span>الإجمالي قبل الضريبة</span><b>${sar(T.afterDiscount)}</b></div><div><span>ضريبة القيمة المضافة ${inv.vatRate}%</span><b>${sar(T.vat)}</b></div>` : (T.discount ? `<div><span>الخصم</span><b>${sar(T.discount)}</b></div>` : '')}
<div class="n"><span>الإجمالي${tax ? ' شامل الضريبة' : ''}</span><span>${sar(T.net)}</span></div>
<div class="wd">${esc(arAmount(T.net))}<span>${esc(enAmount(T.net))}</span></div></div></div>
<div class="ft"><span>${esc(c.address)}</span><span>${esc((c.phones || []).join(' — '))}</span><span>${esc(c.note || '')}</span></div></div>`
}

/* ------------------------------------------------------------------ 4. thermal 80 mm */

function thermal(inv, a) {
  const c = inv.company || {}, cl = inv.client || {}, T = inv.totals || {}, w = when(inv.at), tax = isTax(inv), [tAr] = title(inv)
  return `<style>@page{size:80mm auto;margin:3mm}.page{width:74mm;padding:4mm 2mm;font-size:12px;line-height:1.5}.c{text-align:center}.page img.lg{width:38mm}.hr{border-top:1px dashed #0f172a;margin:6px 0}
.r{display:flex;justify-content:space-between;gap:6px}.it{margin:4px 0}.it b{display:block}.big{font-size:15px;font-weight:900}.qr{width:34mm;display:block;margin:6px auto}.stamp{width:34mm;display:block;margin:6px auto;opacity:.9}${SIGN_CSS}${REF_CSS}${QR_CSS}.refb{margin:6px 0}.qrs{flex-direction:column;align-items:center;gap:6mm}.qrs .qr{width:44mm;margin:4px auto}</style>
<div class="page">${voidMark(inv)}<div class="c"><img class="lg" src="${esc(a.logo)}" alt=""><div><b>${esc(c.nameAr)}</b></div>${tax ? `<div>الرقم الضريبي: ${esc(c.vatNo)}</div>` : ''}<div>السجل التجاري: ${esc(c.crNo)}</div><div class="big">${tAr}</div></div>
${refBlock(inv)}<div class="hr"></div><div class="r"><span>رقم: ${inv.number}</span><span>${w.date} ${w.time}</span></div><div>العميل: <b>${esc(cl.name)}</b></div>${cl.vatNo ? `<div>ض.ع: ${esc(cl.vatNo)}</div>` : ''}${inv.tech?.name ? `<div>الفني: ${esc(inv.tech.name)}</div>` : ''}
<div class="hr"></div>${(inv.rows || []).map((r) => `<div class="it"><b>${esc(r.name)}</b><div class="r"><span>${r.qty} × ${money(r.price)}</span><span>${money(r.net)}</span></div></div>`).join('')}
<div class="hr"></div>${tax ? `<div class="r"><span>قبل الضريبة</span><span>${money(T.afterDiscount)}</span></div><div class="r"><span>الضريبة ${inv.vatRate}%</span><span>${money(T.vat)}</span></div>` : ''}
<div class="r big"><span>الإجمالي</span><span>${sar(T.net)}</span></div><div class="c" style="font-size:11px">${esc(arAmount(T.net))}</div>
<div class="qrs">${tax ? qrImg(a) : ''}${qr2Img(a)}</div>${stampImg(inv, a)}<div class="c">${signImg(inv, a)}${receiverLine(inv)}</div><div class="hr"></div><div class="c">${esc((c.phones || []).join(' — '))}<br>${esc(c.address)}</div></div>`
}

const RENDER = { official, classic, modern, thermal }

/** The bundled logo and stamp, unless the owner set his own in the settings. */
export function assetUrls(inv) {
  const base = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.BASE_URL) || '/'
  const abs = (u) => { try { return new URL(u, typeof location !== 'undefined' ? location.href : 'http://x/').href } catch { return u } }
  return { logo: abs(inv.company?.logo || base + 'invoice/logo.png'), stamp: abs(inv.company?.stamp || base + 'invoice/stamp.png'), signature: inv.company?.signature ? abs(inv.company.signature) : '' }
}

/** The whole page (preview in an iframe, or printed). a = { logo, stamp, qr (data URL) }. */
export function invoiceHtml(inv, a, { bar = true } = {}) {
  const tpl = RENDER[inv.template] ? inv.template : (inv.type === 'tax' ? 'official' : 'classic')
  const [tAr] = title(inv)
  return `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${tAr} ${inv.number || ''}</title>
<style>${BASE_CSS}</style></head><body>${bar ? `<div class="bar"><button onclick="print()" style="background:#22c55e;color:#04120a">🖨 طباعة / حفظ PDF</button><button onclick="close()" style="background:#334155;color:#fff">إغلاق</button></div>` : ''}${RENDER[tpl](inv, a)}</body></html>`
}

async function qrData(text) {
  if (!text) return ''
  // margin 4 = the standard quiet zone; scale 10 px per square so the picture is crisp at any print size
  try { const QR = (await import('qrcode')).default; return await QR.toDataURL(text, { margin: 4, scale: 10, errorCorrectionLevel: 'M', color: { dark: '#000000', light: '#ffffff' } }) } catch { return '' }
}

/** Everything the page needs (logo, stamp, QR as a picture). */
export async function invoiceAssets(inv) { return { ...assetUrls(inv), qr: isTax(inv) ? await qrData(inv.qr) : '', qr2: inv.viewUrl ? await qrData(inv.viewUrl) : '' } }

/** Opens the invoice in a new tab, ready to print or save as PDF. */
export async function printInvoice(inv) {
  const w = window.open('', '_blank')
  const html = invoiceHtml(inv, await invoiceAssets(inv))
  if (!w) return false
  w.document.open(); w.document.write(html); w.document.close()
  return true
}
