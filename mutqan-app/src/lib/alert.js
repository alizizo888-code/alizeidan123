// Sounds without audio files: synthesized tones the owner can pick from, with volume/repeat/vibration controls.
let ctx = null
const stateListeners = new Set()
// 'idle' = no touch yet (the browser keeps sound locked until the first tap) · 'suspended' = locked again · 'running' = sound works
export const audioState = () => (!ctx ? 'idle' : ctx.state === 'running' ? 'running' : 'suspended')
export const onAudioState = (fn) => { stateListeners.add(fn); return () => stateListeners.delete(fn) }
const emit = () => stateListeners.forEach((f) => { try { f(audioState()) } catch { /* ignore */ } })
const unlock = () => {
  try {
    if (!ctx) { ctx = new (window.AudioContext || window.webkitAudioContext)(); ctx.onstatechange = emit }
    if (ctx.state !== 'running') ctx.resume().then(emit, emit)
    emit()
  } catch { /* no audio */ }
}
if (typeof window !== 'undefined') ['pointerdown', 'keydown', 'touchstart', 'click'].forEach((ev) => window.addEventListener(ev, unlock, { passive: true }))
if (typeof document !== 'undefined') document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && ctx && ctx.state !== 'running') unlock() })
// Explicit unlock (the sound chip): resumes the audio engine and plays a short confirmation so the person hears that it works.
/** The page's one audio engine (unlocked), for anything else that plays sound (the spoken alerts). */
export const audioContext = () => { unlock(); return ctx }
export function unlockAudio() { unlock(); try { playTone('bell', { volume: 0.8 }) } catch { /* ignore */ } return audioState() }

// [freq, startSec, durSec, wave]
export const TONES = {
  chime: { label: 'جرس هادئ', notes: [[784, 0, 0.12], [988, 0.12, 0.18]] },
  bell: { label: 'جرس مزدوج', notes: [[880, 0, 0.14], [1175, 0.14, 0.18]] },
  pop: { label: 'نقرة خفيفة', notes: [[1320, 0, 0.07], [1760, 0.07, 0.09]] },
  ding: { label: 'دِنغ', notes: [[1046, 0, 0.35]] },
  alarm: { label: 'إنذار', notes: [[740, 0, 0.12, 'square'], [988, 0.14, 0.12, 'square'], [740, 0.28, 0.12, 'square'], [988, 0.42, 0.12, 'square']] },
  siren: { label: 'صفارة', notes: [[600, 0, 0.2, 'sawtooth'], [900, 0.2, 0.2, 'sawtooth'], [600, 0.4, 0.2, 'sawtooth'], [900, 0.6, 0.2, 'sawtooth']] },
  marimba: { label: 'ماريمبا', notes: [[523, 0, 0.1], [659, 0.1, 0.1], [784, 0.2, 0.1], [1046, 0.3, 0.2]] },
  phone: { label: 'رنة تليفون', notes: [[440, 0, 0.4], [480, 0, 0.4], [440, 0.55, 0.4], [480, 0.55, 0.4]] },
  rise: { label: 'تصاعدي', notes: [[523, 0, 0.09], [659, 0.09, 0.09], [784, 0.18, 0.09], [988, 0.27, 0.09], [1175, 0.36, 0.18]] },
  whistle: { label: 'صفير', notes: [[1568, 0, 0.12], [2093, 0.16, 0.22]] },
  harp: { label: 'هارب', notes: [[392, 0, 0.3], [494, 0.06, 0.3], [587, 0.12, 0.3], [784, 0.18, 0.4]] },
  buzzer: { label: 'بازر قوي', notes: [[220, 0, 0.25, 'square'], [220, 0.32, 0.25, 'square'], [220, 0.64, 0.4, 'square']] },
  swoosh: { label: 'إرسال (صادر)', notes: [[660, 0, 0.06, 'triangle'], [990, 0.05, 0.06, 'triangle'], [1480, 0.1, 0.08, 'triangle']] },
  bubble: { label: 'فقاعة (وارد)', notes: [[1175, 0, 0.07], [880, 0.08, 0.09], [1318, 0.18, 0.12]] },
  fanfare: { label: 'طلب جديد', notes: [[523, 0, 0.12, 'triangle'], [659, 0.12, 0.12, 'triangle'], [784, 0.24, 0.12, 'triangle'], [1046, 0.36, 0.3, 'triangle'], [784, 0.7, 0.1, 'triangle'], [1046, 0.82, 0.35, 'triangle']] },
  knock: { label: 'دقّتين (الفنيين)', notes: [[330, 0, 0.09, 'square'], [330, 0.16, 0.09, 'square'], [660, 0.34, 0.16]] },
  radar: { label: 'رادار (العمليات)', notes: [[1046, 0, 0.1], [1046, 0.25, 0.1], [1568, 0.5, 0.22]] },
}

// Each kind of alert has its own sound, so the ear knows what came before the eye looks (owner order 2026-11-20):
// a new order, a message in, a message out, the technicians' moves, the operations room, the customer's updates, a call.
export const TONE_KINDS = {
  order: { label: '🆕 طلب جديد وارد', tone: 'fanfare', vibe: 'strong' },
  msg_in: { label: '💬 رسالة واردة', tone: 'bubble', vibe: 'double' },
  msg_out: { label: '📤 رسالة صادرة (اتبعتت)', tone: 'swoosh', vibe: 'short' },
  tech: { label: '🧰 حركة الفنيين (قبل / في الطريق / وصل / خلّص)', tone: 'knock', vibe: 'double' },
  ops: { label: '🛰️ تنبيهات العمليات (متأخر / تعذّر / دفع / تقارير)', tone: 'radar', vibe: 'pulse' },
  customer: { label: '👤 تحديثات طلبي (للعميل)', tone: 'marimba', vibe: 'double' },
  call: { label: '📞 مكالمة / خط ساخن', tone: 'phone', vibe: 'sos' },
}
const ORDER_EV = ['new_order', 'order_bot', 'wa_handoff', 'order_new', 'order_created', 'warranty_claim']
const MSG_EV = ['order_msg', 'wa_message', 'phone_wa', 'chat', 'message', 'msg', 'chat_message', 'support_msg']
const TECH_EV = ['assigned', 'order_assigned', 'accepted', 'rejected', 'en_route', 'nearby', 'arrived', 'started', 'progress', 'completed', 'contact', 'distance', 'tech_application']
/** Which sound an alert gets. `customer` = the viewer is a customer (no staff role). */
export function kindOf(event = '', { customer = false, priority } = {}) {
  const e = String(event || '')
  if (e.includes('call')) return 'call'
  if (MSG_EV.includes(e) || /msg|message|chat/.test(e)) return 'msg_in'
  if (customer) return 'customer'
  if (ORDER_EV.includes(e) || e.startsWith('new_order')) return 'order'
  if (TECH_EV.includes(e) || e === 'order_status') return 'tech'
  return priority === 'high' && !e ? 'order' : 'ops'
}
/** The tone and vibration this device plays for a kind: the device's own pick, else the default for the kind. */
export function kindTone(kind) {
  const d = deviceSound(); const k = TONE_KINDS[kind] || TONE_KINDS.ops
  return { tone: TONES[d['tone_' + kind]] ? d['tone_' + kind] : k.tone, vibe: VIBES[d['vibe_' + kind]] ? d['vibe_' + kind] : k.vibe }
}
/** Play one kind (e.g. 'msg_out' right after sending). Respects mute and the company volume. */
export function playKind(kind, cfg = {}, { repeat } = {}) {
  try {
    unlock()
    const e = effectiveSound(cfg)
    if (e.enabled === false) return
    const t = kindTone(kind)
    playTone(t.tone, { volume: e.volume ?? 0.7, repeat: repeat || 1 })
    if (e.vibrate !== false) navigator.vibrate?.(VIBES[t.vibe].p)
  } catch { /* ignore */ }
}

// Vibration patterns (ms on/off). Android phones vibrate; iPhones ignore vibration from web apps — the sound still plays.
export const VIBES = {
  short: { label: 'قصير', p: [120] },
  double: { label: 'مزدوج', p: [180, 100, 180] },
  long: { label: 'طويل', p: [700] },
  pulse: { label: 'نبض', p: [220, 90, 220, 90, 320] },
  strong: { label: 'قوي متكرر', p: [500, 150, 500, 150, 800] },
  sos: { label: 'استغاثة', p: [100, 80, 100, 80, 100, 200, 300, 120, 300, 120, 300, 200, 100, 80, 100, 80, 100] },
}
export const vibeSupported = () => typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function'
export function vibrate(name) { try { const v = VIBES[name] || VIBES.short; navigator.vibrate?.(v.p) } catch { /* ignore */ } }

// This device's own choice, on top of the company setting (the owner's): '' / undefined = follow the company.
// { mute, normalTone, highTone, volume, vibrate: true|false|undefined, normalVibe, highVibe }
const DEV_KEY = 'mq_sound_device'
const devListeners = new Set()
export function deviceSound() { try { return JSON.parse(localStorage.getItem(DEV_KEY) || '{}') || {} } catch { return {} } }
export function setDeviceSound(patch) {
  const next = { ...deviceSound(), ...patch }
  Object.keys(next).forEach((k) => { if (next[k] === '' || next[k] == null) delete next[k] })
  try { localStorage.setItem(DEV_KEY, JSON.stringify(next)) } catch { /* ignore */ }
  devListeners.forEach((f) => { try { f(next) } catch { /* ignore */ } })
  return next
}
export const onDeviceSound = (fn) => { devListeners.add(fn); return () => devListeners.delete(fn) }
// What actually plays on this device: the company setting, then this device's choices.
export function effectiveSound(cfg = {}) {
  const d = deviceSound()
  const e = { ...cfg }
  if (d.mute) e.enabled = false
  ;['normalTone', 'highTone', 'normalVibe', 'highVibe'].forEach((k) => { if (d[k]) e[k] = d[k] })
  if (typeof d.volume === 'number') e.volume = d.volume
  if (typeof d.vibrate === 'boolean') e.vibrate = d.vibrate
  e.normalVibe = VIBES[e.normalVibe] ? e.normalVibe : 'short'
  e.highVibe = VIBES[e.highVibe] ? e.highVibe : 'pulse'
  return e
}
export const vibePattern = (cfg, hi) => { const e = effectiveSound(cfg); return e.vibrate === false ? [] : VIBES[hi ? e.highVibe : e.normalVibe].p }
const span = (t) => t.notes.reduce((m, n) => Math.max(m, n[1] + n[2]), 0)

function tone(freq, start, dur, vol, wave = 'sine') {
  if (!ctx) return
  const o = ctx.createOscillator(); const g = ctx.createGain()
  o.type = wave; o.frequency.value = freq
  const t0 = ctx.currentTime + start
  g.gain.setValueAtTime(0.0001, t0)
  g.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol), t0 + 0.02)
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur)
  o.connect(g); g.connect(ctx.destination)
  o.start(t0); o.stop(t0 + dur + 0.05)
}

// Owner decision (Nov 2026): no ringing / siren / beep tones anywhere — alerts are SPOKEN (voice.js) plus vibration.
// Set localStorage 'mq_tones_on' = '1' on a device only to bring the old tones back for testing.
export const tonesEnabled = () => { try { return localStorage.getItem('mq_tones_on') === '1' } catch { return false } }
export function playTone(name, { volume = 0.7, repeat = 1 } = {}) {
  if (!tonesEnabled()) return
  const t = TONES[name] || TONES.chime
  const vol = 0.28 * Math.min(1, Math.max(0, volume))   // keep headroom: synth waves are loud
  const gap = span(t) + 0.18
  for (let r = 0; r < Math.max(1, Math.min(6, repeat)); r++) t.notes.forEach(([f, s, d, w]) => tone(f, r * gap + s, d, vol, w))
}

// cfg = notify settings .sound ; priority 'high' | 'normal'
// kind (optional): play that kind's own tone instead of the generic normal/high one.
export function chime(priority = 'normal', cfg = {}, kind = '') {
  try {
    unlock()
    const hi = priority === 'high'
    const e = effectiveSound(cfg)
    if (kind && TONE_KINDS[kind]) {
      if (e.enabled === false) return
      const t = kindTone(kind)
      playTone(t.tone, { volume: e.volume ?? 0.7, repeat: hi ? e.highRepeat || 3 : kind === 'msg_out' ? 1 : kind === 'order' || kind === 'call' ? 2 : 1 })
      if (e.vibrate !== false) navigator.vibrate?.(VIBES[hi ? e.highVibe : t.vibe].p)
      return
    }
    if (e.enabled !== false) playTone(hi ? e.highTone || 'bell' : e.normalTone || 'chime', { volume: e.volume ?? 0.7, repeat: hi ? e.highRepeat || 3 : 1 })
    if (e.enabled !== false && e.vibrate !== false) navigator.vibrate?.(VIBES[hi ? e.highVibe : e.normalVibe].p)
  } catch { /* ignore */ }
}
