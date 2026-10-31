#!/usr/bin/env bash
# Build the private desk apps (desk.oxygen11.com) and push them as a NEW COMMIT on top of `desk-dist` (never force-push:
# the hosting folder is a git checkout that fast-forwards).
# RECOVER=<sha> PUSH_BRANCH=<new-branch>: build on top of a commit the server already has and push to a NEW branch.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
(cd "$ROOT/mutqan-app" && npx vite build -c vite.desk.config.js --logLevel error)
cd "$ROOT"; REMOTE=$(git remote get-url alizeidan 2>/dev/null || git remote get-url origin)
TMP=$(mktemp -d); git init -q "$TMP"; cd "$TMP"
git remote add o "$REMOTE"
if [ -n "${RECOVER:-}" ]; then git fetch -q o "$RECOVER" && git checkout -q -b build FETCH_HEAD; else git fetch -q o desk-dist && git checkout -q -b build FETCH_HEAD; fi
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -a "$ROOT/mutqan-app/dist-desk/." .
# Native APKs are binaries: they live only in the deploy branch, copied from APK_DIR (oxygen-deskops.apk, oxygen-deskctl.apk).
for a in oxygen-deskops.apk oxygen-deskctl.apk; do [ -n "${APK_DIR:-}" ] && [ -f "$APK_DIR/$a" ] && cp "$APK_DIR/$a" . || true; done
git add -A
git -c user.name=oxygen-bot -c user.email=bot@oxygen11.com commit -q -m "desk apps build $(date -u +%FT%TZ)" || { echo "no changes"; exit 0; }
if [ -n "${RECOVER:-}" ]; then git push -q o "build:refs/heads/${PUSH_BRANCH:?PUSH_BRANCH required}"; echo "pushed ${PUSH_BRANCH}"; else git push -q o build:refs/heads/desk-dist; echo "pushed desk-dist"; fi
