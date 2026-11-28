#!/usr/bin/env bash
# The whole chain, end to end, through the real screens: customer → operations → technician → owner.
#   tests/e2e/run.sh            runs it and leaves screenshots in tests/e2e/out/
set -euo pipefail
cd "$(dirname "$0")"
export MQ_DATA="$PWD/out/data"
export MQ_DESK=1
rm -rf out; mkdir -p out/shots "$MQ_DATA"
php seed.php >/dev/null
# A server left over on this port (an earlier run killed mid-way) answers instead of ours, and every
# check after that is about the wrong data — that produced a "failure" in login and WhatsApp orders
# that had nothing to do with the code. So a busy port is a stop, not a warning.
if (exec 3<>/dev/tcp/127.0.0.1/8099) 2>/dev/null; then echo "port 8099 is already in use — stop that server first (pkill -f 'php -S 127.0.0.1:8099')" >&2; exit 1; fi
(cd ../mutqan && exec php -S 127.0.0.1:8099 router.php >"$OLDPWD/out/server.log" 2>&1) &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 1.5
node "${1:-flow.cjs}"   # tests/e2e/run.sh full.cjs — Ali's full scenario
