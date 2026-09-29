#!/usr/bin/env bash
# Phase 3, slice 6 — announcements and notifications: tests/phase3/slice6/run.sh
# A fresh SCRATCH database (txtschedules_dev8 — never the installed one), the application on :8191 (php -S, or TS_APP=apache for a real Apache with the rendered deploy vhost), a fake kernel (:8192 — K6 texts
# and K7 covers, as each proof tells it) and a fake MaluDB that also stands in for MaluMail (:8193, /v1/send). NOTHING REAL IS SENT: email goes to the stub at fresh example.invalid addresses, texts to the stubbed
# kernel. Needs `sudo -n -u postgres`, php, the kernel's web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/txtschedules-shots-p3s6).
# PROOFS="announcements reminders" runs some. The scratch setup leaves the cluster roles' passwords alone; the last line reports the installed app's health when one is installed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev8} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev8.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev8-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots-p3s6}
mkdir -p "$SHOTS" "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json" "$TS_DEV_STATE/world2.json" "$TS_DEV_STATE/kernel.json.reads" "$TS_DEV_STATE/kernel.json.sms"
export MALUMAIL_API_URL=http://127.0.0.1:8193 MALUMAIL_API_KEY=mm_$(printf 'a%.0s' $(seq 1 48)) MAIL_FROM=noreply@example.invalid MAIL_FROM_NAME=txtSchedules FAKE_MALUMAIL_LOG="$TS_DEV_STATE/malumail.log"
: > "$FAKE_MALUMAIL_LOG"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-schema announcements reminders k6 email sensitive expiry calendar daily prefs rights agents registry}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"
  if [ "$p" = schema ]; then
    sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$TS_DEV_DB" -f db/proof/phase0_proof.sql | grep -E 'passed|FAIL|checks' | tail -3 || status=1
  fi
  php "tests/phase3/slice6/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice6/browser.mjs || status=1; fi
if [ -f "$ROOT/config/.env" ]; then
  port=$(grep -m1 '^APP_INTERNAL_PORT=' "$ROOT/config/.env" | cut -d= -f2-)
  h=$(curl -s -m 5 "http://127.0.0.1:${port}/api/v1/health.php" | grep -o '"database":"[a-z]*"' | head -1)
  echo "live health: ${h:-unreachable}"; [ "$h" = '"database":"ok"' ] || status=1
fi
[ $status -eq 0 ] && echo "ALL SLICE 6 PROOFS PASSED" || echo "SOME SLICE 6 PROOFS FAILED"
exit $status
