import { useEffect } from 'react'
import { NET } from '../lib/netBrand'

// Satellite sites only: every visible «أكسجين / مُتقن / OXYGEN» (screens, hints, placeholders, aria labels) becomes this site's own name.
// «MUTQAN AI» (the assistant's persona) is left alone. The two core sites render nothing here.
const RX = /مُتقن|أكسجين|OXYGEN(?! AI)/g
const ATTRS = ['placeholder', 'title', 'aria-label', 'alt']
function fixText(n) { const v = n.nodeValue; if (v && RX.test(v)) { RX.lastIndex = 0; n.nodeValue = v.replace(RX, NET.name) } RX.lastIndex = 0 }
function fixTree(root) {
  if (!root) return
  if (root.nodeType === 3) { fixText(root); return }
  if (root.nodeType !== 1 || /^(SCRIPT|STYLE|TEXTAREA)$/.test(root.tagName)) return
  for (const a of ATTRS) { const v = root.getAttribute && root.getAttribute(a); if (v && RX.test(v)) root.setAttribute(a, v.replace(RX, NET.name)); RX.lastIndex = 0 }
  for (let c = root.firstChild; c; c = c.nextSibling) fixTree(c)
}

export default function NetRebrand() {
  useEffect(() => {
    if (NET.core || !NET.name) return undefined
    fixTree(document.body)
    const mo = new MutationObserver((list) => {
      for (const m of list) {
        if (m.type === 'characterData') fixText(m.target)
        else if (m.type === 'attributes') fixTree(m.target)
        else m.addedNodes.forEach((n) => fixTree(n))
      }
    })
    mo.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS })
    return () => mo.disconnect()
  }, [])
  return null
}
