#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop — the two MCP servers on SCRATCH loopback ports from the scratch environment ($TS_DEV_ENV):
# records :8194, activity :8195 (never the installed application's ports or services). A third instance with a dead kernel URL
# (records :8197) proves the run-facts gate fails closed when the kernel cannot be reached. Pid files live in $TS_DEV_STATE.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${TS_DEV_ENV:-/tmp/txtschedules-dev.env}
STATE=${TS_DEV_STATE:-/tmp/txtschedules-dev-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    PY="$ROOT/mcp/venv/bin/python"
    launch() {  # name, then VAR=value pairs, then the script — exec so $! is the python process itself (stop kills exactly it)
      local name=$1; shift
      ( cd "$ROOT/mcp" && exec env "$@" >"$STATE/mcp-$name.log" 2>&1 ) &
      echo $! > "$STATE/mcp-$name.pid"
    }
    launch records MCP_RECORDS_PORT=8194 MCP_ACTIVITY_PORT=8195 "$PY" records_server.py
    launch activity MCP_RECORDS_PORT=8194 MCP_ACTIVITY_PORT=8195 "$PY" activity_server.py
    launch nokernel MCP_RECORDS_PORT=8197 OS_INTERNAL_URL=http://127.0.0.1:1 "$PY" records_server.py
    for p in 8194 8195 8197; do
      for i in $(seq 1 50); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8194, activity :8195, records-with-no-kernel :8197)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
