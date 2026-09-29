<?php
/**
 * Proof — the labor budget (spec "Proof: Budget"): hours and amount per area and in total against scheduled, the numbers equal mcp_labor_weekly, a position's default rate moves the cost and not
 * the hours, an open shift adds hours and no cost; and cost is invisible without labor.view.
 */
require __DIR__ . '/lib.php';
$W = reset5();
/** The budget screen's JSON with every hours / cost / budget number a float (JSON writes 22.0 as 22). */
function bscreen(string $jar, string $path): array
{
    [$c, $d, $r] = screen($jar, $path);
    $f = fn ($v) => $v === null ? null : (float) $v;
    foreach ($d['areas'] ?? [] as $i => $a) { foreach (['scheduled_hours', 'scheduled_cost', 'budget_hours', 'budget_amount'] as $k) { $d['areas'][$i][$k] = $f($a[$k]); } }
    foreach ($d['by_day'] ?? [] as $i => $a) { foreach (['scheduled_hours', 'scheduled_cost'] as $k) { $d['by_day'][$i][$k] = $f($a[$k]); } }
    return [$c, $d, $r];
}
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dee = as_dee(); $sol = as_setter(); $ana = as_member(30);
$ws = wk(120); $srv = $W['aSrv']; $bar = $W['aBar'];
fx(102, $srv, 26, $ws, 4);                       // Priya, Server, Friday 17-23: 6 h at her own 24.61 = 147.66
fx(102, $srv, 30, $ws, 4);                       // Ana, Server, Friday: 6 h at the default 21.37 = 128.22
fx(102, $bar, 30, $ws, 5, '17:00', '21:00');     // Ana, Bar, Saturday: 4 h at 23.19 = 92.76
fx(102, $srv, null, $ws, 6);                     // an OPEN Server shift, Sunday: 6 h, no cost
$fri = dayn($ws, 4); $sat = dayn($ws, 5); $sun = dayn($ws, 6);
echo "1. Setting the budget (settings.manage and labor.view)\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '100', 'budget_amount' => '900.50']);
$bg = budget_of(102, $ws, 'all');
ok($c === 200 && $bg && (float) $bg['budget_hours'] === 100.0 && (float) $bg['budget_amount'] === 900.5 && ($b['record_id'] ?? 0) === (int) $bg['id'] && str_ends_with($b['location'] ?? '', '#budget-' . $bg['id']), 'budget_save all: 100 h and $900.50 — 200, the row is there, the location ends in the record id');
$row = q("SELECT source, scope_id, before, after, entity_id FROM activity_log WHERE action = 'budget.update' AND id > :s", ['s' => $since])[0] ?? [];
$aft = json_decode($row['after'] ?? '{}', true);
ok(($row['source'] ?? '') === 'web' && (int) $row['scope_id'] === 102 && (int) $row['entity_id'] === (int) $bg['id'] && $row['before'] === null && $aft['area'] === 'all' && $aft['hours'] == 100 && $aft['amount'] == 900.5, 'logged budget.update: the site, the budget, area / hours / amount (a plan, not a wage)');
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'front', 'budget_hours' => '20', 'budget_amount' => '300']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'bar', 'budget_hours' => '5', 'budget_amount' => '80']);
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => dayn($ws, 3), 'area' => 'kitchen', 'budget_hours' => '30']);
ok($c === 200 && (float) budget_of(102, $ws, 'kitchen')['budget_hours'] === 30.0 && budget_of(102, $ws, 'kitchen')['budget_amount'] === null, 'hours alone are a budget (kitchen 30 h, no amount); any date in the week names the week');
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'front', 'budget_hours' => '20', 'budget_amount' => '310']);
$row = q("SELECT before, after FROM activity_log WHERE action = 'budget.update' ORDER BY id DESC LIMIT 1")[0];
ok(json_decode($row['before'], true)['amount'] == 300 && json_decode($row['after'], true)['amount'] == 310, 'changing one: before $300 → after $310 in the log (restore prior)');
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'front', 'budget_hours' => '20', 'budget_amount' => '300']);
echo "2. The numbers equal mcp_labor_weekly\n";
[$c, $d] = bscreen($mara, "/budget?site=102&week=$ws");
$areas = array_column($d['areas'] ?? [], null, 'area');
ok($c === 200 && $areas['all']['scheduled_hours'] === 22.0 && $areas['all']['scheduled_cost'] === 368.64 && $areas['all']['budget_hours'] === 100.0 && $areas['all']['budget_amount'] === 900.5, 'Total: 22 h scheduled, $368.64 (147.66 + 128.22 + 92.76), against 100 h and $900.50');
ok($areas['front']['scheduled_hours'] === 18.0 && $areas['front']['scheduled_cost'] === 275.88 && $areas['front']['budget_hours'] === 20.0 && $areas['front']['budget_amount'] === 300.0, 'Front of house: 18 h (the open shift\'s 6 included), $275.88, against 20 h and $300');
ok($areas['bar']['scheduled_hours'] === 4.0 && $areas['bar']['scheduled_cost'] === 92.76 && $areas['bar']['budget_amount'] === 80.0, 'Bar: 4 h, $92.76, against $80');
ok($areas['kitchen']['scheduled_hours'] === 0.0 && $areas['kitchen']['budget_hours'] === 30.0 && !isset($areas['management']) && !isset($areas['other']), 'Kitchen: a budget and nothing scheduled shows; areas with neither (management, other) do not');
$records = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$records->exec("SELECT set_config('app.member_id', '33', false)");
$view = [];
foreach ($records->query("SELECT area, scheduled_hours, scheduled_cost, budget_hours, budget_amount FROM mcp_labor_weekly WHERE site_id = 102 AND week_start = '$ws'")->fetchAll() as $r) { $view[$r['area']] = $r; }
$same = true;
foreach ($view as $area => $r) { $a = $areas[$area] ?? null; $same = $same && $a && (float) $r['scheduled_hours'] === $a['scheduled_hours'] && (float) $r['scheduled_cost'] === $a['scheduled_cost'] && ($r['budget_hours'] === null ? null : (float) $r['budget_hours']) === $a['budget_hours'] && ($r['budget_amount'] === null ? null : (float) $r['budget_amount']) === $a['budget_amount']; }
ok($same && count($view) === count($areas), 'the page\'s JSON equals the records role\'s mcp_labor_weekly row for row (' . count($view) . ' areas: ' . implode(', ', array_keys($view)) . ')');
$html = page($mara, "/budget?site=102&week=$ws")['body'];
$txt = fn (string $id) => trim(html_entity_decode(strip_tags((preg_match('/id="' . $id . '"[^>]*>(.*?)<\/(?:span|div)>/s', $html, $m) ? $m[1] : ''))));
ok($txt('budget-area-all-cost') === '$368.64 of $900.50' && $txt('budget-area-all-hours') === '22 h of 100 h', 'the page: "' . $txt('budget-area-all-cost') . '" and "' . $txt('budget-area-all-hours') . '"');
ok(str_contains($html, 'id="budget-area-bar-over"') && !str_contains($html, 'id="budget-area-all-over"') && !str_contains($html, 'id="budget-area-front-over"'), 'only the Bar is over budget ($92.76 > $80): the badge is there, and on no other area');
ok(str_contains($html, 'bar-danger') && preg_match('/id="budget-area-bar".*?bar-danger/s', $html), 'and its bar is danger');
ok(str_contains($html, 'Overtime multipliers are not applied') && str_contains($html, 'open shift adds hours and no cost'), 'the page says overtime is not applied and an open shift costs nothing');
echo "3. By day\n";
$by = array_column($d['by_day'], null, 'date');
ok($by[$fri]['scheduled_hours'] === 12.0 && $by[$fri]['scheduled_cost'] === 275.88 && $by[$sat]['scheduled_hours'] === 4.0 && $by[$sat]['scheduled_cost'] === 92.76, 'Friday 12 h / $275.88 (two servers), Saturday 4 h / $92.76');
ok($by[$sun]['scheduled_hours'] === 6.0 && $by[$sun]['scheduled_cost'] === null && $by[$sun]['open_shifts'] === 1, 'Sunday: an open shift adds 6 h and NO cost (null)');
ok(round(array_sum(array_column($d['by_day'], 'scheduled_hours')), 2) === 22.0 && round(array_sum(array_map(fn ($r) => (float) $r['scheduled_cost'], $d['by_day'])), 2) === 368.64, 'the days add up to the week');
ok(count($d['by_day']) === 3 && str_contains($html, 'id="budget-day-' . $sun . '-cost"'), 'days with nothing scheduled are dashes on the page, not rows of zero in the JSON');
echo "4. A rate changes the cost, never the hours\n";
[$c, $b] = act($owner, '/positions/rate.php', ['position' => $srv, 'rate' => '30.00']);
[, $d2] = bscreen($mara, "/budget?site=102&week=$ws"); $a2 = array_column($d2['areas'], null, 'area');
ok($c === 200 && $a2['all']['scheduled_hours'] === 22.0 && $a2['all']['scheduled_cost'] === 420.42 && $a2['front']['scheduled_cost'] === 327.66, 'the Server default 21.37 → 30.00: Ana\'s shift is $180.00, Priya\'s stays on her own rate — Total $420.42, hours still 22');
ok($a2['bar']['scheduled_cost'] === 92.76, 'the Bar is unchanged');
act($owner, '/positions/rate.php', ['position' => $srv, 'rate' => '21.37']);
[, $d3] = bscreen($mara, "/budget?site=102&week=$ws");
ok(array_column($d3['areas'], null, 'area')['all']['scheduled_cost'] === 368.64, 'restored: $368.64 again');
act($owner, '/staff/wage.php', ['member' => 30, 'position' => $srv, 'rate' => '25.00']);
[, $d4] = bscreen($mara, "/budget?site=102&week=$ws");
ok(array_column($d4['areas'], null, 'area')['all']['scheduled_cost'] === 390.42, 'Ana\'s OWN rate (25.00) beats the default: Total $390.42');
act($owner, '/staff/wage.php', ['member' => 30, 'position' => $srv, 'rate' => '']);
echo "5. An open shift adds hours and no cost\n";
fx(102, $srv, null, $ws, 3);
[, $d5] = bscreen($mara, "/budget?site=102&week=$ws"); $a5 = array_column($d5['areas'], null, 'area');
ok($a5['all']['scheduled_hours'] === 28.0 && $a5['all']['scheduled_cost'] === 368.64 && $a5['front']['scheduled_hours'] === 24.0, 'another open Server shift (6 h): hours 22 → 28, cost stays $368.64');
[$sid] = [(int) one("SELECT id FROM shifts WHERE scope_id = 102 AND assignee_member_id IS NULL AND week_id = (SELECT id FROM schedule_weeks WHERE scope_id = 102 AND week_start = :w) ORDER BY id DESC LIMIT 1", ['w' => $ws])];
admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now(), cancel_reason = 'SMOKE' WHERE id = $sid");
[, $d6] = bscreen($mara, "/budget?site=102&week=$ws");
ok(array_column($d6['areas'], null, 'area')['all']['scheduled_hours'] === 22.0, 'a cancelled shift is not counted');
echo "6. The builder carries the same\n";
[, $bd] = screen($mara, "/builder?site=102&week=$ws");
ok((float) ($bd['labor']['scheduled_cost'] ?? 0) === 368.64 && (float) ($bd['labor']['budget_amount'] ?? 0) === 900.5 && (float) $bd['labor']['scheduled_hours'] === 22.0, 'the builder\'s labor bar is the budget screen\'s total (368.64 of 900.5)');
$bh = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($bh, '$368.64 of $900.50 budget') && str_contains($bh, 'id="builder-foot-cost-' . $fri . '"') && str_contains(strip_tags(preg_match('/id="builder-foot-cost-' . $fri . '"[^>]*>(.*?)<\/td>/s', $bh, $m) ? $m[1] : ''), '$275.88'), 'the builder: "$368.64 of $900.50 budget" and Friday\'s cost row $275.88');
ok(str_contains($bh, 'id="builder-to-budget"') && str_contains($bh, 'id="builder-to-forecast"'), 'the builder links to the Budget and the Forecast');
echo "7. Clearing, refusing\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'kitchen']);
$row = q("SELECT before, after FROM activity_log WHERE action = 'budget.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && budget_of(102, $ws, 'kitchen') === null && str_contains($b['location'] ?? '', 'notice=bd_cleared') && json_decode($row['before'], true)['hours'] == 30 && json_decode($row['after'], true)['removed'] === true, 'both empty removes it (kitchen), the log keeps what it was');
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'management']);
ok($c === 200 && budget_of(102, $ws, 'management') === null, 'removing a budget that was never set is a quiet 200');
foreach ([['budget_hours' => '-1', 'm' => 'Budget hours are from 0 to 99999.'], ['budget_hours' => 'lots', 'm' => 'Budget hours are from 0 to 99999.'], ['budget_hours' => '100000', 'm' => 'Budget hours are from 0 to 99999.'],
          ['budget_amount' => '-5', 'm' => 'A budget amount is from 0 to 9999999.'], ['budget_amount' => '99999999', 'm' => 'A budget amount is from 0 to 9999999.'],
          ['area' => 'kitchenette', 'budget_hours' => '5', 'm' => 'The area is all, front, kitchen, bar, management or other.'], ['week_start' => 'soon', 'budget_hours' => '5', 'm' => 'Give week_start as a date like 2026-10-05.']] as $t) {
    $m = $t['m']; unset($t['m']);
    [$c, $b] = act($owner, '/labor/budget.php', $t + ['site' => 102, 'week_start' => $ws, 'area' => 'other']);
    ok($c === 422 && msg($b) === $m, 'budget_save ' . json_encode($t) . ' → 422 "' . msg($b) . '"');
}
ok(budget_of(102, $ws, 'other') === null, 'none of the refused ones was saved');
[$c, $b] = act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => wk(121), 'area' => 'all', 'budget_amount' => '1,250.00']);
ok($c === 200 && (float) budget_of(102, wk(121), 'all')['budget_amount'] === 1250.0 && (float) budget_of(102, $ws, 'all')['budget_amount'] === 900.5, 'a comma in an amount is fine ($1,250.00), and another week\'s budget is its own');
echo "8. Cost is invisible without labor.view\n";
$sinceAny = last_activity_id();
foreach (['Pat (schedule.build, no labor.view)' => $pat, 'Sol (settings.manage, no labor.view)' => $sol, 'Priya (staff)' => $priya, 'Ana (staff)' => $ana] as $who => $jar) {
    $r = req('GET', "/budget?site=102&week=$ws", ['jar' => $jar]); $rj = req('GET', "/budget?site=102&week=$ws", ['jar' => $jar, 'headers' => JSONH]);
    ok($r['code'] === 403 && $rj['code'] === 403 && array_filter(cost_marks(), fn ($m) => str_contains($r['body'] . $rj['body'], $m)) === [], "$who: /budget is 403 (page and JSON), no figure in the answer");
}
foreach (['Pat' => $pat, 'Sol' => $sol] as $who => $jar) {
    $html = page($jar, "/builder?site=102&week=$ws")['body']; [, $bj] = screen($jar, "/builder?site=102&week=$ws");
    ok(!str_contains($html, 'builder-foot-cost') && !str_contains($html, 'id="builder-labor"') && !str_contains($html, 'id="builder-to-budget"') && !array_key_exists('labor', $bj) && array_filter(cost_marks(), fn ($m) => str_contains($html . json_encode($bj), $m)) === [], "$who: the builder has no cost row, no labor bar, no Budget link, no `labor` in its JSON, no cost figure");
    ok(str_contains($html, 'builder-foot-hours') && str_contains($html, 'id="builder-to-forecast"'), "$who: it still shows the hours and the Forecast link");
    $fc = req('GET', "/forecast?site=102&week=$ws", ['jar' => $jar]);
    ok($fc['code'] === 200 && !str_contains($fc['body'], 'id="forecast-to-budget"') && !str_contains($fc['body'], '$'), "$who: the forecast page shows no Budget link and no money");
}
$sh = req('GET', "/shifts/" . (int) one("SELECT id FROM shifts WHERE scope_id = 102 AND assignee_member_id = 26 AND week_id = (SELECT id FROM schedule_weeks WHERE scope_id = 102 AND week_start = :w)", ['w' => $ws]), ['jar' => $pat]);
ok(array_filter(cost_marks(), fn ($m) => str_contains($sh['body'], $m)) === [], 'a shift\'s own page shows Pat no cost');
$mine = ['Priya' => $priya, 'Ana' => $ana];
foreach ($mine as $who => $jar) { foreach (["/builder?site=102&week=$ws", "/team-schedule", "/my-schedule", '/', "/forecast?site=102&week=$ws", '/requests'] as $p) { foreach ([[], JSONH] as $h) { $r = req('GET', $p, ['jar' => $jar, 'headers' => $h]); if (array_filter(cost_marks(), fn ($m) => str_contains($r['body'], $m))) { ok(false, "$who $p leaks a cost"); } } } }
ok(true, 'a crawl of the builder, team schedule, my schedule, home, forecast and requests as staff shows no cost figure');
echo "9. Rights\n";
[$c, $b] = act($mara, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '1']);
ok($c === 403 && (float) budget_of(102, $ws, 'all')['budget_hours'] === 100.0, 'a manager without settings.manage: budget_save is 403 and nothing changed');
[$c, $b] = act($sol, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '1']);
ok($c === 403 && (float) budget_of(102, $ws, 'all')['budget_hours'] === 100.0, 'settings.manage WITHOUT labor.view: 403 too — an amount is a cost figure');
[$c, $b] = act($mara, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '10']);
ok($c === 403 && ratio(102, $srv) === null, 'a manager without settings.manage: ratio_save is 403');
[$c, $b] = act($sol, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '10', 'min_staff' => '1']);
ok($c === 200 && (float) ratio(102, $srv)['covers_per_staff'] === 10.0, 'settings.manage alone may set a ratio (a ratio is not pay)');
[$c, $b] = act($priya, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '1']);
ok($c === 403, 'staff: budget_save is 403');
[$c, $b] = act($dee, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '1']);
ok($c === 404 && (float) budget_of(102, $ws, 'all')['budget_hours'] === 100.0, 'Downtown\'s manager on Airport: 404');
$r = req('GET', "/budget?site=102&week=$ws", ['jar' => $dee]);
ok($r['code'] === 404 && array_filter(cost_marks(), fn ($m) => str_contains($r['body'], $m)) === [], 'Downtown\'s manager opening Airport\'s budget: 404');
$r = req('GET', "/budget?site=101&week=$ws", ['jar' => $dee]);
ok($r['code'] === 200 && array_filter(cost_marks(), fn ($m) => str_contains($r['body'], $m)) === [], 'and Downtown\'s own: 200, none of Airport\'s figures');
ok(str_contains(page($mara, "/budget?site=102&week=$ws")['body'], 'id="budget-form-all"') === false && !str_contains(page($mara, "/budget?site=102&week=$ws")['body'], 'budget-form-'), 'Mara sees the numbers but no form to change them');
ok(str_contains(page($owner, "/budget?site=102&week=$ws")['body'], 'id="budget-form-all"') && str_contains(page($owner, "/budget?site=102&week=$ws")['body'], 'id="budget-form-management"'), 'the owner sees a form for every area, including those with nothing yet');
echo "10. The trail\n";
$logs = q("SELECT before, after, scope_id, source FROM activity_log WHERE action = 'budget.update' AND actor_member_id IS NOT NULL");
ok(count($logs) >= 8 && count(array_filter($logs, fn ($r) => (int) $r['scope_id'] === 102 && $r['source'] === 'web')) === count($logs), count($logs) . ' budget.update rows, every one with the site and source web');
ok(wage_leaks(json_encode($logs)) === [] && array_filter(cost_marks(), fn ($m) => str_contains(json_encode($logs), $m)) === [], 'no wage and no scheduled cost in any of them');
finish();
