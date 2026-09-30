<?php
/** Proof — the schema this slice stands on (db/005, db/011, db/013): no migration was needed; the database's own rules refuse what the handlers rely on it to refuse, and ts_staffing_needs() is right. */
require __DIR__ . '/lib.php';
$W = reset5();
$root = dirname(__DIR__, 3);
$files = glob($root . '/db/0*.sql'); sort($files);
ok(in_array(basename(end($files)), ['015_exchange_overlap.sql', '016_mcp_servers.sql'], true), 'slice 5 added no migration: db/015 (or Phase 4: db/016) is the last');
foreach (['labor_budgets', 'forecast_covers', 'staffing_ratios', 'day_parts'] as $t) { ok((int) one("SELECT count(*) FROM pg_class WHERE relname = :t AND relkind = 'r'", ['t' => $t]) === 1, "$t exists"); }
foreach (['mcp_labor_budgets', 'mcp_labor_weekly', 'mcp_forecast_covers', 'mcp_staffing_ratios', 'mcp_day_parts'] as $v) { ok((int) one("SELECT count(*) FROM pg_class WHERE relname = :v AND relkind = 'v' AND reloptions::text LIKE '%security_barrier=true%'", ['v' => $v]) === 1, "$v is a security-barrier view"); }
ok((int) one("SELECT count(*) FROM pg_proc WHERE proname = 'ts_staffing_needs'") === 1, 'ts_staffing_needs exists');
$a = dpid(102, 'lunch'); $d = dpid(102, 'dinner');
ok($a > 0 && $d > 0 && one("SELECT service_name FROM day_parts WHERE id = $d") === 'Dinner', 'every restaurant ships a Lunch and a Dinner day-part with the service name Reservations uses');
$r = admin_sql("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) VALUES (102, '2030-01-01', $d, -1)");
ok(str_contains($r, 'violates check constraint'), 'negative covers are refused by the database');
$r = admin_sql("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source) VALUES (102, '2030-01-01', $d, 5, 'guess')");
ok(str_contains($r, 'violates check constraint'), 'a source other than manual, copied or reservations is refused');
admin_sql("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) VALUES (102, '2030-01-01', $d, 5)");
$r = admin_sql("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) VALUES (102, '2030-01-01', $d, 6)");
ok(str_contains($r, 'duplicate key'), 'one cell per restaurant, day and day-part');
admin_sql("DELETE FROM forecast_covers");
$r = admin_sql("INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff) VALUES (102, {$W['aSrv']}, 0)");
ok(str_contains($r, 'violates check constraint'), 'a ratio of zero covers per person is refused');
$r = admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area) VALUES (102, '2030-01-07', 'all')");
ok(str_contains($r, 'violates check constraint'), 'a budget needs hours or an amount');
$r = admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours) VALUES (102, '2030-01-07', 'kitchenette', 5)");
ok(str_contains($r, 'violates check constraint'), 'a budget area is one of the six');
$r = admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours) VALUES (102, '2030-01-07', 'all', -5)");
ok(str_contains($r, 'violates check constraint'), 'negative budget hours are refused');
// ts_staffing_needs, as the function computes it (a member with schedule.build at the site)
admin_sql("INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff) VALUES (102, {$W['aSrv']}, 25, 1)");
put_cell(102, '2030-01-04', $d, 80, 'manual'); put_cell(102, '2030-01-05', $d, 0, 'manual'); put_cell(102, '2030-01-06', $d, 26, 'manual');
$as = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$needs = function (int $m) use ($as, $d): array { $as->exec("SELECT set_config('app.member_id', '$m', false)"); $o = []; foreach ($as->query("SELECT on_date::text AS on_date, day_part_id, recommended, expected_covers FROM ts_staffing_needs(102, '2030-01-04', '2030-01-07')")->fetchAll() as $r) { if ((int) $r['day_part_id'] === $d) { $o[$r['on_date']] = $r; } } return $o; };
$n = $needs(33);
ok((int) $n['2030-01-04']['recommended'] === 4 && (int) $n['2030-01-05']['recommended'] === 1 && (int) $n['2030-01-06']['recommended'] === 2 && (int) $n['2030-01-07']['recommended'] === 1 && $n['2030-01-07']['expected_covers'] === null,
    'recommended = max(minimum, ceil(covers / N)): 80 covers per 25 → 4, 0 → 1 (the minimum), 26 → 2, no forecast → the minimum');
ok($needs(26) === [] && $needs(31) === [], 'a person without schedule.build gets no rows from ts_staffing_needs (the function checks the right itself)');
admin_sql("DELETE FROM forecast_covers; DELETE FROM staffing_ratios");
finish();
