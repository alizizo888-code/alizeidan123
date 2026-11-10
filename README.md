# Oxygen Master (مركز التحكم)

Owner-only control panel for the Oxygen face network. One PHP API on Hostinger, a static
Arabic PWA on the same domain, and a small signed bridge plugin on every WordPress face.

```
Owner (phone / tablet / laptop)
   │  HTTPS, same domain, PIN login, HttpOnly session cookie, CSRF on writes
   ▼
Master: api/index.php  +  src/  +  MySQL      (desk.oxygen11.com)
   │  server-to-server, HMAC-SHA256 over method, route, timestamp, nonce, body
   ▼
Oxygen Bridge plugin on each face (bridge/oxy-bridge)  →  /wp-json/oxy-bridge/v1/*
```

## Why this design

| Earlier failure | Cause | What this does instead |
|---|---|---|
| 403 / CORS from browser or Artifact | browsers block cross-origin calls to 22 domains | the browser only talks to its own domain; the server calls the faces |
| "no permission" in APK or desk | no owner session reached the faces | owner session lives on the master; faces only accept signed calls |
| Secrets in front-end code | any visitor could read them | per-face secret encrypted in MySQL (AES-256-GCM), key in server config |
| WP-cron only runs on visits | articles and jobs stalled | durable queue + scheduled tick every 10 minutes |
| One bad face blocks a batch | sequential calls timed out | batches of 5–10 jobs, retries with backoff, per-face status |

## Layout

| Path | Served? | Purpose |
|---|---|---|
| `index.html`, `app.js`, `app.css`, `sw.js`, `manifest.webmanifest`, `icon.svg` | yes | the PWA |
| `api/index.php` | yes (via `/api/*`) | JSON router |
| `src/` | **no** (blocked by `.htaccess`) | auth, HMAC, vault, queue, site client |
| `sql/schema.sql` | **no** | tables (MySQL and SQLite compatible) |
| `seed/sites.json` | **no** | the 22 faces, generated from the Oxygen registry |
| `bridge/oxy-bridge/` | **no** | WordPress plugin, deploy to each face |
| `tests/run.php` | **no** | `php tests/run.php`, no network or DB server needed |
| `docs/config.example.php` | **no** | template for the server config file |

## Install (one time)

1. **Database.** Create a MySQL database and user on Hostinger (hPanel, or the Hostinger API). Note the name, user and password. The host is `127.0.0.1:3306` from the app.
2. **Server config.** Copy `docs/config.example.php` to `domains/oxygen11.com/oxy-master-config.php`, the folder that contains `public_html` (one level above it, not inside it). Fill in the DB values and generate three secrets (`master_key`, `setup_token`, `cron_token`). This file is never in git.
3. **Deploy the desk.** Follow `docs/CUTOVER.md`. The old desk build is kept in git (branch `desk-dist`) before the desk folder is cleared.
4. **First run.** Open `https://desk.oxygen11.com/`. The setup screen asks for the setup token and a new 6-digit PIN. It creates the tables and the 22 faces.
5. **Pair each face.** Install `bridge/oxy-bridge` on the face (Plugins › Add, upload the folder zip). Open **Settings › Oxygen Bridge**, copy the key, and in the panel press **ربط المفتاح** on that face. The key is encrypted at rest.
6. **Scheduler.** In GitHub, add repository secrets `MASTER_URL` (for example `https://desk.oxygen11.com`) and `CRON_TOKEN` (the same value as in the config). The workflow `.github/workflows/tick.yml` then runs every 10 minutes.

## Security notes

- The PIN is hashed (`password_hash`). Five wrong tries lock the panel for 15 minutes.
- Sessions are random 256-bit tokens stored hashed. The cookie is HttpOnly, Secure, SameSite=Strict.
- Every write needs the CSRF token from the session (`X-CSRF` header).
- The bridge rejects requests with a stale timestamp (more than 5 minutes) or a reused nonce (replay).
- Bridge secrets rotate from the face's admin page. Rotating invalidates the old one, so re-pair after rotating.
- Nothing destructive runs automatically. Deletions and DNS changes are not implemented in this phase.

## Tests

```
php tests/run.php
```

## Status by phase

- Phase 0 (this): auth, signed bridge, site registry, health checks, cache purge, queue, PWA shell.
- Phase 1: article queue and quotas per face, holding-page and stale-cache repair.
- Phase 2: Google (Search Console sitemaps, Blogger posts) through OAuth, one consent per Google account.
- Phase 3: AI assistant that only calls the typed actions above, with approvals and a daily budget.
- Phase 4: reports, passkey login, theme and polish.
