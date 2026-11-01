// The desk apps are fixed to ONE site (window.__DESK_BRAND__ is set by the page that opened them); the customer app keeps the build's own name.
export const DESK_BRAND = typeof window !== 'undefined' ? (window.__DESK_BRAND__ || '') : ''
export const brandLabel = (fallback = 'مُتقن') => (DESK_BRAND === 'oxygen' ? 'أكسجين' : DESK_BRAND === 'mutqan' ? 'مُتقن' : fallback)
