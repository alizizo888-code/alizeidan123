#!/usr/bin/env node
// CLI used by n8n/cron on the VPS:  node hub/bin-hub.js run morning|noon|evening | report | gsc | selftest
const [cmd, a] = process.argv.slice(2), R = require('./report'), GIT = require('./gitsync')
;(async () => {
  if (cmd === 'run') { if (a === 'morning') require('./runner').dailyReset(); console.log(JSON.stringify(await require('./runner').runWindow(a), null, 1)) }
  else if (cmd === 'gsc') console.log(await require('./gsc').checkAll())
  else if (cmd === 'report') { const r = R.build(); await R.send(r); console.log(r.summary, GIT.push('Hub report ' + r.day)) }
  else console.log('usage: run <morning|noon|evening> | gsc | report')
})()
