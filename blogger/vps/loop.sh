#!/usr/bin/env bash
# Blogger network on the VPS as a Docker project («oxygen-blogger», Hostinger Docker Manager) — no terminal, no cron file.
# The code comes from the public deploy branch «blogger-vps» of alizizo888-code/alizeidan123 (no secrets in it);
# the keys come from the project's environment (BLOGGER_CLIENT_ID / BLOGGER_CLIENT_SECRET / BLOGGER_REFRESH_TOKEN,
# optional GEMINI_API_KEY / GROQ_API_KEY). The ledger lives in the volume (/data), so a code update never loses it.
# Every hour at :17 UTC: pull → pages (≤5) → articles (rest of the 15 writes/hour) → report. Same rules as run.sh.
set -uo pipefail
REPO=${CODE_REPO:-https://github.com/alizizo888-code/alizeidan123.git}
BR=${CODE_BRANCH:-blogger-vps}
APP=/app; DATA=/data
mkdir -p "$DATA/reports"
log() { echo "[$(date -u '+%F %T')Z] $*"; }

pull() {
  if [ ! -d "$APP/.git" ]; then git clone -q --depth 1 -b "$BR" "$REPO" "$APP" || return 1
  else git -C "$APP" fetch -q --depth 1 origin "$BR" && git -C "$APP" reset -q --hard "origin/$BR" || return 1; fi
  # the volume's ledger wins over the one in the code (first start: seed it from the code)
  [ -f "$DATA/published.json" ] || cp "$APP/blogger/state/published.json" "$DATA/published.json"
  cp "$DATA/published.json" "$APP/blogger/state/published.json"
}

once() {
  pull || { log "pull failed"; return 1; }
  cd "$APP" || return 1
  if [ -z "${BLOGGER_REFRESH_TOKEN:-}" ]; then log "skip: BLOGGER_* not set"; return 0; fi
  local MODE=${1:-publish} P NP RC=0
  if [ "$MODE" = status ]; then node blogger/api/daily.js status; return $?; fi
  P=$(node blogger/api/pages.js 5 2>&1 | tail -1); log "pages: $P"
  NP=$(printf '%s' "$P" | sed -n 's/.*"pages":\([0-9]*\).*/\1/p'); NP=${NP:-0}
  if printf '%s' "$P" | grep -q '"blocked":true'; then log "Blogger block on pages — no articles this hour"
  else MAX_WRITES=$((15 - NP)) node blogger/api/daily.js publish; RC=$?; fi
  # 2 old posts an hour get «&src=<title>» on their app links (the order then names the article it came from)
  [ "$RC" = 0 ] && node blogger/api/daily.js retag 2 > /dev/null 2>&1
  cp "$APP/blogger/state/published.json" "$DATA/published.json"
  node blogger/api/report.js > /dev/null 2>&1 && cp -r "$APP/blogger/state/reports/." "$DATA/reports/" 2>/dev/null
  cp "$APP/blogger/state/DASHBOARD.md" "$DATA/" 2>/dev/null
  # once a day (the 03:17 UTC run): Search Console — submit missing / failed sitemaps of every blog and WordPress site
  if [ "$(date -u +%H)" = "03" ] || [ ! -f "$DATA/gsc.json" ]; then
    node blogger/api/gsc.js > "$DATA/gsc.log" 2>&1 && cp blogger/state/gsc.json "$DATA/gsc.json" 2>/dev/null; log "gsc: $(tail -c 300 "$DATA/gsc.log")"
  fi
  log "run done (exit $RC)"
  return $RC
}

case "${1:-loop}" in
  once) once publish ;;
  status) once status ;;
  loop)
    log "oxygen-blogger started; status check:"; once status || true
    while true; do
      m=$(date -u +%-M); s=$(( ((77 - m) % 60) * 60 )); [ "$s" -eq 0 ] && s=3600
      sleep "$s"
      once publish || true
    done ;;
esac
