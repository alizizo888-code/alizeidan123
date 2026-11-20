// Optional AI helper for the Blogger publisher (owner order 2026-11-08): free-tier APIs make every article more unique.
// Providers, tried in order, each used only when its key exists as a repo secret:
//   1. Google Gemini      GEMINI_API_KEY      (model: GEMINI_MODEL, else the newest flash model from the live list)
//   2. Groq               GROQ_API_KEY        (model: GROQ_MODEL, else the best one from the live list)
//   3. OpenRouter         OPENROUTER_API_KEY  (model: OPENROUTER_MODEL, default meta-llama/llama-3.3-70b-instruct:free)
// With no key at all, enrich() returns null and the article is published as built by the generator (nothing breaks).
// The model only writes 3 extra sections as JSON; the HTML is built here, escaped, and checked:
// no prices, phone numbers, ratings, licences, years of experience or invented customer stories are accepted.
const e = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const DRY = process.env.BLOGGER_DRY === '1'
const TIMEOUT = 45000

// model names change over time (a retired model answers 404): pick one from the live list unless GEMINI_MODEL / GROQ_MODEL is set
const MODELS = {}
async function geminiModel(key) {
  if (process.env.GEMINI_MODEL) return process.env.GEMINI_MODEL
  if (!MODELS.gemini) {
    try {
      const j = await (await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${key}&pageSize=200`, { signal: AbortSignal.timeout(TIMEOUT) })).json()
      const ms = (j.models || []).filter((m) => (m.supportedGenerationMethods || []).includes('generateContent')).map((m) => m.name.split('/')[1])
      let ok = ms.filter((m) => m.includes('flash') && !/image|tts|live|audio|embed|thinking|exp|preview/.test(m)); if (!ok.length) ok = ms.filter((m) => m.includes('flash')); if (!ok.length) ok = ms
      const v = (m) => (m.match(/\d+/g) || []).slice(0, 2).map(Number)
      ok.sort((a, b) => { const x = v(a), y = v(b); return (y[0] || 0) - (x[0] || 0) || (y[1] || 0) - (x[1] || 0) || (a.includes('lite') - b.includes('lite')) })
      MODELS.gemini = ok[0] || 'gemini-2.5-flash'
    } catch { MODELS.gemini = 'gemini-2.5-flash' }
  }
  return MODELS.gemini
}
const GROQ_PREFER = ['llama-3.3-70b', 'gpt-oss-120b', 'llama-4-maverick', 'llama-4-scout', 'kimi-k2', 'qwen3-32b', 'gpt-oss-20b', 'llama-3.1-8b']
async function groqModel(key) {
  if (process.env.GROQ_MODEL) return process.env.GROQ_MODEL
  if (!MODELS.groq) {
    try {
      const j = await (await fetch('https://api.groq.com/openai/v1/models', { headers: { authorization: 'Bearer ' + key }, signal: AbortSignal.timeout(TIMEOUT) })).json()
      const ids = (j.data || []).filter((m) => m.active !== false && !/whisper|guard|tts|orpheus|playai|distil/.test(m.id)).map((m) => m.id)
      MODELS.groq = GROQ_PREFER.map((p) => ids.find((i) => i.includes(p))).find(Boolean) || ids[0] || 'llama-3.3-70b-versatile'
    } catch { MODELS.groq = 'llama-3.3-70b-versatile' }
  }
  return MODELS.groq
}

const PROVIDERS = [
  { name: 'groq', key: 'GROQ_API_KEY', ask: async (prompt, key, model) => openaiLike('https://api.groq.com/openai/v1/chat/completions', key, model || await groqModel(key), prompt),
    // when the best model hits its daily token cap or fails the JSON check, smaller models still have their own free quota
    fallbacks: ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'] },
  { name: 'gemini', key: 'GEMINI_API_KEY', async ask(prompt, key) {
    const model = await geminiModel(key)
    const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${key}`, {
      method: 'POST', headers: { 'content-type': 'application/json' }, signal: AbortSignal.timeout(TIMEOUT),
      body: JSON.stringify({ contents: [{ role: 'user', parts: [{ text: prompt }] }], generationConfig: { temperature: 0.9, responseMimeType: 'application/json' } }),
    })
    const j = await r.json().catch(() => ({}))
    // «high demand» (503) is momentary: one more try after a short wait before giving the article up
    if (r.status === 503 && !this._retried) { this._retried = true; await new Promise((ok) => setTimeout(ok, 6000)); try { return await this.ask(prompt, key) } finally { this._retried = false } }
    if (!r.ok) throw new Error(`gemini ${r.status} ${(j.error && j.error.message) || ''}`.slice(0, 160))
    return ((j.candidates || [])[0] || {}).content?.parts?.map((p) => p.text).join('') || ''
  } },
  { name: 'openrouter', key: 'OPENROUTER_API_KEY', ask: (prompt, key) => openaiLike('https://openrouter.ai/api/v1/chat/completions', key, process.env.OPENROUTER_MODEL || 'meta-llama/llama-3.3-70b-instruct:free', prompt) },
]
async function openaiLike(url, key, model, prompt, strict = true) {
  const r = await fetch(url, {
    method: 'POST', signal: AbortSignal.timeout(TIMEOUT),
    headers: { 'content-type': 'application/json', authorization: 'Bearer ' + key },
    body: JSON.stringify(Object.assign({ model, temperature: 0.8, messages: [{ role: 'user', content: prompt }] }, strict ? { response_format: { type: 'json_object' } } : {})),
  })
  const j = await r.json().catch(() => ({}))
  // 2026-11-20: Groq's JSON mode refuses many Arabic answers («Failed to validate/generate JSON») although the text is fine —
  // ask once more without the JSON mode and let toHtml() cut the object out of the reply.
  if (!r.ok && strict && r.status === 400 && /JSON/i.test(String((j.error && (j.error.message || j.error)) || ''))) return openaiLike(url, key, model, prompt, false)
  if (!r.ok) throw new Error(`${new URL(url).host} ${r.status} ${(j.error && (j.error.message || j.error)) || ''}`.slice(0, 160))
  return ((j.choices || [])[0] || {}).message?.content || ''
}

// several free keys per provider (owner order 2026-11-20): KEY=a,b,c — each Google / Groq account has its own free quota
const keysOf = (p) => String(process.env[p.key] || '').split(',').map((k) => k.trim()).filter(Boolean)
const available = () => PROVIDERS.filter((p) => keysOf(p).length)
const disabled = new Set()   // «provider|key|model» that hit its free quota (429/403) is skipped for the rest of the run

const BAD = /ريال|ر\.س|\bSAR\b|\d{3,}|[٠-٩]{3,}|\d+ ?%|نجوم|مرخص|ترخيص|رخصة|سنة خبرة|سنوات خبرة|عاماً من الخبرة|عميلنا|أحد عملائنا|اتصل بنا على/

function prompt(ctx) {
  return `أنت كاتب محتوى عربي محترف لموقع خدمات صيانة في السعودية. اكتب 3 أقسام جديدة تضاف إلى مقال بعنوان «${ctx.title}».
التخصص: ${ctx.kind === 'fridge' ? 'غرف التبريد والتجميد والثلاجات التجارية' : ctx.kind === 'ac' ? 'المكيفات (غسيل وصيانة وتركيب وتكييف مركزي)' : ctx.spec}.
المكان: ${ctx.where}. الجهة: ${ctx.brand}. زاوية الموقع: ${ctx.angle || 'عام'}.
الشروط:
- كل قسم له عنوان (h2) مختلف تماماً عن العناوين العادية، و2 إلى 3 فقرات، والمجموع بين 350 و500 كلمة.
- معلومات عملية وصحيحة مرتبطة بمناخ ${ctx.where.includes('جدة') ? 'جدة الرطب' : 'مكة الحار'} ونوع المباني والاستخدام الفعلي.
- لا تذكر أي سعر أو رقم هاتف أو أرقام إحصائية أو تقييمات أو تراخيص أو سنوات خبرة، ولا قصة عميل حقيقي. أي مثال يجب أن تقول إنه افتراضي.
- الأسلوب يقود القارئ لطلب فني متخصص بدل الإصلاح بنفسه، بدون مبالغة.
- لا تكرر هذه العناوين الموجودة: ${ctx.headings.slice(0, 20).join(' | ')}
أرجع JSON فقط بهذا الشكل: {"sections":[{"h2":"...","paragraphs":["...","..."]}]}`
}

function toHtml(raw) {
  const t = String(raw || ''), a = t.indexOf('{'), b = t.lastIndexOf('}')
  let j; try { j = JSON.parse(a >= 0 && b > a ? t.slice(a, b + 1) : t) } catch { return null }
  const secs = (j.sections || []).filter((s) => s && s.h2 && Array.isArray(s.paragraphs) && s.paragraphs.length)
  if (secs.length < 2) return null
  const text = secs.map((s) => s.h2 + ' ' + s.paragraphs.join(' ')).join(' ')
  if (BAD.test(text)) return null
  const words = text.split(/\s+/).filter(Boolean).length
  if (words < 250) return null
  return { words, html: '<!--ai-->' + secs.slice(0, 4).map((s) => `<h2>${e(s.h2)}</h2>` + s.paragraphs.slice(0, 4).map((p) => `<p>${e(p)}</p>`).join('')).join('') }
}

// ctx = {title, kind, spec, where, brand, angle, headings[]}. Returns {provider, words, html} or null.
async function enrich(ctx) {
  if (DRY) return null
  for (const p of available()) {
    const keys = keysOf(p)
    for (let ki = 0; ki < keys.length; ki++) {
      for (const model of [null, ...(p.fallbacks || [])]) {
        const tag = `${p.name}|${ki}|${model || '*'}`
        if (disabled.has(tag)) continue
        try {
          const h = toHtml(await p.ask(prompt(ctx), keys[ki], model))
          if (h) return { provider: p.name + (keys.length > 1 ? '#' + (ki + 1) : ''), ...h }
        } catch (err) {
          if (/ 429| 403/.test(err.message)) disabled.add(tag)
          ctx.errors && ctx.errors.push(`ai ${p.name}#${ki + 1}${model ? ' ' + model : ''}: ` + String(err.message).slice(0, 120))
          if (!/ 429| 400| 413/.test(err.message) || !p.fallbacks) break   // only a quota / JSON / size failure is worth a smaller model
        }
      }
    }
  }
  return null
}

// put the AI sections before the FAQ of the article (or at the end when there is no FAQ)
function insert(html, extra) {
  const i = html.lastIndexOf('<h2>أسئلة شائعة</h2>')
  return i > 0 ? html.slice(0, i) + extra + html.slice(i) : html + extra
}

module.exports = { enrich, insert, available: () => available().map((p) => p.name) }
