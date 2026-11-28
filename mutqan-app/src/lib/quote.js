// Quotation page — his template (header, «عرض سعر / QUOTATION», number / salesman / issue date / valid till / status,
// customer details, items with tax, totals, terms, bank accounts, footer). Amounts come from the server.
import { money, arAmount } from './invoice'

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
const br = (s) => esc(s).replace(/\n/g, '<br>')
const dmy = (iso) => { const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}-${m[2]}-${m[1]}` : '' }
export const QSTATUS = { draft: ['مسودة', 'Draft'], approved: ['معتمد', 'Approved'], sent: ['مرسل', 'Sent'], accepted: ['مقبول', 'Accepted'], rejected: ['مرفوض', 'Rejected'] }

export function quoteAssets(q) {
  const base = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.BASE_URL) || '/'
  const abs = (u) => { try { return new URL(u, typeof location !== 'undefined' ? location.href : 'http://x/').href } catch { return u } }
  const c = q.company || {}
  // a branch with its own footer uses it; a branch other than Makkah without one gets a written footer (the bundled image says Makkah)
  const ownFooter = c.branchFooter || c.footer
  const textFooter = !ownFooter && c.branchId && c.branchId !== 'mkk'
  return { textFooter, header: abs(c.header || base + 'quote/header.png'), footer: textFooter ? '' : abs(ownFooter || base + 'quote/footer.png'), title: abs(c.title || base + 'quote/title.png'),
    stamp: abs(c.stamp || base + 'invoice/stamp.png'), signature: c.signature ? abs(c.signature) : '' }
}

export function quoteHtml(q, a, { bar = true } = {}) {
  const cl = q.client || {}, T = q.totals || {}, st = QSTATUS[q.status] || QSTATUS.draft, c = q.company || {}
  const draft = (q.status || 'draft') === 'draft'
  const tr = (ar, v, en) => `<tr><td class="k">${ar}</td><td class="v">${v}</td><td class="e">${en}</td></tr>`
  const addr = [cl.building, cl.street, cl.district, cl.city, cl.zip].filter(Boolean).join('، ')
  const terms = (q.terms || []).map((t) => { const m = String(t).match(/^([^:：]{2,30})\s*[:：]\s*(.*)$/s); return m ? `<li><b>${esc(m[1])} :</b> ${br(m[2])}</li>` : `<li>${br(t)}</li>` }).join('')
  return `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(q.number || 'عرض سعر')}</title>
<style>*{box-sizing:border-box}body{margin:0;background:#eef1f6;font-family:'Times New Roman',Tajawal,'Noto Naskh Arabic',Tahoma,serif;color:#111;-webkit-print-color-adjust:exact;print-color-adjust:exact}
@page{size:A4;margin:0}.page{width:210mm;min-height:297mm;margin:16px auto;background:#fff;padding:7mm 10mm 24mm;position:relative}.hdr{width:100%;display:block}.ttl{display:flex;justify-content:flex-start;margin:3mm 0 3mm}.ttl img{height:15mm}
.meta{display:flex;gap:6mm;align-items:flex-start}.meta table{border-collapse:collapse;font-size:12px;flex:1}.meta td{border:1px solid #9ca3af;padding:4px 6px;text-align:center}.meta td.e{direction:ltr}
.dn{font-size:11px;margin:2px 0 3mm}.cd{width:100%;border-collapse:collapse;font-size:12.5px}.cd td{border:1px solid #9ca3af;padding:4px 8px}.cd .hd{background:#f3f4f6;font-weight:800}.cd td.k{width:18%}.cd td.e{width:18%;direction:ltr;text-align:left}.cd td.v{text-align:center}
.it{width:100%;border-collapse:collapse;font-size:12.5px}.it th{background:#f3f4f6;border:1px solid #9ca3af;padding:6px 4px;font-weight:800}.it th small{display:block;font-weight:600}.it td{border:1px solid #9ca3af;padding:5px 5px;text-align:center;vertical-align:top}.it td.n{text-align:center}.it td.n .sp{font-size:11px;color:#374151;margin-top:3px;line-height:1.5}
.tt{width:100%;border-collapse:collapse;font-size:12.5px}.tt td{border:1px solid #9ca3af;padding:4px 7px;text-align:center}.tt td.e{direction:ltr}
.trm{margin-top:3mm;font-size:12.5px}.trm h3{margin:0 0 2mm;font-size:14px;text-decoration:underline}.trm ul{margin:0;padding:0 6mm 0 0;list-style:none}.trm li{margin:1mm 0}
.xn{font-weight:700;text-decoration:underline;margin:3mm 0;font-size:13px}.bk{font-size:12.5px;margin-top:2mm}.bk div{margin:1mm 0}.bk b{direction:ltr;display:inline-block}
.intro{font-size:13px;margin:0 0 4mm;line-height:1.8}.ft{position:absolute;left:10mm;right:10mm;bottom:6mm}.ft img{width:100%;display:block}
.sg{display:flex;justify-content:space-between;align-items:flex-end;margin-top:2mm;page-break-inside:avoid}.sg .p{font-size:12px;text-align:center}.sg img.st{width:30mm;opacity:.9}.sg img.si{height:16mm}
.wd{font-size:12px;margin-top:2mm;font-weight:700}.wm{position:absolute;top:42%;left:0;right:0;text-align:center;font-size:90px;font-weight:900;color:rgba(148,163,184,.16);transform:rotate(-18deg);pointer-events:none}
.bar{position:sticky;top:0;z-index:9;display:flex;gap:8px;justify-content:center;padding:10px;background:#0f172a}.bar button{font:inherit;font-weight:800;border:0;border-radius:10px;padding:9px 18px;cursor:pointer}
@media print{body{background:#fff}.bar{display:none}.page{margin:0}}</style></head><body>
${bar ? '<div class="bar"><button onclick="print()" style="background:#22c55e">🖨 طباعة / حفظ PDF</button><button onclick="close()" style="background:#334155;color:#fff">إغلاق</button></div>' : ''}
<div class="page">${draft ? '<div class="wm">DRAFT</div>' : ''}
<img class="hdr" src="${esc(a.header)}" alt="">
<div class="ttl"><img src="${esc(a.title)}" alt="عرض سعر QUOTATION"></div>
<div class="meta"><table>${tr('رقم عرض السعر', esc(q.number || '—'), 'quotation No')}${tr(esc(q.staff?.title || 'أخصائي المبيعات'), esc(q.staff?.name || ''), esc(q.staff?.titleEn || 'Sales man'))}</table>
<table>${tr('تاريخ الاصدار', dmy(q.issueDate), 'Issue Date')}${tr('صالح حتى', dmy(q.validTill), 'Valid till')}${tr('حالة العرض', st[1], 'QTN Status')}</table></div>
<div class="dn">${esc(c.note || 'ملاحظة : لا يعتبر عرض السعر معتمدا بشكل رسمي في حال كانت حالته Draft')}</div>
${q.intro ? `<div class="intro">${br(q.intro)}</div>` : ''}
<table class="cd"><tr><td class="hd" colspan="3">بيانات العميل <span style="direction:ltr">Customer Details</span></td></tr>
${tr('اسم العميل', esc(cl.name), 'Name').replace(/<td class="k">/, '<td class="k">')}
${cl.responsible ? tr('الشخص المسؤول', esc(cl.responsible), 'Responsible Person') : ''}
${tr('الجوال', esc(cl.phone), '.Mobile No')}
${cl.vatNo ? tr('الرقم الضريبي', esc(cl.vatNo), 'VAT No') : ''}${cl.crNo ? tr('السجل التجاري', esc(cl.crNo), 'CR No') : ''}${addr ? tr('العنوان', esc(addr), 'Address') : ''}
${tr('ملاحظات', br(q.remark), 'Remark')}</table>
<table class="it"><thead><tr><th>ر.ت<small>No</small></th><th>اسم الصنف<small>Item Name</small></th><th>الكمية<small>Quantity</small></th><th>السعر<small>Price</small></th><th>الضريبة<small>Tax</small></th><th>المجموع شامل الضريبة<small>SubT.+VAT</small></th></tr></thead>
<tbody>${(q.rows || []).map((r) => `<tr><td>${r.no}</td><td class="n">${esc(r.name)}${r.specs ? `<div class="sp">${br(r.specs)}</div>` : ''}</td><td>${Number(r.qty).toFixed(1)}</td><td>${money(r.qty ? r.afterDiscount / r.qty : 0)}</td><td>${money(r.vat)}</td><td>${money(r.net)}</td></tr>`).join('')}</tbody></table>
<table class="tt"><tr><td>الاجمالي بدون الضريبة</td><td class="e">Total Excl.VAT</td><td>${money(T.afterDiscount)}</td></tr><tr><td>اجمالي الضريبة</td><td class="e">Total VAT</td><td>${money(T.vat)} ر.س</td></tr><tr><td>اجمالي المبلغ شامل الضريبة</td><td class="e">Total include tax</td><td><b>${money(T.net)} ر.س</b></td></tr></table>
<div class="wd">${esc(arAmount(T.net))}</div>
${terms ? `<div class="trm"><h3>الشروط والاحكام</h3><ul>${terms}</ul></div>` : ''}
${q.extraNote ? `<div class="xn">** ${br(q.extraNote)}</div>` : ''}
${q.showBanks !== false && (c.banks || []).length ? `<div class="bk"><b style="direction:rtl">الحسابات البنكية:</b>${c.banks.map((b) => `<div>${esc(b.bank)} : <b>IBAN - ${esc(b.iban)}</b></div>`).join('')}</div>` : ''}
${q.stamped || q.signed ? `<div class="sg"><div class="p">${q.signed && a.signature ? `<img class="si" src="${esc(a.signature)}" alt=""><br>` : ''}${esc(q.staff?.name || '')}</div>${q.stamped ? `<img class="st" src="${esc(a.stamp)}" alt="">` : ''}</div>` : ''}
<div class="ft">${a.textFooter ? `<div style="border-top:3px solid #dc2626;padding-top:6px;display:flex;justify-content:space-between;font-size:12px;font-weight:700"><span>📞 ${esc((c.phones || []).join(' — '))}</span><span>${esc(c.cityLine || '')}</span><span>${esc(c.address || '')}</span></div>` : `<img src="${esc(a.footer)}" alt="">`}</div></div></body></html>`
}

export function printQuote(q) {
  const w = window.open('', '_blank')
  if (!w) return false
  w.document.open(); w.document.write(quoteHtml(q, quoteAssets(q))); w.document.close()
  return true
}
