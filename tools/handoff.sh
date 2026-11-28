#!/usr/bin/env bash
# What happened while I was away: every commit on mutqan since the commit this account last reviewed (HANDOFF.md
# «آخر مراجعة»), grouped into the other account's work vs. the robots (health snapshots, ledgers, reports).
#   tools/handoff.sh A        → since account A's last review      (A = الحساب الأصلي, B = الحساب التاني)
#   tools/handoff.sh <sha>    → since that commit
# Then: read it, and update «آخر مراجعة» for your account in HANDOFF.md with the sha it prints at the end.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
git fetch -q --depth=400 origin mutqan 2>/dev/null || true
ARG=${1:-B}
if [[ "$ARG" =~ ^[AB]$ ]]; then
  SHA=$(sed -n "s/^| *$ARG *|[^|]*| *\`\([0-9a-f]\{7,40\}\)\`.*/\1/p" HANDOFF.md | head -1)
  [ -n "$SHA" ] || { echo "no «آخر مراجعة» sha for account $ARG in HANDOFF.md"; exit 1; }
else SHA=$ARG; fi
BOT='health snapshot|Network review|ledger|Blogger (API|pass|run)|blogger pass|VPS Blogger|Uptime|report|state:'
echo "== منذ $SHA =="
echo "-- شغل (حسابات Claude / علي):"
git log --format='%h %ad %an | %s' --date=format:'%m-%d %H:%M' "$SHA..origin/mutqan" | grep -Ev "$BOT" || echo "   (مفيش)"
echo "-- تشغيل آلي (عدد): $(git log --format='%s' "$SHA..origin/mutqan" | grep -Ec "$BOT" || true)"
echo "-- ملفات اتغيرت (من غير الحالة والتقارير):"
git diff --stat "$SHA" origin/mutqan -- . ':!network/state' ':!blogger/state' ':!oxygen-ac-automation/app' | tail -25
echo "== آخر commit دلوقتي: $(git rev-parse --short origin/mutqan) — حطه في «آخر مراجعة» لحسابك =="
