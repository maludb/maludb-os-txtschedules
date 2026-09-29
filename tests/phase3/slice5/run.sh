#!/usr/bin/env bash
# Phase 3, slice 5 — labor and forecast: tests/phase3/slice5/run.sh
# A fresh SCRATCH database (txtschedules_dev7 — never the installed one), the application on :8191 (php -S, or TS_APP=apache for a real Apache with the rendered deploy vhost), a fake kernel
# (:8192, answering K7's covers read as each proof tells it) and a fake MaluDB (:8193). Nothing is sent: notices only queue in notification_outbox. Needs `sudo -n -u postgres`, php, the
# kernel's web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/txtschedules-shots-p3s5). PROOFS="forecast k7" runs some.
# The scratch setup leaves the cluster roles' passwords alone (tests/setup_dev.sh reads them from config/.env when the app is installed); the last line of a run reports the installed app's health (`live health: ok`) when one is installed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev7} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev7.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev7-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots-p3s5}
mkdir -p "$SHOTS" "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json" "$TS_DEV_STATE/world2.json" "$TS_DEV_STATE/kernel.json.reads"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-schema forecast k7 budget rights agents registry}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"
  if [ "$p" = schema ]; then
    sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$TS_DEV_DB" -f db/proof/phase0_proof.sql | grep -E 'passed|FAIL|checks' | tail -3 || status=1
  fi
  php "tests/phase3/slice5/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice5/browser.mjs || status=1; fi
if [ -f "$ROOT/config/.env" ]; then
  port=$(grep -m1 '^APP_INTERNAL_PORT=' "$ROOT/config/.env" | cut -d= -f2-)
  h=$(curl -s -m 5 "http://127.0.0.1:${port}/api/v1/health.php" | grep -o '"database":"[a-z]*"' | head -1)
  echo "live health: ${h:-unreachable}"; [ "$h" = '"database":"ok"' ] || status=1
fi
[ $status -eq 0 ] && echo "ALL SLICE 5 PROOFS PASSED" || echo "SOME SLICE 5 PROOFS FAILED"
exit $status
