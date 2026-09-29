<?php
/**
 * Proof — reports (spec "Proof": reports): each report equals its view's numbers (the records role's, for the same person); a manager without labor.view has no labor report and no cost column; the CSV is exactly the table and
 * is logged report.export; no report, no CSV and no log line holds a fixture rate; paging; the range; rights; the overrides list carries the reason a manager gave in the builder (slice 2's handler).
 */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = admin_jar(102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dana = as_member(32); $lee = as_member(31); $ana = as_member(30); $dee = as_dee(); $dex = as_dex(); $sol = as_setter();
$srv = $W['aSrv']; $bar = $W['aBar'];
admin_sql("DELETE FROM exchange_claims; DELETE FROM exchange_invitees; DELETE FROM exchanges;");        // a report of trades counts every trade of the restaurant: earlier proofs' are gone
$A = wk(190); $B = wk(191); $C = wk(192); $D = wk(193); $E = wk(194);
$today = (new DateTimeImmutable('now', new DateTimeZone(TZA)))->format('Y-m-d');
$costs = ['838.56', '699.42', '139.14', '442.98', '110.75', '33.42', '221.49', '66.84', '96.17', '32.06', '267.36', '128.22', '147.66', '900.50', '61.94'];

echo "0. The fixture (through the fixtures' own tables; overrides and trades through the handlers)\n";
foreach ([26 => [1, 2, 3], 30 => [1], 31 => [4]] as $m => $days) { foreach ($days as $dy) { fx(102, $srv, $m, $A, $dy); } }
fx(102, $bar, 30, $A, 2);
$open = fx(102, $srv, null, $A, 5);
$cx = fx(102, $srv, 32, $A, 6);
fx_publish(102, $A);
admin_sql("UPDATE shifts SET published_at = now() - interval '100 hours' WHERE week_id = " . week_id_of(102, $A) . "; UPDATE shifts SET status = 'cancelled', cancelled_at = published_at + interval '30 hours' WHERE id = $cx");
fx(102, $srv, 26, $B, 1); $dOpen = fx(102, $srv, null, $B, 2);                                            // week B is a draft
// the overrides, made the way a manager makes them in the builder
$e = week_id_of(102, $E) ?? null;
fx(102, $srv, 31, $E, 1, '17:00', '23:00'); $rest = fx(102, $srv, null, $E, 2, '06:00', '12:00');
fx(102, $srv, null, $E, 4, '17:00', '23:30', ['break' => 0]); $brk = (int) one("SELECT max(id) FROM shifts WHERE scope_id = 102 AND week_id = :w", ['w' => week_id_of(102, $E)]);
[$c, $b] = act($mara, '/shifts/assign.php', ['shift' => $rest, 'assignee' => 31, 'override_reason' => 'SMOKE s7 rest is fine']);
ok($c === 200, 'Mara puts Lee on a shift with 7 hours of rest, with a reason (a soft warning overridden): 200');
[$c, $b] = act($owner, '/shifts/assign.php', ['shift' => $brk, 'assignee' => 30, 'override_reason' => 'SMOKE s7 no break needed']);
ok($c === 200, 'the owner puts Ana on a 6.5-hour shift with no break planned, with a reason: 200');
// trades
$T = wk(196);
$ps = fn (int $dy) => fx(102, $srv, 26, $T, $dy); $tMon = $ps(0); $tWed = $ps(2); $tFri = $ps(4); $tThu = $ps(3); $tTue = fx(102, $srv, 30, $T, 1); $tSat = fx(102, $srv, 30, $T, 5);
fx_publish(102, $T);
$x1 = offer($priya, $tMon); claim($lee, $x1); $x2 = offer($priya, $tWed); claim($lee, $x2); $x3 = offer($priya, $tFri); claim($ana, $x3);
$x4 = offer($priya, $tThu); act($priya, '/exchanges/cancel.php', ['exchange' => $x4]);
[$c, $b] = act($ana, '/exchanges/give.php', ['shift' => $tTue, 'colleague' => 31]); $x5 = (int) $b['record_id']; act($lee, '/exchanges/accept.php', ['exchange' => $x5]);
$x6 = offer($ana, $tSat);
admin_sql("UPDATE exchanges SET created_at = now() - interval '10 hours', decided_at = now() - interval '5 hours' WHERE status = 'approved'");
ok(status_of($x1) === 'approved' && status_of($x2) === 'approved' && status_of($x3) === 'approved' && status_of($x4) === 'cancelled' && status_of($x5) === 'approved' && status_of($x6) === 'open', 'the trades: three offers taken, one cancelled, one give accepted, one offer still open');
// time off
$unp = typ(102, 'unpaid'); $vac = typ(102, 'vacation'); $TO = wk(197);
[$c, $r1] = req_off($priya, $unp, dayn($TO, 0), dayn($TO, 1)); act($mara, '/time-off/approve.php', ['request' => $r1['record_id']]);
grant(30, $vac, 40);
[$c, $r2] = req_off($ana, $vac, dayn($TO, 2), dayn($TO, 2)); act($mara, '/time-off/approve.php', ['request' => $r2['record_id']]);
ok(status_off((int) $r1['record_id']) === 'approved' && status_off((int) $r2['record_id']) === 'approved' && bal(30, $vac) === 32.0, 'Priya\'s two days unpaid and Ana\'s vacation day are approved (Ana\'s balance 40 → 32)');
$AB = [$A, dayn($B, 6)];
// budgets and thresholds (last: an overtime line of 9 and a limit of 5 would make every trade above wait for a manager)
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $A, 'area' => 'all', 'budget_hours' => '100', 'budget_amount' => '900.50']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $A, 'area' => 'bar', 'budget_hours' => '5']);
[$c] = set($owner, 102, ['overtime_weekly_hours' => '9']);
admin_sql("UPDATE staff_profiles SET max_hours_week = 5 WHERE member_id = 31");


echo "1. The list of reports, by right\n";
$expect = ['Mara (manager)' => [$mara, ['hours', 'labor', 'open', 'trades', 'overtime', 'overrides', 'time-off']], 'Owner' => [$owner, ['hours', 'labor', 'open', 'trades', 'overtime', 'overrides', 'time-off']],
           'Pat (builds, no pay)' => [$pat, ['hours', 'open', 'trades', 'overtime', 'overrides']], 'Sol (settings, builds, no pay)' => [$sol, ['hours', 'open', 'trades', 'overtime', 'overrides']]];
foreach ($expect as $who => [$jar, $want]) {
    [$c, $d] = screen($jar, '/reports/?site=102');
    ok($c === 200 && array_column($d['reports'], 'report') === $want, "$who is offered " . implode(', ', $want));
}
$h = page($mara, '/reports/?site=102')['body'];
ok(str_contains($h, 'id="reports-header"') && str_contains($h, 'id="report-hours"') && str_contains($h, 'id="report-time-off"') && str_contains($h, 'id="nav-reports"') && !str_contains($h, 'is not built yet'), 'the page is a list of the seven with a menu item — not a placeholder');
foreach (['Priya' => $priya, 'Dana (shift lead)' => $dana] as $who => $jar) { ok(req('GET', '/reports/?site=102', ['jar' => $jar])['code'] === 403 && req('GET', '/reports/?site=102&report=hours', ['jar' => $jar, 'headers' => JSONH])['code'] === 403, "$who: the reports are 403"); }
ok(req('GET', '/reports/?site=102', ['jar' => $dee])['code'] === 404 && req('GET', '/reports/?site=102', ['headers' => JSONH])['code'] === 401 && req('GET', '/reports/?site=102&report=nope', ['jar' => $owner])['code'] === 404, 'another restaurant\'s manager: 404; anonymous: 401; an unknown report: 404');
ok(str_contains(page($pat, '/')['body'], 'id="nav-reports"') && !str_contains(page($priya, '/')['body'], 'id="nav-reports"'), 'the menu item is there for Pat and not for Priya');

echo "2. Hours\n";
[$c, $r] = rep($mara, 102, 'hours', $AB[0], $AB[1]);
$rows = $r['rows'];
ok($c === 200 && count($rows) === 4 && array_column($rows, 'person') === ['SMOKE Ana', 'SMOKE Lee', 'SMOKE Priya', 'SMOKE Priya'] && array_column($rows, 'week') === [$A, $A, $A, $B], 'hours: one row a person and week — Ana, Lee, Priya in week A and Priya in the draft week B');
ok(array_column($rows, 'hours') === ['12', '6', '18', '6'] && array_column($rows, 'shifts') === ['2', '1', '3', '1'] && $rows[0]['positions'] === 'Bar 6 h, Server 6 h' && $rows[2]['positions'] === 'Server 18 h', 'paid hours 12 / 6 / 18 / 6 (the cancelled shift and the open ones are not anyone\'s); Ana\'s positions "Bar 6 h, Server 6 h"');
ok($rows[2]['flag'] === 'Over the overtime line' && $rows[0]['flag'] === 'Over the overtime line' && $rows[1]['flag'] === 'Over their own limit' && $rows[3]['flag'] === '' && $rows[1]['own_limit'] === '5' && $rows[0]['overtime_after'] === '9', 'flags: Priya and Ana over the line of 9, Lee over his own limit of 5, Priya\'s draft week clean');
$view = recq(33, "SELECT display_name, week_start::text AS w, scheduled_hours, shifts, max_hours_week FROM mcp_hours_weekly WHERE site_id = 102 AND week_start IN ('$A', '$B') ORDER BY week_start, lower(display_name)");
ok(count($view) === 4 && array_map(fn ($v) => [$v['display_name'], $v['w'], rtrim(rtrim((string) $v['scheduled_hours'], '0'), '.'), (string) $v['shifts']], $view) === array_map(fn ($x) => [$x['person'], $x['week'], $x['hours'], $x['shifts']], $rows), 'equal to mcp_hours_weekly as the records role reads it for Mara: the same people, weeks, hours and shifts');
[$c, $r1] = rep($mara, 102, 'hours', $A, dayn($A, 6));
ok(count($r1['rows']) === 3 && !in_array($B, col_of($r1, 'week'), true), 'a range of week A only leaves week B out');
[$c, $r1] = rep($mara, 102, 'hours', dayn($A, 3), dayn($A, 3));
ok(count($r1['rows']) === 3, 'a range that touches only the middle of a week still shows that week');
[$c, $rp] = rep($pat, 102, 'hours', $AB[0], $AB[1]);
ok($rp['rows'] == $rows, 'Pat (builds) sees the same table');

echo "3. Labor against budget\n";
[$c, $r] = rep($mara, 102, 'labor', $AB[0], $AB[1]);
$byWA = []; foreach ($r['rows'] as $x) { $byWA[$x['week'] . '/' . $x['area']] = $x; }
$t = $byWA["$A/Total"] ?? []; $bb = $byWA["$A/Bar"] ?? []; $fr = $byWA["$A/Front of house"] ?? [];
ok($c === 200 && ($t['hours'] ?? '') === '42' && ($t['cost'] ?? '') === '838.56' && ($t['budget_hours'] ?? '') === '100' && ($t['hours_over'] ?? '') === '-58' && ($t['budget_amount'] ?? '') === '900.50' && ($t['cost_over'] ?? '') === '-61.94', 'week A total: 42 h (the open shift adds hours, not cost), cost 838.56 against 100 h and 900.50: 58 h and 61.94 under');
ok(($bb['hours'] ?? '') === '6' && ($bb['cost'] ?? '') === '139.14' && ($bb['budget_hours'] ?? '') === '5' && ($bb['hours_over'] ?? '') === '1' && ($bb['budget_amount'] ?? 'x') === '' && ($fr['hours'] ?? '') === '36' && ($fr['cost'] ?? '') === '699.42', 'bar 6 h / 139.14 (1 h over its 5 h budget); front of house 36 h / 699.42');
ok(($byWA["$B/Total"]['hours'] ?? '') === '12' && ($byWA["$B/Total"]['cost'] ?? '') === '147.66', 'the draft week counts (12 h, 147.66): drafts are the plan');
$lv = recq(33, "SELECT area, scheduled_hours, scheduled_cost, budget_hours, budget_amount FROM mcp_labor_weekly WHERE site_id = 102 AND week_start = '$A' ORDER BY area");
$ok = count($lv) === 3;
foreach ($lv as $v) { $x = $byWA["$A/" . ($v['area'] === 'all' ? 'Total' : ['bar' => 'Bar', 'front' => 'Front of house'][$v['area']])] ?? null; $ok = $ok && $x !== null && (float) $x['hours'] === (float) $v['scheduled_hours'] && (float) $x['cost'] === (float) $v['scheduled_cost']; }
ok($ok, 'equal to mcp_labor_weekly as the records role reads it for Mara: hours and cost of every area');
foreach (['Pat (no labor.view)' => $pat, 'Sol (no labor.view)' => $sol, 'Priya' => $priya, 'Dana' => $dana] as $who => $jar) {
    $c1 = req('GET', "/reports/?site=102&report=labor&from=$A&to=" . dayn($B, 6), ['jar' => $jar, 'headers' => JSONH]); $c2 = req('GET', "/reports/?site=102&report=labor&from=$A&to=" . dayn($B, 6) . '&format=csv', ['jar' => $jar]);
    ok($c1['code'] === 403 && $c2['code'] === 403 && !array_filter($costs, fn ($m) => str_contains($c1['body'] . $c2['body'], $m)), "$who: the labor report and its CSV are 403 with no figure in them");
}
[$c, $r] = rep($dee, 101, 'labor', $AB[0], $AB[1]);
ok($c === 200 && $r['rows'] === [], 'Dee (Downtown manager) asks Downtown\'s labor report: it is empty of Airport\'s figures');
ok(rep($dee, 102, 'labor', $AB[0], $AB[1])[0] === 404, 'and Airport\'s: 404');

echo "4. Open shifts unfilled\n";
[$c, $r] = rep($mara, 102, 'open', $AB[0], $AB[1]);
$rows = $r['rows'];
ok($c === 200 && count($rows) === 3 && array_column($rows, 'state') === ['Open', 'Cancelled', 'Open, not published'] && array_column($rows, 'week') === [$A, $A, $B] && array_column($rows, 'position') === ['Server', 'Server', 'Server'], 'three rows: the open Saturday, the cancelled Sunday, the unpublished draft\'s open shift — by week and position');
$want = (new DateTimeImmutable(dayn($A, 5)))->format('D Y-m-d') . ' 17:00–23:00';
ok($rows[0]['shift'] === $want && $rows[1]['open_hours'] === '30' && (float) $rows[0]['open_hours'] >= 100 && (float) $rows[0]['open_hours'] < 101 && $rows[2]['open_hours'] === '', 'the shift in the restaurant\'s time ("' . $rows[0]['shift'] . '"); how long: cancelled after 30 h, the open one 100+ h and counting, the unpublished one none yet');
$exp = recq(33, "SELECT count(*) AS n FROM mcp_shifts WHERE site_id = 102 AND ((status = 'scheduled' AND assignee_member_id IS NULL) OR status = 'cancelled') AND week_id IN (" . week_id_of(102, $A) . ', ' . week_id_of(102, $B) . ")")[0]['n'];
ok((int) $exp === 3, 'the same three as mcp_shifts (open scheduled or cancelled) shows Mara');
[$c, $rp] = rep($pat, 102, 'open', $AB[0], $AB[1]);
ok($rp['rows'] == $rows, 'Pat sees the same table');
[$c, $r1] = rep($mara, 102, 'open', dayn($A, 6), dayn($A, 6));
ok(count($r1['rows']) === 1 && $r1['rows'][0]['state'] === 'Cancelled', 'a range of the Sunday alone: the cancelled shift only');

echo "5. Trades\n";
[$c, $r, $dd] = rep($mara, 102, 'trades', plus($today, -3), plus($today, 1)); 
$rows = $r['rows'];
$kinds = array_values(array_filter($rows, fn ($x) => $x['measure'] === 'By kind and state'));
ok($c === 200 && array_map(fn ($x) => [$x['item'], $x['count'], $x['avg_hours']], $kinds) === [['Offer — Open', '1', ''], ['Offer — Done', '3', '5'], ['Offer — Cancelled', '1', ''], ['Give — Done', '1', '5']], 'counts by kind and state: offers open 1, done 3 (5 hours to a decision), cancelled 1 (a cancellation is not a decision — no hours); gives done 1');
$pick = array_values(array_filter($rows, fn ($x) => $x['measure'] === 'Picked up most')); $off = array_values(array_filter($rows, fn ($x) => $x['measure'] === 'Offered most'));
ok(array_map(fn ($x) => [$x['item'], $x['count']], $pick) === [['SMOKE Lee', '2'], ['SMOKE Ana', '1']] && array_map(fn ($x) => [$x['item'], $x['count']], $off) === [['SMOKE Priya', '4'], ['SMOKE Ana', '1']], 'who picks up most (Lee 2, Ana 1) and who offers most (Priya 4, Ana 1)');
$dbn = (int) one("SELECT count(*) FROM exchanges WHERE scope_id = 102"); $sum = array_sum(array_column($kinds, 'count'));
ok($sum === $dbn && $dbn === 6, 'the counts add up to the six trades in the restaurant');
[$c, $r1] = rep($mara, 102, 'trades', plus($today, 5), plus($today, 9));
ok($r1['rows'] === [], 'a range with no trades is empty');
[$c, $rp] = rep($pat, 102, 'trades', plus($today, -3), plus($today, 1));
ok($rp['rows'] == $rows, 'Pat (builds, and cannot see exchanges through the view) reads the same table');
ok(rep($priya, 102, 'trades', plus($today, -3), plus($today, 1))[0] === 403, 'staff: 403');

echo "6. Overtime\n";
[$c, $r] = rep($mara, 102, 'overtime', $AB[0], $AB[1]);
$rows = $r['rows'];
ok($c === 200 && array_column($rows, 'person') === ['SMOKE Ana', 'SMOKE Priya'] && array_column($rows, 'over') === ['3', '9'] && array_column($rows, 'hours') === ['12', '18'] && array_column($rows, 'after') === ['9', '9'], 'over the line of 9: Ana 12 h (3 over), Priya 18 h (9 over) — Lee (6) and the draft week are not on it');
ok(array_column($rows, 'extra') === ['33.42', '110.75'] && str_contains(implode('|', array_column($r['columns'], 'label')), 'Extra cost of the overtime (USD)'), 'with labor.view the extra cost of the overtime hours (33.42, 110.75) — the multiplier is 1.5 — and the column says so');
$ov = recq(33, "SELECT display_name, scheduled_hours, overtime_weekly_hours FROM mcp_hours_weekly WHERE site_id = 102 AND over_overtime AND week_start IN ('$A', '$B') ORDER BY lower(display_name)");
ok(count($ov) === 2 && array_column($ov, 'display_name') === ['SMOKE Ana', 'SMOKE Priya'], 'the same two people the view marks over_overtime');
[$c, $rp] = rep($pat, 102, 'overtime', $AB[0], $AB[1]);
ok(array_column($rp['rows'], 'over') === ['3', '9'] && !isset($rp['rows'][0]['extra']) && !str_contains(implode('|', array_column($rp['columns'], 'label')), 'cost'), 'Pat sees who and how many hours over — and no cost column at all');
$csvPat = csv_of($pat, 102, 'overtime', $AB[0], $AB[1]);
ok($csvPat[0] === 200 && count($csvPat[1][0]) === 5 && !array_filter($costs, fn ($m) => str_contains($csvPat[2]['body'], $m)), 'and neither does his CSV');
set($owner, 102, ['overtime_multiplier' => '2']);
ok(array_column(rep($mara, 102, 'overtime', $AB[0], $AB[1])[1]['rows'], 'extra') === ['66.84', '221.49'], 'with a multiplier of 2 the extra follows (66.84, 221.49): it follows the setting');
set($owner, 102, ['overtime_multiplier' => '1.5']);

echo "7. Overrides\n";
[$c, $r] = rep($mara, 102, 'overrides', plus($today, -1), plus($today, 1));
$rows = $r['rows'];
ok($c === 200 && count($rows) === 2 && in_array('SMOKE s7 rest is fine', array_column($rows, 'reason'), true) && in_array('SMOKE s7 no break needed', array_column($rows, 'reason'), true), 'the two overrides made in the builder are here with the reasons their managers gave');
$m1 = array_values(array_filter($rows, fn ($x) => $x['reason'] === 'SMOKE s7 rest is fine'))[0]; $m2 = array_values(array_filter($rows, fn ($x) => $x['reason'] === 'SMOKE s7 no break needed'))[0];
ok($m1['rule'] === 'Rest between shifts' && $m1['person'] === 'SMOKE Lee' && $m1['by'] === 'SMOKE Mara' && $m1['context'] === 'build' && str_contains($m1['message'], 'Less than 10 hours between shifts.') && $m2['rule'] === 'Break planned' && $m2['person'] === 'SMOKE Ana' && $m2['by'] === 'SMOKE Owner', 'who, about whom, which rule, the warning, where');
$n = (int) recq(33, "SELECT count(*) AS n FROM mcp_rule_overrides WHERE site_id = 102 AND created_at::date >= current_date - 1")[0]['n'];
ok($n === 2, 'equal to mcp_rule_overrides as the records role reads it: 2');
admin_sql("INSERT INTO rule_overrides (scope_id, shift_id, member_id, rule_key, message, reason, overridden_by, context, created_at) VALUES (102, NULL, 31, 'min_rest', 'old', 'SMOKE s7 in march', 33, 'publish', '2031-03-04 03:30:00+00')");
[$c, $r1] = rep($mara, 102, 'overrides', '2031-03-03', '2031-03-03');
ok(count($r1['rows']) === 1 && $r1['rows'][0]['at'] === '2031-03-03 21:30' && $r1['rows'][0]['context'] === 'publish', 'a row of 03:30 UTC on March 4 is March 3, 21:30 in Airport\'s zone, and belongs to March 3');
ok(rep($mara, 102, 'overrides', '2031-03-04', '2031-03-04')[1]['rows'] === [], 'and not to March 4');
ok(rep($pat, 102, 'overrides', plus($today, -1), plus($today, 1))[1]['rows'] == $rows, 'Pat sees the same table');

echo "8. Time off\n";
[$c, $r] = rep($mara, 102, 'time-off', $TO, dayn($TO, 6));
$rows = $r['rows'];
$pr = array_values(array_filter($rows, fn ($x) => $x['person'] === 'SMOKE Priya' && $x['type'] === 'Unpaid'))[0] ?? []; $an = array_values(array_filter($rows, fn ($x) => $x['person'] === 'SMOKE Ana' && $x['type'] === 'Vacation / PTO'))[0] ?? [];
ok($c === 200 && ($pr['requests'] ?? '') === '1' && ($pr['hours'] ?? '') === '16' && ($pr['balance'] ?? 'x') === '' && ($an['requests'] ?? '') === '1' && ($an['hours'] ?? '') === '8' && ($an['balance'] ?? '') === '32', 'Priya: 1 request, 16 h, no balance (unpaid keeps none); Ana: 1 request, 8 h, balance now 32 h');
$vb = recq(33, "SELECT member_id, balance_hours FROM mcp_time_off_balances WHERE site_id = 102 AND type_name = 'Vacation / PTO' AND member_id = 30");
ok((float) $vb[0]['balance_hours'] === 32.0, 'equal to mcp_time_off_balances as the records role reads it for Mara: 32');
[$c, $r1] = rep($mara, 102, 'time-off', dayn($TO, 14), dayn($TO, 20));
$only = array_values(array_filter($r1['rows'], fn ($x) => $x['requests'] !== '0'));
ok($only === [] && count(array_filter($r1['rows'], fn ($x) => $x['person'] === 'SMOKE Ana' && $x['balance'] === '32')) === 1, 'a range with no approved time off still lists balances (Ana 32) with no requests');
foreach (['Pat' => $pat, 'Sol' => $sol, 'Dana' => $dana] as $who => $jar) { ok(req('GET', "/reports/?site=102&report=time-off&from=$TO&to=" . dayn($TO, 6), ['jar' => $jar, 'headers' => JSONH])['code'] === 403, "$who (no requests.approve): the time-off report is 403"); }

echo "9. Paging, the range and the screen\n";
for ($i = 1; $i <= 120; $i++) { $vals[] = "(102, NULL, 31, 'min_rest', 'bulk', 'SMOKE s7 bulk " . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . "', 33, 'build', '2032-06-01 12:00:00+00'::timestamptz + interval '$i minutes')"; }
admin_sql("INSERT INTO rule_overrides (scope_id, shift_id, member_id, rule_key, message, reason, overridden_by, context, created_at) VALUES " . implode(',', $vals) . ", (102, NULL, 31, 'min_rest', 'f', '=SMOKE s7 formula', 33, 'build', '2032-06-02 12:00:00+00')");
[$c, $p1, $d1] = rep($mara, 102, 'overrides', '2032-06-01', '2032-06-02');
ok($c === 200 && $p1['total'] === 121 && count($p1['rows']) === 50 && $p1['page'] === 1 && $p1['per_page'] === 50, 'the JSON says 121 rows, 50 on page 1');
[, $p3] = rep($mara, 102, 'overrides', '2032-06-01', '2032-06-02', '&page=3');
ok(count($p3['rows']) === 21 && $p3['page'] === 3 && rep($mara, 102, 'overrides', '2032-06-01', '2032-06-02', '&page=99')[1]['page'] === 3, 'page 3 has the last 21; a page past the end is the last page');
$h1 = page($mara, "/reports/?site=102&report=overrides&from=2032-06-01&to=2032-06-02")['body'];
ok(substr_count($h1, '<tr id="report-row-') === 50 && str_contains($h1, 'id="report-count">121 rows — page 1 of 3<') && str_contains($h1, 'id="report-next"') && !str_contains($h1, 'id="report-prev"') && str_contains($h1, 'table-responsive'), 'the page: 50 rows, "121 rows — page 1 of 3", a next link, the table inside a scrolling card');
ok(str_contains($h1, 'id="reports-csv-btn"') && str_contains($h1, 'format=csv'), 'and a Download CSV button');
$since = last_activity_id();
[$c, $rows, $raw] = csv_of($mara, 102, 'overrides', '2032-06-01', '2032-06-02');
ok($c === 200 && str_starts_with($raw['headers'], 'HTTP/') && preg_match('/^Content-Type: text\/csv; charset=utf-8/mi', $raw['headers']) === 1 && preg_match('/^Content-Disposition: attachment; filename="txtschedules-overrides-2032-06-01-to-2032-06-02\.csv"/mi', $raw['headers']) === 1, 'the CSV is a download: text/csv, attachment, a filename with the report and the range');
ok(count($rows) === 122 && $rows[0] === ['When', 'Rule', 'About', 'Went ahead', 'Why', 'The warning', 'Where'], 'header + 121 rows: EVERY row, not the page');
$table = rep($mara, 102, 'overrides', '2032-06-01', '2032-06-02')[1]; $all = [];
foreach ([1, 2, 3] as $pg) { foreach (rep($mara, 102, 'overrides', '2032-06-01', '2032-06-02', "&page=$pg")[1]['rows'] as $x) { $all[] = $x; } }
$cmp = array_map(fn ($x) => array_map(fn ($v) => (string) $v, array_values($x)), $all);
$csvRows = array_map(fn ($r) => str_starts_with($r[4], "'=") ? array_replace($r, [4 => substr($r[4], 1)]) : $r, array_slice($rows, 1));
ok($cmp === $csvRows, 'the CSV is exactly the table: the three pages of the screen, cell for cell (a cell that would run as a formula only gains a leading apostrophe)');
ok(count(array_filter($rows, fn ($r) => $r[4] === "'=SMOKE s7 formula")) === 1, 'a reason that starts with = arrives as \'=SMOKE s7 formula — a spreadsheet will not run it');
$lg = q("SELECT scope_id, source, actor_member_id, after FROM activity_log WHERE action = 'report.export' AND id > :s", ['s' => $since]);
$a = json_decode($lg[0]['after'] ?? '{}', true);
ok(count($lg) === 1 && (int) $lg[0]['scope_id'] === 102 && (int) $lg[0]['actor_member_id'] === 33 && $lg[0]['source'] === 'web' && $a == ['report' => 'overrides', 'site' => 102, 'from' => '2032-06-01', 'to' => '2032-06-02', 'rows' => 121], 'the download is logged report.export with the report, site, range and row count: ' . ($lg[0]['after'] ?? ''));
$sv = q("SELECT after, scope_id FROM activity_log WHERE action = 'screen.view' AND screen = 'reports' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok(json_decode($sv['after'] ?? '{}', true) === ['report' => 'overrides'] && (int) $sv['scope_id'] === 102, 'a look at a report is logged screen.view with after.report');
$n0 = (int) one("SELECT count(*) FROM activity_log WHERE action = 'report.export'");
ok(req('GET', '/reports/?site=102&report=overrides&from=2032-06-01&to=2032-06-01&format=csv', ['jar' => $mara])['code'] === 200 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'report.export'") === $n0 + 1, 'every download is one more row');
$before = (int) one("SELECT count(*) FROM activity_log WHERE action = 'report.export'");
req('GET', '/reports/?site=102&report=overrides&from=2032-06-01&to=2032-06-02', ['jar' => $mara]); rep($mara, 102, 'hours', $A, $A);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'report.export'") === $before, 'looking at the table is not an export');
foreach ([['from=2032-06-05&to=2032-06-01', 'The range ends before it starts.'], ['from=2030-01-01&to=2031-06-01', 'A report covers at most a year.'], ['from=yesterday&to=2032-06-01', 'Give from as a date like 2026-10-05.'], ['from=2032-13-40&to=2032-06-01', null]] as [$qs, $words]) {
    $r = req('GET', "/reports/?site=102&report=hours&$qs", ['jar' => $mara, 'headers' => JSONH]); $j = json_decode($r['body'], true);
    ok($r['code'] === 422 && ($words === null || ($j['error']['message'] ?? '') === $words), "a bad range ($qs): 422" . ($words ? " \"$words\"" : ''));
}
$r = req('GET', '/reports/?site=102&report=hours', ['jar' => $mara, 'headers' => JSONH]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['result']['rows']), 'no range given: this and the last weeks by default');
[$c, $e] = rep($mara, 102, 'hours', '2033-01-03', '2033-01-09');
$eh = page($mara, '/reports/?site=102&report=hours&from=2033-01-03&to=2033-01-09')['body'];
[$ce, $erows] = csv_of($mara, 102, 'hours', '2033-01-03', '2033-01-09');
ok($e['rows'] === [] && str_contains($eh, 'id="report-empty">Nothing in this range.<') && count($erows) === 1, 'an empty range: "Nothing in this range." and a CSV of the header alone');
ok(req('GET', '/reports/?site=101&report=hours&from=2032-06-01&to=2032-06-02', ['jar' => $dee, 'headers' => JSONH])['code'] === 200 && rep($dex, 101, 'labor', $A, $B)[0] === 200 && count(screen($dex, '/reports/?site=101')[1]['reports']) === 7, 'Downtown\'s manager reads Downtown\'s; its admin is offered all seven there');

echo "10. No rate anywhere\n";
$reports = ['hours', 'labor', 'open', 'trades', 'overtime', 'overrides', 'time-off'];
$people = ['Owner' => $owner, 'Mara' => $mara, 'Pat' => $pat, 'Sol' => $sol];
$leaks = []; $costLeaks = []; $n = 0;
foreach ($people as $who => $jar) {
    foreach ($reports as $rp) {
        foreach ([[$A, dayn($B, 6)], [$T, dayn($T, 6)], [plus($today, -3), plus($today, 1)], [$TO, dayn($TO, 6)]] as [$f, $t]) {
            foreach (['', '&format=csv'] as $fmt) {
                $r = req('GET', "/reports/?site=102&report=$rp&from=$f&to=$t$fmt", ['jar' => $jar, 'headers' => $fmt ? [] : JSONH]); $n++;
                foreach (wage_marks() as $w) { if (str_contains(strip_ts($r['body']), $w)) { $leaks[] = "$who $rp $fmt $w" ; } }
                if (!in_array($who, ['Owner', 'Mara'], true)) { foreach ($costs as $m) { if (str_contains($r['body'], $m)) { $costLeaks[] = "$who $rp $fmt $m"; } } }
            }
        }
        $h = page($jar, "/reports/?site=102&report=$rp&from=$A&to=" . dayn($B, 6))['body']; $n++;
        foreach (wage_marks() as $w) { if (str_contains(strip_ts($h), $w)) { $leaks[] = "$who $rp html $w"; } }
        if (!in_array($who, ['Owner', 'Mara'], true)) { foreach ($costs as $m) { if (str_contains($h, $m)) { $costLeaks[] = "$who $rp html $m"; } } }
    }
}
ok($leaks === [] && $costLeaks === [], "$n responses — 4 people x 7 reports x page, JSON and CSV: no fixture wage in any, and no cost for someone without labor.view" . ($leaks || $costLeaks ? ' — LEAK: ' . implode('; ', array_slice(array_merge($leaks, $costLeaks), 0, 6)) : ''));
$logs = json_encode(q("SELECT action, before, after, route FROM activity_log WHERE action IN ('report.export', 'screen.view', 'settings.update')")) . json_encode(q('SELECT subject, body FROM notification_outbox'));
ok(wage_leaks($logs) === [] && array_filter($costs, fn ($m) => str_contains($logs, $m)) === [], 'the trail (report.export, screen.view) and the outbox hold no wage and no cost figure');
$src = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && grep -lE "wage_rate|wage_override|default_wage|ts_effective_rate" app/features/reports/*.php app/features/site/*.php app/features/rules/*.php app/views/reports/*.php app/views/site/*.php app/views/rules/*.php html/reports/*.php html/site/*.php html/rules/*.php');
ok(trim((string) $src) === '', 'no file of the slice names a wage column or the rate function (grep)');
$costFiles = trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && grep -lE "scheduled_cost|\\.cost\\b|sum\\(x\\.cost" app/features/reports/*.php app/features/site/*.php app/features/rules/*.php app/views/reports/*.php html/reports/*.php html/site/*.php html/rules/*.php'));
ok($costFiles === 'app/features/reports/queries.php', 'cost is named in one file of the slice: ' . $costFiles);
ok(substr_count((string) file_get_contents(dirname(__DIR__, 3) . '/app/features/reports/queries.php'), "has_right('labor.view'") === 0 && str_contains((string) file_get_contents(dirname(__DIR__, 3) . '/html/reports/index.php'), "has_right('labor.view', \$siteId)"), 'and the only place that decides to ask for cost is the screen\'s one line: has_right(\'labor.view\', $siteId)');
reset7();
admin_sql("UPDATE staff_profiles SET max_hours_week = NULL WHERE member_id = 31; DELETE FROM rule_overrides WHERE reason LIKE 'SMOKE%' OR reason LIKE '=SMOKE%';");
finish();
