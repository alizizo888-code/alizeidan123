// Visual themes (classic -> primitive -> tech -> modern/app) and contact widgets (floating, dropdown, bar, tab...). Pure HTML/CSS, no JS, so it survives Blogger/WordPress sanitising.
const h = (s) => { let x = 2166136261; for (const c of String(s)) x = Math.imul(x ^ c.charCodeAt(0), 16777619); return x >>> 0 }
const THEMES = [
  { id: 'classic_paper', era: 'كلاسيكي', css: 'font-family:Georgia,"Times New Roman",serif;background:#fbf8f1;color:#2b2118;border:1px solid #d9cdb4;padding:22px;line-height:2', h: '#7a3e12', a: '#7a3e12', hb: 'border-bottom:2px double #7a3e12;padding-bottom:6px' },
  { id: 'classic_blue', era: 'كلاسيكي', css: 'font-family:Tahoma,Arial,sans-serif;background:#fff;color:#1b2a41;border:3px solid #1b3a6b;padding:20px;line-height:1.95', h: '#1b3a6b', a: '#1b3a6b', hb: 'background:#e8eef8;padding:6px 10px;border-right:6px solid #1b3a6b' },
  { id: 'primitive_90s', era: 'بدائي', css: 'font-family:"Courier New",monospace;background:#fff;color:#000;border:2px outset #888;padding:14px;line-height:1.8', h: '#0000cc', a: '#0000ee', hb: 'text-decoration:underline' },
  { id: 'primitive_table', era: 'بدائي', css: 'font-family:Verdana,sans-serif;background:#ffffe0;color:#222;border:1px solid #333;padding:14px;line-height:1.85', h: '#800000', a: '#0000aa', hb: 'background:#c0c0c0;padding:3px 8px;border:1px solid #666' },
  { id: 'tech_dark', era: 'تقني', css: 'font-family:system-ui,sans-serif;background:#0b1220;color:#d6e2f5;border:1px solid #1f3b63;border-radius:14px;padding:22px;line-height:1.95', h: '#38bdf8', a: '#38bdf8', hb: 'border-right:4px solid #38bdf8;padding-right:10px' },
  { id: 'tech_neon', era: 'تقني', css: 'font-family:system-ui,sans-serif;background:#0d0d14;color:#e6e6f0;border:1px solid #a855f7;border-radius:12px;padding:22px;line-height:1.95;box-shadow:0 0 22px #a855f733', h: '#c084fc', a: '#22d3ee', hb: 'text-shadow:0 0 8px #a855f777' },
  { id: 'tech_grid', era: 'تقني', css: 'font-family:ui-monospace,Menlo,monospace;background:#f3f6fa;color:#0f172a;border:1px solid #94a3b8;border-radius:4px;padding:20px;line-height:1.9', h: '#0369a1', a: '#0369a1', hb: 'border-bottom:1px dashed #0369a1;padding-bottom:4px' },
  { id: 'modern_clean', era: 'حديث', css: 'font-family:system-ui,sans-serif;background:#fff;color:#1e293b;border-radius:18px;padding:24px;line-height:2;box-shadow:0 8px 30px #0f172a14', h: '#0f766e', a: '#0f766e', hb: '' },
  { id: 'modern_soft', era: 'حديث', css: 'font-family:system-ui,sans-serif;background:linear-gradient(180deg,#fff7ed,#fff);color:#3b2a1a;border-radius:22px;padding:24px;line-height:2', h: '#ea580c', a: '#ea580c', hb: '' },
  { id: 'modern_glass', era: 'حديث', css: 'font-family:system-ui,sans-serif;background:linear-gradient(135deg,#e0f2fe,#ede9fe);color:#1e1b4b;border-radius:24px;padding:24px;line-height:2;border:1px solid #ffffffaa', h: '#4338ca', a: '#4338ca', hb: '' },
  { id: 'app_cards', era: 'تطبيق', css: 'font-family:system-ui,sans-serif;background:#f1f5f9;color:#0f172a;border-radius:26px;padding:18px;line-height:1.95', h: '#16a34a', a: '#16a34a', hb: 'background:#fff;border-radius:14px;padding:10px 14px;box-shadow:0 2px 8px #0f172a10' },
  { id: 'app_ios', era: 'تطبيق', css: 'font-family:-apple-system,system-ui,sans-serif;background:#f2f2f7;color:#111;border-radius:28px;padding:18px;line-height:1.95', h: '#007aff', a: '#007aff', hb: 'font-weight:800' },
  { id: 'earth', era: 'حديث', css: 'font-family:system-ui,sans-serif;background:#f5f1e8;color:#2f2a1e;border-radius:12px;padding:22px;line-height:2;border-top:6px solid #65a30d', h: '#3f6212', a: '#3f6212', hb: '' },
  { id: 'mono_ink', era: 'كلاسيكي', css: 'font-family:Georgia,serif;background:#fff;color:#111;border-top:5px solid #111;border-bottom:5px solid #111;padding:22px;line-height:2', h: '#111', a: '#b91c1c', hb: 'letter-spacing:.3px' },
]
const COL = ['#16a34a', '#0ea5e9', '#f97316', '#e11d48', '#7c3aed', '#0f172a', '#ca8a04', '#0d9488']
const wa = (href, col, extra = '') => `<a href="${href}" style="display:inline-block;background:${col};color:#fff;font-weight:800;text-decoration:none;${extra}">`
const tel = (n) => 'tel:+' + String(n).replace(/\D/g, '')
const WIDGETS = {
  fab_call: (hf, c, n, ord, num) => `<div style="position:fixed;bottom:18px;left:18px;z-index:99;display:flex;flex-direction:column;gap:10px"><a href="${tel(num)}" aria-label="اتصال" style="width:54px;height:54px;border-radius:50%;background:#0f172a;color:#fff;font:800 12px system-ui;display:flex;align-items:center;justify-content:center;text-decoration:none;box-shadow:0 6px 18px #0004">اتصال</a><a href="${hf}" aria-label="واتساب" style="width:54px;height:54px;border-radius:50%;background:${c};color:#fff;font:800 12px system-ui;display:flex;align-items:center;justify-content:center;text-decoration:none;box-shadow:0 6px 18px #0004">واتساب</a></div>`,
  bar_split: (hf, c, n, ord, num) => `<div style="position:fixed;bottom:0;left:0;right:0;z-index:99;display:flex"><a href="${tel(num)}" style="flex:1;background:#0f172a;color:#fff;font:800 15px system-ui;text-align:center;padding:13px;text-decoration:none">اتصل</a><a href="${hf}" style="flex:1;background:${c};color:#fff;font:800 15px system-ui;text-align:center;padding:13px;text-decoration:none">واتساب</a></div>`,
  fab: (hf, c, n) => `<a href="${hf}" aria-label="واتساب" style="position:fixed;bottom:18px;left:18px;z-index:99;width:58px;height:58px;border-radius:50%;background:${c};color:#fff;font:800 13px system-ui;display:flex;align-items:center;justify-content:center;text-decoration:none;box-shadow:0 6px 18px #0004">واتساب</a>`,
  fab_label: (hf, c, n) => `<a href="${hf}" style="position:fixed;bottom:18px;right:18px;z-index:99;background:${c};color:#fff;font:800 15px system-ui;padding:13px 22px;border-radius:99px;text-decoration:none;box-shadow:0 6px 18px #0004">تواصل مع ${n}</a>`,
  bar: (hf, c, n) => `<div style="position:fixed;bottom:0;left:0;right:0;z-index:99;background:${c};text-align:center;padding:11px"><a href="${hf}" style="color:#fff;font:800 16px system-ui;text-decoration:none">اطلب الآن عبر واتساب مع ${n}</a></div>`,
  topbar: (hf, c, n) => `<div style="position:sticky;top:0;z-index:99;background:${c};text-align:center;padding:9px"><a href="${hf}" style="color:#fff;font:700 14px system-ui;text-decoration:none">استشارة سريعة مع ${n} على واتساب</a></div>`,
  sidetab: (hf, c, n) => `<a href="${hf}" style="position:fixed;top:45%;left:0;z-index:99;background:${c};color:#fff;font:800 14px system-ui;padding:12px 8px;writing-mode:vertical-rl;border-radius:0 10px 10px 0;text-decoration:none">واتساب ${n}</a>`,
  dropdown: (hf, c, n, ord, num) => `<details style="position:fixed;bottom:18px;left:18px;z-index:99;font:700 14px system-ui"><summary style="list-style:none;cursor:pointer;background:${c};color:#fff;padding:12px 20px;border-radius:99px;box-shadow:0 6px 18px #0004">تواصل معنا ▾</summary><div style="background:#fff;border:1px solid #ddd;border-radius:14px;margin-bottom:8px;padding:8px;min-width:190px;position:absolute;bottom:52px;left:0;box-shadow:0 8px 24px #0003"><a href="${hf}" style="display:block;padding:10px;color:#15803d;text-decoration:none">واتساب ${n}</a><a href="${tel(num)}" style="display:block;padding:10px;color:#0f172a;text-decoration:none;border-top:1px solid #eee">اتصال مباشر</a><a href="${ord}" style="display:block;padding:10px;color:#0369a1;text-decoration:none;border-top:1px solid #eee">اطلب فني من التطبيق</a></div></details>`,
  stack: (hf, c, n, ord) => `<div style="position:fixed;bottom:16px;right:16px;z-index:99;display:flex;flex-direction:column;gap:8px">${wa(hf, c, 'padding:11px 18px;border-radius:14px;font-size:14px;box-shadow:0 4px 14px #0003')}واتساب</a>${wa(ord, '#0f172a', 'padding:11px 18px;border-radius:14px;font-size:14px;box-shadow:0 4px 14px #0003')}اطلب فنياً</a></div>`,
  inline_card: () => '',                                          // in-flow buttons only
}
const KEYS = Object.keys(WIDGETS)
// pick by seed so every site/article gets a different look; recent choices are avoided by the caller
exports.THEMES = THEMES; exports.WIDGETS = KEYS
exports.pick = (seed, avoid = []) => { const t = THEMES.filter((x) => !avoid.includes(x.id)), T = t[h(seed + 't') % t.length], W = KEYS[h(seed + 'w') % KEYS.length]; return { theme: T, widget: W, color: COL[h(seed + 'c') % COL.length] } }
// wrap article body (Blogger/WP post) in the theme box + add the floating widget
exports.wrap = (html, pk, line, href, orderUrl) => {
  const T = pk.theme, n = line && line.display_name ? line.display_name : 'الفريق', w = line ? WIDGETS[pk.widget](href, pk.color, n, orderUrl, line.number) : ''
  const styled = html.replace(/<h2(?![^>]*style)/g, `<h2 style="color:${T.h};${T.hb}"`).replace(/<h3(?![^>]*style)/g, `<h3 style="color:${T.h}"`).replace(/<a (?![^>]*style)/g, `<a style="color:${T.a}" `)
  return `<div dir="rtl" style="${T.css}" data-theme="${T.id}">${styled}</div>${w}`
}
// full static landing page with theme and widget
exports.page = (title, html, pk, line, href, orderUrl, url, desc) => {
  const T = pk.theme, n = line && line.display_name ? line.display_name : 'الفريق', w = line ? WIDGETS[pk.widget](href, pk.color, n, orderUrl, line.number) : ''
  const bg = /dark|neon/.test(T.id) ? '#05080f' : '#e9edf2'
  return `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title>${desc ? `<meta name="description" content="${desc}">` : ''}${url ? `<link rel="canonical" href="${url}">` : ''}<body style="margin:0;background:${bg}"><main style="max-width:880px;margin:14px auto;${T.css}">${html.replace(/<h2(?![^>]*style)/g, `<h2 style="color:${T.h};${T.hb}"`).replace(/<h1(?![^>]*style)/g, `<h1 style="color:${T.h}"`)}</main>${w}</body></html>`
}
