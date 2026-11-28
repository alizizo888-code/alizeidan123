// Street map ⇄ satellite on every map (owner order 2026-11-20): satellite shows the actual roofs, so a house, a
// building entrance or a villa can be pinned exactly; the street map shows names. Both free, no key.
// 2026-11-21: the desk sends "Referrer-Policy: no-referrer", and OpenStreetMap answers 403 to tile requests without a
// Referer — the grey "dead" map. Every tile layer now sets its own referrerPolicy, and the street map falls back
// automatically (OSM → CARTO → Esri street) when a provider keeps failing, so the map never stays blank.
const KEY = 'mq_map_base'
const ESRI = 'https://server.arcgisonline.com/ArcGIS/rest/services/'
const REF = { referrerPolicy: 'strict-origin-when-cross-origin', crossOrigin: false }
const STREETS = [
  ['https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' }],
  ['https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png', { subdomains: 'abcd', maxNativeZoom: 20, attribution: '&copy; OpenStreetMap &copy; CARTO' }],
  [ESRI + 'World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxNativeZoom: 19, attribution: 'Tiles &copy; Esri' }],
]
let streetIdx = 0 // shared by every map on the page: once a provider fails, the next maps start on the working one

function tile(L, url, opts) {
  return L.tileLayer(url, { maxZoom: 20, keepBuffer: 4, updateWhenIdle: false, ...REF, ...opts })
}

export function addBaseLayers(L, map, { position = 'topleft' } = {}) {
  let street = null
  let errors = 0, loads = 0
  const makeStreet = () => {
    const [u, o] = STREETS[streetIdx]
    const t = tile(L, u, o)
    errors = 0; loads = 0
    t.on('tileload', () => { loads++ })
    t.on('tileerror', () => {
      errors++
      // switch provider when it fails repeatedly and nothing has loaded
      if (errors >= 4 && loads === 0 && streetIdx < STREETS.length - 1) {
        streetIdx++
        const was = map.hasLayer(t)
        map.removeLayer(t)
        street = makeStreet()
        if (was && mode === 'street') street.addTo(map)
      }
    })
    return t
  }
  street = makeStreet()
  const photo = tile(L, ESRI + 'World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxNativeZoom: 19, attribution: 'Imagery &copy; Esri' })
  const labels = tile(L, ESRI + 'Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxNativeZoom: 19, pane: 'overlayPane', opacity: 0.9 })
  const roads = tile(L, ESRI + 'Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', { maxNativeZoom: 19, pane: 'overlayPane', opacity: 0.7 })
  const sat = L.layerGroup([photo, roads, labels])
  let mode = 'street'
  try { mode = localStorage.getItem(KEY) === 'sat' ? 'sat' : 'street' } catch { /* default */ }
  const set = (m) => {
    mode = m
    if (m === 'sat') { map.removeLayer(street); sat.addTo(map) } else { map.removeLayer(sat); street.addTo(map) }
    try { localStorage.setItem(KEY, m) } catch { /* ignore */ }
    if (btn) btn.innerHTML = m === 'sat' ? '🗺️ <span>خريطة</span>' : '🛰️ <span>قمر صناعي</span>'
  }
  let btn = null
  const Ctl = L.Control.extend({
    onAdd() {
      btn = L.DomUtil.create('button', '')
      btn.type = 'button'
      btn.setAttribute('aria-label', 'تبديل خريطة / قمر صناعي')
      btn.style.cssText = 'background:#0d1220;color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:10px;padding:5px 9px;font:700 11px Tahoma,sans-serif;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.35);display:flex;gap:4px;align-items:center'
      L.DomEvent.disableClickPropagation(btn)
      L.DomEvent.on(btn, 'click', (e) => { L.DomEvent.stop(e); set(mode === 'sat' ? 'street' : 'sat') })
      return btn
    },
  })
  new Ctl({ position }).addTo(map)
  set(mode)
  map.setMaxZoom?.(20)
  keepAlive(map)
  return { set, get: () => mode }
}

// The map "drops" when its box is resized, hidden in a tab or the phone wakes up: Leaflet keeps the old size and
// draws grey. Re-measure on resize, on visibility and when the container changes size.
export function keepAlive(map) {
  const fix = () => { try { map.invalidateSize({ pan: false }) } catch { /* removed */ } }
  const vis = () => { if (!document.hidden) setTimeout(fix, 120) }
  window.addEventListener('resize', fix)
  window.addEventListener('orientationchange', fix)
  document.addEventListener('visibilitychange', vis)
  let ro = null
  try { ro = new ResizeObserver(fix); ro.observe(map.getContainer()) } catch { /* old browser */ }
  map.on('unload', () => {
    window.removeEventListener('resize', fix)
    window.removeEventListener('orientationchange', fix)
    document.removeEventListener('visibilitychange', vis)
    ro?.disconnect()
  })
  setTimeout(fix, 250)
}
