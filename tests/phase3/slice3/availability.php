<?php
/** Proof — availability (spec "Proof": Availability): pending until approved, the builder's soft warning only once approved, a second approved block replaces the first, approval off = approved at once, who may read and change whose. */
require __DIR__ . '/lib.php';
$W = reset3();
$priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32); $mara = as_member(33); $owner = as_member(1, 102); $marco = as_member(27, 101); $sam = as_member(28, 101); $dee = as_dee();
$srv = srv();
$sv = fn (string $jar, array $f): array => act($jar, '/availability/save.php', $f);
$ws = wk(60);                                    // the builder proofs' own far week (Monday); day 4 = Friday, weekday 5

echo "1. Priya submits Unavailable Fri 5–11 pm: it waits for a manager and counts for nothing\n";
$since = last_activity_id(); $ob = last_outbox_id();
[$c, $b] = $sv($priya, ['weekday' => 5, 'starts_at' => '17:00', 'ends_at' => '23:00', 'kind' => 'unavailable']);
$a1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $a1 > 0 && preg_match('~#availability-block-' . $a1 . '$~', (string) $b['location']) && ($b['status'] ?? '') === 'pending', 'availability_submit: 200, status pending, record_id, the location ends in the record id (' . ($b['location'] ?? '?') . ')');
$row = blocks_of(26)[0];
ok($row['status'] === 'pending' && $row['weekday'] == 5 && $row['a'] === '17:00' && $row['b'] === '23:00' && $row['kind'] === 'unavailable' && $row['scope_id'] === null, 'the block is stored pending: Friday 17:00–23:00 unavailable, for every restaurant she works at (scope NULL)');
$log = q("SELECT source, scope_id, actor_member_id, after FROM activity_log WHERE action = 'availability.submit' AND entity_id = :i", ['i' => $a1]);
ok(count($log) === 1 && $log[0]['source'] === 'web' && (int) $log[0]['scope_id'] === 102 && (int) $log[0]['actor_member_id'] === 26 && str_contains($log[0]['after'], '"status": "pending"'), 'logged once: availability.submit, source web, with the site (102), status pending');
$told = told_ref("availability:$a1");
ok(in_array(33, $told, true) && in_array(1, $told, true) && !in_array(26, $told, true) && !in_array(30, $told, true), 'the approvers (Mara, the owner) are told; Priya and a colleague are not: ' . json_encode($told));
ok((int) one("SELECT count(*) FROM notification_outbox WHERE id > :o AND kind = 'request_decided' AND body LIKE '%Unavailable%'", ['o' => $ob]) >= 2, 'the notice says what was asked (kind request_decided)');
[$c, $d] = screen($priya, '/availability');
ok($c === 200 && count($d['pending']) === 1 && $d['pending'][0]['availability_id'] === $a1 && $d['blocks'] === [], 'her screen: the block is in "pending", the effective set is empty');
$html = html_entity_decode(page($priya, '/availability')['body']);
ok(str_contains($html, 'Waiting for a manager') && str_contains($html, 'id="availability-pending"') && substr_count($html, 'class="card h-100" id="availability-day-') === 7, 'the page says "Waiting for a manager" and lists seven day cards');
$tw = wk(61);
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($tw, 4, '17:00'), 'ends_at' => at($tw, 4, '23:00'), 'assignee' => 26]);
ok($c === 200, 'while it is pending the builder still schedules her that Friday: no warning (' . $c . ')');
[$c, $d] = screen($priya, '/requests?state=waiting');
ok(count($d['availability'] ?? []) === 1 && $d['availability'][0]['status'] === 'pending', 'My requests lists it as waiting');

echo "2. A manager approves it — now it warns\n";
$since = last_activity_id(); $ob = last_outbox_id();
[$c, $d] = screen($mara, '/approvals');
ok($c === 200 && count($d['availability']) === 1 && $d['availability'][0]['availability_id'] === $a1 && $d['availability'][0]['member']['name'] === 'SMOKE Priya', 'the approvals inbox (Mara) shows it, with the person');
$page = html_entity_decode(page($mara, '/approvals')['body']);
ok(str_contains($page, 'id="availability-block-' . $a1 . '-approve-btn"') && str_contains($page, 'id="availability-block-' . $a1 . '-decline-btn"'), 'the card has Approve and Decline inline');
ok(preg_match('/id="nav-approvals-count">(\d+)</', page($mara, '/')['body'], $m) && (int) $m[1] >= 1, 'the menu badge counts it (' . ($m[1] ?? 0) . ')');
[$c, $b] = act($mara, '/availability/approve.php', ['availability' => $a1, 'note' => 'SMOKE fine']);
ok($c === 200 && ($b['status'] ?? '') === 'approved', 'availability_approve: 200 approved');
$row = blocks_of(26)[0];
ok($row['status'] === 'approved' && (int) one('SELECT decided_by FROM availability_rules WHERE id = :i', ['i' => $a1]) === 33 && one('SELECT decision_note FROM availability_rules WHERE id = :i', ['i' => $a1]) === 'SMOKE fine', 'approved by Mara with her note');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'availability.approve' AND entity_id = :i AND scope_id = 102 AND id > :s", ['i' => $a1, 's' => $since]) === 1, 'availability.approve is logged with the site');
$n = outbox_ref("availability:$a1"); $mine = array_filter($n, fn ($r) => (int) $r['member_id'] === 26 && str_contains($r['body'], 'approved'));
ok(count($mine) >= 1, 'Priya is told it was approved');
$tw2 = wk(62);
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($tw2, 4, '17:00'), 'ends_at' => at($tw2, 4, '23:00'), 'assignee' => 26]);
ok($c === 422 && str_contains(msg($b), 'Marked unavailable then.'), 'now the builder warns: "' . msg($b) . '"');
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($tw2, 4, '17:00'), 'ends_at' => at($tw2, 4, '23:00'), 'assignee' => 26, 'override_reason' => 'SMOKE short-staffed']);
ok($c === 200, 'and it is soft: with a reason the manager may still schedule her');
[$c, $b] = act($mara, '/availability/approve.php', ['availability' => $a1]);
ok($c === 422 && str_contains(msg($b), 'already approved'), 'approving it again: 422 "' . msg($b) . '"');
[$c, $d] = screen($priya, '/availability');
ok(count($d['blocks']) === 1 && $d['pending'] === [] && $d['blocks'][0]['words'] === '5:00–11:00 pm' && $d['blocks'][0]['day'] === 'Friday', 'her screen: one effective block "Friday 5:00–11:00 pm", nothing pending');

echo "3. A second approved block over it replaces the first\n";
[$c, $b] = $sv($priya, ['weekday' => 5, 'starts_at' => '18:00', 'ends_at' => '22:00', 'kind' => 'unavailable']);
$a2 = (int) $b['record_id'];
ok($c === 200 && ($b['status'] ?? '') === 'pending' && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a1]) === 'approved', 'the new block waits; the old one stays approved meanwhile');
[$c, $b] = act($owner, '/availability/approve.php', ['availability' => $a2]);
ok($c === 200 && (int) $b['replaced'] === 1, 'the owner approves it: replaced = 1');
ok((string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a1]) === 'replaced' && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a2]) === 'approved', 'the first became `replaced`, the second is approved');
[$c, $d] = screen($priya, '/availability');
ok(count($d['blocks']) === 1 && $d['blocks'][0]['availability_id'] === $a2, 'the screen shows the effective set only: the second block');
[$c, $b] = $sv($priya, ['weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '10:00', 'kind' => 'preferred']); $a3 = (int) $b['record_id'];
act($mara, '/availability/approve.php', ['availability' => $a3]);
ok((string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a2]) === 'approved' && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a3]) === 'approved', 'a block that does not overlap the first does not replace it (Fri 08:00–10:00 beside 18:00–22:00)');
[$c, $b] = $sv($priya, ['weekday' => 5, 'starts_at' => '19:00', 'ends_at' => '20:00', 'kind' => 'available', 'effective_from' => date('Y-m-d', time() + 30 * 86400)]); $a4 = (int) $b['record_id'];
[$c, $b] = act($mara, '/availability/approve.php', ['availability' => $a4]);
$old = q('SELECT status, effective_to::text AS t FROM availability_rules WHERE id = :i', ['i' => $a2])[0];
ok($c === 200 && $old['status'] === 'approved' && $old['t'] === date('Y-m-d', time() + 29 * 86400) && (int) $b['replaced'] === 1, 'a block that takes effect LATER does not wipe the present: the older one ends the day before (' . $old['t'] . ')');
$c1 = (int) one("SELECT count(*) FROM availability_rules WHERE member_id = 26 AND status = 'approved'");

echo "4. With availability_needs_approval off it is approved at once\n";
settings(102, ['availability_needs_approval' => false]);
[$c, $b] = $sv($ana, ['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', 'kind' => 'unavailable']); $n1 = (int) $b['record_id'];
ok($c === 200 && ($b['status'] ?? '') === 'approved' && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $n1]) === 'approved' && preg_match('~-' . $n1 . '$~', $b['location']), 'Ana\'s block: approved at once, the location ends in the id');
ok(told_ref("availability:$n1") === [], 'nobody is told (nothing waits)');
[$c, $b] = $sv($ana, ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00', 'kind' => 'available']); $n2 = (int) $b['record_id'];
ok($c === 200 && (int) $b['replaced'] === 1 && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $n1]) === 'replaced', 'a second, overlapping block is approved at once and replaces the first');
settings(102, ['availability_needs_approval' => true]);

echo "5. Whole day, past midnight, and the words that refuse\n";
[$c, $b] = $sv($lee, ['weekday' => 0, 'kind' => 'unavailable']); $w1 = (int) $b['record_id'];
$row = q("SELECT to_char(starts_at, 'HH24:MI') AS a, to_char(ends_at, 'HH24:MI') AS b FROM availability_rules WHERE id = :i", ['i' => $w1])[0];
ok($c === 200 && $row['a'] === '00:00' && $row['b'] === '00:00', 'no times = the whole day (00:00 to 00:00)');
[, $d] = screen($lee, '/availability');
ok($d['pending'][0]['all_day'] === true && $d['pending'][0]['words'] === 'All day', 'shown as "All day"');
[$c, $b] = $sv($lee, ['weekday' => 3, 'starts_at' => '22:00', 'ends_at' => '02:00', 'kind' => 'unavailable']); $w2 = (int) $b['record_id'];
[, $d] = screen($lee, '/availability');
$words = array_column(array_column($d['pending'], null, 'availability_id'), 'words', 'availability_id');
ok($c === 200 && $words[$w2] === '10:00 pm–2:00 am (next day)', 'a block may run past midnight: "' . ($words[$w2] ?? '') . '"');
$bad = [
    [['weekday' => 9, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable'], 'Choose a day of the week.'],
    [['weekday' => 1, 'starts_at' => '10:00', 'kind' => 'unavailable'], 'Give both a start and an end, or leave both empty for the whole day.'],
    [['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'sometimes'], 'Choose available, unavailable or preferred.'],
    [['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '10:00', 'kind' => 'unavailable'], 'A block needs a start and an end that differ (00:00 to 00:00 is the whole day).'],
    [['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable', 'effective_from' => '2030-05-10', 'effective_to' => '2030-05-01'], 'A block cannot end before it takes effect.'],
    [['weekday' => 1, 'starts_at' => '25:00', 'ends_at' => '12:00', 'kind' => 'unavailable'], 'Give the times as hours and minutes, like 17:00.'],
];
$n0 = (int) one('SELECT count(*) FROM availability_rules'); $badOk = [];
foreach ($bad as [$f, $why]) { [$c, $b] = $sv($lee, $f); if ($c !== 422 || msg($b) !== $why) { $badOk[] = json_encode($f) . " → $c " . msg($b); } }
ok($badOk === [] && (int) one('SELECT count(*) FROM availability_rules') === $n0, 'six malformed submissions are each refused 422 in words, and nothing was stored' . ($badOk ? ' — ' . implode('; ', $badOk) : ''));
$ws5 = wk(63);
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws5, 6, '12:00'), 'ends_at' => at($ws5, 6, '16:00'), 'assignee' => 31]);   // Lee's Sunday all-day block is still pending
ok($c === 200, 'a pending whole-day block warns nobody (Sunday shift for Lee saved)');
act($mara, '/availability/approve.php', ['availability' => $w1]);
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws5, 6, '18:00'), 'ends_at' => at($ws5, 6, '21:00'), 'assignee' => 31]);
ok($c === 422 && str_contains(msg($b), 'Marked unavailable then.'), 'once approved the whole day is unavailable (a shift any hour of that Sunday: "' . msg($b) . '")');

echo "6. Who may read and change whose\n";
[$c, $b] = act($lee, '/availability/save.php', ['member' => 26, 'weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable']);
ok($c === 403 && msg($b) === 'You may not build the schedule here.', 'Lee (staff, same restaurant) cannot enter availability for Priya: 403 "' . msg($b) . '"');
$r = req('GET', '/availability?member=26', ['jar' => $lee, 'headers' => JSONH]);
ok($r['code'] === 403, 'nor read it: 403');
$r = req('GET', '/availability?member=26', ['jar' => $dana, 'headers' => JSONH]);
ok($r['code'] === 403, 'a shift lead (no schedule.build) neither: 403');
[$c, $b] = act($lee, '/availability/remove.php', ['availability' => $a2]);
ok($c === 403, 'Lee removing Priya\'s block: 403');
foreach (['Sam (another restaurant)' => $sam, 'Dee (manager of another restaurant)' => $dee] as $who => $jar) {
    $r = req('GET', '/availability?member=26', ['jar' => $jar, 'headers' => JSONH]);
    [$c2, $b2] = act($jar, '/availability/save.php', ['member' => 26, 'weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable']);
    [$c3] = act($jar, '/availability/remove.php', ['availability' => $a2]);
    [$c4] = act($jar, '/availability/approve.php', ['availability' => $a4]);
    ok($r['code'] === 404 && $c2 === 404 && $c3 === 404 && $c4 === 404, "$who: read, enter, remove and approve Priya's availability are all 404 (" . implode(',', [$r['code'], $c2, $c3, $c4]) . ')');
}
$before = count(blocks_of(26));
[$c, $b] = act($lee, '/availability/approve.php', ['availability' => $w2]);
ok($c === 403 && msg($b) === 'You may not approve requests here.', 'Lee approving a colleague\'s pending block: 403 "' . msg($b) . '"');
[$c, $b] = act($priya, '/availability/approve.php', ['availability' => $w2]);
ok($c === 403, 'Priya (staff) approving one: 403');
[$c, $b] = $sv($mara, ['weekday' => 4, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable']); $m1 = (int) $b['record_id'];
ok($c === 200 && ($b['status'] ?? '') === 'pending', 'Mara\'s own block goes through the same approval: pending');
[$c, $b] = act($mara, '/availability/approve.php', ['availability' => $m1]);
ok($c === 403 && msg($b) === 'You cannot decide your own availability.', 'and she cannot approve her own: 403 "' . msg($b) . '"');
[$c, $b] = act($owner, '/availability/approve.php', ['availability' => $m1]);
ok($c === 200, 'the owner can');
[$c, $b] = act($mara, '/availability/save.php', ['member' => 30, 'weekday' => 4, 'starts_at' => '14:00', 'ends_at' => '16:00', 'kind' => 'preferred']);
$e1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && ($b['status'] ?? '') === 'approved' && (int) one('SELECT decided_by FROM availability_rules WHERE id = :i', ['i' => $e1]) === 33, 'a manager who may approve enters Ana\'s block for her (member=30): approved at once, by Mara');
[$c, $d] = screen($mara, '/availability?member=30');
ok($c === 200 && $d['member']['name'] === 'SMOKE Ana' && count($d['blocks']) >= 2, 'Mara reads Ana\'s availability');
$page = html_entity_decode(page($mara, '/availability?member=30')['body']);
ok(str_contains($page, 'You are looking at SMOKE Ana') && str_contains($page, 'id="availability-person-form"'), 'the page says whose it is, and offers the person chooser');
[$c, $b] = act($priya, '/availability/save.php', ['member' => 30, 'weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable']);
ok($c === 403, 'Priya cannot enter for Ana: 403');
echo "   — a block for one restaurant and one for every restaurant\n";
[$c, $b] = $sv($marco, ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable', 'site' => 101]); $k1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && (int) one('SELECT scope_id FROM availability_rules WHERE id = :i', ['i' => $k1]) === 101 && (int) one("SELECT scope_id FROM activity_log WHERE action = 'availability.submit' AND entity_id = :i", ['i' => $k1]) === 101, 'Marco\'s block for Downtown: scope 101, logged at Downtown');
[$c, $b] = act($mara, '/availability/approve.php', ['availability' => $k1]);
ok($c === 404, 'Mara (Airport only) cannot see or approve it: 404');
[$c, $b] = act($dee, '/availability/approve.php', ['availability' => $k1]);
ok($c === 200, 'Dee (Downtown manager) approves it');
[$c, $b] = $sv($marco, ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable']); $k2 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && one('SELECT scope_id FROM availability_rules WHERE id = :i', ['i' => $k2]) === null, 'Marco\'s block with no restaurant is for every restaurant he works at (scope NULL)');
[$c, $b] = act($dee, '/availability/approve.php', ['availability' => $k2]);
ok($c === 200, 'a manager at ANY of his restaurants may approve it (Dee, Downtown)');
[$c, $b] = $sv($marco, ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable', 'site' => 555]);
ok($c === 404, 'a restaurant he does not hold: 404');
[$c, $b] = $sv($priya, ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable', 'site' => 101]);
ok($c === 404, 'a restaurant Priya does not work at: 404 ("Not found.")');

echo "7. Decline, remove, and what the person sees\n";
[$c, $b] = $sv($priya, ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '14:00', 'kind' => 'unavailable']); $d1 = (int) $b['record_id'];
$ob = last_outbox_id();
[$c, $b] = act($mara, '/availability/decline.php', ['availability' => $d1, 'note' => 'SMOKE we need Mondays']);
ok($c === 200 && ($b['status'] ?? '') === 'declined' && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $d1]) === 'declined', 'availability_decline: 200, declined');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE id > :o AND member_id = 26 AND body LIKE '%declined%' AND body LIKE '%we need Mondays%'", ['o' => $ob]) >= 1, 'Priya is told, with the note');
[, $d] = screen($priya, '/requests?state=decided');
ok(count(array_filter($d['availability'] ?? [], fn ($x) => $x['availability_id'] === $d1 && $x['status'] === 'declined')) === 1, 'My requests (decided) lists the declined block');
[$c, $b] = act($priya, '/availability/remove.php', ['availability' => $a3]);
ok($c === 200 && (string) one('SELECT status FROM availability_rules WHERE id = :i', ['i' => $a3]) === 'replaced' && (int) one("SELECT count(*) FROM activity_log WHERE action = 'availability.remove' AND entity_id = :i AND scope_id = 102", ['i' => $a3]) === 1, 'Priya removes her own block: 200, out of effect (kept in the record), logged with the site');
[$c, $b] = act($priya, '/availability/remove.php', ['availability' => $a3]);
ok($c === 422, 'removing it twice: 422 "' . msg($b) . '"');
[$c, $b] = act($mara, '/availability/remove.php', ['availability' => $e1]);
ok($c === 200, 'a manager removes a block of Ana\'s at her restaurant: 200');
[, $d] = screen($priya, '/availability');
ok(!in_array($a3, array_column($d['blocks'], 'availability_id'), true), 'the removed block is gone from her screen');
$html = page($priya, '/availability?add=4')['body'];
ok(str_contains($html, 'id="availability-form"') && str_contains($html, 'name="weekday"') && preg_match('~<option value="4" selected>Thursday~', $html), '"+ Add" on Thursday opens the form with the day chosen');
ok(substr_count($html, 'availability-day-') >= 7 && str_contains($html, 'id="availability-day-3-add-btn"'), 'every day has its own + Add');
echo "8. An action token acts as its member\n";
$key = need('ACTION_TOKEN_KEY');
$person = fn (int $m): string => ($p = $m . '.' . (time() + 300)) . '.' . hash_hmac('sha256', $p, $key);
$r = req('POST', '/availability/save.php', ['headers' => array_merge(JSONH, ['X-Action-Token: ' . $person(30)]), 'form' => ['weekday' => 6, 'starts_at' => '12:00', 'ends_at' => '15:00', 'kind' => 'unavailable']]);
$b = json_decode($r['body'], true);
$row = q("SELECT source, actor_member_id, scope_id FROM activity_log WHERE action = 'availability.submit' AND entity_id = :i", ['i' => (int) ($b['record_id'] ?? 0)])[0] ?? [];
ok($r['code'] === 200 && ($row['source'] ?? '') === 'assistant' && (int) $row['actor_member_id'] === 30, 'availability_submit with Ana\'s action token: 200, no CSRF token, source assistant, her id');
finish();
