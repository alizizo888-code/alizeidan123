import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { team } from '../lib/team'

// The owner's switch (الأمان والدخول ← عنوان واحد للفريق): when it is on, any team page opened on any site
// goes to the same page on the one staff address. Customers' pages are never touched.
// «?stay=1» on a team page keeps this tab where it is (a way back if the staff address is ever down).
const KEY = 'mq_staff_home'
const TEAM = /^\/(staff|notifications)(\/|$)/
let asked = null
function staffHome() {
  try { const c = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (c && Date.now() - c.at < 10 * 60 * 1000) return Promise.resolve(c.v) } catch { /* ignore */ }
  if (!asked) asked = team.config().then((r) => { const v = (r && r.staffHome) || ''; try { sessionStorage.setItem(KEY, JSON.stringify({ v, at: Date.now() })) } catch { /* ignore */ } return v }).catch(() => '').finally(() => { setTimeout(() => { asked = null }, 1000) })
  return asked
}
export function staffTarget(home, path, search) {
  if (!home || !TEAM.test(path)) return ''
  if (/(^|[?&])stay=1/.test(search || '')) return ''
  let u; try { u = new URL(home) } catch { return '' }
  if (typeof location !== 'undefined' && u.host === location.host) return ''   // already on the staff address
  return u.origin + u.pathname + '#' + path + (search || '')
}
export default function StaffRedirect() {
  const { pathname, search } = useLocation()
  useEffect(() => {
    if (typeof window !== 'undefined' && window.__DESK_KIND__) return   // the staff address itself
    if (!TEAM.test(pathname)) return
    let alive = true
    staffHome().then((home) => { const to = alive && staffTarget(home, pathname, search); if (to) location.replace(to) })
    return () => { alive = false }
  }, [pathname, search])
  return null
}
