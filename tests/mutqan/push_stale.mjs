// Reloading the app must not ring old alerts again (owner: «التنبيهات بترجع بعد إغلاق الكل»).
globalThis.localStorage = { getItem: () => null, setItem: () => {} }
globalThis.window = globalThis.window || { addEventListener() {}, dispatchEvent() {} }
const { staleAt } = await import('../../mutqan-app/src/lib/push.js')
let pass = 0, fail = 0
const t = (n, c) => { if (c) { pass++; console.log('PASS push: ' + n) } else { fail++; console.log('FAIL push: ' + n) } }
const now = Date.parse('2026-11-23T08:00:00.000Z')
t('fresh alert rings', !staleAt('2026-11-23T07:58:00.000Z', now))
t('alert from an hour ago does not ring on reload', staleAt('2026-11-23T07:00:00.000Z', now))
t('no timestamp is not treated as stale', !staleAt(undefined, now) && !staleAt('', now))
console.log(`\npush: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
