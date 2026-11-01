import { defineConfig, mergeConfig } from 'vite'
import base from './vite.config.js'

// Every build gets an id: stamped into both pages (window.__DESK_BUILD__) and published as desk-version.json,
// so an app that is still running an older build notices it and reloads itself (no manual cache clearing).
const BUILD_ID = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '')
const deskBuildId = () => ({
  name: 'desk-build-id',
  transformIndexHtml: (html) => html.replaceAll('__DESK_BUILD_ID__', BUILD_ID),
  generateBundle() { this.emitFile({ type: 'asset', fileName: 'desk-version.json', source: JSON.stringify({ v: BUILD_ID }) }) },
})

export default mergeConfig(base, defineConfig({
  base: './',
  publicDir: 'public-desk',
  plugins: [deskBuildId()],
  build: { outDir: 'dist-desk', emptyOutDir: true, rollupOptions: { input: { desk: 'desk.html', 'desk-ops': 'desk-ops.html' } } },
}))
