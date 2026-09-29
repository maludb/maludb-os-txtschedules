#!/usr/bin/env bash
# The proofs' tests/setup_dev.sh gives the three CLUSTER roles a scratch password (roles are shared with the installed application). When the application is installed
# (config/.env exists) this puts the passwords config/.env carries back, so a proof run never leaves the installed application unable to log in to its database.
# Reads three keys from config/.env (never sourced, never printed); a no-op when there is no config/.env.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
ENVF="$ROOT/config/.env"
[ -f "$ENVF" ] || exit 0
val() { grep -m1 "^$1=" "$ENVF" | cut -d= -f2-; }
for pair in "DB_USER:DB_PASSWORD" "MCP_RECORDS_DB_USER:MCP_RECORDS_DB_PASSWORD" "MCP_ACTIVITY_DB_USER:MCP_ACTIVITY_DB_PASSWORD"; do
  u=$(val "${pair%%:*}"); p=$(val "${pair##*:}")
  [ -n "$u" ] && [ -n "$p" ] && sudo -n -u postgres psql -q -c "ALTER ROLE $u WITH LOGIN PASSWORD '$p'" >/dev/null
done
