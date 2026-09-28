#!/usr/bin/env bash
# Runs every Phase 2 proof on a fresh SCRATCH database (never the installed one): tests/phase2/run.sh
#   TS_APP=apache tests/phase2/run.sh   serves the app from a real Apache with the rendered deploy vhost instead of php -S
#   needs: `sudo -n -u postgres`, php, /srv/apps/projects/mcp/venv (asyncpg + httpx, for the ingest proof), node with the
#   kernel's web/node_modules (Playwright + its Chromium, for the browser proof). Screenshots go to $SHOTS
#   (default /tmp/txtschedules-shots). Ends with one line per proof and exit status 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots}
mkdir -p "$SHOTS"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
for p in sso gates sync ingest kernel_compat vhost; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== browser"; node tests/phase2/browser.mjs || status=1
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
