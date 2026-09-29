#!/usr/bin/env bash
# Phase 3, slice 2 — the week builder: tests/phase3/slice2/run.sh
# A fresh SCRATCH database (txtschedules_dev4 — never the installed one), the application on :8191 (php -S, or TS_APP=apache for a real Apache with the rendered
# deploy vhost), a fake kernel (:8192) and a fake MaluDB (:8193). Nothing is sent: notices only queue in notification_outbox. Needs `sudo -n -u postgres`, php, the kernel's
# web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/txtschedules-shots-p3s2). PROOFS="draft rules" runs some.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev4} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev4.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev4-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots-p3s2}
mkdir -p "$SHOTS" "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json" "$TS_DEV_STATE/world2.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-schema draft rules published numbers templates autofill rights agents}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"
  if [ "$p" = schema ]; then
    sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$TS_DEV_DB" -f db/proof/phase0_proof.sql | grep -E 'passed|FAIL|checks' | tail -3 || status=1
  fi
  php "tests/phase3/slice2/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1; fi
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
