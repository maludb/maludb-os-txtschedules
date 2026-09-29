<?php
/** Proof — schema: nothing was migrated (db/015 is still the last file), and what the slice stands on is enforced by the database itself, under the handlers' own checks: the settings' ranges, a rule's severity, a day-part's key, an override's reason, and the privileges. */
require __DIR__ . '/lib.php';
$W = reset7();
$root = dirname(__DIR__, 3);
$files = glob($root . '/db/0*.sql'); sort($files);
ok(basename(end($files)) === '015_exchange_overlap.sql', 'no migration in this slice: db/015 is still the last file');
$exp = fn (string $sql): string => trim(admin_sql($sql));
foreach ([["UPDATE site_settings SET time_off_day_hours = 0 WHERE scope_id = 102", 'time_off_day_hours'], ["UPDATE site_settings SET time_off_day_hours = 24.5 WHERE scope_id = 102", 'time_off_day_hours'], ["UPDATE site_settings SET cutoff_minutes = -1 WHERE scope_id = 102", 'cutoff'],
          ["UPDATE site_settings SET reminder_minutes_before = 2881 WHERE scope_id = 102", 'reminder'], ["UPDATE site_settings SET overtime_weekly_hours = 0 WHERE scope_id = 102", 'overtime'], ["UPDATE site_settings SET overtime_multiplier = 0.5 WHERE scope_id = 102", 'overtime_multiplier'],
          ["UPDATE site_settings SET claim_mode = 'lottery' WHERE scope_id = 102", 'claim_mode'], ["UPDATE site_settings SET approval_swap = 'sometimes' WHERE scope_id = 102", 'approval_swap'], ["UPDATE site_settings SET currency = 'usd' WHERE scope_id = 102", 'currency'],
          ["UPDATE site_settings SET week_start = 7 WHERE scope_id = 102", 'week_start']] as [$sql, $why]) {
    ok(str_contains($exp($sql), 'violates check constraint'), "the database itself refuses: $sql");
}
ok(str_contains($exp("UPDATE site_rules SET severity = 'medium' WHERE scope_id = 102 AND rule_key = 'min_rest'"), 'violates check constraint'), 'a rule\'s severity is hard, soft or off — the table says so');
ok(str_contains($exp("INSERT INTO site_rules (scope_id, rule_key, severity) VALUES (102, 'no_such_rule', 'soft')"), 'violates foreign key constraint'), 'a rule the engine does not know cannot be given to a restaurant');
ok(str_contains($exp("INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at) VALUES (102, 'Bad Key', 'x', '10:00', '11:00')"), 'violates check constraint') && str_contains($exp("INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at) VALUES (102, 'lunch', 'x', '10:00', '11:00')"), 'duplicate key'), 'a day-part\'s key is lower-case words and unique per restaurant');
ok(str_contains($exp("INSERT INTO rule_overrides (scope_id, rule_key, message, reason, context) VALUES (102, 'min_rest', 'm', 'x', 'build')"), 'violates check constraint'), 'an override needs a reason of at least three characters');
$rules = (int) $exp("SELECT count(*) FROM site_rules WHERE scope_id = 102");
ok($rules === 12 && (int) $exp("SELECT count(*) FROM rule_kinds") === 12 && (int) $exp("SELECT count(*) FROM rule_presets WHERE key = 'generic'") === 1, 'a restaurant has a row for each of the engine\'s twelve rules, and one preset ships: generic');
$engine = $exp("SELECT prosrc FROM pg_proc WHERE proname = 'ts_assignment_warnings'");
ok(str_contains($engine, 'FROM site_rules sr') && str_contains($engine, 'FROM site_settings WHERE scope_id') && !str_contains($engine, 'cache'), 'the engine reads site_rules and site_settings on every question — a setting reaches the next action, nothing is cached');
$exchange = $exp("SELECT prosrc FROM pg_proc WHERE proname = 'ts_exchange_create'") . $exp("SELECT prosrc FROM pg_proc WHERE proname = 'ts_exchange_check_taker'");
ok(str_contains($exchange, 'v_settings.cutoff_minutes') && str_contains($exchange, 'allow_swap'), 'and so do the exchange functions (the cutoff and which kinds exist are read live)');
$tt = $exp("SELECT prosrc FROM pg_proc WHERE proname = 'ts_time_off_hours'");
ok(str_contains($tt, 'time_off_day_hours'), 'the time-off trigger counts a day from the restaurant\'s setting (D13), not a constant');
$def = $exp("SELECT pg_get_viewdef('mcp_sites'::regclass)");
ok(str_contains($def, 'time_off_day_hours') && str_contains($def, 'cutoff_minutes') && str_contains($def, 'rule_preset'), 'mcp_sites carries the settings the tools and the pages read');
$opts = $exp("SELECT array_to_string(reloptions, ',') FROM pg_class WHERE relname IN ('mcp_sites', 'mcp_site_rules', 'mcp_day_parts', 'mcp_rule_overrides', 'mcp_labor_weekly', 'mcp_hours_weekly') AND reloptions IS NOT NULL");
ok(substr_count($opts, 'security_barrier=true') === 6, 'the six views the slice reads are security barriers');
finish();
