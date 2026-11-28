import { useEffect, useState } from 'react'

// While the customer is typing in a conversation, the floating buttons (AI · WhatsApp · hotline · call · Home)
// step aside: on a phone they sat right on top of the chat's send button.
// Two signals: a chat panel says it is open (setChatOpen), or any text field has focus (the keyboard is up).
const open = new Set()
const subs = new Set()
let typing = false
const emit = () => subs.forEach((f) => f())

export function setChatOpen(key, on) {
  if (on) open.add(key); else open.delete(key)
  emit()
}

const isField = (el) => !!el && (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && !['checkbox', 'radio', 'button', 'submit', 'range', 'file'].includes(el.type)) || el.isContentEditable)
if (typeof document !== 'undefined') {
  document.addEventListener('focusin', (e) => { const v = isField(e.target); if (v !== typing) { typing = v; emit() } })
  document.addEventListener('focusout', () => { setTimeout(() => { const v = isField(document.activeElement); if (v !== typing) { typing = v; emit() } }, 50) })
}

export const chatActive = () => open.size > 0 || typing

export function useChatActive() {
  const [v, setV] = useState(chatActive)
  useEffect(() => { const f = () => setV(chatActive()); subs.add(f); f(); return () => subs.delete(f) }, [])
  return v
}
