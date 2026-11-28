// In-process scheduler (no cron/n8n needed). Riyadh time. Each job runs once per day at its time; missed jobs run late (within the same day) after a restart.
const { q } = require('./db'), P = require('./planner'), R = require('./report'), GIT = require('./gitsync')
const JOBS = [['morning', '06:30'], ['noon', '13:00'], ['evening', '20:30'], ['gsc', '09:00'], ['report', '23:30']]
const hm = () => new Date(Date.now() + 3 * 3600e3).toISOString().slice(11, 16)
exports.start = (runJob) => setInterval(async () => {
  const day = P.day(), now = hm()
  for (const [job, at] of JOBS) {
    const key = `sched:${day}:${job}`; if (now < at || q.setting(key, false)) continue
    q.setSetting(key, true)
    try { await runJob(job); q.ev('info', 'job ' + job + ' done') } catch (e) { q.ev('error', 'job ' + job + ': ' + e.message) }
  }
}, 60e3)
exports.JOBS = JOBS
