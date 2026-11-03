# Desk cutover: owner checklist

Scope: replace the old build on `desk.oxygen11.com` with the master. The old build is kept in git
(branch `desk-dist`, last commit `6af0a10`, build 202611031028). Nothing is deleted from GitHub.

## What the tools cannot do (owner, in hPanel)

1. **Clear the old desk folder.** Open File Manager › `public_html/desk` and move the 18 old items
   (`desk.html`, `desk-ops.html`, `apps.html`, `assets/`, `icon-*.png`, `apple-touch-icon-*.png`,
   `manifest-*.webmanifest`, `sw-desk.js`, `desk-version.json`, `robots.txt`, `.htaccess`) into a
   folder named `_old-desk-202611031028` inside `public_html` (move, do not delete). Deploys refuse a folder that still has other files.
2. **Create the MySQL database and user** (Databases › MySQL). Use database name `oxymaster`,
   a user `oxymaster`, and a password you choose. Keep the password out of chat.
3. **Upload the config file** `oxy-master-config.php` to `/home/u283616892/domains/oxygen11.com/`
   (one level above `public_html`). Fill in `FILL_DB_NAME`, `FILL_DB_USER`, `FILL_DB_PASSWORD`.
4. **GitHub secrets** (repo `alizizo888-code/alizeidan123` › Settings › Secrets › Actions):
   `MASTER_URL` = `https://desk.oxygen11.com` and `CRON_TOKEN` = the `cron_token` value from the config file.

## After the deploy (done by the assistant, then confirmed by the owner)

5. The desk serves the master from branch `oxygen-master`.
6. Open `https://desk.oxygen11.com/`, enter the **setup token** (`setup_token` in the config file),
   and choose your 6-digit PIN. This creates the 22 faces.
7. Install `bridge/oxy-bridge` on each face, copy its key from **Settings › Oxygen Bridge**, and press
   **ربط المفتاح** on that face in the panel.

## Rollback

Redeploy branch `desk-dist` to the desk folder, or move `_old-desk-202611031028` back. Nothing in git is rewritten.
