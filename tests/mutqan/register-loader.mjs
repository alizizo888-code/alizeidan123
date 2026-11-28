import { register } from 'node:module'
import { pathToFileURL } from 'node:url'
// Resolved against this file, not the working directory: run.sh runs from tests/mutqan while a
// developer runs the suites from the repository root, and both have to work.
register('./extensionless-loader.mjs', import.meta.url)
