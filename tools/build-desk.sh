#!/usr/bin/env bash
# Build the private desk apps (desk.oxygen11.com) and push them to the `desk-dist` branch (deployed to the desk subdomain folder).
set -euo pipefail
cd "$(dirname "$0")/.."
(cd mutqan-app && npx vite build -c vite.desk.config.js --logLevel error)
TMP=$(mktemp -d)
REMOTE=$(git remote get-url alizeidan 2>/dev/null || git remote get-url origin)
git init -q "$TMP" && cp -a mutqan-app/dist-desk/. "$TMP"/ && cd "$TMP"
git checkout -q -b desk-dist && git add -A && git -c user.name=oxygen-bot -c user.email=bot@oxygen11.com commit -q -m "desk apps build $(date -u +%FT%TZ)"
git push -q -f "$REMOTE" desk-dist
echo "pushed desk-dist"
