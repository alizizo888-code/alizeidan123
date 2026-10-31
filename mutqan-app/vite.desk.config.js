import { defineConfig, mergeConfig } from 'vite'
import base from './vite.config.js'
export default mergeConfig(base, defineConfig({
  base: './',
  publicDir: 'public-desk',
  build: { outDir: 'dist-desk', emptyOutDir: true, rollupOptions: { input: { desk: 'desk.html', 'desk-ops': 'desk-ops.html' } } },
}))
