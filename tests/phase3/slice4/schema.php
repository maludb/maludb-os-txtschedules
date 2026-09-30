<?php
/** Proof — the schema this slice stands on (db/006, db/009, db/013): no migration was needed; the database's own rules refuse what the handlers rely on it to refuse. */
require __DIR__ . '/lib.php';
$W = reset4();
$root = dirname(__DIR__, 3);
$files = glob($root . '/db/0*.sql'); sort($files);
ok(in_array(basename(end($files)), ['015_exchange_overlap.sql', '016_mcp_servers.sql'], true), 'slice 4 added no migration: db/015 (or Phase 4: db/016) is the last');
foreach (['ts_effective_rate', 'ts_ensure_staff_profile', 'ts_position_cert_same_site', 'ts_assignment_warnings'] as $f) { ok((int) one('SELECT count(*) FROM pg_proc WHERE proname = :f', ['f' => $f]) === 1, "$f exists"); }
foreach (['mcp_positions', 'mcp_staff', 'mcp_staff_positions', 'mcp_certification_kinds', 'mcp_certifications', 'mcp_certifications_due', 'mcp_members'] as $v) { ok((int) one("SELECT count(*) FROM pg_class WHERE relname = :v AND relkind = 'v' AND reloptions::text LIKE '%security_barrier=true%'", ['v' => $v]) === 1, "$v is a security-barrier view"); }
$srv = $W['aSrv'];
ok((float) one('SELECT ts_effective_rate(26, :p)', ['p' => $srv]) === 24.61 && (float) one('SELECT ts_effective_rate(31, :p)', ['p' => $srv]) === 21.37, 'the effective rate is the person\'s own when set, else the position\'s default (24.61, 21.37)');
$r = admin_sql("UPDATE staff_positions SET is_primary = true WHERE member_id = 30; UPDATE staff_positions SET is_primary = true WHERE member_id = 30");
ok(str_contains($r, 'staff_positions_one_primary') || str_contains($r, 'duplicate key'), 'a person has only one main position: the database refuses a second');
$r = admin_sql("INSERT INTO positions (scope_id, name) VALUES (102, 'server')");
ok(str_contains($r, 'positions_scope_name_live'), 'a live position name is unique in a restaurant (any case): refused by the database');
$r = admin_sql("INSERT INTO certification_kinds (scope_id, key, name) VALUES (102, 'food_handler', 'Other')");
ok(str_contains($r, 'duplicate key'), 'a kind key is unique in a restaurant');
$r = admin_sql("INSERT INTO staff_positions (member_id, position_id, wage_override) VALUES (31, $srv, -1) ON CONFLICT (member_id, position_id) DO UPDATE SET wage_override = -1");
ok(str_contains($r, 'wage_override_check') || str_contains($r, 'violates check constraint'), 'a negative rate is refused by the database');
$r = admin_sql("UPDATE staff_profiles SET is_minor = true, minor_until = NULL WHERE member_id = 30");
ok(str_contains($r, 'violates check constraint'), 'a minor without an end date is refused by the database');
$r = admin_sql("UPDATE staff_profiles SET max_hours_week = 101 WHERE member_id = 30");
ok(str_contains($r, 'violates check constraint'), 'a weekly limit past 100 hours is refused by the database');
admin_sql("SELECT ts_ensure_staff_profile(26, 101)");
ok((int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 26') === 102, 'ts_ensure_staff_profile never moves an existing main restaurant');
ok(trim(admin_sql("SELECT count(*) FROM pg_proc WHERE proname = 'ts_effective_rate' AND has_function_privilege('txtschedules_records_ro', oid, 'EXECUTE')")) === '0', 'the records role cannot run ts_effective_rate (a rate comes only through the gated views)');
ok(trim(admin_sql("SELECT has_table_privilege('txtschedules_rw', 'certifications', 'DELETE')")) === 'f', 'a card cannot be deleted by the application role: removing marks it (removed_at)');
finish();
