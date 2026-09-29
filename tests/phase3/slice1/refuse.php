<?php
/** Proof — the database refuses, the screen says why (spec "Proof", 4): the button is disabled with the database's sentence and a forced POST is 422 with the same. */
require __DIR__ . '/lib.php';
$W = world();
$ana = as_member(30); $lee = as_member(31); $owner = as_member(1, 102); $marco = as_member(27, 101); $priya = as_member(26); $mara = as_member(33);

echo "1. Not the main restaurant (D4)\n";
$s = mkshift(102, srv(), 30, 400);
$x = offer($ana, $s);
$card = card_of($owner, $x);
ok($card !== null && $card['may_take'] === false && $card['reason'] === 'You can pick up shifts only at your main restaurant.', 'the owner (an approver at Airport, main Downtown) sees the offer, its button disabled: "' . ($card['reason'] ?? '?') . '"');
$r = page($owner, '/marketplace');
ok(str_contains($r['body'], 'id="exchange-card-' . $x . '-why"') && str_contains($r['body'], 'only at your main restaurant.') && preg_match('/id="exchange-card-' . $x . '-take-btn"[^>]*disabled|disabled[^>]*id="exchange-card-' . $x . '-take-btn"/', $r['body']), 'the rendered card carries the muted line and a disabled button');
[$c, $b] = claim($owner, $x);
ok($c === 422 && msg($b) === 'You can pick up shifts only at your main restaurant.', 'a forced POST by the owner: 422, the same sentence');
[$c, $b] = claim($marco, $x);
ok($c === 422 && msg($b) === 'You can pick up shifts only at your main restaurant.', 'a forced POST by Marco (main Downtown): 422, the same sentence (he cannot even see the card)');
ok(status_of($x) === 'open' && (int) one('SELECT count(*) FROM exchange_claims WHERE exchange_id = :x', ['x' => $x]) === 0, 'nothing changed: still open, no claim');

echo "2. Approved time off\n";
$tt = (int) one("SELECT id FROM time_off_types WHERE scope_id = 102 AND key = 'vacation'");
admin_sql("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, status) SELECT 31, 102, $tt, starts_at - interval '1 hour', ends_at + interval '1 hour', 'approved' FROM shifts WHERE id = $s");
$card = card_of($lee, $x);
ok($card !== null && $card['may_take'] === false && $card['reason'] === 'On approved time off then.', 'Lee is on approved time off then: the card says "' . ($card['reason'] ?? '?') . '"');
[$c, $b] = claim($lee, $x);
ok($c === 422 && msg($b) === 'On approved time off then.', 'and a forced POST is 422 with the same sentence');
admin_sql("DELETE FROM time_off_requests WHERE member_id = 31 AND status = 'approved'");
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $x]);

echo "3. An overlap (the exclusion constraint's neighbour, db/015)\n";
$h = mkshift(102, srv(), 26, 410);
$xo = offer($priya, $h);
$clash = mkshift(102, srv(), 31, 412);
$card = card_of($lee, $xo);
ok($card !== null && $card['may_take'] === false && str_starts_with((string) $card['reason'], 'That overlaps your shift on '), 'the card says "' . ($card['reason'] ?? '?') . '"');
[$c, $b] = claim($lee, $xo);
ok($c === 422 && str_starts_with(msg($b), 'That overlaps your shift on ') && holder_of($h) === 26, 'a forced POST: 422 the same, and the shift did not move');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $xo]);

echo "4. The cutoff\n";
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $W['p5']]);
ok($c === 422 && msg($b) === 'Too close to the start of the shift to change hands.', 'an offer of a shift that starts within the restaurant\'s cutoff (120 min): 422 "Too close to the start of the shift to change hands."');
$r = page($priya, '/shifts/' . $W['p5']);
ok(str_contains($r['body'], 'id="shift-too-close"') && !str_contains($r['body'], 'id="offer-form"'), 'the shift page says so and shows no Offer button');
$h = mkshift(102, srv(), 30, 420);
$xc = offer($ana, $h);
q('UPDATE shifts SET starts_at = now() + interval \'1 hour\', ends_at = now() + interval \'5 hours\' WHERE id = :s', ['s' => $h]);
[$c, $b] = claim($lee, $xc);
ok($c === 422 && msg($b) === 'Too close to the start of the shift to change hands.', 'a claim after the shift drifted inside the cutoff: 422 the same');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $xc]);

echo "5. A hard rule (made hard by SQL) and a soft one\n";
$l0 = mkshift(102, srv(), 31, 430);
$hh = mkshift(102, srv(), 26, 440);       // 4 hours after Lee's shift ends
$xh = offer($priya, $hh);
$card = card_of($lee, $xh);
ok($card !== null && $card['may_take'] === true && $card['soft_warnings'] !== [], 'as a soft rule (min_rest): the button stays; the warning is "' . ($card['soft_warnings'][0] ?? '?') . '"');
$r = page($lee, '/marketplace');
ok(str_contains($r['body'], 'id="exchange-card-' . $xh . '-warning"') && str_contains(html_entity_decode($r['body']), 'A manager will look at this one: Less than 10 hours between shifts.'), 'the card says "A manager will look at this one: Less than 10 hours between shifts."');
admin_sql("UPDATE site_rules SET severity = 'hard' WHERE scope_id = 102 AND rule_key = 'min_rest'");
$card = card_of($lee, $xh);
ok($card !== null && $card['may_take'] === false && $card['reason'] === 'Less than 10 hours between shifts.', 'made hard by SQL: the button is disabled with "' . ($card['reason'] ?? '?') . '"');
[$c, $b] = claim($lee, $xh);
ok($c === 422 && msg($b) === 'Less than 10 hours between shifts.' && holder_of($hh) === 26, 'a forced POST: 422 with that sentence');
admin_sql("UPDATE site_rules SET severity = 'soft' WHERE scope_id = 102 AND rule_key = 'min_rest'");
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $xh]);
$hb = mkshift(102, $W['aBar'], 30, 450);
$xb = offer($ana, $hb);
[$c, $b] = claim($lee, $xb);
ok($c === 422 && msg($b) === 'Does not work this position here.', 'a Bar shift and Lee does not work Bar (hard by default): 422 "Does not work this position here."');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $xb]);

echo "6. The restaurant turned swaps off (D2)\n";
$h = mkshift(102, srv(), 26, 460);
settings(102, ['allow_swap' => false]);
$r = page($priya, '/shifts/' . $h);
ok(str_contains($r['body'], 'id="offer-form"') && !str_contains($r['body'], 'id="shift-swap-details"') && !str_contains($r['body'], 'shift-swap-open'), 'the shift page has Offer but no Swap button');
[$c, $b] = act($priya, '/exchanges/swap.php', ['shift' => $h, 'colleague' => 30, 'swap_shift' => $W['a2']]);
ok($c === 422 && msg($b) === 'This restaurant does not allow that kind of trade.', 'a forced swap POST: 422 "This restaurant does not allow that kind of trade."');
settings(102, ['allow_offer' => false, 'allow_give' => false]);
$r = page($priya, '/shifts/' . $h);
ok(str_contains($r['body'], 'id="shift-no-trades"'), 'with every trade off the page says "This restaurant does not let staff trade shifts."');
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $h]);
ok($c === 422 && msg($b) === 'This restaurant does not allow that kind of trade.', 'a forced offer: 422 the same');
settings(102, ['allow_swap' => true, 'allow_offer' => true, 'allow_give' => true]);

echo "7. Only the holder trades a shift; the gates\n";
[$c, $b] = act($ana, '/exchanges/offer.php', ['shift' => $h]);
ok($c === 422 && msg($b) === 'Only the person working a shift can offer it.', 'Ana offering Priya\'s shift: 422 "Only the person working a shift can offer it."');
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $W['j1']]);
ok($c === 404 && msg($b) === 'Shift not found.', 'a shift at a restaurant she does not hold: 404 "Shift not found."');
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => 99999999]);
ok($c === 404 && msg($b) === 'Shift not found.', 'a shift that does not exist: the same 404');
$r = req('POST', '/exchanges/offer.php', ['jar' => $priya, 'headers' => JSONH, 'form' => ['shift' => $h]]);
ok($r['code'] === 403, 'without the CSRF token: 403');
$r = req('GET', '/exchanges/offer.php', ['jar' => $priya, 'headers' => JSONH]);
ok($r['code'] === 405, 'a GET to a handler: 405');
$r = req('POST', '/exchanges/offer.php', ['headers' => JSONH, 'form' => ['shift' => $h]]);
ok($r['code'] === 401, 'no session: 401');
[$c, $b] = claim($lee, 99999999);
ok($c === 404 && msg($b) === 'That trade is no longer available.', 'claiming a trade that does not exist: 404 "That trade is no longer available."');
[$c, $b] = act($mara, '/exchanges/approve.php', ['exchange' => 99999999]);
ok($c === 404, 'deciding a trade that does not exist: 404');
finish();
