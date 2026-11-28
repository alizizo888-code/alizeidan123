// The order's number as the customer hears it on WhatsApp (1018) — the same everywhere. Old orders without a number
// fall back to the id's tail. Twin of Mutqan_Store::num() on the server (live test 2026-11-23: the technician's screen
// showed «#9995» while the customer and the room had «1018»).
export const orderNo = (o) => {
  const n = String(o?.number ?? '').trim()
  return n || String(o?.id ?? '').slice(-5)
}

// Where the order is, in words: the address, else «📍 لوكيشن على الخريطة» when the customer sent his location on WhatsApp
// (those orders have lat/lng and no address — the screens said «بدون عنوان» and the technician thought he had nowhere to go).
export const placeText = (o) => (o?.address ? String(o.address) : (o?.lat && o?.lng) ? '📍 لوكيشن على الخريطة' : 'بدون عنوان')
