// Entry of the private desk apps (desk.oxygen11.com): same screens as the Mutqan staff app, password-less secret-link entry, one app for both brands.
import { syncChrome } from '../lib/chrome.js'
import React from 'react'
import ReactDOM from 'react-dom/client'
import DeskApp from './DeskApp'
import ErrorBoundary from '../components/ErrorBoundary'
import { logError, autoRepairOnce, CHUNK_RE } from '../lib/recover'
window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); autoRepairOnce('preload') })
import '../index.css'
import '../skin.css'
import { auth } from '../lib/auth'
import { startInbox } from '../lib/push'

window.addEventListener('error', (e) => { logError(e.error || e.message, 'window'); if (CHUNK_RE.test(String(e.message))) autoRepairOnce('chunk-load') })
window.addEventListener('unhandledrejection', (e) => { logError(e.reason, 'promise'); if (CHUNK_RE.test(String(e.reason?.message))) autoRepairOnce('chunk-load') })
try { if ('serviceWorker' in navigator) navigator.serviceWorker.register('./sw-desk.js').catch(() => {}) } catch { /* ignore */ }
auth.refresh()
startInbox()
ReactDOM.createRoot(document.getElementById('root')).render(<React.StrictMode><ErrorBoundary><DeskApp /></ErrorBoundary></React.StrictMode>)
try { clearTimeout(window.__mqWatch) } catch { /* ignore */ }
const splash = document.getElementById('boot-splash')
if (splash) setTimeout(() => { splash.classList.add('hide'); setTimeout(() => splash.remove(), 400) }, 600)
try { syncChrome() } catch { /* ignore */ }
