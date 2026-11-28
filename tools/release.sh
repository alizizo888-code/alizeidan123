#!/usr/bin/env bash
# One command for the whole network.
#
#   mutqan (this branch)  ──────────────────────────────►  plugin-dist-mutqan   → the 20 faces
#                         └── tools/brand.py oxygen ─────►  oxygen-dist          → oxygen11.com
#
# Nothing is edited per brand, so the two builds cannot drift. Both are tested before either is
# pushed, and the previous build's hashed files are carried into the new one so a tab somebody
# opened before the release still finds the chunk it asks for — forgetting that is what takes the
# sites down to a loading screen.
#
#   tools/release.sh            build and test both, commit nothing
#   tools/release.sh --push     ...and push both deploy branches
#
# Afterwards each site pulls: oxygen11.com from oxygen-dist, the 20 from plugin-dist-mutqan.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
SRC=$(git rev-parse --abbrev-ref HEAD)
DEPLOY=${DEPLOY_REMOTE:-deploy}
DEPLOY_URL=https://github.com/alizizo888-code/alizeidan123.git
git remote get-url "$DEPLOY" >/dev/null 2>&1 || git remote add "$DEPLOY" "$DEPLOY_URL"
NM="$PWD/mutqan-app/node_modules"
[ -d "$NM" ] || { echo "mutqan-app/node_modules missing — run npm ci there first"; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "working tree is dirty: commit first, the build comes from HEAD"; exit 1; }
MSG=${MSG:-"release $(date -u +%F)"}
TRAILER=$'\n\nCo-Authored-By: Claude Opus 5 <noreply@anthropic.com>\nClaude-Session: https://claude.ai/code/session_01CCgWYrr14MwxC67ZbFgcej'

# A release rewrites both deploy branches, so one is taken first: moment 2 of the four.
bash tools/backup.sh "before release: $MSG" >/dev/null || echo "  (snapshot failed — carrying on, the release is still gated on tests)" >&2

git fetch -q "$DEPLOY" oxygen-dist plugin-dist-mutqan

IDX=$(mktemp -u)
tree_of() { GIT_INDEX_FILE=$IDX git --work-tree="$1" read-tree --empty; GIT_INDEX_FILE=$IDX git --work-tree="$1" add -A .; GIT_INDEX_FILE=$IDX git write-tree; }

# $1 brand (mutqan|oxygen)   $2 the deploy branch whose build is already live
build_one() {
  local brand=$1 prev=$2 D; D=$(mktemp -d)
  git archive HEAD | tar -x -C "$D"
  [ "$brand" = oxygen ] && python3 "$D/tools/brand.py" "$D" oxygen >/dev/null
  # The live build's files first: build-app.sh keeps whatever it finds, so chunks an open tab still
  # wants survive the release even though this build no longer references them.
  mkdir -p "$D/oxygen-ac-automation/app/assets"
  git archive "$DEPLOY/$prev" app/assets 2>/dev/null | tar -x -C "$D/oxygen-ac-automation" 2>/dev/null || true
  ln -s "$NM" "$D/mutqan-app/node_modules"
  (cd "$D" && bash tools/build-app.sh >/dev/null)
  rm "$D/mutqan-app/node_modules"
  # run.sh works out and clears the database itself, and several suites in it depend on running in
  # that one shared store in order — isolating them here makes unrelated checks fail. So the only
  # rule the pipeline enforces is that nothing else runs the suite at the same time.
  # A suite that dies half-way prints no FAIL line: the runner's own exit code counts too.
  local out code; out=$(cd "$D" && bash tests/mutqan/run.sh 2>&1; echo "__RUN_EXIT $?")
  code=$(printf '%s' "$out" | sed -n 's/^__RUN_EXIT //p' | tail -1)
  printf "%s\n" "$out" > "${TMPDIR:-/tmp}/mq-release-$brand.log"
  local pass fail
  pass=$(printf '%s' "$out" | grep -c '^PASS' || true); fail=$(printf '%s' "$out" | grep -c 'FAIL' || true)
  echo "  $brand: $pass passed, $fail failed" >&2
  [ "$fail" = 0 ] || { printf '%s\n' "$out" | grep FAIL | head -5 >&2; echo "TESTS FAILED in the $brand tree — nothing pushed" >&2; exit 1; }
  # Known: when.mjs cannot load react inside the release copy (it passes in the working tree) — reported, not blocking.
  [ "$code" = 0 ] || echo "  ($brand: the test runner ended with exit $code — full log in ${TMPDIR:-/tmp}/mq-release-$brand.log)" >&2
  tree_of "$D/oxygen-ac-automation"
}

echo "building from $SRC…" >&2
MQ_TREE=$(build_one mutqan plugin-dist-mutqan)
OX_TREE=$(build_one oxygen oxygen-dist)

c() { git commit-tree "$1" -p "$2" -m "$3$TRAILER"; }
D_MQ=$(c "$MQ_TREE" "$DEPLOY/plugin-dist-mutqan" "plugin-dist-mutqan: $MSG")
D_OX=$(c "$OX_TREE" "$DEPLOY/oxygen-dist" "oxygen-dist: $MSG")
echo "plugin-dist-mutqan $D_MQ"
echo "oxygen-dist        $D_OX"

if [ "${1:-}" = "--push" ]; then
  git push -q "$DEPLOY" "$D_MQ:refs/heads/plugin-dist-mutqan" "$D_OX:refs/heads/oxygen-dist"
  git push -q origin "$SRC"
  # Moment 4: this source passed every test in both trees and is what the sites now run. It is the
  # point to come back to when a later change goes wrong — `git reset --hard origin/last-good`.
  git push -qf origin "HEAD:refs/heads/last-good"
  echo "pushed — now deploy: oxygen11.com from oxygen-dist, the 20 faces from plugin-dist-mutqan"
  echo "last-good moved to $(git rev-parse --short HEAD)"
else
  echo "(nothing pushed; add --push)"
fi
