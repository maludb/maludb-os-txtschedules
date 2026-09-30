#!/usr/bin/env bash
# Phase 4 — the two MCP servers, app_roles, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (txtschedules_dev10 — never the installed one), the application on :8191 (php -S, or TS_APP=apache for a real Apache with the rendered deploy
# vhost, which also proves the /mcp/records and /mcp/activity proxy lines), a fake kernel (:8192 — run-facts, K6, K7) and a fake MaluDB/MaluMail (:8193), and the servers
# under test on :8194 (records), :8195 (activity) and :8197 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service, Apache, unit,
# database or role password (setup_dev.sh reads the live roles' passwords and never alters them). PROOFS="gate tools_schedule" runs some. The last line reports the installed
# application's health. Needs `sudo -n -u postgres`, php, mcp/venv, the kernel's mcp/venv (for the hook proof).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev10} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev10.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev10-state}
mkdir -p "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json" "$TS_DEV_STATE/world2.json" "$TS_DEV_STATE/kernel.json.reads" "$TS_DEV_STATE/kernel.json.sms"
export MALUMAIL_API_URL=http://127.0.0.1:8193 MALUMAIL_API_KEY=mm_$(printf 'a%.0s' $(seq 1 48)) MAIL_FROM=noreply@example.invalid MAIL_FROM_NAME=txtSchedules FAKE_MALUMAIL_LOG="$TS_DEV_STATE/malumail.log"
: > "$FAKE_MALUMAIL_LOG"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
trap '"$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-phase0 schema gate kernel_contract tools_schedule tools_requests tools_activity wages hook registry}
for p in $proofs; do
  echo "== $p"
  if [ "$p" = phase0 ]; then
    sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$TS_DEV_DB" -f db/proof/phase0_proof.sql | grep -E 'passed|FAIL|checks' | tail -3 || status=1
    continue
  fi
  php "tests/phase4/$p.php" || status=1
done
if [ -f "$ROOT/config/.env" ]; then
  port=$(grep -m1 '^APP_INTERNAL_PORT=' "$ROOT/config/.env" | cut -d= -f2-)
  h=$(curl -s -m 5 "http://127.0.0.1:${port}/api/v1/health.php" | grep -o '"database":"[a-z]*"' | head -1)
  echo "live health: ${h:-unreachable}"; [ "$h" = '"database":"ok"' ] || status=1
fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
