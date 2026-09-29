<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: a week of shifts (hours, an overtime line, an open shift, a budget), two overrides made in the builder, two trades, approved time off, and the cast's claims. */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = as_member(1, 102); $mara = as_member(33); $priya = as_member(26); $lee = as_member(31); $ana = as_member(30); as_member(32); as_planner(); as_setter(); as_dee(); as_dex(); as_member(34);
$srv = $W['aSrv']; $bar = $W['aBar'];
admin_sql("DELETE FROM exchange_claims; DELETE FROM exchange_invitees; DELETE FROM exchanges;");
$A = wk(200); $B = wk(201); $E = wk(202); $T = wk(203); $TO = wk(204);
foreach ([26 => [1, 2, 3], 30 => [1], 31 => [4]] as $m => $days) { foreach ($days as $dy) { fx(102, $srv, $m, $A, $dy); } }
fx(102, $bar, 30, $A, 2); fx(102, $srv, null, $A, 5); fx_publish(102, $A);
fx(102, $srv, 26, $B, 1); fx(102, $srv, null, $B, 2);
fx(102, $srv, 31, $E, 1); $rest = fx(102, $srv, null, $E, 2, '06:00', '12:00');
act($mara, '/shifts/assign.php', ['shift' => $rest, 'assignee' => 31, 'override_reason' => 'SMOKE Lee asked for the morning and the crew agreed']);
$brk = fx(102, $srv, null, $E, 4, '17:00', '23:30', ['break' => 0]);
act($owner, '/shifts/assign.php', ['shift' => $brk, 'assignee' => 30, 'override_reason' => 'SMOKE no break needed on a quiet night']);
$tMon = fx(102, $srv, 26, $T, 0); $tWed = fx(102, $srv, 26, $T, 2); fx_publish(102, $T);
$x1 = offer($priya, $tMon); claim($lee, $x1); $x2 = offer($priya, $tWed);
admin_sql("UPDATE exchanges SET created_at = now() - interval '10 hours', decided_at = now() - interval '5 hours' WHERE status = 'approved'");
[$c, $r1] = req_off($priya, typ(102, 'unpaid'), dayn($TO, 0), dayn($TO, 1)); act($mara, '/time-off/approve.php', ['request' => $r1['record_id']]);
grant(30, typ(102, 'vacation'), 40);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $A, 'area' => 'all', 'budget_hours' => '100', 'budget_amount' => '900.50']);
set($owner, 102, ['overtime_weekly_hours' => '9']);
echo json_encode(['ids' => ['lunch' => dpid(102, 'lunch'), 'dinner' => dpid(102, 'dinner'), 'srv' => $srv], 'weeks' => ['A' => $A, 'B' => $B, 'E' => $E, 'T' => $T, 'TO' => $TO, 'end' => dayn($B, 6)], 'today' => (new DateTimeImmutable('now', new DateTimeZone(TZA)))->format('Y-m-d'),
    'claims' => cast() + ['35' => planner_claims(), '36' => dee_claims(), '37' => setter_claims(), '38' => dex_claims()]]);
