// The desk apps are fixed to ONE site (window.__DESK_BRAND__ is set by the page that opened them); the customer app keeps the build's own name.
export const DESK_BRAND = typeof window !== 'undefined' ? (window.__DESK_BRAND__ || '') : ''
// Desk apps (owner / operations / technician) serve the whole network: all 22 sites feed one operations room.
export const brandLabel = (fallback = 'مُتقن') => (typeof window !== 'undefined' && window.__DESK_KIND__ ? 'شبكة المواقع · ' + ({ control: 'المالك', ops: 'العمليات', tech: 'الفني' }[window.__DESK_KIND__] || '') : DESK_BRAND === 'oxygen' ? 'أكسجين' : DESK_BRAND === 'mutqan' || DESK_BRAND === 'tech' ? 'مُتقن' : fallback)
