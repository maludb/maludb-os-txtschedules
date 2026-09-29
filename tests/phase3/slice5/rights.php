<?php
/**
 * Proof — rights (spec "Proof: Rights"): who opens the forecast and the budget, who may change what, a restaurant not held does not exist, the handlers refuse a GET, a missing token and a
 * missing CSRF; the records role's views say the same as the pages; and a crawl of every page of the slice as each kind of person finds no wage and — without labor.view — no cost.
 */
require __DIR__ . '/lib.php';
$W = reset5();
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $ana = as_member(30); $dee = as_dee(); $joe = as_member(34); $sol = as_setter(); $dana = as_member(32);
$ws = wk(130); $fri = dayn($ws, 4); $dinner = dpid(102, 'dinner'); $srv = $W['aSrv'];
fx(102, $srv, 26, $ws, 4); fx(102, $srv, 30, $ws, 4); fx(101, $W['dSrv'], 34, $ws, 4);
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '100', 'budget_amount' => '900.50']);
act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '80']);
act($dee, '/labor/forecast.php', ['site' => 101, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '33']);
echo "1. Who opens the screens\n";
$people = ['Owner' => $owner, 'Mara (manager, Airport)' => $mara, 'Pat (builds, no pay)' => $pat, 'Sol (settings, no pay)' => $sol, 'Dana (shift lead)' => $dana, 'Priya (staff)' => $priya, 'Ana (staff)' => $ana, 'Dee (manager, Downtown)' => $dee, 'Joe (staff, Downtown)' => $joe];
$expect = [ // [forecast 102, budget 102, forecast 101, budget 101]
    'Owner' => [200, 200, 200, 200], 'Mara (manager, Airport)' => [200, 200, 404, 404], 'Pat (builds, no pay)' => [200, 403, 404, 404], 'Sol (settings, no pay)' => [200, 403, 404, 404], 'Dana (shift lead)' => [403, 403, 404, 404],
    'Priya (staff)' => [403, 403, 404, 404], 'Ana (staff)' => [403, 403, 404, 404], 'Dee (manager, Downtown)' => [404, 404, 200, 200], 'Joe (staff, Downtown)' => [404, 404, 403, 403]];
$paths = ["/forecast?site=102&week=$ws", "/budget?site=102&week=$ws", "/forecast?site=101&week=$ws", "/budget?site=101&week=$ws"];
foreach ($people as $who => $jar) {
    $got = []; $gotJ = [];
    foreach ($paths as $p) { $got[] = req('GET', $p, ['jar' => $jar])['code']; $gotJ[] = req('GET', $p, ['jar' => $jar, 'headers' => JSONH])['code']; }
    ok($got === $expect[$who] && $gotJ === $expect[$who], "$who: forecast/budget at Airport then Downtown → " . implode('/', $got) . ' (page and JSON alike)');
}
$r = req('GET', "/forecast?site=102&week=$ws");
ok(in_array($r['code'], [302, 401], true), 'anonymous: the forecast is not shown (' . $r['code'] . ')');
$r = req('GET', "/forecast?site=999&week=$ws", ['jar' => $mara]);
ok($r['code'] === 404, 'a restaurant that does not exist: 404');
echo "2. Who may change what (every handler)\n";
$h = [['/labor/forecast.php', ['on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '5'], 'schedule.build'],
      ['/labor/forecast-copy.php', ['from_week' => $ws, 'to_week' => wk(131)], 'schedule.build'],
      ['/labor/forecast-fill.php', ['week_start' => $ws], 'schedule.build'],
      ['/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '9'], 'settings.manage'],
      ['/labor/budget.php', ['week_start' => $ws, 'area' => 'all', 'budget_hours' => '9'], 'settings.manage + labor.view']];
$snap = fn () => json_encode([q('SELECT * FROM forecast_covers ORDER BY id'), q('SELECT * FROM staffing_ratios ORDER BY position_id'), q('SELECT * FROM labor_budgets ORDER BY id')]);
$want = [ // [priya, dana, dee(at 102 → 404), sol, pat, mara]
    '/labor/forecast.php' => [403, 403, 404, 200, 200, 200], '/labor/forecast-copy.php' => [403, 403, 404, 200, 200, 200], '/labor/forecast-fill.php' => [403, 403, 404, 200, 200, 200],
    '/labor/ratio.php' => [403, 403, 404, 200, 403, 403], '/labor/budget.php' => [403, 403, 404, 403, 403, 403]];
kread(['mode' => 'no_connection']);
foreach ($h as [$path, $form, $needs]) {
    $codes = [];
    foreach ([$priya, $dana, $dee, $sol, $pat, $mara] as $jar) {
        $before = $snap();
        [$c, $b] = act($jar, $path, ['site' => 102] + $form);
        $codes[] = $c === 409 ? 200 : $c;                       // a fill with no connection refuses in words after the right was granted
        if ($c === 403 || $c === 404) { if ($snap() !== $before) { ok(false, "$path: a refused call changed data"); } }
        if ($path === '/labor/ratio.php' && $c === 200) { act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']); }
    }
    ok($codes === $want[$path], "$path (needs $needs): Priya, Dana, Dee, Sol, Pat, Mara → " . implode('/', $codes));
}
foreach ($h as [$path, $form]) {
    $r = req('POST', $path, ['jar' => $owner, 'form' => ['site' => 102] + $form]);       // no CSRF token
    ok($r['code'] === 403, "$path without a CSRF token: 403");
    $r = req('POST', $path, ['headers' => JSONH, 'form' => ['site' => 102] + $form]);     // no session, no token
    ok($r['code'] === 401, "$path with no session: 401");
    $r = req('GET', $path . '?site=102', ['jar' => $owner]);
    ok($r['code'] === 405, "$path by GET: 405");
}
ok((int) cell(102, $fri, $dinner)['expected_covers'] === 5 && (float) ratio(102, $srv)['covers_per_staff'] === 25.0 && (float) budget_of(102, $ws, 'all')['budget_hours'] === 100.0, 'and after all that: the covers are the 5 the three who may type them typed, the ratio still 1 per 25 and the budget still 100 h — no refused call changed anything');
echo "3. The records role says the same\n";
$records = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$n = function (int $m, string $sql) use ($records): int { $records->exec("SELECT set_config('app.member_id', '$m', false)"); return (int) $records->query($sql)->fetchColumn(); };
$q1 = "SELECT count(*) FROM mcp_forecast_covers WHERE site_id = 102"; $q2 = "SELECT count(*) FROM mcp_labor_budgets WHERE site_id = 102"; $q3 = "SELECT count(*) FROM mcp_labor_weekly WHERE site_id = 102";
$q4 = "SELECT count(*) FROM mcp_staffing_ratios WHERE site_id = 102"; $q5 = "SELECT count(*) FROM mcp_day_parts WHERE site_id = 102";
$wid = (int) one("SELECT id FROM schedule_weeks WHERE scope_id = 102 AND week_start = :w", ['w' => $ws]);
$q6 = "SELECT count(*) FROM ts_staffing_needs(102, '$ws', '$ws')"; $q7 = "SELECT count(*) FROM mcp_shifts WHERE site_id = 102 AND cost IS NOT NULL AND week_id = $wid";
ok($n(33, $q1) >= 1 && $n(35, $q1) === $n(33, $q1) && $n(37, $q1) === $n(33, $q1) && $n(26, $q1) === 0 && $n(32, $q1) === 0 && $n(36, $q1) === 0, 'mcp_forecast_covers: Mara, Pat and Sol (who build) see Airport\'s cell; Priya, Dana and Dee do not');
ok($n(33, $q2) === 1 && $n(1, $q2) === 1 && $n(35, $q2) === 0 && $n(37, $q2) === 0 && $n(26, $q2) === 0 && $n(36, $q2) === 0, 'mcp_labor_budgets: only labor.view (Mara, the owner) — not Pat, Sol, Priya or Dee');
ok($n(33, $q3) >= 1 && $n(35, $q3) === 0 && $n(37, $q3) === 0 && $n(26, $q3) === 0 && $n(36, $q3) === 0, 'mcp_labor_weekly: the same');
ok($n(33, $q4) === 1 && $n(35, $q4) === 1 && $n(26, $q4) === 0 && $n(36, $q4) === 0, 'mcp_staffing_ratios: people who build only');
ok($n(26, $q5) === 2 && $n(30, $q5) === 2 && $n(36, $q5) === 0 && $n(34, $q5) === 0, 'mcp_day_parts: any member of the restaurant (Priya, Ana), nobody else (Dee, Joe)');
ok($n(33, $q6) >= 1 && $n(35, $q6) >= 1 && $n(26, $q6) === 0 && $n(36, $q6) === 0, 'ts_staffing_needs: rows for people who build at the site, none for Priya or Dee');
ok($n(33, $q7) === 2 && $n(35, $q7) === 0 && $n(26, $q7) === 0, 'mcp_shifts.cost: Mara reads the two costed shifts; Pat and Priya read no cost');
echo "4. A crawl: every page of the slice, HTML and JSON, as each kind of person\n";
$pages = ["/forecast?site=102&week=$ws", "/forecast?site=102&week=$ws&day=$fri", "/forecast?site=101&week=$ws", "/budget?site=102&week=$ws", "/budget?site=101&week=$ws", "/budget?site=102&week=" . wk(131), "/builder?site=102&week=$ws", "/builder?site=101&week=$ws", "/positions/?site=102", '/'];
$costful = ['Owner' => true, 'Mara (manager, Airport)' => true, 'Dee (manager, Downtown)' => true];
$allowedCost = ['Owner' => ['147.66', '128.22', '92.76', '368.64', '275.88'], 'Mara (manager, Airport)' => ['147.66', '128.22', '92.76', '368.64', '275.88'], 'Dee (manager, Downtown)' => []];
foreach ($people as $who => $jar) {
    $leaks = []; $costLeaks = []; $n = 0;
    foreach ($pages as $p) {
        foreach ([[], JSONH] as $hd) {
            $r = req('GET', $p, ['jar' => $jar, 'headers' => $hd]); $n++;
            foreach (wage_marks() as $w) { if (str_contains(strip_ts($r['body']), $w) && !(str_starts_with($p, '/positions/') && ($costful[$who] ?? false))) { $leaks[] = "$w @ $p"; } }
            foreach (cost_marks() as $m) { if (str_contains($r['body'], $m) && !in_array($m, $allowedCost[$who] ?? [], true)) { $costLeaks[] = "$m @ $p"; } }
        }
    }
    ok($leaks === [] && $costLeaks === [], "$who: $n responses — no wage, and no cost they may not see" . ($leaks || $costLeaks ? ' — LEAK: ' . implode('; ', array_slice(array_merge($leaks, $costLeaks), 0, 5)) : ''));
}
$logs = json_encode(q("SELECT action, before, after, route FROM activity_log WHERE action IN ('forecast.update', 'forecast.copy', 'forecast.fill', 'ratio.update', 'budget.update')")) . json_encode(q('SELECT subject, body FROM notification_outbox'));
ok(wage_leaks($logs) === [] && array_filter(cost_marks(), fn ($m) => str_contains($logs, $m)) === [], 'the trail and the outbox hold no wage and no scheduled cost');
finish();
