<?php
/** Builds what the browser proof looks at (fresh shifts and trades, all SMOKE) and prints it as JSON: ids, the cast's claims and the week to open. */
require __DIR__ . '/lib.php';
$W = world();
$priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32);
$p = [];
$p['offerAna'] = offer($ana, mkshift(102, srv(), 30, 1900));                     // Lee takes it on the phone
$p['offerAna2'] = offer($ana, mkshift(102, srv(), 30, 1960));                    // Lee takes it with JavaScript off
$p['offerAna3'] = offer($ana, mkshift(102, srv(), 30, 2020));                    // the owner sees it disabled (main Downtown)
settings(102, ['approval_pickup' => 'always']);
$sPending = mkshift(102, srv(), 26, 2080);
$p['pendingShift'] = $sPending;
$p['pending'] = offer($priya, $sPending, 'SMOKE can someone take this');
claim($ana, $p['pending']);
settings(102, ['approval_pickup' => 'on_warning']);
$sGive = mkshift(102, srv(), 26, 2140);
[, $b] = act($priya, '/exchanges/give.php', ['shift' => $sGive, 'colleague' => 31, 'note' => 'SMOKE it is yours if you want it']);
$p['give'] = (int) $b['record_id'];
$p['open'] = mkshift(102, srv(), null, 2200);
$p['offerable'] = mkshift(102, srv(), 26, 2260);                                 // Priya offers this one in the browser
$p['offerable2'] = mkshift(102, srv(), 26, 2320);
$tz = 'America/Chicago';
$p['week'] = monday_of(new DateTimeImmutable((string) one('SELECT starts_at FROM shifts WHERE id = :s', ['s' => $W['p2']])), $tz);
// a busy week for the strip: three more Priya shifts around p2
$p['weekShifts'] = [mkshift(102, srv(), 26, 60, 5), mkshift(102, srv(), 26, 124, 6)];
$p['w'] = $W;
echo json_encode(['ids' => $p, 'claims' => cast()]);
