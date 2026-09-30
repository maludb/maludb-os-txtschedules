<?php
/**
 * Proof — db/016 and the two read roles' reach. The migration is additive and idempotent; the three new functions are granted to exactly the roles that need them;
 * the records role reads views (and calls the gated functions) and NO base table; the activity role reads mcp_activity_log and nothing else; neither can write.
 */
require __DIR__ . '/lib.php';
reset_p4();
$psql = fn (string $sql) => admin_sql($sql);
echo "1. db/016\n";
$twice = shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d ' . escapeshellarg(need('DB_NAME')) . ' -f db/016_mcp_servers.sql 2>&1');
ok(trim((string) $twice) === '', 'applied a second time: no error, no notice (create or replace throughout)');
$f = fn (string $fn, string $role) => (bool) one("SELECT has_function_privilege(:r, :f, 'EXECUTE')", ['r' => $role, 'f' => $fn]);
foreach (['records' => 'txtschedules_records_ro', 'activity' => 'txtschedules_activity_ro', 'rw' => 'txtschedules_rw'] as $n => $role) {
    ok($f('mcp_admit_agent(bigint)', $role) && $f('mcp_member_kind(bigint)', $role), "$n role: may call mcp_admit_agent and mcp_member_kind (the gate's first contact)");
}
ok($f('ts_time_off_taken(bigint,date,date)', 'txtschedules_records_ro') && !$f('ts_time_off_taken(bigint,date,date)', 'txtschedules_activity_ro'), 'ts_time_off_taken: the records role only (it serves the records server\'s share) — not the activity role');
ok(!$f('ts_time_off_taken(bigint,date,date)', 'public') && !$f('mcp_admit_agent(bigint)', 'public'), 'and not PUBLIC');
ok(one("SELECT prosecdef FROM pg_proc WHERE proname = 'ts_time_off_taken'") && one("SELECT prosecdef FROM pg_proc WHERE proname = 'mcp_admit_agent'") && one("SELECT proconfig::text FROM pg_proc WHERE proname = 'ts_time_off_taken'") === '{search_path=public}', 'SECURITY DEFINER with a fixed search_path');
foreach (['txtschedules_records_ro', 'txtschedules_activity_ro'] as $role) {
    $bases = array_column(q("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"), 'tablename');
    $readable = array_filter($bases, fn ($t) => one("SELECT has_table_privilege(:r, 'public.' || :t, 'SELECT')", ['r' => $role, 't' => $t]) && !in_array($t, ['ts_rights', 'ts_roles', 'ts_role_rights', 'rule_kinds', 'rule_presets'], true));
    ok(count($bases) > 40 && $readable === [], "$role reads NO base table (" . count($bases) . ' checked; the roles catalogue and rule kinds are the only tables it may read)' . ($readable ? ' — READS: ' . implode(',', $readable) : ''));
    $write = array_filter($bases, fn ($t) => one("SELECT has_table_privilege(:r, 'public.' || :t, 'INSERT') OR has_table_privilege(:r, 'public.' || :t, 'UPDATE') OR has_table_privilege(:r, 'public.' || :t, 'DELETE')", ['r' => $role, 't' => $t]));
    ok($write === [], "$role can write no table");
}
$views = array_column(q("SELECT viewname FROM pg_views WHERE schemaname = 'public' AND viewname LIKE 'mcp\\_%' ORDER BY 1"), 'viewname');
$act = array_filter($views, fn ($v) => one("SELECT has_table_privilege('txtschedules_activity_ro', 'public.' || :v, 'SELECT')", ['v' => $v]));
ok(array_values($act) === ['mcp_activity_log'], 'the activity role reads exactly one view: mcp_activity_log');
$rec = array_filter($views, fn ($v) => one("SELECT has_table_privilege('txtschedules_records_ro', 'public.' || :v, 'SELECT')", ['v' => $v]));
ok(count($rec) === count($views) - 1 && !in_array('mcp_activity_log', $rec, true), 'the records role reads every other mcp view (' . count($rec) . ') and not the activity log');
ok(count($views) === 33, '33 mcp_* views: the 29 of db/013, mcp_hours_weekly (014), mcp_app_roles (004), mcp_time_off_ledger and mcp_exchange_invitees (016): ' . count($views));
ok(!array_filter($views, fn ($v) => !one("SELECT (reloptions::text LIKE '%security_barrier=true%') OR :v = 'mcp_app_roles' FROM pg_class WHERE relname = :v", ['v' => $v])), 'every view (bar the catalogue) is a security_barrier view');
foreach (['mcp_time_off_ledger', 'mcp_exchange_invitees'] as $v) {
    $d = strtolower(q("SELECT definition FROM pg_views WHERE viewname = '$v'")[0]['definition']);
    ok(str_contains($d, 'app_current_member_id()') && str_contains($d, 'ts_scopes_with_right'), "$v: the caller's own rows, or a right at the site — tested once per statement (uncorrelated set)");
}
echo "2. The ledger and the invitees, as the servers see them\n";
$rec = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$as = fn (int $m, string $sql) => (function () use ($rec, $m, $sql) { $rec->exec("SELECT set_config('app.member_id', '$m', false)"); return $rec->query($sql)->fetchAll(); })();
$W = world();
$typ = (int) one("SELECT id FROM time_off_types WHERE scope_id = 102 AND key = 'vacation'");
admin_sql("DELETE FROM time_off_ledger; INSERT INTO time_off_ledger (member_id, type_id, delta_hours, reason) VALUES (30, $typ, 8, 'grant'), (31, $typ, 8, 'grant');");
ok(count($as(30, 'SELECT * FROM mcp_time_off_ledger')) === 1 && count($as(33, 'SELECT * FROM mcp_time_off_ledger')) === 2 && count($as(35, 'SELECT * FROM mcp_time_off_ledger')) === 0 && count($as(34, 'SELECT * FROM mcp_time_off_ledger')) === 0, 'ledger: Ana sees her one row, Mara (approves) both, Pat (builds only) none, Joe none');
admin_sql("DELETE FROM exchanges;");
$fri = next_dow('America/Chicago', 5); clear_day(102, $fri);
$sh = local_shift(102, $W['aSrv'], 30, $fri, '17:00', '22:00');
admin_sql("INSERT INTO exchanges (scope_id, kind, shift_id, from_member_id, status, expires_at, created_by) VALUES (102, 'coverage', $sh, NULL, 'open', now() + interval '1 day', 33);
           INSERT INTO exchange_invitees (exchange_id, member_id) SELECT max(id), 31 FROM exchanges; INSERT INTO exchange_invitees (exchange_id, member_id) SELECT max(id), 32 FROM exchanges;");
ok(array_column($as(31, 'SELECT * FROM mcp_exchange_invitees'), 'member_id') == [31] && count($as(33, 'SELECT * FROM mcp_exchange_invitees')) === 2 && count($as(32, 'SELECT * FROM mcp_exchange_invitees')) === 2 && count($as(30, 'SELECT * FROM mcp_exchange_invitees')) === 0 && count($as(34, 'SELECT * FROM mcp_exchange_invitees')) === 0,
   'invitees: Lee sees his own invitation; Dana (shift lead, coverage.fill) and Mara (approves) see both; Ana and Joe none');
admin_sql("DELETE FROM exchanges; DELETE FROM time_off_ledger;");
finish();
