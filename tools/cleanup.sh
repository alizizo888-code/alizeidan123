#!/usr/bin/env bash
# Remove the branches nothing deploys from, after making sure they can come back.
#
# Every branch here was last touched between 29 October and 3 November and nothing on any server
# pulls from it. The live ones are listed below and are never touched.
#
#   tools/cleanup.sh            list what would go, delete nothing
#   tools/cleanup.sh --delete   take a snapshot, verify it, then delete
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

# What the servers actually pull from. Deleting one of these takes a site down.
# Checked against every repository hPanel lists for oxygen11.com (hosting_git_list-website-repositories):
# desk-dist feeds desk/, mutqan-plugin feeds plugins/mutqan, wa-gateway is what the phone clones.
# desk-dist was on the delete list below until 2026-11-11 — running this would have taken the desk down.
KEEP_DEPLOY=(oxygen-dist plugin-dist-mutqan oxygen-master main desk-dist mutqan-plugin wa-gateway)
KEEP_ORIGIN=(mutqan last-good)

DEAD_DEPLOY=(blogger-queue desk-dist-r2 mutqan mutqan1 mutqan-app-only
             mutqan-original-look oxygen oxygen-site plugin-dist)
DEAD_ORIGIN=(main oxygen oxygen-ac-plugin)
DEAD_OXY=(feature/mutqan-app google main mutqan mutqan-work oxygen plugin-dist plugin-dist-mutqan متقن)

for b in "${DEAD_DEPLOY[@]}"; do
  for k in "${KEEP_DEPLOY[@]}"; do [ "$b" = "$k" ] && { echo "refusing: $b is deployed"; exit 1; }; done
done
# A list typed by hand is how desk-dist got onto it. Before deleting anything, ask the host itself
# which branches it pulls (needs HOSTINGER_API_TOKEN; without it, the hand list above is all there is).
if [ -n "${HOSTINGER_API_TOKEN:-}" ] && [ "${1:-}" = "--delete" ]; then
  LIVE=$(curl -fsS -H "Authorization: Bearer $HOSTINGER_API_TOKEN" \
    "https://developers.hostinger.com/api/hosting/v1/accounts/u283616892/websites/oxygen11.com/git/repositories" 2>/dev/null \
    | grep -o '"branch":"[^"]*"' | cut -d'"' -f4 | sort -u || true)
  for b in "${DEAD_DEPLOY[@]}"; do
    if printf '%s\n' $LIVE | grep -qx "$b"; then echo "refusing: hPanel says a site pulls $b"; exit 1; fi
  done
fi

show() {
  echo "alizeidan123 — ${#DEAD_DEPLOY[@]} فرع:"; printf '    %s\n' "${DEAD_DEPLOY[@]}"
  echo "mutaqin-oxygen-app — ${#DEAD_ORIGIN[@]} فرع:"; printf '    %s\n' "${DEAD_ORIGIN[@]}"
  echo "oxy — ${#DEAD_OXY[@]} فرع (المستودع كله ميت):"; printf '    %s\n' "${DEAD_OXY[@]}"
  echo
  echo "يفضل شغّالاً: ${KEEP_DEPLOY[*]} · ${KEEP_ORIGIN[*]}"
}

if [ "${1:-}" != "--delete" ]; then show; echo; echo "(مفيش حاجة اتمسحت — ضيف --delete)"; exit 0; fi

show
SNAP=$(bash tools/backup.sh "قبل مسح الفروع الميتة" 2>&1 | tee /dev/stderr | grep -o 'backups/[0-9-]*' | tail -1)
[ -n "$SNAP" ] || { echo "SNAPSHOT FAILED — nothing deleted"; exit 1; }

# A snapshot nobody has restored from is a promise, not a backup. One branch is brought back and
# checked against the live ref before anything is removed.
PROBE=${DEAD_DEPLOY[0]}
WANT=$(git rev-parse "deploy/$PROBE" 2>/dev/null || true)
git branch -D "restored/$PROBE" >/dev/null 2>&1 || true
bash tools/backup.sh --restore "$SNAP" "$PROBE" >/dev/null
GOT=$(git rev-parse "restored/$PROBE" 2>/dev/null || true)
git branch -D "restored/$PROBE" >/dev/null 2>&1 || true
[ -n "$GOT" ] && [ "$GOT" = "$WANT" ] || { echo "RESTORE CHECK FAILED ($PROBE) — nothing deleted"; exit 1; }
echo "snapshot $SNAP verified by restoring $PROBE"

del() { local r=$1; shift; for b in "$@"; do
  if git push -q "$r" --delete "$b" 2>/dev/null; then echo "  حُذف $r:$b"; else echo "  !! تعذّر $r:$b"; fi
done; }
git remote get-url oxy >/dev/null 2>&1 || git remote add oxy https://github.com/alizizo888-code/oxy.git
del deploy "${DEAD_DEPLOY[@]}"
del origin "${DEAD_ORIGIN[@]}"
del oxy    "${DEAD_OXY[@]}"
echo
echo "تم. أي فرع يرجع بـ: tools/backup.sh --restore $SNAP <اسم-الفرع>"
