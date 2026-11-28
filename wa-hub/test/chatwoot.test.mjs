// The team's app: every customer chat lands in Chatwoot, an agent's answer goes out on WhatsApp, no loops.
import { openStore } from '../src/store.js'
import { createChatwoot } from '../src/chatwoot.js'
let pass = 0, fail = 0
const t = (n, ok, x = '') => { if (ok) { pass++; console.log('PASS wa-hub chatwoot: ' + n) } else { fail++; console.log('FAIL wa-hub chatwoot: ' + n + ' -> ' + JSON.stringify(x)) } }
const store = openStore(':memory:')
store.putLine({ id: 'l1', label: 'العملاء والطلبات', phone: '966549423050' })
store.touchChat('500000001', { phone: '966500000001', name: 'علي', line: 'l1' })
store.touchChat('536554731', { phone: '966536554731', kind: 'staff', line: 'l1' })
const calls = []; let nextId = 100
globalThis.fetch = async (url, o) => {
  const path = url.replace(/^.*\/accounts\/7/, ''); const b = o.body ? JSON.parse(o.body) : null
  calls.push(o.method + ' ' + path + (b && b.content ? ' «' + b.content.slice(0, 30) + '»' : ''))
  const ok = (j) => ({ ok: true, status: 200, json: async () => j })
  if (path === '/inboxes') return ok({ id: 11 })
  if (path === '/agents') return ok([{ id: 1 }, { id: 2 }])
  if (path === '/inbox_members') return ok({})
  if (path === '/contacts') return ok({ payload: { contact: { id: 5, contact_inboxes: [{ source_id: 'src-1', inbox: { id: 11 } }] } } })
  if (path === '/conversations') return ok({ id: 77 })
  if (path.endsWith('/messages')) return ok({ id: ++nextId })
  return ok({})
}
const wp = async (op) => (op === 'chatwoot' ? { url: 'https://chat.test', account: 7, token: 'tok' } : null)
const cw = createChatwoot({ store, wp, hookBase: 'https://hub.test' })
const settle = () => new Promise((r) => setTimeout(r, 30))
const r = await cw.link(store.lines())
t('link: one inbox per line, the whole team added', r.ok && r.inboxes[0].inbox === 11 && r.inboxes[0].members === 2 && calls.includes('POST /inboxes'), r)
cw.mirror('500000001', 'l1', 'in', 'بكام الغسيل؟'); await settle()
t('a customer message lands as incoming, after the identity card', calls.some((c) => c.includes('🪪 كارت المحادثة')) && calls.some((c) => c.includes('«بكام الغسيل؟»')), calls)
cw.mirror('500000001', 'l1', 'out', 'غسيل المكيف 100 ر.س', 'reply'); await settle()
t('the bot\'s answer lands as outgoing, labeled', calls.some((c) => c.includes('🤖 الاستقبال')), calls)
const before = calls.length
cw.mirror('536554731', 'l1', 'in', 'تقرير'); await settle()
t('the team\'s chats are not mirrored', calls.length === before, calls.slice(before))
cw.mirror('500000001', 'l1', 'out', 'من التطبيق', 'chatwoot'); await settle()
t('what came from Chatwoot is not mirrored back', calls.length === before, calls.slice(before))
const sent = []
const send = (p, tx, o) => sent.push({ p, tx, o })
const key = store.get('cwSecret')
t('webhook with a wrong key is refused', cw.hook({ event: 'message_created' }, 'bad', send).status === 403)
t('our own message echoed back is ignored', cw.hook({ event: 'message_created', message_type: 'outgoing', id: nextId, conversation: { id: 77 }, content: 'x' }, key, send).ignored === 'echo')
t('a private note is not sent', cw.hook({ event: 'message_created', message_type: 'outgoing', private: true, id: 999, conversation: { id: 77 }, content: 'x' }, key, send).ignored === 'type')
const h = cw.hook({ event: 'message_created', message_type: 'outgoing', id: 1000, conversation: { id: 77 }, content: 'أهلا يا علي، الفني جاي العصر', sender: { name: 'مهندس' } }, key, send)
t('an agent\'s answer goes out on WhatsApp from the same line', h.sent && sent[0].p === '966500000001' && sent[0].o.line === 'l1' && sent[0].o.purpose === 'chatwoot', sent)
t('…and the bot keeps out of that chat', store.chat('500000001').humanAt > 0)
console.log(`wa-hub chatwoot: ${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
