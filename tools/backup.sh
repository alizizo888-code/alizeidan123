#!/usr/bin/env bash
# A snapshot you can actually come back from.
#
# Deleting a branch does not delete its commits: they survive for as long as some ref can reach
# them. So a snapshot here is ONE commit whose parents are the heads of every branch of every
# repository, carrying a readable manifest as its tree. One ref keeps every object alive, and
# `git branch x <snapshot>^<n>` brings any of them back exactly as it was — which a copied folder
# cannot promise. (Tags would say the same thing more plainly, but this environment only permits
# writing branches, so the parents carry the guarantee instead.)
#
#   tools/backup.sh "<why>"                      take one now
#   tools/backup.sh --list                       what snapshots exist
#   tools/backup.sh --restore <snapshot> <branch>   bring one branch back
#
# Four moments are covered, which is the whole point of having this at all:
#   1. before anything is deleted or rewritten   — by hand, or by any script that destroys
#   2. with every release                        — tools/release.sh calls this
#   3. every 15 days                             — .github/workflows/backup.yml
#   4. the last build that actually worked       — the moving branch `last-good`, set by
#      release.sh once its tests pass, so there is always a known-good point to fall back to
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

REPOS=("origin|https://github.com/alizizo888-code/mutaqin-oxygen-app"
       "deploy|https://github.com/alizizo888-code/alizeidan123.git"
       "oxy|https://github.com/alizizo888-code/oxy.git")

ensure_remotes() {
  for spec in "${REPOS[@]}"; do
    local r=${spec%%|*} u=${spec#*|}
    git remote get-url "$r" >/dev/null 2>&1 || git remote add "$r" "$u"
  done
}

if [ "${1:-}" = "--list" ]; then
  ensure_remotes; git fetch -q origin '+refs/heads/backups/*:refs/remotes/origin/backups/*' 2>/dev/null || true
  git for-each-ref --sort=-creatordate --format='%(refname:short)   %(creatordate:short)   %(subject)' 'refs/remotes/origin/backups/*'
  echo; echo "last-good: $(git for-each-ref --format='%(objectname:short) %(creatordate:short)' refs/remotes/origin/last-good || echo 'not set yet')"
  exit 0
fi

if [ "${1:-}" = "--restore" ]; then
  SNAP=${2:?usage: tools/backup.sh --restore <snapshot> <branch-to-restore>}
  WANT=${3:?usage: tools/backup.sh --restore <snapshot> <branch-to-restore>}
  ensure_remotes; git fetch -q origin "+refs/heads/$SNAP:refs/remotes/origin/$SNAP"
  SHA=$(git show "origin/$SNAP:MANIFEST.tsv" | awk -v b="$WANT" -F'\t' '$2==b{print $3; exit}')
  [ -n "$SHA" ] || { echo "no branch named $WANT in $SNAP — see: git show origin/$SNAP:MANIFEST.tsv"; exit 1; }
  git branch "restored/$WANT" "$SHA"
  echo "restored/$WANT now holds $SHA (from $SNAP). Look at it before pushing it anywhere."
  exit 0
fi

WHY=${1:-"routine snapshot"}
STAMP=$(date -u +%Y%m%d-%H%M)
ensure_remotes
echo "snapshot $STAMP — $WHY"

W=$(mktemp -d); PARENTS=(); ROWS=0
{ echo "# نسخة احتياطية — $STAMP"; echo; echo "السبب: $WHY"; echo "الوقت: $(date -u +%FT%TZ) UTC"; echo
  echo "استرجاع فرع:"; echo '```'; echo "tools/backup.sh --restore backups/$STAMP <اسم-الفرع>"; echo '```'; echo
  echo "| المستودع | الفرع | الكوميت |"; echo "|---|---|---|"; } > "$W/README.md"
: > "$W/MANIFEST.tsv"

for spec in "${REPOS[@]}"; do
  r=${spec%%|*}
  # A repository that cannot be reached is written down as such, never skipped in silence: a
  # backup that quietly missed something is worse than no backup at all.
  if ! git fetch -q "$r" '+refs/heads/*:refs/remotes/'"$r"'/*' 2>/dev/null; then
    echo "  !! $r unreachable — NOT in this snapshot" >&2
    echo "| $r | — | **تعذّر الوصول** |" >> "$W/README.md"
    continue
  fi
  n=0
  while read -r sha ref; do
    b=${ref#refs/remotes/$r/}
    [ -n "$sha" ] && [ "$b" != HEAD ] || continue
    case "$b" in backups/*|last-good) continue;; esac      # snapshots of snapshots say nothing
    PARENTS+=(-p "$sha"); n=$((n+1)); ROWS=$((ROWS+1))
    printf '%s\t%s\t%s\n' "$r" "$b" "$sha" >> "$W/MANIFEST.tsv"
    echo "| $r | \`$b\` | \`${sha:0:9}\` |" >> "$W/README.md"
  done < <(git for-each-ref --format='%(objectname) %(refname)' "refs/remotes/$r/")
  echo "  $r: $n branches"
done
[ "$ROWS" -gt 0 ] || { echo "nothing reachable to back up — refusing to record an empty snapshot" >&2; exit 1; }

IDX=$(mktemp -u)
GIT_INDEX_FILE=$IDX git --work-tree="$W" read-tree --empty
GIT_INDEX_FILE=$IDX git --work-tree="$W" add -A .
TREE=$(GIT_INDEX_FILE=$IDX git write-tree)
# Every branch head is a parent, so one ref keeps every object in the snapshot alive.
SNAP=$(git commit-tree "$TREE" "${PARENTS[@]}" -m "snapshot $STAMP: $WHY")
git push -q origin "$SNAP:refs/heads/backups/$STAMP"
echo "  $ROWS branches held by backups/$STAMP"
