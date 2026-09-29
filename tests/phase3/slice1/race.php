<?php
/** Proof — exactly one wins (spec "Proof", 3): two sessions POST shift_pickup for the same offer at the same instant, three rounds, curl in parallel. */
require __DIR__ . '/lib.php';
$W = world();
$ana = as_member(30); $lee = as_member(31); $dana = as_member(32);
$tok = fn (string $j) => csrf_of(req('GET', '/', ['jar' => $j])['body']);
$tl = $tok($lee); $td = $tok($dana);
$wins = [];
foreach ([260, 300, 340] as $round => $h) {
    $shift = mkshift(102, srv(), 30, $h);
    $x = offer($ana, $shift);
    $mh = curl_multi_init();
    $hs = [];
    foreach ([[$lee, $tl], [$dana, $td]] as $i => [$jar, $t]) {
        $ch = curl_init(BASE . '/exchanges/claim.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['exchange' => $x, 'csrf_token' => $t]), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => '/dev/null']);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05); } while ($running > 0);
    $res = [];
    foreach ($hs as $ch) { $res[] = [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true)]; curl_multi_remove_handle($mh, $ch); }
    $codes = array_column($res, 0); sort($codes);
    ok($codes === [200, 422], "round " . ($round + 1) . ": one request 200 and one 422 (" . implode(',', array_column($res, 0)) . ')');
    $loser = array_values(array_filter($res, fn ($r) => $r[0] === 422))[0][1] ?? [];
    ok(msg($loser) === 'Someone else already took this shift.', 'round ' . ($round + 1) . ': the other is told "Someone else already took this shift."');
    ok((int) one("SELECT count(*) FROM exchange_claims WHERE exchange_id = :x AND status = 'won'", ['x' => $x]) === 1 && (int) one('SELECT count(*) FROM exchange_claims WHERE exchange_id = :x', ['x' => $x]) === 1, 'round ' . ($round + 1) . ': one winning claim in the table, and only one claim row');
    $winner = (int) one("SELECT member_id FROM exchange_claims WHERE exchange_id = :x AND status = 'won'", ['x' => $x]);
    ok(holder_of($shift) === $winner && status_of($x) === 'approved', 'round ' . ($round + 1) . ': the shift is the winner\'s (member ' . $winner . ')');
    $wins[] = $winner;
    ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'shift.assign' AND entity_id = :s", ['s' => $shift]) === 1, 'round ' . ($round + 1) . ': exactly one shift.assign row for it');
}
finish();
