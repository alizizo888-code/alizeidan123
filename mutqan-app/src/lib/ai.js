import { post } from './_api'
// Server-side free-tier gateway (Groq / Gemini / Cloudflare / OpenRouter with automatic failover).
export const ai = {
  // opts.tier: 'low' (default, free providers) | 'mid' (customer service/orders) | 'routine' | 'high' — the server caps it by role.
  run: (prompt, opts = {}) => post('/ai', { prompt, opts }),
}
