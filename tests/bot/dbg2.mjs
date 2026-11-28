import { converse } from './sim.mjs'
import { norm } from '../../mutqan-app/src/botData.js'
for (const q of process.argv.slice(2)) { const r = converse([q]).last; console.log(JSON.stringify(norm(q)), '=>', r.action, r.understood, r.reply.slice(0,80)) }
