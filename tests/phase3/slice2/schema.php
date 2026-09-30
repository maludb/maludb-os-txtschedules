<?php
/** Proof — the schema slice 2 relies on is all there, and slice 2 changed none of it (no db/016): the roles' privileges, the constraint, the guards. */
require __DIR__ . '/lib.php';
$rw = 'txtschedules_rw'; $ro = 'txtschedules_records_ro';
$priv = fn (string $role, string $obj, string $p) => (bool) one('SELECT has_table_privilege(:r, :o, :p)', ['r' => $role, 'o' => $obj, 'p' => $p]);
$fn = fn (string $role, string $sig) => (bool) one('SELECT has_function_privilege(:r, :f, \'EXECUTE\')', ['r' => $role, 'f' => $sig]);
ok(count(glob(dirname(__DIR__, 3) . '/db/0*.sql')) === 16 && !file_exists(dirname(__DIR__, 3) . '/db/017_week_builder.sql'), 'the schema has sixteen migrations (Phase 4 added db/016): slice 2 added none (everything it needs was in db/008, 009, 013, 014)');
$missing = [];
foreach (['schedule_weeks' => ['SELECT', 'INSERT', 'UPDATE'], 'shifts' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], 'schedule_templates' => ['SELECT', 'INSERT', 'UPDATE'], 'template_shifts' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
          'rule_overrides' => ['SELECT', 'INSERT'], 'notification_outbox' => ['SELECT', 'INSERT'], 'exchanges' => ['SELECT', 'UPDATE']] as $t => $ps) {
    foreach ($ps as $p) { if (!$priv($rw, $t, $p)) { $missing[] = "$p on $t"; } }
}
ok($missing === [], 'the application role holds the table privileges the builder writes with' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
$bad = [];
foreach (['mcp_schedule_weeks', 'mcp_shifts', 'mcp_templates', 'mcp_template_shifts', 'mcp_hours_weekly', 'mcp_labor_weekly', 'mcp_rule_overrides', 'mcp_members', 'mcp_positions', 'mcp_staff_positions', 'mcp_sites'] as $v) {
    if (!$priv($rw, $v, 'SELECT') || !$priv($ro, $v, 'SELECT')) { $bad[] = $v; }
}
ok($bad === [], 'both roles read the views the screens and the tools share' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$bad = [];
foreach (['ts_publish_week(bigint, bigint)', 'ts_check_assignment(bigint, bigint, bigint, timestamptz, timestamptz, integer, bigint)', 'ts_week_warnings(bigint)', 'ts_staffing_needs(bigint, date, date)', 'ts_exchange_cancel(bigint, bigint)'] as $f) {
    if (!$fn($rw, $f)) { $bad[] = $f; }
}
ok($bad === [], 'the application role may run the functions the builder calls' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$roPdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$wid = (int) one('SELECT min(id) FROM schedule_weeks');
try { $roPdo->query("SELECT ts_publish_week($wid, 1)"); $code = 'ran'; } catch (PDOException $e) { $code = (string) $e->getCode(); }
ok(!$priv($ro, 'shifts', 'INSERT') && !$priv($ro, 'shifts', 'UPDATE') && !$priv($ro, 'shifts', 'DELETE') && !$priv($ro, 'schedule_weeks', 'INSERT') && !$priv($ro, 'schedule_weeks', 'UPDATE') && $code === '42501', 'the read role (the MCP server\'s) can write no shift, and calling ts_publish_week as it fails with "insufficient privilege" (42501)');
ok((int) one("SELECT count(*) FROM pg_constraint WHERE conname = 'shifts_no_overlap' AND contype = 'x'") === 1 && (int) one("SELECT count(*) FROM pg_trigger WHERE tgname IN ('shifts_check', 'shifts_no_delete_published') AND NOT tgisinternal") === 2, 'the exclusion constraint (one person, one place at a time) and the two guard triggers are in place');
ok((int) one("SELECT count(*) FROM rule_kinds") === 12 && (int) one("SELECT count(*) FROM site_rules WHERE scope_id = 102") === 12, 'the rules engine\'s twelve rule kinds, and Airport has its twelve rules from the generic preset');
finish();
