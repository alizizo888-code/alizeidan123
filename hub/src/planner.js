// Daily planning: how many posts per window, which sites first, and which topic keeps the mix (>=70% cooling/cold, >=70% Makkah).
const { q } = require('./db')
const TZ = 3 * 3600e3
exports.day = (t = Date.now()) => new Date(t + TZ).toISOString().slice(0, 10)
exports.WINDOWS = { morning: { hour: 6, share: 0.34 }, noon: { hour: 13, share: 0.33 }, evening: { hour: 20, share: 0.33 } }
exports.MAX_PER_ACCOUNT_HOUR = +process.env.MAX_PER_ACCOUNT_HOUR || 15
exports.writesLastHour = (accountId, now = Date.now()) => q.get('SELECT COUNT(*) n FROM writes WHERE account_id=? AND at>?', accountId, now - 3600e3).n
// target posts for the day = sum of the daily_cap of live sites whose account is active; split across windows
exports.todayTarget = () => q.get("SELECT COALESCE(SUM(s.daily_cap),0) n FROM sites s JOIN accounts a ON a.id=s.account_id WHERE s.status='live' AND a.status='active'").n
exports.sitesFor = (win, day) => {
  const done = Object.fromEntries(q.all("SELECT site_id, COUNT(*) n FROM articles WHERE status='published' AND substr(published_at,1,10)=? GROUP BY site_id", day).map((r) => [r.site_id, r.n]))
  const per = exports.WINDOWS[win].share
  return q.all("SELECT s.*, a.status astatus FROM sites s JOIN accounts a ON a.id=s.account_id WHERE s.status='live' AND a.status='active' ORDER BY COALESCE(s.last_post_at,'') ASC")
    .map((s) => ({ ...s, done: done[s.id] || 0, want: Math.min(s.daily_cap, Math.ceil(s.daily_cap * per)) }))
    .filter((s) => s.done < s.daily_cap && s.done < (win === 'morning' ? s.want : win === 'noon' ? Math.min(s.daily_cap, s.want * 2) : s.daily_cap))
}
// choose the topic: keep running shares at/above the targets
exports.pickTopic = (plan, usedTitles, mix, recentKw = new Set()) => {
  const fresh = plan.filter((e) => !usedTitles.has(e.t)); if (!fresh.length) return null
  const needCool = mix.n && mix.cooling / mix.n < 0.7, needMk = mix.n && mix.makkah / mix.n < 0.7
  const score = (e) => (needCool && e.cool ? 2 : 0) + (needMk && e.city === 'mk' ? 2 : 0) + (e.cool ? 1 : 0) + (e.city === 'mk' ? 1 : 0) - (recentKw.has(e.kw) ? 3 : 0)
  return fresh.sort((a, b) => score(b) - score(a))[0]
}
exports.mix = (day) => q.setting('mix:' + day, { n: 0, cooling: 0, makkah: 0 })
exports.addMix = (day, e) => { const m = exports.mix(day); m.n++; m.cooling += e.cool ? 1 : 0; m.makkah += e.city === 'mk' ? 1 : 0; q.setSetting('mix:' + day, m) }
