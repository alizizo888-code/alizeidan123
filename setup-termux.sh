#!/data/data/com.termux/files/usr/bin/bash
# One command on the phone that carries the work WhatsApp:
#   curl -sL https://raw.githubusercontent.com/alizizo888-code/alizeidan123/wa-gateway/setup-termux.sh | bash
# Installs the gateway, asks once for the secret shown in the desk, then starts it and prints a pairing code
# to type into WhatsApp on this same phone (no second phone needed to scan a QR).
set -e
echo "== تثبيت الوسيط =="
pkg update -y >/dev/null 2>&1 || true
pkg install -y nodejs-lts git >/dev/null
cd "$HOME"
if [ -d wa-gateway/.git ]; then (cd wa-gateway && git pull -q); else git clone -q -b wa-gateway https://github.com/alizizo888-code/alizeidan123.git wa-gateway; fi
cd wa-gateway
npm ci --omit=dev --no-audit --no-fund >/dev/null
if [ ! -f .env ]; then
  echo
  echo "انسخ «المفتاح السري» من الديسك ← صندوق الواتساب ← الأرقام ← إعداد الوسيط، والصقه هنا:"
  read -r SECRET < /dev/tty
  echo "رقم واتساب الشغل اللي على الموبايل ده (Enter = 0536554731):"
  read -r PHONE < /dev/tty
  PHONE=${PHONE:-0536554731}
  TOKEN=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
  cat > .env <<ENV
GATEWAY_TOKEN=$TOKEN
WEBHOOK_URL=https://oxygen11.com/wp-json/mutqan/v1/wa-hook
WEBHOOK_SECRET=$SECRET
DATA_DIR=./data
SEND_DAILY_CAP=200
PAIR_PHONE=$PHONE
SESSION_LABEL=واتساب الشغل
ENV
fi
cat > "$HOME/wa.sh" <<'RUN'
cd "$HOME/wa-gateway" && termux-wake-lock 2>/dev/null; set -a && . ./.env && set +a && exec node src/index.js
RUN
chmod +x "$HOME/wa.sh"
echo
echo "== بيشتغل… هيطلعلك «كود الربط» تحت. بعد كده تشغّله تاني بالأمر:  bash ~/wa.sh =="
echo "مهم: إعدادات أندرويد ← التطبيقات ← Termux ← البطارية ← غير مقيّد"
bash "$HOME/wa.sh"
