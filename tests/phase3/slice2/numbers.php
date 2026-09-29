<?php
/** Proof — hours, cost, needs (spec "Proof", 4): the row-end hours equal mcp_hours_weekly; cost and budget only with labor.view; the staffing strip matches ts_staffing_needs(); no page carries a wage. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people();
$mara = as_member(33); $owner = as_member(1, 102); $planner = as_planner(); $priya = as_member(26); $ana = as_member(30);
$srv = srv();
$ws = wk(36); $we = dayn($ws, 6);
// Ana 18 h (3 x 6, no break), Priya 12 h + one with a 30-minute break, an open shift; Priya has her own rate (24.61), Ana the position's default (21.37)
fx(102, $srv, 30, $ws, 0); fx(102, $srv, 30, $ws, 2); fx(102, $srv, 30, $ws, 4);
fx(102, $srv, 26, $ws, 1); fx(102, $srv, 26, $ws, 3, '11:00', '19:00', ['break' => 30]);
fx(102, $srv, null, $ws, 5, '11:00', '15:00');
admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount) VALUES (102, '$ws', 'all', 60, 600) ON CONFLICT DO NOTHING;
           INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff) VALUES (102, $srv, 20, 1) ON CONFLICT DO NOTHING;
           INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) SELECT 102, '" . dayn($ws, 4) . "', id, 60 FROM day_parts WHERE scope_id = 102 AND key = 'dinner' ON CONFLICT DO NOTHING;");
$as = function (int $member, callable $f) { q("SELECT set_config('app.member_id', :m, false)", ['m' => (string) $member]); try { return $f(); } finally { q("SELECT set_config('app.member_id', '', false)"); } };

echo "1. Hours\n";
[$c, $d] = builder($mara, 102, $ws);
$hoursJson = array_column($d['hours'], 'scheduled_hours', 'member_id');
$view = $as(33, fn () => array_column(q('SELECT member_id, scheduled_hours FROM mcp_hours_weekly WHERE site_id = 102 AND week_start = :w', ['w' => $ws]), 'scheduled_hours', 'member_id'));
ok($c === 200 && (float) $hoursJson[30] === 18.0 && (float) $hoursJson[26] === 13.5, 'the hours a person has: Ana 18, Priya 13.5 (a 30-minute break is not paid): ' . json_encode($hoursJson));
ok(array_map('floatval', $hoursJson) == array_map('floatval', $view), 'they equal mcp_hours_weekly row for row: ' . json_encode($view));
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(preg_match('/id="builder-hours-30" data-hours="18"/', $page) === 1 && preg_match('/id="builder-hours-26" data-hours="13\.5"/', $page) === 1 && str_contains($page, '18 / 40 h'), 'the page\'s row-end cells say 18 and 13.5, against the 40 hours\' threshold');
$cell = fn (string $html, int $m) => preg_match('/id="builder-hours-' . $m . '".*?<\/td>/s', $html, $x) ? $x[0] : '';
admin_sql("UPDATE site_settings SET overtime_weekly_hours = 20 WHERE scope_id = 102");
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($cell($page, 30), 'bar-warning') && str_contains($cell($page, 26), 'bar-primary'), 'against a threshold of 20: Ana at 18 (90 %) is in the warning band, Priya at 13.5 is primary');
admin_sql("UPDATE site_settings SET overtime_weekly_hours = 17 WHERE scope_id = 102");
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($cell($page, 30), 'bar-danger') && str_contains($cell($page, 30), 'text-danger') && str_contains($cell($page, 26), 'bar-primary'), 'against 17: Ana at 18 is over — danger; Priya is not');
admin_sql("UPDATE site_settings SET overtime_weekly_hours = 40 WHERE scope_id = 102");

echo "2. Cost and budget — only with labor.view\n";
$expect = round(18 * 21.37 + 13.5 * 24.61, 2);
[, $d] = builder($mara, 102, $ws);
ok(isset($d['labor']) && abs((float) $d['labor']['scheduled_cost'] - $expect) < 0.011 && (float) $d['labor']['budget_amount'] === 600.0 && (float) $d['labor']['budget_hours'] === 60.0, 'the manager\'s builder answers labor: cost ' . ($d['labor']['scheduled_cost'] ?? '?') . ' against a budget of 600 and 60 hours');
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($page, 'id="builder-labor"') && str_contains($page, 'id="builder-foot-cost"') && preg_match('/\$' . preg_quote(number_format($expect, 2), '/') . '|\$' . preg_quote(number_format($expect - 0.01, 2), '/') . '/', $page) === 1 && str_contains($page, ' of $600.00 budget'), 'the page has the labor card (cost of $600.00 budget) and the cost row under the grid');
[, $do] = builder($owner, 102, $ws);
ok(isset($do['labor']), 'the owner (admin) sees it too');
$pj = builder($planner, 102, $ws);
$pp = page($planner, "/builder?site=102&week=$ws");
ok($pj[0] === 200 && !array_key_exists('labor', $pj[1]) && $pj[1]['hours'] !== [], 'a builder WITHOUT labor.view (role planner): hours yes, no labor key');
ok(!str_contains($pp['body'], 'id="builder-labor"') && !str_contains($pp['body'], 'id="builder-foot-cost"') && !str_contains($pp['body'], 'Labor cost') && str_contains($pp['body'], 'id="builder-foot-hours"'), 'and on the page: hours row, no cost row, no labor card');
foreach (['/shifts/', '/weeks/publish-confirm?site=102&week=' . $ws] as $p) { }
$pc = json_encode(screen($planner, "/weeks/publish-confirm?site=102&week=$ws")[1]);
ok(!str_contains($pc, 'cost'), 'the planner\'s publish summary has no cost');
$mc = screen($mara, "/weeks/publish-confirm?site=102&week=$ws")[1];
ok(abs((float) $mc['cost'] - $expect) < 0.011, 'the manager\'s publish summary has the cost: ' . ($mc['cost'] ?? '?'));

echo "3. The staffing strip\n";
$fn = $as(33, fn () => q('SELECT on_date::text AS on_date, day_part, position_name, expected_covers, recommended, scheduled, open_shifts FROM ts_staffing_needs(102, :a, :b)', ['a' => $ws, 'b' => $we]));
$want = [];
foreach ($fn as $r) { if ((int) $r['recommended'] === 0 && (int) $r['scheduled'] === 0 && (int) $r['open_shifts'] === 0) { continue; } $want[] = $r['on_date'] . '|' . $r['day_part'] . '|' . $r['position_name'] . '|' . $r['recommended'] . '|' . $r['scheduled']; }
[, $d] = builder($mara, 102, $ws);
$got = [];
foreach ($d['needs'] as $date => $rows) { foreach ($rows as $n) { $got[] = $date . '|' . $n['day_part'] . '|' . $n['position'] . '|' . $n['recommended'] . '|' . $n['scheduled']; } }
sort($want); sort($got);
ok($want !== [] && $want === $got, 'the strip equals ts_staffing_needs(): ' . count($got) . ' rows');
$fri = array_values(array_filter($d['needs'][dayn($ws, 4)] ?? [], fn ($n) => $n['day_part'] === 'Dinner'))[0] ?? [];
ok(($fri['expected'] ?? 0) === 60 && $fri['recommended'] === 3 && $fri['scheduled'] === 1 && $fri['gap'] === 2, 'Friday dinner: 60 covers → 3 servers recommended, 1 scheduled (Ana 17–23), 2 short: ' . json_encode($fri));
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($page, 'id="builder-needs"') && str_contains($page, '1/3 — 2 short') && str_contains($page, 'text-danger'), 'the page shows "1/3 — 2 short" in danger');
[, $dp] = builder($planner, 102, $ws);
ok(!empty($dp['needs']), 'the strip is a builder\'s (a planner has it too)');

echo "4. No page of the slice carries a wage\n";
$all = '';
foreach ([$mara, $owner, $planner] as $jar) {
    foreach (["/builder?site=102&week=$ws", "/builder?site=102&week=$ws&view=positions", "/builder?site=102&week=$ws&day=" . dayn($ws, 4), '/builder/day?site=102&date=' . dayn($ws, 4), '/templates/?site=102',
              "/weeks/publish-confirm?site=102&week=$ws", '/shifts/new?site=102&date=' . dayn($ws, 4)] as $p) {
        $all .= page($jar, $p)['body'] . req('GET', $p, ['jar' => $jar, 'headers' => JSONH])['body'];
    }
}
$one = (int) one("SELECT min(id) FROM shifts WHERE week_id = :w", ['w' => week_id_of(102, $ws)]);
foreach ([$mara, $planner] as $jar) { $all .= page($jar, "/shifts/$one")['body'] . page($jar, "/shifts/$one/edit")['body']; }
ok(leaks($all) === [], 'grep of ' . number_format(strlen($all)) . ' bytes (three builders\' pages and JSON): none of the fixture rates (21.37, 24.61, 23.19, 22.83)' . (leaks($all) ? ' — LEAKED ' . implode(',', leaks($all)) : ''));
$planners = '';
foreach (["/builder?site=102&week=$ws", '/builder/day?site=102&date=' . dayn($ws, 4), "/weeks/publish-confirm?site=102&week=$ws", "/shifts/$one"] as $p) { $planners .= page($planner, $p)['body'] . req('GET', $p, ['jar' => $planner, 'headers' => JSONH])['body']; }
ok(!preg_match('/"cost"|\$\s?\d+\.\d\d|Labor cost|labor\b.*budget/i', $planners), 'and nothing of a cost, a dollar amount or the budget on the planner\'s pages');
ok(!preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)[:<]/', $all . $planners), 'no PHP warning or notice printed on any page');
$logs = json_encode(q("SELECT before, after FROM activity_log WHERE scope_id = 102 AND action LIKE 'shift.%' OR action LIKE 'week.%'"));
ok(leaks($logs) === [] && !str_contains($logs, 'cost'), 'and no activity row carries a wage or a cost');
finish();
