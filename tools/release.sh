#!/usr/bin/env bash
# One command, two brands. Source of truth = branch pub-mutqan (متقن). The OXYGEN (أكسجين) branch and every dist branch are DERIVED:
#   pub-mutqan  --tools/brand.py-->  oxygen tree  --build/tests-->  pub-oxygen + oxygen-dist
# Nothing is hand-edited per brand, so the two sites can never drift or collide (same code, routes, options; only text/logo differ).
# usage: tools/release.sh [--push]     (run from any worktree of the repo; needs node_modules in mutqan-app)
set -euo pipefail
REPO=$(git rev-parse --show-toplevel); cd "$REPO"
NM=$(git worktree list --porcelain | awk '/^worktree/{print $2}' | while read -r w; do [ -d "$w/mutqan-app/node_modules" ] && echo "$w/mutqan-app/node_modules" && break; done)
[ -n "$NM" ] || { echo "node_modules not found"; exit 1; }
MSG=${MSG:-"release"}
TRAILER=$'\n\nCo-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>\nClaude-Session: https://claude.ai/code/session_01CCgWYrr14MwxC67ZbFgcej'
T=$(mktemp -d)
git archive pub-mutqan | tar -x -C "$T"
git archive pub-oxygen oxygen-ac-automation/app | tar -x -C "$T"          # previous oxygen build: its hashed chunks stay for open tabs
python3 tools/brand.py "$T" oxygen
ln -s "$NM" "$T/mutqan-app/node_modules"
(cd "$T" && bash tools/build-app.sh | tail -1)
TL=$(cd "$T/tests/mutqan" && bash run.sh 2>&1 | grep -E "FAIL|passed" || true); echo "$TL"
BL=$(cd "$T" && bash tests/bot/run.sh 2>&1 | grep -E "FAIL|passed" || true); echo "$BL"
if printf '%s\n%s\n' "$TL" "$BL" | grep -q "FAIL"; then echo "TESTS FAILED in the derived tree — nothing was committed or pushed"; exit 1; fi
rm "$T/mutqan-app/node_modules"
IDX=$(mktemp -u)
tree_of() { GIT_INDEX_FILE=$IDX git --work-tree="$1" read-tree --empty; GIT_INDEX_FILE=$IDX git --work-tree="$1" add -A .; GIT_INDEX_FILE=$IDX git write-tree; }
git fetch -q alizeidan 2>/dev/null || true
OXY_TREE=$(tree_of "$T")
OXY_DIST=$(tree_of "$T/oxygen-ac-automation")
MQ_DIST=$(git rev-parse pub-mutqan:oxygen-ac-automation)
c() { git commit-tree "$1" -p "$2" -m "$3$TRAILER"; }
P_OXY=$(c "$OXY_TREE" pub-oxygen "oxygen: $MSG")
git update-ref refs/heads/pub-oxygen "$P_OXY"
D_OXY=$(c "$OXY_DIST" alizeidan/oxygen-dist "oxygen-dist: $MSG")
D_MQ1=$(c "$MQ_DIST" alizeidan/mutqan1 "mutqan1: $MSG")
D_MQP=$(c "$MQ_DIST" alizeidan/plugin-dist-mutqan "plugin-dist-mutqan: $MSG")
echo "pub-oxygen $P_OXY | oxygen-dist $D_OXY | mutqan1 $D_MQ1 | plugin-dist-mutqan $D_MQP"
if [ "${1:-}" = "--push" ]; then
  git push -q alizeidan "$D_OXY:refs/heads/oxygen-dist" "$D_MQ1:refs/heads/mutqan1" "$D_MQP:refs/heads/plugin-dist-mutqan" pub-mutqan:refs/heads/mutqan pub-oxygen:refs/heads/main pub-oxygen:refs/heads/oxygen
  git push -q mx pub-mutqan:refs/heads/mutqan pub-oxygen:refs/heads/oxygen
  echo pushed
fi
