import { db } from './db'
import { team } from './team'
import { post, currentUser, getAnonId } from './_api'

// In-app hotline (free): WebRTC audio between a customer and operations. Signalling rides on the `calls` document (each side writes only its own field),
// the media goes peer-to-peer. STUN is free; an optional TURN server (owner setting `hotline.turn`) improves reach on strict mobile networks.
const STUN = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }]
const POLL_MS = 800
// When the phones cannot reach each other directly (carrier NAT, no TURN server), the voice goes through the site
// instead: short self-contained clips up and down (see Mutqan_Relay). A little behind real time, but it works anywhere.
const RELAY_AFTER_MS = 9000
const CLIP_MS = 1200
const MIMES = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/ogg;codecs=opus']
const pickMime = () => { try { if (typeof MediaRecorder === 'undefined') return ''; return MIMES.find((m) => MediaRecorder.isTypeSupported(m)) || '' } catch { return '' } }
const toB64 = (blob) => new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = no; r.readAsDataURL(blob) })
const fromB64 = (b, type) => { const s = atob(b); const a = new Uint8Array(s.length); for (let i = 0; i < s.length; i++) a[i] = s.charCodeAt(i); return new Blob([a], { type }) }

export function iceServers(turn) {
  const t = turn && turn.url ? [{ urls: String(turn.url).split(',').map((x) => x.trim()).filter(Boolean), username: turn.user || '', credential: turn.pass || '' }] : []
  return [...STUN, ...t]
}

// role: 'caller' (customer) | 'callee' (operations)
export class CallSession {
  constructor({ id, role, turn, onState, onRemote }) {
    this.id = id; this.role = role; this.onState = onState || (() => {}); this.onRemote = onRemote || (() => {})
    this.pc = new RTCPeerConnection({ iceServers: iceServers(turn) })
    this.mine = role === 'caller' ? 'cSig' : 'sSig'
    this.theirs = role === 'caller' ? 'sSig' : 'cSig'
    this.cands = []; this.appliedRemote = 0; this.sentCands = 0; this.remoteSet = false; this.closed = false; this.timer = null
    this.pc.onicecandidate = (e) => { if (e.candidate) { this.cands.push(e.candidate.toJSON()); this.flushSoon() } }
    this.pc.ontrack = (e) => this.onRemote(e.streams[0])
    this.relay = false; this.muted = false
    this.pc.onconnectionstatechange = () => {
      if (this.relay || this.closed) return
      const st = this.pc.connectionState
      if (st === 'connected') { clearTimeout(this._w); this.onState('connected'); return }
      if (st === 'failed') { if (!this.startRelay()) this.onState('failed'); return }
      this.onState(st)
    }
  }
  async mic() {
    this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } })
    this.stream.getTracks().forEach((t) => this.pc.addTrack(t, this.stream))
    // One player made now, inside the tap that started the call, so phones let it play later without another tap.
    try { this.player = new Audio(); this.player.autoplay = true; this.player.play().catch(() => {}) } catch { this.player = null }
  }
  setMuted(m) { this.muted = !!m; this.stream?.getAudioTracks().forEach((t) => { t.enabled = !m }) }
  // Direct path has this long to come up once both descriptions are in; otherwise both sides switch to the relay.
  watch() { clearTimeout(this._w); this._w = setTimeout(() => { if (!this.closed && !this.relay && this.pc.connectionState !== 'connected') this.startRelay() }, RELAY_AFTER_MS) }
  startRelay() {
    if (this.relay) return true
    if (this.closed) return false
    this.mime = pickMime()
    if (!this.mime || !this.stream) return false
    this.relay = true; clearTimeout(this._w)
    this.flush({ relay: true })   // tells the other side to switch too
    try { this.pc.close() } catch { /* ignore */ }
    this.seq = 0; this.after = -1; this.queue = []; this.playing = false
    this.record(); this.listen()
    this.onState('connected')
    return true
  }
  record() {
    if (this.closed) return
    let r
    try { r = new MediaRecorder(this.stream, { mimeType: this.mime }) } catch { return }
    const parts = []
    r.ondataavailable = (e) => { if (e.data && e.data.size) parts.push(e.data) }
    r.onstop = async () => {
      if (this.muted || !parts.length) return
      const seq = this.seq++
      try { await post('/relay', { op: 'put', id: this.id, side: this.side(), seq, mime: this.mime, b: await toB64(new Blob(parts, { type: this.mime })) }) } catch { /* a lost clip is a short gap */ }
    }
    r.start()
    this._r = setTimeout(() => { try { r.stop() } catch { /* ignore */ } this.record() }, CLIP_MS)
  }
  listen() {
    const run = async () => {
      if (this.closed) return
      try {
        const res = await post('/relay', { op: 'get', id: this.id, side: this.side(), after: this.after })
        for (const c of res?.clips || []) { this.after = Math.max(this.after, c.seq); this.queue.push(c) }
        if (this.queue.length > 4) this.queue.splice(0, this.queue.length - 2)   // fell behind: catch up rather than drift
        this.playNext()
      } catch { /* retry */ }
      this._l = setTimeout(run, 600)
    }
    run()
  }
  playNext() {
    if (this.playing || !this.queue.length || this.closed) return
    const c = this.queue.shift()
    const url = URL.createObjectURL(fromB64(c.b, c.mime))
    const p = this.player || new Audio()
    this.playing = true
    const done = () => { URL.revokeObjectURL(url); this.playing = false; this.playNext() }
    p.onended = done; p.onerror = done
    p.src = url
    p.play().catch(done)
  }
  side() { return this.role === 'caller' ? 'c' : 's' }
  flushSoon() { clearTimeout(this._f); this._f = setTimeout(() => this.flush(), 350) }
  async flush(extra = {}) {
    if (this.closed) return
    const sig = { ...(this.sig || {}), ...extra, c: this.cands.slice() }
    this.sig = sig
    try { await db.updateShared('calls', this.id, { [this.mine]: sig }) } catch { /* next flush retries */ }
  }
  async startCaller() {
    await this.mic()
    const offer = await this.pc.createOffer({ offerToReceiveAudio: true })
    await this.pc.setLocalDescription(offer)
    await this.flush({ sdp: offer.sdp, type: offer.type })
    this.poll()
  }
  async startCallee(callDoc) {
    await this.mic()
    const off = callDoc.cSig
    await this.pc.setRemoteDescription({ type: 'offer', sdp: off.sdp }); this.remoteSet = true; this.watch()
    const answer = await this.pc.createAnswer()
    await this.pc.setLocalDescription(answer)
    await this.flush({ sdp: answer.sdp, type: answer.type })
    await this.applyRemote(callDoc)
    this.poll()
  }
  async applyRemote(doc) {
    const sig = doc?.[this.theirs]
    if (!sig) return
    if (sig.relay && !this.relay) { this.startRelay(); return }
    if (this.relay) return
    if (!this.remoteSet && this.role === 'caller' && sig.sdp) { await this.pc.setRemoteDescription({ type: 'answer', sdp: sig.sdp }); this.remoteSet = true; this.watch() }
    if (!this.remoteSet) return
    const list = sig.c || []
    for (; this.appliedRemote < list.length; this.appliedRemote++) { try { await this.pc.addIceCandidate(list[this.appliedRemote]) } catch { /* stale */ } }
  }
  poll() {
    const run = async () => {
      if (this.closed) return
      try {
        const rows = await db.selectShared('calls', { id: this.id }, { limit: 1 })
        const doc = (rows || [])[0]
        if (doc) {
          this.doc = doc
          await this.applyRemote(doc)
          if (['ended', 'declined', 'missed'].includes(doc.status)) { this.onState(doc.status); return this.close() }
          if (doc.status === 'active' && !this.answered) { this.answered = true; this.onState('answered') }
        }
      } catch { /* retry */ }
      // Once the voice is up the document only has to say "hung up": no need to ask the site every 0.8 s for the whole call.
      this.timer = setTimeout(run, this.relay || this.pc.connectionState === 'connected' ? POLL_MS * 3 : POLL_MS)
    }
    run()
  }
  close() {
    this.closed = true; clearTimeout(this.timer); clearTimeout(this._f); clearTimeout(this._w); clearTimeout(this._r); clearTimeout(this._l)
    try { if (this.player) { this.player.pause(); this.player.src = '' } } catch { /* ignore */ }
    try { this.stream?.getTracks().forEach((t) => t.stop()) } catch { /* ignore */ }
    try { this.pc.close() } catch { /* ignore */ }
  }
}

export const hotlineSupported = () => typeof RTCPeerConnection !== 'undefined' && !!navigator.mediaDevices?.getUserMedia

// callback: nobody was online on the paired hotline, so the call is recorded as a missed one with a call-back request (the server alerts the team: app + Telegram).
export async function createCall({ name, phone, userId, deviceId, callback = false }) {
  const gid = await team.groupId()
  const row = await db.insertShared('calls', {
    status: callback ? 'missed' : 'ringing', ...(callback ? { callback: true } : {}), name: name || 'عميل', phone: phone || '', userId: userId || '', deviceId: deviceId || '', createdAt: new Date().toISOString(),
  }, undefined, { groupId: gid, visibleTo: 'creator-and-admin', writableBy: 'creator-and-admin', expiresAt: new Date(Date.now() + 86400000).toISOString() })
  return row?.id
}
// answeredAt / endedAt feed the call register (duration, answered vs missed). Once a call is over its signalling data (SDP + network addresses) is useless: wipe it.
const OVER = ['ended', 'missed', 'declined']
export const setCallStatus = (id, status, extra = {}) => db.updateShared('calls', id, {
  status, ...extra,
  ...(status === 'active' && !extra.answeredAt ? { answeredAt: new Date().toISOString() } : {}),
  ...(OVER.includes(status) ? { endedAt: new Date().toISOString(), cSig: null, sSig: null } : {}),
})

// ---- calls between the three sides of a job (customer, technician, operations): the server decides who may ring whom.
export const callnet = {
  can: (orderId) => post('/callnet', { op: 'can', orderId }),
  request: (orderId) => post('/callnet', { op: 'request', orderId }),
  decide: (orderId, allow) => post('/callnet', { op: 'decide', orderId, allow }),
  policy: () => post('/callnet', { op: 'policy' }),
  setPolicy: (clientToTech) => post('/callnet', { op: 'setPolicy', clientToTech }),
  start: (t) => post('/callnet', { op: 'start', ...t }).then((r) => r?.id),
}
// The id the server knows this device by: the signed-in account, or the anonymous visitor id.
export const myCallId = () => currentUser()?.id || 'anon-' + getAnonId()
// How each side looks on a ringing phone and on the call screen.
export const SIDES = {
  ops: { label: 'العمليات', icon: '🎧', ring: 'border-sky-400/60 shadow-sky-500/20', badge: 'bg-sky-500/20 text-sky-200', grad: 'from-sky-400 to-indigo-600' },
  tech: { label: 'الفني', icon: '🔧', ring: 'border-amber-400/60 shadow-amber-500/20', badge: 'bg-amber-500/20 text-amber-200', grad: 'from-amber-400 to-orange-600' },
  client: { label: 'العميل', icon: '👤', ring: 'border-emerald-400/60 shadow-emerald-500/20', badge: 'bg-emerald-500/20 text-emerald-200', grad: 'from-emerald-400 to-teal-600' },
}
export const sideOf = (c) => SIDES[c?.from?.role] || SIDES.client
