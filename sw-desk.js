// Desk apps: installable shell; data always comes live from the brand servers (no caching of API calls).
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))
self.addEventListener('fetch', () => {})
