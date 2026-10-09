#!/usr/bin/env bash
# The record picker (2026-10-09; design-system reference record-picker.md) — tests/picker/run.sh
# A fresh SCRATCH database (txtschedules_dev11 — never the installed one), the application on :$TS_PORT (default 8191; php -S), a fake kernel (+1) and a fake MaluDB (+2).
# Proofs: picker.php (the endpoint's refusals, search, escaping, paging, the site and shift params, every converted form, a saved write through the hidden input) and the browser
# pass (browser.mjs: open, search, pick, keyboard, 375 px, dark mode; screenshots in $SHOTS). PROOFS="picker" runs one. Needs `sudo -n -u postgres`, php, the kernel's web/node_modules.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export TS_PORT=${TS_PORT:-8191} TS_DEV_DB=${TS_DEV_DB:-txtschedules_dev11} TS_DEV_ENV=${TS_DEV_ENV:-/tmp/txtschedules-dev11.env} TS_DEV_STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev11-state} SHOTS=${SHOTS:-/tmp/txtschedules-shots-picker}
export TS_BASE="http://127.0.0.1:$TS_PORT"
mkdir -p "$SHOTS" "$TS_DEV_STATE"; rm -f "$TS_DEV_STATE/world.json" "$TS_DEV_STATE/world2.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop; "$ROOT/tests/restore_role_passwords.sh"' EXIT
set -a; . "$TS_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$TS_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$TS_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-picker}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"
  php "tests/picker/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/picker/browser.mjs || status=1; fi
[ $status -eq 0 ] && echo "ALL PICKER PROOFS PASSED" || echo "SOME PICKER PROOFS FAILED"
exit $status
