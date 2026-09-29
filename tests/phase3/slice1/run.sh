#!/usr/bin/env bash
# Phase 3, slice 1 — shifts and the marketplace: tests/phase3/slice1/run.sh
# A fresh SCRATCH database (txtschedules_dev3 — never the installed one), the application on :8191 (php -S, or TS_APP=apache for a real Apache with
# the rendered deploy vhost), a fake kernel (:8192) and a fake MaluDB (:8193). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules
# (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/txtschedules-shots-p3s1). One line per check; exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev3} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev3.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev3-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots-p3s1}
mkdir -p "$SHOTS" "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }   # the two restaurants with their time zones, as the kernel sends them
status=0
proofs=${PROOFS:-schema world see trade race refuse approval giveswap choose coverage agents zones}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"
  if [ "$p" = schema ]; then
    sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$TS_DEV_DB" -f db/proof/phase0_proof.sql | grep -E 'passed|FAIL|checks' | tail -3 || status=1
    php tests/phase3/slice1/schema.php || status=1
  else
    php "tests/phase3/slice1/$p.php" || status=1
  fi
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1; fi
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
