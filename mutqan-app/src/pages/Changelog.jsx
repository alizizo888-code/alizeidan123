// «سجل التطورات والتعديلات» (owner, 2026-11-28): every change made to the system, newest first, straight from the
// sessions log in HANDOFF.md — bundled at build time, so whatever is deployed shows exactly what it contains.
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Search } from 'lucide-react'
import raw from '../../../HANDOFF.md?raw'

const TAGS = [
  ['واتساب', /واتساب|whatsapp|wa-hub|بوت|bot/i], ['العمليات', /عمليات|ops|dispatch|إسناد|توزيع/i], ['الفنيين', /فني|tech/i],
  ['الدفع', /دفع|pay|إيصال|تحويل|بنك/i], ['الخريطة', /خريطة|map|fleet/i], ['المواقع', /موقع|site|wordpress|بلوجر|blogger|seo/i],
  ['التطبيقات', /تطبيق|app|apk|push|إشعار/i], ['الذكاء الاصطناعي', /ai|ذكاء|مساعد|assistant/i], ['إصلاح', /اتصلح|fix|bug|خلل|بايظ/i],
]

function parse(md) {
  const part = (md.split(/## آخر جلسات[^\n]*\n/)[1] || '').split(/\n## /)[0]
  const out = []
  let cur = null
  for (const line of part.split('\n')) {
    const m = line.match(/^- \*\*([^*]+)\*\*\s*[—-]?\s*(.*)$/)
    if (m) { cur = { date: m[1].trim(), text: m[2].trim() }; out.push(cur); continue }
    if (cur && line.trim()) cur.text += '\n' + line.replace(/^\s*-\s*/, '• ').trim()
  }
  return out.map((e) => ({ ...e, tags: TAGS.filter(([, re]) => re.test(e.text)).map(([t]) => t) }))
}
const clean = (s) => s.replace(/`([^`]+)`/g, '$1').replace(/\*\*([^*]+)\*\*/g, '$1')

export default function Changelog() {
  const nav = useNavigate()
  const all = useMemo(() => parse(raw || ''), [])
  const [q, setQ] = useState('')
  const [tag, setTag] = useState('')
  const list = all.filter((e) => (!tag || e.tags.includes(tag)) && (!q || (e.date + e.text).includes(q)))
  return (
    <div dir="rtl" className="h-full overflow-y-auto bg-[#090c14] text-white pb-24">
      <div className="sticky top-0 z-10 bg-[#090c14]/95 backdrop-blur border-b border-white/10 px-4 py-3 flex items-center gap-2">
        <button onClick={() => nav(-1)} className="w-9 h-9 rounded-full bg-white/5 flex items-center justify-center"><ChevronRight size={18} /></button>
        <div className="flex-1 min-w-0"><p className="text-[11px] text-primary font-bold">النظام</p><h1 className="font-black text-lg">سجل التطورات والتعديلات</h1></div>
        <span className="text-[11px] text-slate-400">{all.length} تحديث</span>
      </div>
      <div className="max-w-3xl mx-auto px-4 pt-3 grid gap-3">
        <div className="relative"><Search size={14} className="absolute top-3 right-3 text-slate-500" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث في التعديلات…" className="w-full rounded-xl bg-white/5 border border-white/10 py-2.5 pr-9 pl-3 text-sm" /></div>
        <div className="flex gap-1.5 overflow-x-auto pb-1">
          <button onClick={() => setTag('')} className={`shrink-0 rounded-full px-3 py-1 text-[11px] font-black border ${!tag ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10'}`}>الكل</button>
          {TAGS.map(([t]) => <button key={t} onClick={() => setTag(t === tag ? '' : t)} className={`shrink-0 rounded-full px-3 py-1 text-[11px] font-black border ${tag === t ? 'bg-primary text-black border-primary' : 'bg-white/5 border-white/10'}`}>{t}</button>)}
        </div>
        {list.map((e, i) => (
          <div key={i} className="rounded-2xl bg-white/5 border border-white/10 p-3">
            <div className="flex items-center gap-2 mb-1.5"><span className="rounded-full bg-primary/20 text-primary text-[11px] font-black px-2.5 py-0.5">{e.date}</span>{i === 0 && !q && !tag && <span className="text-[10px] font-black text-emerald-400">الأحدث</span>}<div className="flex-1" />{e.tags.slice(0, 3).map((t) => <span key={t} className="text-[9px] rounded bg-white/10 px-1.5 py-0.5 text-slate-300">{t}</span>)}</div>
            <p className="text-[12.5px] leading-6 text-slate-200 whitespace-pre-line break-words">{clean(e.text)}</p>
          </div>
        ))}
        {!list.length && <p className="text-center text-sm text-slate-500 py-10">مفيش نتايج.</p>}
      </div>
    </div>
  )
}
