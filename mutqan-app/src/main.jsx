import { syncChrome } from './lib/chrome.js'
import React from 'react'
import ReactDOM from 'react-dom/client'
import './lib/origin'
import './lib/servicesOff'
import App from './App'
import ErrorBoundary from './components/ErrorBoundary'
import { initPerf } from './lib/perf'
import { logError, autoRepairOnce, CHUNK_RE } from './lib/recover'
// A preload is only a head start: the screen's own import still fetches what it needs. Reloading the whole app
// for a missed preload is what made the app restart on every screen (lost settings, bell, sign-in). Log it, carry on.
window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); try { console.warn('[mutqan] preload missed', e.payload && e.payload.message) } catch { /* ignore */ } })
import './index.css'
import './skin.css'
import { auth } from './lib/auth'
import { startInbox } from './lib/push'
import { registerServiceWorker } from './lib/install'

initPerf()
registerServiceWorker()
try { import('./lib/presence').then((m) => m.startPresence()).catch(() => {}) } catch { /* the heartbeat is extra */ }
window.addEventListener('error', (e) => { logError(e.error || e.message, 'window'); if (CHUNK_RE.test(String(e.message))) autoRepairOnce('chunk-load') })
window.addEventListener('unhandledrejection', (e) => { logError(e.reason, 'promise'); if (CHUNK_RE.test(String(e.reason?.message))) autoRepairOnce('chunk-load') })
auth.refresh()
startInbox()

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary><App /></ErrorBoundary>
  </React.StrictMode>
)

// Boot splash: keep it for a short, fixed beat so it reads as an app launch, then fade out.
try { clearTimeout(window.__mqWatch) } catch { /* ignore */ }
const splash = document.getElementById('boot-splash')
if (splash) {
  setTimeout(() => {
    splash.classList.add('hide')
    setTimeout(() => splash.remove(), 400)
  }, 950)
}

try { syncChrome() } catch { /* ignore */ }
