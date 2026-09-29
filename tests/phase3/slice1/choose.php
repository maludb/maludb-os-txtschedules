<?php
/** Proof — manager chooses (spec "Proof", 7): two claims pending, "Give it to…" picks one, the other is lost; claim withdrawn; the words of the outcomes. */
require __DIR__ . '/lib.php';
$W = world();
$ana = as_member(30); $lee = as_member(31); $dana = as_member(32); $mara = as_member(33); $priya = as_member(26);
settings(102, ['claim_mode' => 'manager_chooses']);

echo "1. Two claims are both pending\n";
$s = mkshift(102, srv(), 26, 1000);
$x = offer($priya, $s);
[$c, $b] = claim($lee, $x);
ok($c === 200 && ($b['outcome'] ?? '') === 'claimed' && str_contains(html_entity_decode(json_encode($b)), 'noted'), 'Lee\'s Take it: outcome claimed ("Your interest is noted — a manager chooses.")');
$r = req('POST', '/exchanges/claim.php', ['jar' => $ana, 'form' => ['exchange' => $x, 'csrf_token' => page_csrf($ana), 'return_to' => '/marketplace']]);
ok($r['code'] === 302 && str_ends_with($r['location'], 'notice=claim_noted'), 'a form post lands on the marketplace with ?notice=claim_noted');
ok(str_contains(html_entity_decode(page($ana, $r['location'])['body']), 'Your interest is noted — a manager chooses.'), 'and the banner says "Your interest is noted — a manager chooses."');
ok((int) one("SELECT count(*) FROM exchange_claims WHERE exchange_id = :x AND status = 'pending'", ['x' => $x]) === 2 && status_of($x) === 'open' && holder_of($s) === 26, 'both claims are pending; the exchange is still open; the shift has not moved');
[$c, $d] = screen($lee, '/marketplace?tab=grabs');
ok(card_of($lee, $x) === null, 'a shift I have asked for leaves my "Up for grabs" and is in My claims');
ok(($cc = card_of($lee, $x, 'claims')) !== null && $cc['claim_status'] === 'pending', 'My claims: pending');
$p = page($lee, '/marketplace?tab=claims')['body'];
ok(str_contains($p, 'exchange-card-' . $x . '-withdraw-btn'), 'with Withdraw my claim');
echo "2. The approver picks\n";
[$c, $d] = screen($mara, '/approvals');
$row = array_values(array_filter($d['exchanges'], fn ($e) => $e['exchange_id'] === $x))[0] ?? null;
ok($row !== null && count($row['claimants']) === 2, 'the approvals inbox lists the open trade with its two claimants');
$page = page($mara, "/exchanges/$x")['body'];
ok(str_contains($page, 'id="exchange-choose-31-btn"') && str_contains($page, 'id="exchange-choose-30-btn"'), 'the trade page has "Give it to …" for each');
[$c, $b] = act($dana, '/exchanges/choose.php', ['exchange' => $x, 'member' => 31]);
ok($c === 403, 'a shift lead with the shift not same-day may not choose: 403');
[$c, $b] = act($lee, '/exchanges/choose.php', ['exchange' => $x, 'member' => 31]);
ok($c === 403, 'a claimant may not choose for herself: 403');
[$c, $b] = act($mara, '/exchanges/choose.php', ['exchange' => $x, 'member' => 999]);
ok($c === 422 && msg($b) === 'That person has not asked for this shift.', 'choosing someone who did not ask: 422');
$since = last_activity_id();
[$c, $b] = act($mara, '/exchanges/choose.php', ['exchange' => $x, 'member' => 31]);
ok($c === 200 && holder_of($s) === 31 && status_of($x) === 'approved', 'Give it to Lee: the shift is his');
ok((string) one("SELECT status FROM exchange_claims WHERE exchange_id = :x AND member_id = 30", ['x' => $x]) === 'lost' && (string) one("SELECT status FROM exchange_claims WHERE exchange_id = :x AND member_id = 31", ['x' => $x]) === 'won', 'the other claim is lost');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'exchange.choose' AND id > :s AND scope_id = 102", ['s' => $since]) === 1 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'shift.assign' AND id > :s AND scope_id = 102", ['s' => $since]) === 1, 'exchange.choose and shift.assign are each logged once, with the site');
ok(in_array(26, told($x), true) && in_array(31, told($x), true), 'the holder and the chosen one are told');
ok(card_of($ana, $x, 'claims')['claim_status'] === 'lost', 'Ana\'s claim shows as lost ("Someone else")');
echo "3. Withdrawing a claim\n";
$s2 = mkshift(102, srv(), 26, 1030);
$x2 = offer($priya, $s2);
claim($ana, $x2);
[$c, $b] = act($ana, '/exchanges/withdraw.php', ['exchange' => $x2]);
ok($c === 200 && (string) one("SELECT status FROM exchange_claims WHERE exchange_id = :x AND member_id = 30", ['x' => $x2]) === 'withdrawn', 'claim_withdraw: the claim is withdrawn');
[$c, $b] = act($ana, '/exchanges/withdraw.php', ['exchange' => $x2]);
ok($c === 422 && msg($b) === 'You have no waiting claim on this trade.', 'and again: 422 "You have no waiting claim on this trade."');
[$c, $b] = act($lee, '/exchanges/withdraw.php', ['exchange' => $x2]);
ok($c === 422, 'someone else\'s claim cannot be withdrawn (there is none of hers): 422');
$page = page($mara, '/approvals')['body'];
ok(!str_contains($page, 'approval-card-' . $x2 . '"'), 'a trade with no live claim is not in the inbox');
settings(102, ['claim_mode' => 'first']);
echo "4. The banners\n";
foreach (['claim_pending' => 'Sent to a manager.', 'claim_approved' => 'It\'s yours — added to your schedule.'] as $k => $t) {
    ok(str_contains(html_entity_decode(page($lee, '/marketplace?notice=' . $k)['body'], ENT_QUOTES), $t), "?notice=$k → \"$t\"");
}
ok(!str_contains(page($lee, '/marketplace?notice=<script>')['body'], 'notice-banner'), 'an unknown notice key shows nothing (a whitelist, never request text)');
finish();
