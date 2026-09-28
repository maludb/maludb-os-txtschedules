#!/usr/bin/env bash
# Two people claim the same offered shift at the same moment: exactly one wins (NF-5). Needs two real sessions,
# so it runs on a THROWAWAY database built from db/ (created and dropped here), never on the application's own.
#   sudo -n true && bash db/proof/claim_race.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
DB=txtschedules_racetest_$$
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qAt"
trap '$PSQL -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true' EXIT
$PSQL -c "CREATE DATABASE $DB"
for f in db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
$PSQL -d "$DB" >/dev/null <<'SQL'
SET ROLE txtschedules_rw;
INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES
 (26, 'human', 'SMOKE Priya', 'user', 'active', 'write', '{staff}'),
 (28, 'human', 'SMOKE Sam', 'user', 'active', 'write', '{staff}'),
 (30, 'human', 'SMOKE Ana', 'user', 'active', 'write', '{staff}');
SELECT ts_site_materialise(102, 11, 'SMOKE Airport', NULL, 'America/Chicago', NULL);
INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES
 (26, 102, 'staff', '{staff}', 'write'), (28, 102, 'staff', '{staff}', 'write'), (30, 102, 'staff', '{staff}', 'write');
SELECT ts_ensure_staff_profile(26, 102), ts_ensure_staff_profile(28, 102), ts_ensure_staff_profile(30, 102);
INSERT INTO positions (scope_id, name) VALUES (102, 'Server');
INSERT INTO staff_positions (member_id, position_id) SELECT m, (SELECT id FROM positions) FROM unnest(ARRAY[26, 28, 30]) m;
INSERT INTO schedule_weeks (scope_id, week_start) VALUES (102, current_date + 3);
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, assignee_member_id)
SELECT 102, (SELECT id FROM schedule_weeks), (SELECT id FROM positions), now() + interval '3 days', now() + interval '3 days 5 hours', 26;
SELECT ts_publish_week((SELECT id FROM schedule_weeks), 26);
SELECT ts_exchange_create('offer', (SELECT id FROM shifts), 26);
SQL
X=$($PSQL -d "$DB" -c "SELECT id FROM exchanges")
# Session A takes the lock and holds it for two seconds; session B arrives meanwhile and must wait, then lose.
( $PSQL -d "$DB" -c "BEGIN; SET ROLE txtschedules_rw; SELECT ts_exchange_claim($X, 28); SELECT pg_sleep(2); COMMIT;" > /tmp/claim_race_a.$$ 2>&1 ) &
sleep 0.5
set +e
B=$($PSQL -d "$DB" -c "SET ROLE txtschedules_rw; SELECT ts_exchange_claim($X, 30);" 2>&1)
set -e
wait
A=$(tr '\n' ' ' < /tmp/claim_race_a.$$); rm -f /tmp/claim_race_a.$$
WINNERS=$($PSQL -d "$DB" -c "SELECT count(*) FROM exchange_claims WHERE status = 'won'")
HOLDER=$($PSQL -d "$DB" -c "SELECT assignee_member_id FROM shifts")
fails=0
check() { if [ "$1" = "1" ]; then echo "ok   $2"; else echo "FAIL $2"; fails=$((fails+1)); fi; }
check "$([[ "$A" == *approved* ]] && echo 1)" "session A (Sam) claims first and wins: $A"
check "$([[ "$B" == *"already took"* ]] && echo 1)" "session B (Ana), arriving during A's claim, waits and is told it is gone"
check "$([ "$WINNERS" = "1" ] && echo 1)" "exactly one winning claim ($WINNERS)"
check "$([ "$HOLDER" = "28" ] && echo 1)" "the shift is Sam's ($HOLDER)"
echo "$((4 - fails)) of 4 passed"
exit $fails
