import { converse } from './sim.mjs'
import { PROBE } from './probe_data.mjs'
for (const [cat, list] of Object.entries(PROBE)) for (const q of list) { const r = converse([q]).last; const m = r.reply.match(/\d+\s*(?:ر\.?\s?س|ريال)/g); if (m) console.log(`[${cat}] ${q} -> ${m.join(',')} | q=${r.quote ? r.quote.total : '-'} | ${r.reply.slice(0,110)}`) }
