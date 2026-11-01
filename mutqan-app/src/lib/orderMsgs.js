import { db } from './db'
import { team } from './team'

// A system line / message inside an order's conversation (the server tells the other side by push). kind: text | eta | arrived | postponed | system | nudge | postpone_req
export async function sendOrderMsg(orderId, kind, text = '', extra = {}) {
  return db.insertShared('order_msgs', { orderId, channel: 'tech', kind, text, ...extra }, undefined, { groupId: await team.groupId(), visibleTo: 'group' })
}
