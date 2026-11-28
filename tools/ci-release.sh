#!/usr/bin/env bash
# The release, run by GitHub Actions (.github/workflows/release-build.yml) instead of a machine with node_modules.
# Same recipe as tools/release.sh + tools/build-desk.sh, so either Claude account can ship without building locally:
#   out/plugin-dist-mutqan   the plugin for the 20 faces + mutqan  (mutqan source as is)
#   out/oxygen-dist          the plugin for oxygen11.com           (tools/brand.py oxygen)
#   out/desk-dist            the desk apps' own files (desk.html, desk-ops.html, … assets/)
# The live builds' hashed files are carried into the new ones, so a tab opened before the release still finds its chunk.
set -euo pipefail
ROOT=$(git rev-parse --show-toplevel); cd "$ROOT"
OUT="$ROOT/out"; rm -rf "$OUT"; mkdir -p "$OUT"
DEP=$(mktemp -d)
git -C "$DEP" init -q
git -C "$DEP" remote add o https://github.com/alizizo888-code/alizeidan123.git
git -C "$DEP" fetch -q --depth 1 o '+refs/heads/oxygen-dist:refs/remotes/o/oxygen-dist' '+refs/heads/plugin-dist-mutqan:refs/remotes/o/plugin-dist-mutqan'

build_one() { # $1 brand  $2 deploy branch
  local brand=$1 prev=$2 D; D=$(mktemp -d)
  git archive HEAD | tar -x -C "$D"
  [ "$brand" = oxygen ] && python3 "$D/tools/brand.py" "$D" oxygen >/dev/null
  mkdir -p "$D/oxygen-ac-automation/app/assets"
  git -C "$DEP" archive "o/$prev" app/assets 2>/dev/null | tar -x -C "$D/oxygen-ac-automation" 2>/dev/null || true
  ln -s "$ROOT/mutqan-app/node_modules" "$D/mutqan-app/node_modules"
  (cd "$D" && bash tools/build-app.sh)
  rm "$D/mutqan-app/node_modules"
  cp -a "$D/oxygen-ac-automation" "$OUT/$prev"
  echo "$brand → $prev: $(ls "$OUT/$prev/app/assets" | wc -l) app files"
}
build_one mutqan plugin-dist-mutqan
build_one oxygen oxygen-dist

(cd mutqan-app && npx vite build -c vite.desk.config.js --logLevel error)
cp -a mutqan-app/dist-desk "$OUT/desk-dist"
echo "desk: $(ls "$OUT/desk-dist" | tr '\n' ' ')"
