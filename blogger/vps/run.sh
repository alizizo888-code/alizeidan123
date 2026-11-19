#!/usr/bin/env bash
# One server-side run (called by /etc/cron.d/oxygen-blogger every hour): missing pages first, then articles. No browser involved.
#   run.sh            publish today's share (≤15 posts, stops for the day on a Blogger block)
#   run.sh status     print today's target and counts only
# pull → pages.js (≤5) → daily.js publish (rest of the 15 writes/hour) → report → commit + push ledger.
set -uo pipefail
DIR=/opt/oxygen-blogger
cd "$DIR" || exit 1
set -a; . /etc/oxygen-blogger.env; set +a
unset BLOGGER_ACCESS_TOKEN
echo "=== $(TZ=Asia/Riyadh date '+%F %T Riyadh') ==="
git checkout -q -- . 2>/dev/null   # the server keeps no local edits (make_queue rewrites queue.json each run)
git pull -q --rebase origin mutqan || { git rebase --abort 2>/dev/null; git reset -q --hard origin/mutqan; }

if [ -z "${BLOGGER_REFRESH_TOKEN:-}" ]; then echo "skip: BLOGGER_* not set (re-run setup.sh)"; exit 0; fi
MODE=${1:-publish}; COUNT=${2:-}
if [ "$MODE" = publish ] && [ -z "$COUNT" ]; then
  # ≤15 Blogger writes per hour in total: pages first (up to 5 while any are missing), the rest goes to articles
  P=$(node blogger/api/pages.js 5 2>&1 | tail -1); echo "pages: $P"
  NP=$(printf '%s' "$P" | sed -n 's/.*"pages":\([0-9]*\).*/\1/p'); NP=${NP:-0}
  if printf '%s' "$P" | grep -q '"blocked":true'; then echo "Blogger block on pages — no articles this hour"; COUNT=skip; else export MAX_WRITES=$((15 - NP)); fi
fi
if [ "$COUNT" = skip ]; then RC=0; else
node blogger/api/daily.js "$MODE" $COUNT; RC=$?
fi
[ "$MODE" = status ] && exit $RC
node blogger/api/report.js > /dev/null || true

git checkout -q -- blogger/content/queue.json 2>/dev/null
git add blogger/state/published.json blogger/state/DASHBOARD.md blogger/state/reports
if ! git diff --cached --quiet; then
  git commit -q -m "VPS Blogger run: ledger update"
  git checkout -q -- . 2>/dev/null
  for i in 1 2 3; do git pull -q --rebase origin mutqan && git push -q origin mutqan && break; sleep 20; done
fi
exit $RC
