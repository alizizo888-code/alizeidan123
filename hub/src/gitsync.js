// Pushes reports (never the DB or secrets) to the project repo: set HUB_REPO=/opt/mutaqin-oxygen-app (a clone with push rights via deploy key / token) and HUB_GIT=1.
const cp = require('child_process'), path = require('path')
exports.push = (msg) => {
  if (process.env.HUB_GIT !== '1' || !process.env.HUB_REPO) return 'git sync off'
  const run = (c) => cp.execSync(c, { cwd: process.env.HUB_REPO, stdio: 'pipe' }).toString()
  try { run('git pull --rebase -q origin mutqan'); run('git add hub/reports'); if (!run('git status --porcelain hub/reports').trim()) return 'nothing to commit'
    run(`git commit -q -m ${JSON.stringify(msg + '\n\nCo-Authored-By: Claude <noreply@anthropic.com>')}`); run('git push -q origin mutqan'); return 'pushed' } catch (e) { return 'git error: ' + String(e.stderr || e.message).slice(0, 200) }
}
