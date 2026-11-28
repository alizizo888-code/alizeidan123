import { db } from './db'

export const isInAppBrowser = () => /FBAN|FBAV|Instagram|Line\/|Snapchat|TikTok/i.test(navigator.userAgent || '')
const digits = (n) => String(n || '').replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 1632)).replace(/\D/g, '')
function open(url) { const w = window.open(url, '_blank', 'noopener'); if (!w) location.href = url }
export const share = {
  /**
   * WhatsApp that opens once something async is done (the order is saved), without leaving the page: on a phone the
   * app's own link opens WhatsApp over the browser; on a computer a tab is opened at the tap itself (a browser blocks
   * a tab opened later) and pointed at WhatsApp when ready.
   */
  waLater() {
    const mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '')
    let tab = null
    if (!mobile) { try { tab = window.open('', '_blank') } catch { tab = null } }
    return {
      go(number, text) {
        const n = digits(number); const q = encodeURIComponent(text || '')
        const web = `https://wa.me/${n}?text=${q}`
        // an automated browser (the end-to-end tests) records it instead of leaving for WhatsApp
        if (navigator.webdriver) { window.__waLast = { number: n, text }; try { tab && tab.close() } catch { /* ignore */ } return }
        if (tab) { try { tab.location.href = web; return } catch { /* closed */ } }
        if (mobile) {
          // Android: straight into the WhatsApp app (no api.whatsapp.com page in between); iPhone: its own link.
          location.href = /Android/i.test(navigator.userAgent || '')
            ? `intent://send?phone=${n}&text=${q}#Intent;scheme=whatsapp;S.browser_fallback_url=${encodeURIComponent(web)};end`
            : `whatsapp://send?phone=${n}&text=${q}`
          if (!/Android/i.test(navigator.userAgent || '')) setTimeout(() => { if (document.visibilityState === 'visible') open(web) }, 1600)
          return
        }
        open(web)
      },
      cancel() { try { tab && tab.close() } catch { /* ignore */ } },
    }
  },
  whatsapp(number, text) { const n = digits(number); open(`https://wa.me/${n}?text=${encodeURIComponent(text || '')}`) },
  whatsappOrder({ phone, number, to, text, message, collection, record, groupId } = {}) {
    if (collection && record) { db.insertShared(collection, record, undefined, groupId ? { groupId } : {}).catch(() => {}) } // lead log, best-effort
    share.whatsapp(phone || number || to || '', text || message || '')
  },
  async copy(text) {
    try { await navigator.clipboard.writeText(text); return true } catch {
      const t = document.createElement('textarea'); t.value = text; t.style.cssText = 'position:fixed;opacity:0'
      document.body.appendChild(t); t.select()
      let ok = false; try { ok = document.execCommand('copy') } catch { /* ignore */ }
      t.remove(); return ok
    }
  },
  async native(data) { if (navigator.share) { await navigator.share(data); return true } return false },
}
