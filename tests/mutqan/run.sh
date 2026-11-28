#!/usr/bin/env bash
# Integration tests for the Mutqan backend: a sqlite-backed WordPress stand-in + php -S + HTTP tests.
# Usage: tests/mutqan/run.sh   (needs php with pdo_sqlite, node >= 18)
set -euo pipefail
cd "$(dirname "$0")"
# Ask PHP where the shim will actually look. Guessing it from TMPDIR means that when the two
# disagree the stale database from an earlier run survives, and the suite reports failures that
# have nothing to do with the code.
DATA="$(php -r 'echo getenv("MQ_DATA") ?: sys_get_temp_dir() . "/mutqan-test-data";')"
rm -rf "$DATA"
php setup_owner.php 'Test-Only-Pw-1234' >/dev/null
php cfg_ai.php >/dev/null
# Several free tiers stitched into one service that does not run out.
php providers.php
# A server left over on this port (an earlier run killed mid-way) answers instead of ours, and every
# check after that is about the wrong data — that produced a "failure" in login and WhatsApp orders
# that had nothing to do with the code. So a busy port is a stop, not a warning.
if (exec 3<>/dev/tcp/127.0.0.1/8099) 2>/dev/null; then echo "port 8099 is already in use — stop that server first (pkill -f 'php -S 127.0.0.1:8099')" >&2; exit 1; fi
php -S 127.0.0.1:8099 router.php >/dev/null 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 1
php ac_floor.php
php oxchat.php
php promo.php
php inhouse.php
php wa.php
# One WhatsApp number, three people on the other end: technician, team, customer.
MQ_DATA="$(mktemp -d)" php wa_desk.php
# The phone collects what it has to send: a handset has no address to be called on.
MQ_DATA="$(mktemp -d)" php wa_outbox.php
# Every order on the map, filled in the background.
MQ_DATA="$(mktemp -d)" php geo.php
# Where the work is, and where it has been.
MQ_DATA="$(mktemp -d)" php track.php
# The whole operation in one small answer.
MQ_DATA="$(mktemp -d)" php live.php
# The operations manager: read-only answers, rule alerts, positions, money.
MQ_DATA="$(mktemp -d)" php ops_manager.php
# The internal engine: owner commands without any AI provider, the points wallet.
MQ_DATA="$(mktemp -d)" php quick.php
# «ربط بلمسة»: one field → QR on screen, tap a number → fresh QR, WhatsApp commands.
MQ_DATA="$(mktemp -d)" php wa_link.php
# The installed apps: working downloads, WhatsApp notifications from team phones, server-side «الفني قريب».
MQ_DATA="$(mktemp -d)" php native.php
# The live map: codes, the day's road, speed/ETA, jobs on the way, nothing kept off shift.
MQ_DATA="$(mktemp -d)" php route.php
# «مدرّب متقن»: the artifact bridge (knowledge, learned answers, standing instructions, commands, tickets).
MQ_DATA="$(mktemp -d)" php coach.php
# «السنترال»: identity card, master digest + commands, n8n in/out, VPS installer.
MQ_DATA="$(mktemp -d)" php wa_central.php
MQ_DATA="$(mktemp -d)" php wa_journey.php
MQ_DATA="$(mktemp -d)" php customers.php
MQ_DATA="$(mktemp -d)" php wa_ai.php
MQ_DATA="$(mktemp -d)" php chatwoot.php
MQ_DATA="$(mktemp -d)" php sim_one_line.php
MQ_DATA="$(mktemp -d)" php sim_scenarios.php
# «مركز الواتساب» on the VPS: the site only reports and answers; the hub talks.
MQ_DATA="$(mktemp -d)" php hub.php
node --no-warnings ../../wa-hub/test/brain.test.mjs
node --no-warnings ../../wa-hub/test/revoke.test.mjs
node --no-warnings ../../wa-hub/test/chatwoot.test.mjs
MQ_DATA="$(mktemp -d)" php wa_mirror.php
# Nothing depends on the owner's computer: Blogger key via GitHub identity, VPS gateway enrolled over the API.
MQ_DATA="$(mktemp -d)" php server_auto.php
# One-device activation links for the technician and operations apps.
MQ_DATA="$(mktemp -d)" php devices.php
# All 22 sites in one operations room; linking a new site from the owner app.
MQ_DATA="$(mktemp -d)" php network_link.php
# What the rule bot learns from the model, for when the model is away.
MQ_DATA="$(mktemp -d)" php brain.php
# The assistant in the order chat: answers, remembers, hands over.
MQ_DATA="$(mktemp -d)" php order_ai.php
# A busy week must never hide the newest alert from the phones.
MQ_DATA="$(mktemp -d)" php push_inbox.php
# Real background notifications from this server alone.
MQ_DATA="$(mktemp -d)" php webpush.php
MQ_DATA="$(mktemp -d)" php webpush_apps.php
MQ_DATA="$(mktemp -d)" php backup.php
MQ_DATA="$(mktemp -d)" php teamgeo.php
MQ_DATA="$(mktemp -d)" php dispatch_tell.php
MQ_DATA="$(mktemp -d)" php defaults_on.php
MQ_DATA="$(mktemp -d)" php relay.php
# Calls between customer, technician and operations: who may ring whom, and operations' leave.
MQ_DATA="$(mktemp -d)" php callnet.php
# The owner's own settings, handed to the model one question at a time.
MQ_DATA="$(mktemp -d)" php kb.php
# The price at the end of a job — found by walking one real order through the app.
MQ_DATA="$(mktemp -d)" php final_price.php
# The sales brain: who the customer is, what the day looks like, the plan for one reply.
MQ_DATA="$(mktemp -d)" php sales.php
# The order assistant: answers in the order chat, asks the technician, relays to him and to the room.
MQ_DATA="$(mktemp -d)" php orderbot.php
# WhatsApp devices: every desk device carries its own numbers, every number has a job.
MQ_DATA="$(mktemp -d)" php wa_fleet.php
# Automatic dispatch on the server, with no browser open.
MQ_DATA="$(mktemp -d)" php dispatch.php
# Follow-up watch, coverage areas, working hours, standby technician, auto-transfer (owner, 2026-11-23).
MQ_DATA="$(mktemp -d)" php watch.php
# «طرق الدفع» link of one order (idea 25).
MQ_DATA="$(mktemp -d)" php paychoice.php
# «جلب العملاء»: Google review, seasonal reminder, contracts, referral (ideas 16–21).
MQ_DATA="$(mktemp -d)" php growth.php
MQ_DATA="$(mktemp -d)" php schedule.php
MQ_DATA="$(mktemp -d)" php field_buttons.php
MQ_DATA="$(mktemp -d)" php techid.php
MQ_DATA="$(mktemp -d)" php maintenance.php
MQ_DATA="$(mktemp -d)" php audit_fixes.php
MQ_DATA="$(mktemp -d)" php trainer.php
MQ_DATA="$(mktemp -d)" php command.php
MQ_DATA="$(mktemp -d)" php command_links.php
MQ_DATA="$(mktemp -d)" php deal.php
MQ_DATA="$(mktemp -d)" php concierge.php
MQ_DATA="$(mktemp -d)" php ledger.php
MQ_DATA="$(mktemp -d)" php service.php
MQ_DATA="$(mktemp -d)" php invoice.php
MQ_DATA="$(mktemp -d)" php quote.php
MQ_DATA="$(mktemp -d)" php accounts.php
# What the team writes about a customer never reaches the customer.
MQ_DATA="$(mktemp -d)" php hidden_fields.php
# One staff address; one operations password, still signed by name.
MQ_DATA="$(mktemp -d)" php ops_shared.php
php order_msgs.php
php network.php
php network_seo.php
php face.php
MQ_DATA="$(mktemp -d)" php shop.php
MQ_DATA="$(mktemp -d)" php seo.php
php notif.php
php desk.php
php calls.php
php seo_mix.php
php article_front.php
php defaults.php
MQ_DATA="$(mktemp -d)" php link.php
MQ_DATA="$(mktemp -d)" php link_generate.php
MQ_DATA="$(mktemp -d)" php passkey.php
MQ_DATA="$(mktemp -d)" php wa_orders.php
MQ_DATA="$(mktemp -d)" php otp.php
MQ_DATA="$(mktemp -d)" php linkage.php
# Our numbers and their jobs; the campaigns (owner, 2026-11-21).
MQ_DATA="$(mktemp -d)" php lines_outreach.php
# The AI rewrite asks for room (held drafts, 2026-11-21).
MQ_DATA="$(mktemp -d)" php fp_rewrite.php
# Spoken alerts (owner, 2026-11-22).
MQ_DATA="$(mktemp -d)" php voice.php
node itest.mjs
# The web app's own rules: who stays signed in, and what each role keeps through a bad moment.
node --import ./register-loader.mjs session.mjs
node --import ./register-loader.mjs roles.mjs
# Reads leave together: one page load must not fire ninety requests at /db.
node --import ./register-loader.mjs batching.mjs
# Who is free, what a job is worth, and who owes whom.
node --import ./register-loader.mjs money.mjs
node --import ./register-loader.mjs when.mjs
node ago.mjs
node --import ./register-loader.mjs push_stale.mjs
node --import ./register-loader.mjs follow_up.mjs
node voice.mjs
