// Voice for the staff assistant: dictation (speech → text), speaking replies (text → speech) and the wake call
// («يا مساعد» → «لبيك»). Everything runs in the browser (Web Speech API): no key, no cost, nothing recorded on a server.
// Only one recogniser may listen at a time, so the wake listener pauses while the assistant is listening.

export const SR = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition || null) : null
export const canListen = !!SR
export const canSpeak = typeof window !== 'undefined' && 'speechSynthesis' in window

// «يا مساعد / يا متقن / يا أكسجين / يا مساعدي» at the start of what was said; the rest is the command.
export const WAKE_RE = /(?:^|\s)(?:يا|يـا|ya)\s*(?:مساعد(?:ي)?|متقن|اكسجين|أكسجين|اوكسجين|أوكسجين)[\s،,.!؟?]*/u
export function wakeSplit(text) {
  const m = WAKE_RE.exec(String(text || ''))
  return m ? { woke: true, rest: String(text).slice(m.index + m[0].length).trim() } : { woke: false, rest: '' }
}
export const STOP_RE = /^(?:خلاص|شكرا|شكراً|كفاية|اقفل|سلام|مع السلامة|stop)[\s.!]*$/u
export const YES_RE = /(?:^|\s)(?:نفذ|نفّذ|نفذه|نفّذه|أكد|اكد|أكّد|موافق|تمام نفذ|ايوه نفذ|أيوه نفذ)(?:\s|$)/u
export const NO_RE = /(?:^|\s)(?:الغي|الغيه|إلغاء|الغاء|ألغي|لا تنفذ|لأ|لا)(?:\s|$)/u

// Clean a reply for reading aloud: no links, markdown or emoji; long answers are cut at a sentence.
export function speakable(t) {
  let s = String(t || '').replace(/https?:\/\/\S+/g, ' ').replace(/[*_#`>|•]/g, ' ').replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu, ' ').replace(/\s+/g, ' ').trim()
  if (s.length > 650) { const cut = s.slice(0, 650); const i = Math.max(cut.lastIndexOf('.'), cut.lastIndexOf('،'), cut.lastIndexOf('؟')); s = (i > 200 ? cut.slice(0, i + 1) : cut) + ' والباقي مكتوب على الشاشة.' }
  return s
}

let player = null
/** Speaks Arabic in the one fixed voice (the server's Saudi man; else a system voice named as a man; else silent). */
export async function speak(text) {
  const s = speakable(text)
  if (!s) return
  let audio = null
  try {
    const { ai } = await import('./ai.js')
    audio = (await import('./audio.js')).audio
    const r = await ai.tts(s).catch(() => null)
    if (r?.ok && r.wav) {
      hush()
      player = new Audio('data:audio/wav;base64,' + r.wav)
      await new Promise((resolve) => { player.onended = resolve; player.onerror = resolve; player.play().catch(resolve) })
      return
    }
  } catch { /* fall through */ }
  const male = canSpeak && audio && audio.maleVoice()
  if (!male) return
  await new Promise((resolve) => {
    try {
      window.speechSynthesis.cancel()
      const u = new SpeechSynthesisUtterance(s)
      u.voice = male; u.lang = male.lang; u.rate = 1; u.pitch = 0.9
      let done = false; const fin = () => { if (!done) { done = true; resolve() } }
      u.onend = fin; u.onerror = fin
      setTimeout(fin, Math.min(60000, 2500 + s.length * 110))   // some phones never fire onend
      window.speechSynthesis.speak(u)
    } catch { resolve() }
  })
}
export function hush() { try { if (player) { player.pause(); player = null } if (canSpeak) window.speechSynthesis.cancel() } catch { /* ignore */ } }

/** One utterance → final text (''), interim text goes to onInterim. Rejects 'denied' when the mic is refused. */
export function listenOnce({ onInterim, lang = 'ar-SA', maxMs = 15000 } = {}) {
  return new Promise((resolve, reject) => {
    if (!SR) return reject(new Error('unsupported'))
    claimMic('assistant')
    const r = new SR(); r.lang = lang; r.interimResults = true; r.continuous = false; r.maxAlternatives = 1
    let finalText = ''; let settled = false
    const end = (fn) => { if (settled) return; settled = true; clearTimeout(t); releaseMic('assistant'); fn() }
    const t = setTimeout(() => { try { r.stop() } catch { /* ignore */ } }, maxMs)
    r.onresult = (e) => { let all = ''; for (const res of e.results) { all += res[0].transcript; if (res.isFinal) finalText = all } onInterim && onInterim(all) }
    r.onerror = (e) => end(() => (e.error === 'not-allowed' || e.error === 'service-not-allowed' ? reject(new Error('denied')) : resolve(finalText.trim())))
    r.onend = () => end(() => resolve(finalText.trim()))
    current.stop = () => { try { r.stop() } catch { /* ignore */ } }
    try { r.start() } catch (e) { end(() => reject(e)) }
  })
}
const current = { stop: () => {} }
export function stopListening() { current.stop() }

// Who holds the microphone: the wake listener steps aside while the assistant listens, then resumes.
const micListeners = new Set()
const holders = new Set()
const notify = () => { const h = holders.size ? [...holders][0] : null; micListeners.forEach((f) => f(h)) }
export function claimMic(who) { holders.add(who); notify() }
export function releaseMic(who) { if (holders.delete(who)) notify() }
export function onMic(f) { micListeners.add(f); return () => micListeners.delete(f) }
export const micHolder = () => (holders.size ? [...holders][0] : null)

// The wake call is on only when this person turned it on, on this device.
const WAKE_KEY = 'mq_wake_on'
export const wakeEnabled = () => { try { return localStorage.getItem(WAKE_KEY) === '1' } catch { return false } }
export function setWakeEnabled(on) { try { localStorage.setItem(WAKE_KEY, on ? '1' : '0') } catch { /* ignore */ } window.dispatchEvent(new CustomEvent('mq-wake-setting', { detail: !!on })) }
export const openAssistant = (detail = {}) => window.dispatchEvent(new CustomEvent('mq-assistant-open', { detail }))
