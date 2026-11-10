# Desk cutover: owner checklist

Scope: replace the old build on `desk.oxygen11.com` with the master. The old build is kept in git
(branch `desk-dist`, last commit `6af0a10`, build 202611031028). Nothing is deleted from GitHub.

## Done

1. The old desk files were moved out of the desk folder into `domains/_old-desk-202611031028`. That folder sits
   next to the domain folders, not inside any of them, so the web server never serves it. It holds 20 files and
   folders plus `.git`.
2. The desk folder was empty, and the master was deployed from branch `oxygen-master`.

## Owner, next

3. **Create the MySQL database and user** in hPanel (Databases › MySQL). Name them `oxymaster` and `oxymaster`,
   and choose a password. Keep the password out of chat. Hostinger adds the account prefix, so the real names
   are `u283616892_oxymaster` (database) and `u283616892_oxymaster` (user). Use those full names in the config.
4. **Upload the config file** `oxy-master-config.php` to `domains/oxygen11.com/`. That is the folder that
   contains `public_html`, not a folder inside it. Fill in `FILL_DB_NAME`, `FILL_DB_USER` and `FILL_DB_PASSWORD`.
5. **GitHub secrets** (repo `alizizo888-code/alizeidan123` › Settings › Secrets › Actions):
   `MASTER_URL` = `https://desk.oxygen11.com` and `CRON_TOKEN` = the `cron_token` value from the config file.
6. **First run.** Open `https://desk.oxygen11.com/`, enter the **setup token** (`setup_token` in the config file),
   and choose your 6-digit PIN. This creates the 22 faces.
7. **Pair each face.** Install `bridge/oxy-bridge` on the face, copy its key from **Settings › Oxygen Bridge**,
   and press **ربط المفتاح** on that face in the panel.

Until steps 3 and 4 are done, `https://desk.oxygen11.com/api/health` returns an error. That is expected.

## Rollback

Move `_old-desk-202611031028` back into the desk folder, or redeploy branch `desk-dist` to the desk folder.
Nothing in git is rewritten.
