<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: the ids and the cast's claims. Airport's week 160 weeks out: ratios, covers of every source, shifts, a budget the Bar is over. */
require __DIR__ . '/lib.php';
$W = reset5();
$owner = as_member(1, 102); $mara = as_member(33); as_member(26); as_member(30); as_member(31); as_member(32); as_member(27, 101); as_dee(); as_planner(); as_setter();
$srv = $W['aSrv']; $bar = $W['aBar'];
$ws = wk(160); $lunch = dpid(102, 'lunch'); $dinner = dpid(102, 'dinner');
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']);
act($owner, '/labor/ratio.php', ['position' => $bar, 'covers_per_staff' => '60', 'min_staff' => '0']);
$cells = [[0, $lunch, 30, 'manual'], [0, $dinner, 55, 'manual'], [1, $lunch, 40, 'reservations'], [1, $dinner, 60, 'copied'], [2, $dinner, 45, 'manual'], [3, $dinner, 70, 'reservations'],
          [4, $lunch, 35, 'manual'], [4, $dinner, 80, 'manual'], [5, $dinner, 95, 'manual'], [6, $lunch, 50, 'manual'], [6, $dinner, 65, 'manual']];
foreach ($cells as [$i, $dp, $n, $src]) { put_cell(102, dayn($ws, $i), $dp, $n, $src); }
foreach ([26, 30, 31] as $m) { fx(102, $srv, $m, $ws, 4); }
fx(102, $srv, null, $ws, 4);
fx(102, $srv, 32, $ws, 5); fx(102, $srv, 33, $ws, 5);
fx(102, $srv, 26, $ws, 2); fx(102, $srv, 30, $ws, 2);
fx(102, $srv, 31, $ws, 1);
fx(102, $bar, 30, $ws, 5, '17:00', '21:00');
fx(102, $bar, 32, $ws, 4, '17:00', '22:00');
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '90', 'budget_amount' => '1500']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'front', 'budget_hours' => '70', 'budget_amount' => '1100']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'bar', 'budget_hours' => '10', 'budget_amount' => '150']);
act($owner, '/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'kitchen', 'budget_hours' => '40']);
put_cell(102, dayn(wk(159), 4), $dinner, 88, 'manual'); put_cell(102, dayn(wk(159), 1), $lunch, 33, 'manual');
kread(['mode' => 'no_connection']);
echo json_encode(['ids' => ['srv' => $srv, 'bar' => $bar, 'lunch' => $lunch, 'dinner' => $dinner, 'ws' => $ws, 'prevWs' => wk(159), 'emptyWs' => wk(175)], 'claims' => cast() + ['35' => planner_claims(), '36' => dee_claims(), '37' => setter_claims()]]);
