<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: the ids, the weeks, the cast's claims. */
require __DIR__ . '/lib.php';
$W = reset3();
$mara = as_member(33); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $owner = as_member(1, 102); as_member(32); as_member(27, 101);
$srv = srv();
$vac = typ(102, 'vacation'); $unpaid = typ(102, 'unpaid'); $sick = typ(102, 'sick');
$P = ['vac' => $vac, 'unpaid' => $unpaid, 'sick' => $sick];
$wp = wk(80); $P['week'] = $wp;
$P['thu'] = pub_shift(102, $srv, 26, $wp, 3, '11:00', '15:00');
$P['fri'] = pub_shift(102, $srv, 26, $wp, 4, '17:00', '23:00');
$P['anaFri'] = pub_shift(102, $srv, 30, $wp, 4, '17:00', '23:00');
grant(26, $vac, 24); grant(26, $sick, 40);
// a ledger with variety: a request approved and then cancelled, an adjustment
[$c, $b] = req_off($priya, $vac, dayn(wk(82), 1), dayn(wk(82), 1)); $r = (int) $b['record_id'];
act($mara, '/time-off/approve.php', ['request' => $r]); act($priya, '/time-off/cancel.php', ['request' => $r]);
act($owner, '/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => -2, 'reason' => 'SMOKE taken on the last day']);
// pending time off (the approver's card with covered shifts) and a second one from Ana
[$c, $b] = req_off($priya, $vac, dayn($wp, 3), dayn($wp, 4), ['note' => 'SMOKE sister\'s wedding']); $P['priyaReq'] = (int) $b['record_id'];
[$c, $b] = act($ana, '/time-off/request.php', ['time_off_type' => $unpaid, 'starts_at' => dayn(wk(81), 2) . ' 09:00', 'ends_at' => dayn(wk(81), 2) . ' 13:00', 'note' => 'SMOKE appointment']); $P['anaReq'] = (int) $b['record_id'];
// approved time off for Lee (who is off)
[$c, $b] = req_off($lee, $unpaid, dayn(wk(81), 3), dayn(wk(81), 4)); $P['leeReq'] = (int) $b['record_id'];
act($mara, '/time-off/approve.php', ['request' => $P['leeReq']]);
$P['offDay'] = dayn(wk(81), 3);
// a declined and a cancelled one for Priya's list
[$c, $b] = req_off($priya, $unpaid, dayn(wk(83), 0), dayn(wk(83), 0)); $P['declined'] = (int) $b['record_id']; act($mara, '/time-off/decline.php', ['request' => $P['declined'], 'note' => 'SMOKE busy week']);
// blackout
act($owner, '/time-off/blackout/save.php', ['site' => 102, 'on_date' => dayn(wk(84), 4), 'reason' => "Valentine's Day"]);
act($owner, '/time-off/blackout/save.php', ['site' => 102, 'on_date' => dayn(wk(84), 5), 'reason' => 'SMOKE Private event']);
$P['blackoutDay'] = dayn(wk(84), 4);
// availability: approved and pending
act($priya, '/availability/save.php', ['weekday' => 1, 'kind' => 'unavailable']);
act($priya, '/availability/save.php', ['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '23:00', 'kind' => 'preferred']);
act($priya, '/availability/save.php', ['weekday' => 5, 'starts_at' => '17:00', 'ends_at' => '23:00', 'kind' => 'unavailable']);
foreach (blocks_of(26) as $b) { act($mara, '/availability/approve.php', ['availability' => $b['id']]); }
[$c, $b] = act($priya, '/availability/save.php', ['weekday' => 6, 'starts_at' => '12:00', 'ends_at' => '15:00', 'kind' => 'available']); $P['pendingBlock'] = (int) $b['record_id'];
[$c, $b] = act($lee, '/availability/save.php', ['weekday' => 2, 'starts_at' => '22:00', 'ends_at' => '02:00', 'kind' => 'unavailable']); $P['leeBlock'] = (int) $b['record_id'];
$P['mySunday'] = 0;
echo json_encode(['ids' => $P, 'claims' => cast() + ['35' => planner_claims(), '36' => dee_claims()]]);
