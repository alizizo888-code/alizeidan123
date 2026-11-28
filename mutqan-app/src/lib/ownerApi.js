// The server sides of «جاي منين» (/source) and the owner's delete controls (/owner).
import { post } from './_api'

const call = (path, body) => post(path, body)   // post() returns the data and throws ApiError with the server's message

export const source = {
  lookup: (code) => call('/source', { op: 'lookup', code }),
  search: (q, opts = {}) => call('/source', { op: 'search', q, ...opts }),
  sites: () => call('/source', { op: 'sites' }),
}

export const owner = {
  deleteOrder: (id) => call('/owner', { op: 'deleteOrder', id }),
  deleteOrders: (ids) => call('/owner', { op: 'deleteOrders', ids }),
  voidWarranty: (orderId) => call('/owner', { op: 'voidWarranty', orderId }),
  deleteWarrantyRecord: (id) => call('/owner', { op: 'deleteWarrantyRecord', id }),
  deleteClaim: (id) => call('/owner', { op: 'deleteClaim', id }),
  deleteWarranties: ({ records = [], orders = [], claims = [] }) => call('/owner', { op: 'deleteWarranties', records, orders, claims }),
  deleteTech: (userId) => call('/owner', { op: 'deleteTech', userId }),
  log: () => call('/owner', { op: 'log' }),
  trash: () => call('/owner', { op: 'trash' }),
  restore: (id) => call('/owner', { op: 'restore', id }),
}
