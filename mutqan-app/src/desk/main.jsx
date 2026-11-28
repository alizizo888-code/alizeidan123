// Entry of the private desk apps (desk.oxygen11.com): same screens as the Mutqan staff app, password-less secret-link entry, one app for both brands.
import { syncChrome } from '../lib/chrome.js'
import React from 'react'
import ReactDOM from 'react-dom/client'
import '../lib/servicesOff'
import DeskApp from './DeskApp'
import ErrorBoundary from '../components/ErrorBoundary'
import { logError, autoRepairOnce, CHUNK_RE } from '../lib/recover'
// A preload is only a head start: the screen's own import still fetches what it needs. Reloading the whole app
// for a missed preload is what made the app restart on every screen (lost settings, bell, sign-in). Log it, carry on.
window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); try { console.warn('[mutqan] preload missed', e.payload && e.payload.message) } catch { /* ignore */ } })
import '../index.css'
import '../skin.css'
import { auth } from '../lib/auth'
import { startInbox } from '../lib/push'

window.addEventListener('error', (e) => { logError(e.error || e.message, 'window'); if (CHUNK_RE.test(String(e.message))) autoRepairOnce('chunk-load') })
window.addEventListener('unhandledrejection', (e) => { logError(e.reason, 'promise'); if (CHUNK_RE.test(String(e.reason?.message))) autoRepairOnce('chunk-load') })
// Each desk app (owner / operations / technician / WhatsApp) gets its OWN service worker scope = its own page,
// so the installed apps no longer share one registration, one push subscription or each other's notification taps.
try {
  if ('serviceWorker' in navigator) {
    const page = (location.pathname.split('/').pop() || 'desk.html')
    const base = location.pathname.slice(0, location.pathname.lastIndexOf('/') + 1)
    navigator.serviceWorker.getRegistrations().then((regs) => regs.forEach((r) => { try { if (new URL(r.scope).pathname === base) r.unregister() } catch { /* ignore */ } })).catch(() => {})
    navigator.serviceWorker.register('./sw-desk.js?app=' + encodeURIComponent(window.__DESK_KIND__ || 'control'), { scope: './' + page }).catch(() => {})
  }
} catch { /* ignore */ }
try { navigator.serviceWorker?.addEventListener('message', (e) => { if (e.data && e.data.type === 'mq-open' && e.data.url) location.hash = String(e.data.url).replace(/^#/, '') }) } catch { /* ignore */ }
auth.refresh()
startInbox()
ReactDOM.createRoot(document.getElementById('root')).render(<React.StrictMode><ErrorBoundary><DeskApp /></ErrorBoundary></React.StrictMode>)
try { clearTimeout(window.__mqWatch) } catch { /* ignore */ }
const splash = document.getElementById('boot-splash')
if (splash) setTimeout(() => { splash.classList.add('hide'); setTimeout(() => splash.remove(), 400) }, 600)
try { syncChrome() } catch { /* ignore */ }
