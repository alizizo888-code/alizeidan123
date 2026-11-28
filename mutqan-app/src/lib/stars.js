// A rating is a number (rated in the app) or {stars, comment} (rated on WhatsApp): both mean 1–5 stars.
export const starsOf = (o) => { const r = o?.rating; const n = Number(r && typeof r === 'object' ? r.stars : r) || Number(o?.customerRating) || 0; return n >= 1 && n <= 5 ? n : 0 }
