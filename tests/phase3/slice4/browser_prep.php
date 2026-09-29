<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: the ids, the cast's claims. */
require __DIR__ . '/lib.php';
$W = reset4();
$owner = as_member(1, 102); $mara = as_member(33); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32); as_member(27, 101); $dee = as_dee(); $pat = as_planner();
$P = ['srv' => $W['aSrv'], 'bar' => $W['aBar']];
$fh = kind(102, 'food_handler'); $al = kind(102, 'alcohol_service');
$P['fh'] = $fh; $P['al'] = $al;
[$c, $b] = act($owner, '/positions/save.php', ['site' => 102, 'name' => 'SMOKE Host', 'area' => 'front', 'color' => '#2ca58d', 'certifications' => [$fh]]); $P['host'] = (int) $b['record_id'];
[$c, $b] = act($owner, '/certifications/kinds/save.php', ['site' => 102, 'name' => 'SMOKE First aid', 'track_expiry' => 'no', 'warn_days' => 0, 'positions' => [$P['host']]]); $P['fa'] = (int) $b['record_id'];
act($mara, '/staff/save.php', ['member' => 30, 'positions' => ['Server', 'Bar', 'SMOKE Host'], 'primary' => 'Server', 'max_hours_week' => 30, 'notes' => 'SMOKE prefers weekends']);
act($mara, '/staff/save.php', ['member' => 31, 'is_minor' => 'yes', 'minor_until' => today_plus(300), 'max_hours_week' => 24]);
$ws = wk(0);
pub_shift(102, $W['aSrv'], 33, $ws, 2, '17:00', '23:00'); pub_shift(102, $W['aSrv'], 33, $ws, 3, '11:00', '15:00');
grant(26, typ(102, 'vacation'), 24);
[$c, $b] = act($owner, '/staff/certifications/add.php', ['member' => 26, 'kind' => $fh, 'issued_on' => today_plus(-353), 'expires_on' => today_plus(12), 'reference' => 'SMOKE-P-1']); $P['priyaCard'] = (int) $b['record_id'];
[$c, $b] = act($lee, '/staff/certifications/add.php', ['kind' => $fh, 'issued_on' => today_plus(-800), 'expires_on' => today_plus(-1), 'reference' => 'SMOKE-L-1']); $P['leeCard'] = (int) $b['record_id'];
act($owner, '/staff/certifications/verify.php', ['certification' => $P['leeCard']]);
[$c, $b] = act($ana, '/staff/certifications/add.php', ['kind' => $al, 'issued_on' => today_plus(-20), 'expires_on' => today_plus(345), 'reference' => 'SMOKE-A-1']); $P['anaCard'] = (int) $b['record_id'];
[$c, $b] = act($ana, '/staff/certifications/add.php', ['kind' => $fh, 'issued_on' => today_plus(-20), 'expires_on' => today_plus(5)]); $P['anaCard2'] = (int) $b['record_id'];
act($owner, '/staff/certifications/add.php', ['member' => 32, 'kind' => $al, 'expires_on' => today_plus(200)]);
echo json_encode(['ids' => $P, 'claims' => cast() + ['35' => planner_claims(), '36' => dee_claims()]]);
