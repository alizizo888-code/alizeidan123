import { converse } from './sim.mjs'
import { PROBE } from './probe_data.mjs'
let total = 0, miss = 0
const verbose = process.argv.includes('-v')
for (const [cat, list] of Object.entries(PROBE)) {
  for (const q of list) {
    total++
    const r = converse([q]).last
    if (!r.understood || r.action === 'none' && r.confidence < 0.5) { miss++; console.log(`MISS [${cat}] ${q}\n    -> ${r.reply.slice(0, 90)}`) }
    else if (verbose) console.log(`OK   [${cat}] ${q}\n    -> ${r.reply.slice(0, 160)}`)
  }
}
console.log(`probe: ${total - miss}/${total} understood`)
