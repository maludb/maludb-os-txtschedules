<?php
/**
 * Proof — the restaurant's settings act (spec "Proof": trade settings act; hours a day of time off, D13; slice 3's owed settings half): every change is made through the screen's own handler (never SQL) and the
 * next action of every other slice obeys it; the preview sentence follows each state; the log records the changed fields alone; a manager without settings.manage is refused; a change reaches new requests only.
 */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = admin_jar(102); $mara = as_member(33); $priya = as_member(26); $lee = as_member(31); $ana = as_member(30); $dana = as_member(32); $joe = as_member(34); $dee = as_dee(); $dex = as_dex(); $sol = as_setter(); $pat = as_planner();
$srv = $W['aSrv'];

echo "1. The screen, the sentence and the gates\n";
[$c, $d] = screen($owner, '/site/?site=102');
ok($c === 200 && $d['settings']['allow_swap'] === true && $d['settings']['cutoff_minutes'] === 120 && $d['settings']['time_off_day_hours'] == 8 && $d['settings']['approval_pickup'] === 'on_warning', 'the admin opens Airport\'s settings (JSON): swaps allowed, cutoff 120, a day of time off = 8 h, pick-ups need a manager on a warning');
$html = page($owner, '/site/?site=102')['body'];
ok(str_contains($html, 'id="site-settings-header"') && str_contains($html, 'id="site-settings-form-field-allow-swap"') && str_contains($html, 'id="site-settings-form-field-day-hours"') && !str_contains($html, 'is not built yet'), 'the page has the form, the trade selects and the hours-a-day field — not a placeholder');
ok(str_contains(sentence_on_page($owner, 102), 'Staff can offer, pick up, swap and give shifts. A manager looks at a pick-up, a swap and a give only when a rule warns.') && str_contains(sentence_on_page($owner, 102), 'Nothing changes hands within 2 hours of the start.'), 'the sentence under the trade rows: "Staff can offer, pick up, swap and give shifts. A manager looks at a pick-up, a swap and a give only when a rule warns. … Nothing changes hands within 2 hours of the start."');
$codes = [];
foreach (['Mara (manager)' => $mara, 'Pat (builds, no settings)' => $pat, 'Dana (shift lead)' => $dana, 'Priya (staff)' => $priya, 'Dee (Downtown manager)' => $dee] as $who => $jar) { $codes[$who] = [req('GET', '/site/?site=102', ['jar' => $jar])['code'], req('GET', '/site/day-parts?site=102', ['jar' => $jar])['code']]; }
ok(array_map(fn ($x) => $x, array_column($codes, 0)) === [403, 403, 403, 403, 404] && array_column($codes, 1) === [403, 403, 403, 403, 404], 'settings and day-parts screens: 403 for a manager, a planner, a shift lead and staff; 404 for a manager of another restaurant (a site she does not hold does not exist)');
ok(req('GET', '/site/?site=102', ['jar' => $sol])['code'] === 200 && req('GET', '/site/?site=102', ['jar' => $dex])['code'] === 404 && req('GET', '/site/?site=101', ['jar' => $dex])['code'] === 200, 'a holder of settings.manage (Sol) opens it; Downtown\'s admin opens Downtown\'s and finds no Airport');
$before = site_row(102);
foreach (['Mara' => $mara, 'Pat' => $pat, 'Priya' => $priya] as $who => $jar) {
    [$c, $b] = set($jar, 102, ['allow_swap' => 'no']);
    ok($c === 403 && msg($b) === 'You may not change this restaurant\'s settings here.', "$who: a forced save is 403 \"You may not change this restaurant's settings here.\"");
}
[$c, $b] = set($dex, 102, ['allow_swap' => 'no']);
ok($c === 404, 'Downtown\'s admin saving Airport\'s settings: 404');
$r = req('POST', '/site/save.php', ['jar' => $owner, 'headers' => JSONH, 'form' => ['site' => 102, 'allow_swap' => 'no']]);
ok($r['code'] === 403, 'without the CSRF token: 403'); ok(req('POST', '/site/save.php', ['headers' => JSONH, 'form' => ['site' => 102]])['code'] === 401, 'no session: 401'); ok(req('GET', '/site/save.php?site=102', ['jar' => $owner, 'headers' => JSONH])['code'] === 405, 'a GET: 405');
ok(site_row(102) === $before, 'and none of those refusals changed a thing');

echo "2. Validation — every field in its own words, nothing clamped\n";
$bad = [['week_start' => '7'], ['week_start' => 'x'], ['currency' => 'usd1'], ['allow_swap' => 'maybe'], ['approval_give' => 'sometimes'], ['cutoff_minutes' => '-1'], ['cutoff_minutes' => '10081'], ['cutoff_minutes' => '1.5'],
        ['claim_mode' => 'lottery'], ['offer_expires' => 'never'], ['reminder_minutes_before' => '2881'], ['time_off_day_hours' => '0'], ['time_off_day_hours' => '25'], ['time_off_day_hours' => 'eight'], ['overtime_weekly_hours' => '0'], ['overtime_multiplier' => '0.9']];
$miss = [];
foreach ($bad as $f) { [$c, $b] = set($owner, 102, $f); if ($c !== 422 || msg($b) === '') { $miss[] = json_encode($f) . " → $c"; } }
ok($miss === [] && site_row(102) === $before, count($bad) . ' out-of-range or malformed values are each 422 with a sentence, and nothing was saved' . ($miss ? ' — ' . implode('; ', $miss) : ''));
[$c, $b] = set($owner, 102, ['cutoff_minutes' => '99999']);
ok(msg($b) === 'No trade closer than is a number of minutes from 0 to 10080 (a week).', 'the words: "' . msg($b) . '"');
[$c, $b] = set($owner, 102, ['time_off_day_hours' => '25']);
ok(msg($b) === 'A day of time off counts from 0.25 to 24 hours.', 'and for the hours a day: "' . msg($b) . '"');
[$c, $b] = set($owner, 102, ['cutoff_minutes' => '60', 'time_off_day_hours' => '99']);
ok($c === 422 && (int) site_row(102)['cutoff_minutes'] === 120, 'a save with one bad field saves none of them (the whole is refused)');
[$c, $b] = set($owner, 102, []);
ok($c === 200 && ($b['changed'] ?? null) === [] && str_contains((string) ($b['did'] ?? ''), 'Nothing changed') && (int) one("SELECT count(*) FROM activity_log WHERE action = 'settings.update'") === 0, 'an empty save changes nothing and logs nothing');

echo "3. allow_swap off: the button goes and a forced swap is 422\n";
$h = t7_shift(102, $srv, 26, 400, 4);
$page = page($priya, "/shifts/$h")['body'];
ok(str_contains($page, 'id="shift-swap-details"') || str_contains($page, 'shift-swap-open'), 'before: the shift page offers Swap');
$since = last_activity_id();
[$c, $b] = set($owner, 102, ['allow_swap' => 'no']);
ok($c === 200 && $b['changed'] === ['allow_swap'] && (int) $b['record_id'] === 102 && str_contains($b['location'], '/site/') && str_contains($b['location'], 'notice=st_saved'), 'the admin turns swaps off: 200, changed [allow_swap], the reply names the restaurant and lands on the settings');
ok(site_row(102)['allow_swap'] === 'f' || site_row(102)['allow_swap'] === false, 'the row says so');
$page = page($priya, "/shifts/$h")['body'];
ok(str_contains($page, 'id="offer-form"') && !str_contains($page, 'id="shift-swap-details"') && !str_contains($page, 'shift-swap-open'), 'the shift page has Offer and no Swap button');
[$c, $b] = act($priya, '/exchanges/swap.php', ['shift' => $h, 'colleague' => 30, 'swap_shift' => $W['a2']]);
ok($c === 422 && msg($b) === 'This restaurant does not allow that kind of trade.', 'a forced shift_swap: 422 "This restaurant does not allow that kind of trade."');
ok(str_contains(sentence_on_page($owner, 102), 'Staff can offer, pick up and give shifts.') && !str_contains(sentence_on_page($owner, 102), 'swap'), 'the sentence follows: "Staff can offer, pick up and give shifts."');
$row = q("SELECT source, scope_id, before, after, actor_member_id FROM activity_log WHERE action = 'settings.update' AND id > :s", ['s' => $since]);
ok(count($row) === 1 && (int) $row[0]['scope_id'] === 102 && $row[0]['source'] === 'web' && (int) $row[0]['actor_member_id'] === 1 && json_decode($row[0]['before'], true) === ['allow_swap' => true] && json_decode($row[0]['after'], true) === ['allow_swap' => false], 'logged settings.update with the site: before {allow_swap: true} after {allow_swap: false} — that field alone');
[$c, $b] = set($owner, 102, ['allow_swap' => 'yes']);
[$c2, $b2] = act($priya, '/exchanges/swap.php', ['shift' => $h, 'colleague' => 30, 'swap_shift' => t7_shift(102, $srv, 30, 402, 4)]);
ok($c === 200 && !str_contains(msg($b2), 'does not allow'), 'turned back on: the swap is no longer refused for that reason (' . $c2 . ')');
if (($b2['record_id'] ?? 0) > 0) { q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $b2['record_id']]); }

echo "4. approval_pickup: always / on_warning / never\n";
$mk = function (float $h, ?int $lee_prior = null) use ($srv) { return t7_shift(102, $srv, 26, $h, 3); };
set($owner, 102, ['approval_pickup' => 'always']);
$s1 = $mk(410); clear_for(31, 410);
$x = offer($priya, $s1);
[$c, $b] = claim($lee, $x);
ok($c === 200 && ($b['outcome'] ?? '') === 'pending_approval' && status_of($x) === 'pending_approval' && holder_of($s1) === 26, 'always: a plain claim (no warning at all) waits for a manager');
act($mara, '/exchanges/decline.php', ['exchange' => $x, 'note' => 'SMOKE s7']);
set($owner, 102, ['approval_pickup' => 'on_warning']);
$s2 = $mk(430); clear_for(31, 430);
$x = offer($priya, $s2);
$warn = (int) one('SELECT count(*) FROM ts_shift_warnings(:s, 31)', ['s' => $s2]);
[$c, $b] = claim($lee, $x);
ok($warn === 0 && ($b['outcome'] ?? '') === 'approved' && holder_of($s2) === 31, 'on a warning: the same plain claim goes straight through (no rule warns)');
$s3 = $mk(450); $l3 = t7_shift(102, $srv, 31, 445, 2); // ends 3 h before Priya's starts → min_rest (soft)
$x = offer($priya, $s3);
$warn = (int) one('SELECT count(*) FROM ts_shift_warnings(:s, 31)', ['s' => $s3]);
[$c, $b] = claim($lee, $x);
ok($warn >= 1 && ($b['outcome'] ?? '') === 'pending_approval' && holder_of($s3) === 26, 'on a warning: a claim that trips min_rest (soft) waits for a manager');
act($mara, '/exchanges/decline.php', ['exchange' => $x, 'note' => 'SMOKE s7']);
set($owner, 102, ['approval_pickup' => 'never']);
$x = offer($priya, $s3);
[$c, $b] = claim($lee, $x);
ok(($b['outcome'] ?? '') === 'approved' && holder_of($s3) === 31, 'never: the same claim, with its soft warning, goes through with no manager');
set($owner, 102, ['approval_pickup' => 'on_warning']);
ok(str_contains(preview_of($owner, 102, ['approval_pickup' => 'always']), 'A manager approves every pick-up.') && str_contains(preview_of($owner, 102, ['approval_pickup' => 'never']), 'A pick-up never waits for a manager.'), 'the sentence says "A manager approves every pick-up." and "A pick-up never waits for a manager." as typed (nothing saved)');

echo "5. claim_mode = manager_chooses\n";
set($owner, 102, ['claim_mode' => 'manager_chooses']);
$s4 = $mk(470); clear_for(31, 470); clear_for(30, 470);
$x = offer($priya, $s4);
[$c, $b] = claim($lee, $x); [$c2, $b2] = claim($ana, $x);
ok(($b['outcome'] ?? '') === 'claimed' && ($b2['outcome'] ?? '') === 'claimed' && status_of($x) === 'open' && holder_of($s4) === 26, 'two claims wait — the shift has not moved');
[$c, $b] = act($mara, '/exchanges/choose.php', ['exchange' => $x, 'member' => 30]);
ok($c === 200 && holder_of($s4) === 30, 'the manager chooses Ana: the shift is hers');
set($owner, 102, ['claim_mode' => 'first']);
ok(str_contains(preview_of($owner, 102, ['claim_mode' => 'manager_chooses']), 'a manager chooses.') && str_contains(preview_of($owner, 102, ['claim_mode' => 'first']), 'the first to ask gets it.'), 'the sentence follows claim mode');

echo "6. cutoff_minutes: 180 refuses an offer two hours out and accepts one four hours out\n";
set($owner, 102, ['cutoff_minutes' => '180']);
$near = t7_shift(102, $srv, 26, 2, 2, 0); $far = t7_shift(102, $srv, 26, 4.5, 2, 0);
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $near]);
ok($c === 422 && msg($b) === 'Too close to the start of the shift to change hands.', 'an offer two hours out: 422 "' . msg($b) . '"');
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $far]);
ok($c === 200 && (int) ($b['record_id'] ?? 0) > 0, 'an offer four and a half hours out: accepted');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $b['record_id'] ?? 0]);
set($owner, 102, ['cutoff_minutes' => '60']);
[$c, $b] = act($priya, '/exchanges/offer.php', ['shift' => $near]);
ok($c === 200, 'with the cutoff at 60 minutes the two-hours-out offer is accepted (the cutoff is read live)');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $b['record_id'] ?? 0]);
ok(str_contains(preview_of($owner, 102, ['cutoff_minutes' => '180']), 'Nothing changes hands within 3 hours of the start.') && str_contains(preview_of($owner, 102, ['cutoff_minutes' => '90']), 'within 90 minutes') && str_contains(preview_of($owner, 102, ['cutoff_minutes' => '0']), 'right up to the start') && str_contains(preview_of($owner, 102, ['cutoff_minutes' => '2880']), 'within 2 days'), 'the sentence: 3 hours, 90 minutes, right up to the start, 2 days');
set($owner, 102, ['cutoff_minutes' => '120']);

echo "7. shift_lead_approves_same_day off: the lead is refused a same-day approval\n";
set($owner, 102, ['approval_pickup' => 'always']);
$sd = t7_shift(102, $srv, 26, 7, 3); clear_for(31, 7);
$x = offer($priya, $sd); claim($lee, $x);
ok(status_of($x) === 'pending_approval', 'a same-day trade waits for approval');
set($owner, 102, ['shift_lead_approves_same_day' => 'no']);
[$c, $b] = act($dana, '/exchanges/approve.php', ['exchange' => $x]);
ok($c === 403 && msg($b) === 'This restaurant does not let shift leads approve trades.', 'off: Dana is refused: 403 "This restaurant does not let shift leads approve trades."');
ok(str_contains(preview_of($owner, 102, ['shift_lead_approves_same_day' => 'no']), 'Only a manager approves same-day and next-day trades.'), 'the sentence: "Only a manager approves same-day and next-day trades."');
set($owner, 102, ['shift_lead_approves_same_day' => 'yes']);
[$c, $b] = act($dana, '/exchanges/approve.php', ['exchange' => $x]);
ok($c === 200 && holder_of($sd) === 31, 'on again: Dana approves and the shift is Lee\'s');
set($owner, 102, ['approval_pickup' => 'on_warning']);

echo "8. The other fields of the form reach the rest of the application\n";
[$c, $b] = set($owner, 102, ['week_start' => '0', 'currency' => 'cad', 'availability_needs_approval' => 'no', 'reminder_minutes_before' => '45', 'overtime_weekly_hours' => '32.5', 'overtime_multiplier' => '2', 'offer_expires' => 'at_cutoff']);
ok($c === 200 && $b['changed'] === ['week_start', 'currency', 'offer_expires', 'availability_needs_approval', 'reminder_minutes_before', 'overtime_weekly_hours', 'overtime_multiplier'], 'week start, currency, offer expiry, availability approval, reminder lead, overtime hours and multiplier saved in one go: ' . json_encode($b['changed'] ?? null));
$s = settings_json($owner, 102);
ok($s['week_start'] === 0 && $s['currency'] === 'CAD' && $s['availability_needs_approval'] === false && $s['reminder_minutes_before'] === 45 && $s['overtime_weekly_hours'] == 32.5 && $s['overtime_multiplier'] == 2 && $s['offer_expires'] === 'at_cutoff', 'the screen reads them back typed (currency upper-cased)');
$bd = req('GET', '/builder?site=102&week=' . wk(150), ['jar' => $owner])['body'];
ok(str_contains(sentence_on_page($owner, 102), 'An offer nobody takes ends at the cutoff.') && (int) one("SELECT week_start FROM site_settings WHERE scope_id = 102") === 0, 'the sentence says an offer ends at the cutoff; the week now starts on Sunday');
$row = q("SELECT before, after FROM activity_log WHERE action = 'settings.update' ORDER BY id DESC LIMIT 1")[0];
$ks = array_keys(json_decode($row['after'], true)); sort($ks); $want = ['week_start', 'currency', 'offer_expires', 'availability_needs_approval', 'reminder_minutes_before', 'overtime_weekly_hours', 'overtime_multiplier']; sort($want);
ok($ks === $want && json_decode($row['before'], true)['currency'] === 'USD', 'the log has exactly those seven fields, before and after');
set($owner, 102, ['week_start' => '1', 'currency' => 'USD', 'availability_needs_approval' => 'yes', 'reminder_minutes_before' => '120', 'overtime_weekly_hours' => '40', 'overtime_multiplier' => '1.5', 'offer_expires' => 'at_start']);
ok(site_row(102)['week_start'] == 1 && site_row(102)['currency'] === 'USD' && (float) site_row(102)['overtime_multiplier'] === 1.5, 'and back to the shipped values');

echo "9. Hours a day of time off (D13): the example line, the log, the bounds, new requests only, Downtown apart\n";
$unp = typ(102, 'unpaid'); $unpD = typ(101, 'unpaid');
$w1 = wk(160); $w2 = wk(161); $w3 = wk(162);
[$c, $r1] = req_off($priya, $unp, dayn($w1, 0), dayn($w1, 1));
$id1 = (int) ($r1['record_id'] ?? 0);
ok($c === 200 && $id1 > 0 && (float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $id1]) === 16.0, 'before: two whole days of time off count 16 hours (8 a day)');
$r = req('GET', '/site/?site=102&preview=day&time_off_day_hours=6', ['jar' => $owner]);
ok(trim(strip_tags($r['body'])) === 'Vacation of two whole days counts 12 hours.' && (float) site_row(102)['time_off_day_hours'] === 8.0, 'typing 6 in the field: the example line under it reads "Vacation of two whole days counts 12 hours." (nothing saved yet)');
ok(str_contains(page($owner, '/site/?site=102')['body'], 'id="site-settings-day-hours-example">Vacation of two whole days counts 16 hours.<'), 'and the page renders the saved value\'s line: "Vacation of two whole days counts 16 hours."');
$since = last_activity_id();
[$c, $b] = set($owner, 102, ['time_off_day_hours' => '6']);
ok($c === 200 && $b['changed'] === ['time_off_day_hours'], 'the admin sets "A day of time off counts 6 hours": 200');
$row = q("SELECT scope_id, before, after FROM activity_log WHERE action = 'settings.update' AND id > :s", ['s' => $since]);
ok(count($row) === 1 && (int) $row[0]['scope_id'] === 102 && json_decode($row[0]['before'], true) === ['time_off_day_hours' => 8] && json_decode($row[0]['after'], true) === ['time_off_day_hours' => 6], 'settings.update carries before/after of that field alone: ' . $row[0]['before'] . ' → ' . $row[0]['after']);
ok(str_contains(page($owner, '/site/?site=102')['body'], 'id="site-settings-day-hours-example">Vacation of two whole days counts 12 hours.<'), 'the saved page reads "Vacation of two whole days counts 12 hours."');
foreach (['0', '25', '-3', '24.5', ''] as $v) { [$c, $b] = set($owner, 102, ['time_off_day_hours' => $v]); if ($c !== 422) { ok(false, "time_off_day_hours=$v was $c"); } }
ok((float) site_row(102)['time_off_day_hours'] === 6.0, '0, 25, a negative, 24.5 and an empty value are each 422; the setting is still 6');
[$c, $r2] = req_off($priya, $unp, dayn($w2, 0), dayn($w2, 1));
$id2 = (int) ($r2['record_id'] ?? 0);
ok((float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $id2]) === 12.0 && (float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $id1]) === 16.0, 'a new request with no hours counts 12; the earlier one keeps its 16');
$json = screen($priya, '/time-off')[1];
ok(str_contains(page($priya, '/time-off/new')['body'], 'count 6 a day'), 'the request form says "leave empty to count 6 a day"');
[$c, $b] = set($dex, 102, ['time_off_day_hours' => '10']);
ok($c === 404 && (float) site_row(102)['time_off_day_hours'] === 6.0, 'Downtown\'s own admin cannot set Airport\'s hours a day: 404');
[$c, $b] = set($dex, 101, ['time_off_day_hours' => '10']);
ok($c === 200 && (float) site_row(102)['time_off_day_hours'] === 6.0 && (float) site_row(101)['time_off_day_hours'] === 10.0, 'Downtown\'s setting is its own: 10 there, still 6 at Airport');
[$c, $r3] = req_off($joe, $unpD, dayn($w3, 0), dayn($w3, 1));
ok((float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => (int) ($r3['record_id'] ?? 0)]) === 20.0, 'a Downtown request for two days counts 20 hours');
[$c, $b] = set($mara, 102, ['time_off_day_hours' => '3']);
ok($c === 403 && (float) site_row(102)['time_off_day_hours'] === 6.0, 'a manager without settings.manage: 403 and nothing changed');
[$c, $b] = set($sol, 102, ['time_off_day_hours' => '7']);
ok($c === 200 && (float) site_row(102)['time_off_day_hours'] === 7.0, 'a holder of settings.manage who is no super-admin (Sol) may');
ok(wage_leaks(json_encode(q("SELECT before, after, route FROM activity_log WHERE action = 'settings.update'"))) === [], 'no settings.update row holds a fixture wage');
foreach ([$id1, $id2, (int) ($r3['record_id'] ?? 0)] as $i) { admin_sql("UPDATE time_off_requests SET status = 'cancelled' WHERE id = $i AND status = 'pending'"); }
reset7();
finish();
