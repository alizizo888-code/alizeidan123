// The app is built by Vite, which resolves "./_api" without the .js. Plain node does not, so these
// tests load the real source through this hook rather than through a copy that could drift from it.
import { existsSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'

export async function resolve(specifier, context, next) {
  try {
    return await next(specifier, context)
  } catch (error) {
    if (error.code !== 'ERR_MODULE_NOT_FOUND' || !specifier.startsWith('.')) throw error
    const base = new URL(specifier, context.parentURL)
    for (const ext of ['.js', '.jsx', '/index.js']) {
      const candidate = new URL(base.href + ext)
      if (existsSync(fileURLToPath(candidate))) {
        return next(candidate.href, context)
      }
    }
    throw error
  }
}
