#!/usr/bin/env bash
# The WhatsApp gateway on a free always-on Linux server (Oracle Cloud Always Free, Google Cloud e2-micro…).
# One command, as root, on a fresh Ubuntu 22.04/24.04 (ARM or x86):
#
#   curl -fsSL https://raw.githubusercontent.com/alizizo888-code/alizeidan123/wa-gateway/setup-vps.sh | sudo bash
#
# Nothing has to reach the server: it asks the site for work (pull mode) and pushes messages to it, so
# no domain, no open port, no tunnel. Every SIM in SESSIONS opens by itself and its QR / pairing code
# shows up in the desk (صندوق الواتساب ← الأرقام) and in:  journalctl -u wa-gateway -f
#
# Can run unattended:  WEBHOOK_SECRET=… SITE=https://oxygen11.com SESSIONS="sim01:حراج:haraj,…" bash setup-vps.sh
# Re-running it updates the code and keeps the linked numbers.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "شغّله بـ sudo"; exit 1; }

REPO=${REPO:-https://github.com/alizizo888-code/alizeidan123.git}
BRANCH=${BRANCH:-wa-gateway}
APP=/opt/wa-gateway
DATA=/var/lib/wa-gateway
ENVF=/etc/wa-gateway.env
ask() { local v; read -r -p "$1" v < /dev/tty || true; echo "$v"; }

echo "== 1/6 الحزم =="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl ca-certificates cron >/dev/null
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
echo "node $(node -v)"

echo "== 2/6 ذاكرة احتياطية (swap) للأجهزة الصغيرة =="
MEM=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$MEM" -lt 3000 ] && ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap -q /swapfile && swapon /swapfile
  grep -q /swapfile /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "swap 2G added (RAM ${MEM}MB)"
fi

echo "== 3/6 الكود =="
id wa >/dev/null 2>&1 || useradd --system --home "$DATA" --shell /usr/sbin/nologin wa
SRC=/opt/wa-src
if [ -d "$SRC/.git" ]; then git -C "$SRC" fetch -q --depth 1 origin "$BRANCH" && git -C "$SRC" reset -q --hard FETCH_HEAD
else rm -rf "$SRC"; git clone -q --depth 1 -b "$BRANCH" "$REPO" "$SRC"; fi
# The gateway lives at the root of the wa-gateway branch, and in wa-gateway/ on the others.
G="$SRC"; [ -f "$SRC/wa-gateway/src/index.js" ] && G="$SRC/wa-gateway"
rm -rf "$APP" && mkdir -p "$APP" && cp -r "$G"/package*.json "$G"/src "$APP"/
(cd "$APP" && npm ci --omit=dev --no-audit --no-fund >/dev/null)
mkdir -p "$DATA" && chown -R wa:wa "$DATA"

echo "== 4/6 الإعدادات =="
if [ ! -f "$ENVF" ]; then
  SITE=${SITE:-}; [ -n "$SITE" ] || SITE=$(ask "رابط الموقع (Enter = https://oxygen11.com): "); SITE=${SITE:-https://oxygen11.com}
  WEBHOOK_SECRET=${WEBHOOK_SECRET:-}
  [ -n "$WEBHOOK_SECRET" ] || WEBHOOK_SECRET=$(ask "المفتاح السري (الديسك ← صندوق الواتساب ← الأرقام ← إعداد الوسيط): ")
  [ -n "$WEBHOOK_SECRET" ] || { echo "المفتاح السري مطلوب"; exit 1; }
  if [ -z "${SESSIONS:-}" ]; then
    echo "الشرائح: اكتب كل شريحة كده  id:الاسم:القناة  (القنوات: ads map tiktok haraj site)، وافصل بفاصلة."
    echo "مثال: sim01:إعلانات 1:ads,sim02:ماب الرياض:map,sim03:تيك توك:tiktok,sim04:حراج:haraj"
    SESSIONS=$(ask "الشرائح (Enter = 12 شريحة باسماء sim01..sim12 تعدّلها بعدين): ")
    [ -n "$SESSIONS" ] || SESSIONS=$(for i in $(seq -w 1 12); do printf 'sim%s:شريحة %s:,' "$i" "$i"; done | sed 's/,$//')
  fi
  TOKEN=$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
  cat > "$ENVF" <<ENV
GATEWAY_TOKEN=$TOKEN
WEBHOOK_URL=${SITE%/}/wp-json/mutqan/v1/wa-hook
WEBHOOK_SECRET=$WEBHOOK_SECRET
DATA_DIR=$DATA
HOST=127.0.0.1
PORT=8787
SEND_DAILY_CAP=150
MAX_SESSIONS=20
PULL_EVERY_MS=3000
PULL_IDLE_MS=10000
SESSIONS=$SESSIONS
ENV
  chmod 600 "$ENVF"
else
  echo "الإعدادات موجودة: $ENVF (عدّلها بـ nano ثم: systemctl restart wa-gateway)"
fi

echo "== 5/6 خدمة دايمة (ترجع لوحدها لو وقعت أو السيرفر عمل ريستارت) =="
cat > /etc/systemd/system/wa-gateway.service <<'UNIT'
[Unit]
Description=Mutqan WhatsApp gateway (multi-number)
After=network-online.target
Wants=network-online.target
[Service]
User=wa
EnvironmentFile=/etc/wa-gateway.env
WorkingDirectory=/opt/wa-gateway
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=full
ReadWritePaths=/var/lib/wa-gateway
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable -q wa-gateway
systemctl restart wa-gateway

echo "== 6/6 نسخة احتياطية يومية للجلسات (آخر 7 أيام) + أمر تحديث =="
cat > /etc/cron.daily/wa-gateway-backup <<'BK'
#!/bin/sh
mkdir -p /var/backups/wa-gateway
tar -czf /var/backups/wa-gateway/data-$(date +%u).tgz -C /var/lib wa-gateway 2>/dev/null
BK
chmod +x /etc/cron.daily/wa-gateway-backup
cat > /usr/local/bin/wa-update <<UPD
#!/bin/sh
curl -fsSL https://raw.githubusercontent.com/alizizo888-code/alizeidan123/$BRANCH/setup-vps.sh | REPO=$REPO BRANCH=$BRANCH bash
UPD
chmod +x /usr/local/bin/wa-update

sleep 4
systemctl --no-pager --lines=0 status wa-gateway | head -3
cat <<'DONE'

==========================================================
 ✅ الوسيط شغال 24 ساعة.
 • افتح الديسك ← صندوق الواتساب ← الأرقام: هتلاقي QR لكل شريحة.
   امسحه من موبايل الشريحة: واتساب ← الأجهزة المرتبطة ← ربط جهاز.
 • أو شوف كود الربط هنا:   journalctl -u wa-gateway -f
 • الحالة:                 systemctl status wa-gateway
 • تعديل الشرائح:          nano /etc/wa-gateway.env  ثم  systemctl restart wa-gateway
 • تحديث الكود:            sudo wa-update
==========================================================
DONE
