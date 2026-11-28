/**
 * MapView — Leaflet map component for generated apps.
 * Renders an interactive map with markers, popups, and event handlers.
 * Uses OpenStreetMap tiles (free, no API key needed).
 */

import React, { useEffect, useRef, useState } from 'react'
import '../lib/leafletCss'
import icon2x from 'leaflet/dist/images/marker-icon-2x.png'
import icon1x from 'leaflet/dist/images/marker-icon.png'
import iconShadow from 'leaflet/dist/images/marker-shadow.png'
import { addBaseLayers } from '../lib/baseLayers'

// Leaflet CSS must be loaded for the map to render correctly
const LEAFLET_CSS = 'bundled' // leaflet.css ships inside the app (the desk CSP blocks unpkg)

function ensureLeafletCSS() { void LEAFLET_CSS }

/**
 * @param {object} props
 * @param {[number, number]} [props.center=[51.505, -0.09]] - Map center [lat, lng]
 * @param {number} [props.zoom=13] - Initial zoom level (1-18)
 * @param {Array<{ lat: number, lng: number, label?: string, popup?: string }>} [props.markers] - Map markers
 * @param {function} [props.onMarkerClick] - Called with marker object when a marker is clicked
 * @param {function} [props.onMapClick] - Called with { lat, lng } when the map is clicked
 * @param {boolean} [props.showUserLocation=false] - Show the user's current location
 * @param {string} [props.className] - CSS class for the map container
 * @param {object} [props.style] - Inline styles for the map container
 */
export default function MapView({
  center = [51.505, -0.09],
  zoom = 13,
  markers = [],
  onMarkerClick,
  onMapClick,
  showUserLocation = false,
  className = '',
  style = {},
}) {
  const mapRef = useRef(null)
  const mapInstanceRef = useRef(null)
  const markersLayerRef = useRef(null)
  const [L, setL] = useState(null)

  // Load Leaflet dynamically
  useEffect(() => {
    ensureLeafletCSS()
    import('leaflet').then((mod) => {
      setL(mod.default || mod)
    })
  }, [])

  // Initialize map
  useEffect(() => {
    if (!L || !mapRef.current || mapInstanceRef.current) return

    const map = L.map(mapRef.current).setView(center, zoom)

    addBaseLayers(L, map)

    // Fix Leaflet's default icon path issue with bundlers
    delete L.Icon.Default.prototype._getIconUrl
    L.Icon.Default.mergeOptions({
      iconRetinaUrl: icon2x,
      iconUrl: icon1x,
      shadowUrl: iconShadow,
    })

    if (onMapClick) {
      map.on('click', (e) => {
        onMapClick({ lat: e.latlng.lat, lng: e.latlng.lng })
      })
    }

    mapInstanceRef.current = map
    markersLayerRef.current = L.layerGroup().addTo(map)

    // Show user location
    if (showUserLocation && navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          const userLatLng = [pos.coords.latitude, pos.coords.longitude]
          L.circleMarker(userLatLng, {
            radius: 8,
            fillColor: '#3B82F6',
            color: '#fff',
            weight: 2,
            fillOpacity: 1,
          }).addTo(map).bindPopup('You are here')
        },
        () => {}, // Silently ignore geolocation errors
        { enableHighAccuracy: true }
      )
    }

    return () => {
      map.remove()
      mapInstanceRef.current = null
      markersLayerRef.current = null
    }
  }, [L]) // eslint-disable-line react-hooks/exhaustive-deps

  // Update center/zoom
  useEffect(() => {
    if (!mapInstanceRef.current) return
    mapInstanceRef.current.setView(center, zoom)
  }, [center[0], center[1], zoom]) // eslint-disable-line react-hooks/exhaustive-deps

  // Update markers
  useEffect(() => {
    if (!L || !markersLayerRef.current) return

    markersLayerRef.current.clearLayers()

    markers.forEach((m) => {
      if (m.lat == null || m.lng == null) return

      const marker = L.marker([m.lat, m.lng])

      if (m.popup || m.label) {
        marker.bindPopup(m.popup || m.label)
      }

      if (onMarkerClick) {
        marker.on('click', () => onMarkerClick(m))
      }

      markersLayerRef.current.addLayer(marker)
    })
  }, [L, markers, onMarkerClick])

  const defaultStyle = {
    width: '100%',
    height: '300px',
    borderRadius: '12px',
    overflow: 'hidden',
    ...style,
  }

  return (
    <div
      ref={mapRef}
      className={className}
      style={defaultStyle}
    />
  )
}
