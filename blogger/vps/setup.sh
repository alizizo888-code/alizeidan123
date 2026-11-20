#!/usr/bin/env bash
# One-time setup of the Blogger network automation on the owner's VPS (Ubuntu 24.04, vps.oxygen11.com).
# No browser, no phone, no computer: everything runs on the server through the Blogger API v3.
#
# Run as root in the Hostinger browser terminal (hPanel → VPS → Browser terminal):
#   curl -fsSL https://raw.githubusercontent.com/alizizo888-code/mutaqin-oxygen-app/mutqan/blogger/vps/setup.sh -o /root/oxy-setup.sh && bash /root/oxy-setup.sh
# The script asks for 4 values (nothing is echoed or committed):
#   GITHUB_TOKEN           fine-grained token, repo alizizo888-code/mutaqin-oxygen-app, Contents: Read and write
#   BLOGGER_CLIENT_ID      ┐
#   BLOGGER_CLIENT_SECRET  ├ from blogger/api/SETUP.md steps 1-4 (Google Cloud + OAuth Playground)
#   BLOGGER_REFRESH_TOKEN  ┘
# Optional (Enter to skip): GEMINI_API_KEY, GROQ_API_KEY, OPENROUTER_API_KEY
# Re-running is safe: it keeps existing values unless you type new ones.
set -euo pipefail
DIR=/opt/oxygen-blogger
ENV=/etc/oxygen-blogger.env
REPO=alizizo888-code/mutaqin-oxygen-app

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 1; }
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl ca-certificates cron util-linux >/dev/null
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
echo "node $(node -v)"

touch "$ENV"; chmod 600 "$ENV"
ask() { # ask NAME secret(1/0)
  local cur; cur=$(grep -E "^$1=" "$ENV" | cut -d= -f2- || true)
  local hint=""; [ -n "$cur" ] && hint=" (Enter = keep current)"
  local v; read -r -s -p "$1$hint: " v; echo
  [ -z "$v" ] && v="$cur"
  sed -i "/^$1=/d" "$ENV"; [ -n "$v" ] && echo "$1=$v" >> "$ENV"
  return 0
}
for k in GITHUB_TOKEN BLOGGER_CLIENT_ID BLOGGER_CLIENT_SECRET BLOGGER_REFRESH_TOKEN GEMINI_API_KEY GROQ_API_KEY OPENROUTER_API_KEY MISTRAL_API_KEY; do ask "$k"; done
set -a; . "$ENV"; set +a
[ -n "${GITHUB_TOKEN:-}" ] || { echo "GITHUB_TOKEN is required"; exit 1; }

if [ ! -d "$DIR/.git" ]; then
  git clone -q --branch mutqan --single-branch "https://x-access-token:${GITHUB_TOKEN}@github.com/${REPO}.git" "$DIR"
else
  git -C "$DIR" remote set-url origin "https://x-access-token:${GITHUB_TOKEN}@github.com/${REPO}.git"
  git -C "$DIR" pull -q --rebase origin mutqan
fi
git -C "$DIR" config user.name "oxygen-vps"
git -C "$DIR" config user.email "noreply@users.noreply.github.com"
chmod +x "$DIR/blogger/vps/run.sh"

# Check the Blogger key now (no writes): prints today's target and counts.
if [ -n "${BLOGGER_REFRESH_TOKEN:-}" ]; then
  (cd "$DIR" && node blogger/api/daily.js status) || echo "WARNING: status check failed — re-check the 3 BLOGGER_* values"
else
  echo "NOTE: BLOGGER_* values missing — runs will skip until you re-run this script with them."
fi

# Every hour at :17 (owner order 2026-11-17: higher volume; still ≤15 Blogger writes per hour).
cat > /etc/cron.d/oxygen-blogger <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 * * * * root flock -n /run/oxygen-blogger.lock /opt/oxygen-blogger/blogger/vps/run.sh >> /var/log/oxygen-blogger.log 2>&1
CRON
chmod 644 /etc/cron.d/oxygen-blogger
systemctl enable --now cron >/dev/null 2>&1 || true
timedatectl set-timezone UTC 2>/dev/null || true
echo "Done. Log: /var/log/oxygen-blogger.log   Manual run: /opt/oxygen-blogger/blogger/vps/run.sh"
