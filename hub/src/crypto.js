// AES-256-GCM for refresh tokens / app passwords stored in the DB. Key = HUB_KEY (32 bytes hex) in /opt/hub/.env — never committed.
const c = require('crypto')
const key = () => { const k = process.env.HUB_KEY || ''; if (!/^[0-9a-f]{64}$/i.test(k)) throw new Error('HUB_KEY must be 64 hex chars (openssl rand -hex 32)'); return Buffer.from(k, 'hex') }
exports.enc = (s) => { const iv = c.randomBytes(12), x = c.createCipheriv('aes-256-gcm', key(), iv), d = Buffer.concat([x.update(String(s), 'utf8'), x.final()]); return Buffer.concat([iv, x.getAuthTag(), d]).toString('base64') }
exports.dec = (b) => { const r = Buffer.from(b, 'base64'), x = c.createDecipheriv('aes-256-gcm', key(), r.subarray(0, 12)); x.setAuthTag(r.subarray(12, 28)); return Buffer.concat([x.update(r.subarray(28)), x.final()]).toString('utf8') }
