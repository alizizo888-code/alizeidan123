import { DEFAULT_SCHEDULE, normalizeSchedule, nextSlot, offerFor, bucketLoad, isPeak } from '../../mutqan-app/src/lib/schedule.js'
let ok = true; const t = (n, c) => { console.log((c ? 'PASS ' : 'FAIL ') + n); if (!c) ok = false }
const cfg = normalizeSchedule({})
const now = new Date(2026, 9, 31, 10, 20)       // Saturday 10:20 local
const o0 = offerFor(cfg, {}, now, 0)
t('first offer = now+lead rounded up (12:00)', new Date(o0.at).getHours() === 12)
const o1 = offerFor(cfg, {}, now, 1, o0.at), o2 = offerFor(cfg, {}, now, 2, o0.at), o3 = offerFor(cfg, {}, now, 3, o0.at)
t('postpone 1 = +1h', new Date(o1.at).getHours() === 13)
t('postpone 2 = +3h', new Date(o2.at).getHours() === 15)
t('last step = open window', o3.kind === 'window' && /خلال اليوم/.test(o3.label))
// capacity: fill 12:00 and 13:00 -> next free is later
const load = bucketLoad([...Array(4).fill(new Date(2026, 9, 31, 12, 5).toISOString()), ...Array(4).fill(new Date(2026, 9, 31, 13, 5).toISOString())])
t('full slots skipped', new Date(offerFor(cfg, load, now, 0).at).getHours() === 14)
// peak has lower capacity
const peakLoad = bucketLoad(Array(2).fill(new Date(2026, 9, 31, 17, 0).toISOString()))
const s = nextSlot(cfg, peakLoad, new Date(2026, 9, 31, 17, 0).getTime())
t('peak slot full at capPeak', s.at.getHours() === 18 && isPeak(cfg, s.at))
// after closing -> next day
const late = new Date(2026, 9, 31, 22, 30)
t('after closing -> next day opening', new Date(offerFor(cfg, {}, late, 0).at).getDate() === 1)
// closed day skipped
const cfg2 = normalizeSchedule({ closedDays: [0] })       // Sunday closed
t('closed day skipped', new Date(offerFor(cfg2, {}, late, 0).at).getDay() === 1)
t('normalize rejects junk', normalizeSchedule({ capNormal: 'x', peak: 'no' }).capNormal === DEFAULT_SCHEDULE.capNormal)
process.exit(ok ? 0 : 1)
