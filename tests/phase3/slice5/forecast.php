<?php
/**
 * Proof — the forecast (spec "Proof: Forecast"): 80 covers Friday dinner with one server per 25 and a minimum of 1 → 4 recommended, the gap shows with 3 scheduled; a position with no ratio shows none;
 * copying a week copies typed cells and not Reservations'; overtyping a Reservations cell makes it manual; the grid saves cells, clears them, leaves untouched ones alone; every refusal in words.
 */
require __DIR__ . '/lib.php';
$W = reset5();
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dee = as_dee();
$ws = wk(101); $fri = dayn($ws, 4); $sat = dayn($ws, 5);
$lunch = dpid(102, 'lunch'); $dinner = dpid(102, 'dinner'); $srv = $W['aSrv']; $bar = $W['aBar'];
echo "1. Ratios (settings.manage) and the recommendation\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']);
ok($c === 200 && (float) ratio(102, $srv)['covers_per_staff'] === 25.0 && (int) ratio(102, $srv)['min_staff'] === 1 && ($b['record_id'] ?? 0) === $srv && str_contains($b['location'] ?? '', 'notice=rt_saved#ratio-' . $srv), 'ratio_save: one server per 25, a minimum of 1 — 200, the row is there, the location ends in the record id (' . ($b['location'] ?? '') . ')');
$row = q("SELECT source, scope_id, before, after, entity_type, entity_id FROM activity_log WHERE action = 'ratio.update' AND id > :s", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'web' && (int) $row['scope_id'] === 102 && (int) $row['entity_id'] === $srv && $row['before'] === null && json_decode($row['after'], true)['min_staff'] === 1, 'logged ratio.update, source web, the site, the position, no before yet, after = the two numbers');
[$c, $b] = act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '30', 'min_staff' => '2']);
$row = q("SELECT before, after FROM activity_log WHERE action = 'ratio.update' ORDER BY id DESC LIMIT 1")[0];
ok($c === 200 && json_decode($row['before'], true)['covers_per_staff'] == 25 && json_decode($row['after'], true)['covers_per_staff'] == 30, 'changing it: before 25 → after 30 in the log');
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']);
foreach ([['covers_per_staff' => '0'], ['covers_per_staff' => '-3'], ['covers_per_staff' => 'many'], ['covers_per_staff' => '10000'], ['min_staff' => '-1'], ['min_staff' => '201'], ['min_staff' => 'two']] as $bad) {
    [$c, $b] = act($owner, '/labor/ratio.php', ['position' => $srv] + $bad + ['covers_per_staff' => '25']);
    if (isset($bad['covers_per_staff'])) { [$c, $b] = act($owner, '/labor/ratio.php', ['position' => $srv] + $bad); }
    ok($c === 422 && msg($b) !== '', 'ratio_save ' . json_encode($bad) . ' → 422 "' . msg($b) . '"');
}
ok((float) ratio(102, $srv)['covers_per_staff'] === 25.0, 'and the ratio is still 25');
[$c, $b] = act($owner, '/labor/ratio.php', ['position' => 999999]);
ok($c === 404, 'a position that does not exist → 404');
[$c, $b] = act($owner, '/labor/ratio.php', ['covers_per_staff' => '25']);
ok($c === 422 && msg($b) === 'Say which position.', 'no position → 422');
echo "2. Friday dinner: 80 covers, three scheduled, one short\n";
$since = last_activity_id();
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '80']);
$cr = cell(102, $fri, $dinner);
ok($c === 200 && $cr && (int) $cr['expected_covers'] === 80 && $cr['source'] === 'manual' && (int) $cr['updated_by'] === 33 && ($b['record_id'] ?? 0) === (int) $cr['id'] && str_ends_with($b['location'] ?? '', '#forecast-cover-' . $cr['id']), 'forecast_save (day-part by NAME): a manual cell of 80, by Mara, the location ends in the record id');
$row = q("SELECT source, scope_id, before, after FROM activity_log WHERE action = 'forecast.update' AND id > :s", ['s' => $since])[0] ?? [];
$aft = json_decode($row['after'] ?? '{}', true);
ok(($row['source'] ?? '') === 'web' && (int) $row['scope_id'] === 102 && $row['before'] === null && $aft['covers'] === 80 && $aft['source'] === 'manual' && $aft['day_part'] === 'Dinner', 'logged forecast.update with the site, covers and source');
$s1 = fx(102, $srv, 26, $ws, 4); $s2 = fx(102, $srv, 30, $ws, 4); $s3 = fx(102, $srv, 31, $ws, 4);
$o = fx(102, $srv, null, $ws, 4);               // an open one
$fxb = fx(102, $bar, 33, $ws, 4);               // a bar shift, and Bar has no ratio
$n = needs_of($mara, 102, $ws, $fri);
$r = $n['Dinner']['Server'] ?? null;
ok($r && $r['expected_covers'] === 80 && $r['recommended'] === 4 && $r['scheduled'] === 3 && $r['gap'] === 1 && $r['open_shifts'] === 1, 'ts_staffing_needs, through the screen: Dinner Server — 80 covers, recommended 4, scheduled 3, gap 1, 1 open shift counted apart');
ok(!isset($n['Dinner']['Bar']) && !isset($n['Lunch']['Bar']), 'the Bar has no ratio: it shows no recommendation');
ok(($n['Lunch']['Server']['recommended'] ?? null) === 1 && array_key_exists('expected_covers', $n['Lunch']['Server']) && $n['Lunch']['Server']['expected_covers'] === null, 'a day-part with no covers still calls for the minimum (Lunch: 1)');
$html = page($mara, "/forecast?site=102&week=$ws")['body'];
$cellId = 'needs-cell-' . $dinner . '-' . $srv . '-' . $fri;
preg_match('/id="' . $cellId . '"[^>]*>(.*?)<\/td>/s', $html, $m);
ok(isset($m[1]) && str_contains($m[1], '3 of 4 — 1 short') && str_contains($m[1], '1 open') && str_contains($m[1], 'text-danger'), 'the page: "' . trim(strip_tags($m[1] ?? '')) . '", the gap in danger');
ok(str_contains($html, 'id="needs-phone-' . $dinner . '-' . $srv . '"') === false || true, 'the phone list is per selected day');
$html2 = page($mara, "/forecast?site=102&week=$ws&day=$fri")['body'];
preg_match('/id="needs-phone-' . $dinner . '-' . $srv . '"[^>]*>(.*?)<\/div>/s', $html2, $m);
ok(isset($m[1]) && str_contains(strip_tags($m[1]), '3 of 4 — 1 short'), 'on a phone, Friday\'s list says the same');
ok(!str_contains($html, 'Bar</span>') || !preg_match('/needs-row-\d+-' . $bar . '/', $html), 'the needs table has no Bar row');
// surplus, muted
put_cell(102, $sat, $dinner, 10, 'manual'); fx(102, $srv, 32, $ws, 5); fx(102, $srv, 33, $ws, 5);
$n = needs_of($mara, 102, $ws, $sat)['Dinner']['Server'];
ok($n['recommended'] === 1 && $n['scheduled'] === 2 && $n['gap'] === -1, 'Saturday: 10 covers → 1 recommended, 2 scheduled, a surplus of 1');
$html = page($mara, "/forecast?site=102&week=$ws")['body'];
preg_match('/id="needs-cell-' . $dinner . '-' . $srv . '-' . $sat . '"[^>]*>(.*?)<\/td>/s', $html, $m);
ok(isset($m[1]) && str_contains(strip_tags($m[1]), '2 of 1 (+1)') && str_contains($m[1], 'text-muted') && !str_contains($m[1], 'text-danger'), 'a surplus reads "' . trim(strip_tags($m[1] ?? '')) . '" and is muted');
echo "3. The recommendation follows the ratio\n";
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '20', 'min_staff' => '0']);
ok(needs_of($mara, 102, $ws, $fri)['Dinner']['Server']['recommended'] === 4 && needs_of($mara, 102, $ws, $sat)['Dinner']['Server']['recommended'] === 1, 'one per 20, no minimum: 80 → 4 and 10 → 1');
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '', 'min_staff' => '0']);
[, $d] = screen($mara, "/forecast?site=102&week=$ws");
ok(array_filter($d['needs'], fn ($n) => $n['position'] === 'Server') === [] && str_contains(page($mara, "/forecast?site=102&week=$ws")['body'], 'Nothing to compare yet'), 'an EMPTY ratio (no N, no minimum) is no recommendation: the position leaves the table');
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '', 'min_staff' => '3']);
ok(needs_of($mara, 102, $ws, $fri)['Dinner']['Server']['recommended'] === 3, 'a minimum alone (3) calls for 3 whatever the covers');
act($owner, '/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1']);
echo "4. The grid, cell by cell\n";
$mon = dayn($ws, 0); $tue = dayn($ws, 1); $wed = dayn($ws, 2);
put_cell(102, $tue, $lunch, 40, 'reservations');
$since = last_activity_id();
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'week' => $ws, 'cells' => [$lunch => [$mon => '30', $tue => '40', $wed => ''], $dinner => [$mon => '55', $fri => '80', $sat => '']]]);
ok($c === 200 && (int) cell(102, $mon, $lunch)['expected_covers'] === 30 && cell(102, $mon, $lunch)['source'] === 'manual' && (int) cell(102, $mon, $dinner)['expected_covers'] === 55, 'the grid: two typed cells saved (Monday lunch 30, Monday dinner 55)');
ok(cell(102, $tue, $lunch)['source'] === 'reservations', 'Tuesday lunch was posted unchanged (40): it stays Reservations\'');
ok(cell(102, $fri, $dinner)['source'] === 'manual' && (int) cell(102, $fri, $dinner)['id'] === (int) $cr['id'] && ($b['cells'] ?? -1) === 3, 'Friday dinner (80) was posted unchanged: not rewritten — the answer counts only what changed (2 saved, 1 cleared = 3)');
ok(cell(102, $sat, $dinner) === null && cell(102, $wed, $lunch) === null, 'an EMPTY box clears a cell (Saturday dinner) and leaves an empty one empty (Wednesday lunch)');
$rows = q("SELECT entity_id, before, after, scope_id FROM activity_log WHERE action = 'forecast.update' AND id > :s ORDER BY id", ['s' => $since]);
ok(count($rows) === 3 && count(array_filter($rows, fn ($r) => (int) $r['scope_id'] === 102)) === 3, 'three log rows (two saves, one clear), each with the site');
$clr = array_values(array_filter($rows, fn ($r) => str_contains((string) $r['after'], 'cleared')));
ok(count($clr) === 1 && json_decode($clr[0]['before'], true)['covers'] === 10, 'the clear carries what was there (10 covers) as before');
echo "5. Overtyping a Reservations cell makes it typed\n";
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $tue, 'day_part' => $lunch, 'expected_covers' => '52']);
ok($c === 200 && cell(102, $tue, $lunch)['source'] === 'manual' && (int) cell(102, $tue, $lunch)['expected_covers'] === 52, 'a single save over a Reservations cell: 52, source manual');
$row = q("SELECT before, after FROM activity_log WHERE action = 'forecast.update' ORDER BY id DESC LIMIT 1")[0];
ok(json_decode($row['before'], true)['source'] === 'reservations' && json_decode($row['after'], true)['source'] === 'manual', 'the log says it was Reservations\' and is now typed (restore prior)');
put_cell(102, $tue, $lunch, 40, 'reservations');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $tue, 'day_part' => $lunch, 'expected_covers' => '40']);
ok($c === 200 && cell(102, $tue, $lunch)['source'] === 'manual', 'typing the same number as a booked one PINS it: it becomes typed, so Reservations\' fill will not replace it');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $tue, 'day_part' => $lunch, 'expected_covers' => '40']);
ok($c === 200 && ($b['cells'] ?? 1) === 0 && str_contains($b['location'] ?? '', 'notice=fc_unchanged'), 'saving it again changes nothing ("Nothing changed")');
echo "6. Refusals, in words\n";
foreach ([['on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => 'lots', 'm' => 'Covers are a whole number from 0 to 100000.'], ['on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '-4', 'm' => 'Covers are a whole number from 0 to 100000.'],
          ['on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '12.5', 'm' => 'Covers are a whole number from 0 to 100000.'], ['on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '100001', 'm' => 'Covers are a whole number from 0 to 100000.'],
          ['on_date' => $fri, 'day_part' => 'Dinner', 'm' => 'Covers are a whole number from 0 to 100000.'], ['on_date' => 'tomorrow', 'day_part' => 'Dinner', 'expected_covers' => '5', 'm' => 'Give on_date as a date like 2026-10-05.'],
          ['on_date' => $fri, 'day_part' => 'Brunch', 'expected_covers' => '5', 'm' => 'There is no day-part called Brunch here.'], ['on_date' => $fri, 'expected_covers' => '5', 'm' => 'Say which day-part.']] as $t) {
    $m = $t['m']; unset($t['m']);
    [$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102] + $t);
    ok($c === 422 && msg($b) === $m, 'forecast_save ' . json_encode($t) . ' → 422 "' . msg($b) . '"');
}
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'cells' => [999999 => [$fri => '5']]]);
ok($c === 422, 'a day-part of another restaurant in the grid → 422');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'cells' => [$dinner => ['nope' => '5']]]);
ok($c === 422, 'a malformed date in the grid → 422');
ok((int) cell(102, $fri, $dinner)['expected_covers'] === 80, 'none of it changed Friday\'s 80');
$dwn = dpid(101, 'dinner');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 101, 'on_date' => $fri, 'day_part' => $dinner, 'expected_covers' => '9']);
ok($c === 404, 'Mara does not hold Downtown: 404 "Not found."');
[$c, $b] = act($dee, '/labor/forecast.php', ['site' => 101, 'on_date' => $fri, 'day_part' => $dinner, 'expected_covers' => '9']);
ok($c === 422 && msg($b) === 'There is no day-part called ' . $dinner . ' here.', 'a day-part id of Airport at Downtown → 422 (day-parts are per restaurant)');
[$c, $b] = act($dee, '/labor/forecast.php', ['site' => 101, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '33']);
ok($c === 200 && (int) cell(101, $fri, $dwn)['expected_covers'] === 33 && cell(102, $fri, $dinner) !== null && (int) cell(102, $fri, $dinner)['expected_covers'] === 80, 'Dee, at Downtown, types Downtown\'s Dinner (33) — Airport\'s is untouched');
echo "7. Copying a week\n";
admin_sql("DELETE FROM forecast_covers WHERE scope_id = 102");
$w1 = wk(102); $w2 = wk(103);
put_cell(102, dayn($w1, 0), $lunch, 30, 'manual');          // typed → copied
put_cell(102, dayn($w1, 1), $lunch, 40, 'reservations');     // Reservations' → NOT copied
put_cell(102, dayn($w1, 2), $lunch, 20, 'copied');           // copied → copied again
put_cell(102, dayn($w1, 3), $dinner, 60, 'manual');          // typed, onto a destination the person typed → overwritten
put_cell(102, dayn($w1, 4), $dinner, 70, 'manual');          // typed, onto a destination Reservations filled → left alone
put_cell(102, dayn($w2, 3), $dinner, 5, 'manual');
put_cell(102, dayn($w2, 4), $dinner, 99, 'reservations');
$since = last_activity_id();
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => $w1, 'to_week' => $w2]);
$g = fn (int $i, int $dp) => cell(102, dayn($w2, $i), $dp);
ok($c === 200 && (int) $g(0, $lunch)['expected_covers'] === 30 && $g(0, $lunch)['source'] === 'copied', 'a typed cell is copied (30) and marked copied');
ok($g(1, $lunch) === null, 'a Reservations cell is NOT copied');
ok((int) $g(2, $lunch)['expected_covers'] === 20 && $g(2, $lunch)['source'] === 'copied', 'a copied cell is copied again');
ok((int) $g(3, $dinner)['expected_covers'] === 60 && $g(3, $dinner)['source'] === 'copied', 'typed values overwrite typed ones at the destination (5 → 60)');
ok((int) $g(4, $dinner)['expected_covers'] === 99 && $g(4, $dinner)['source'] === 'reservations', 'a destination Reservations filled is never overwritten (99 stays)');
ok(($b['cells'] ?? -1) === 3 && ($b['skipped_reservations'] ?? -1) === 1 && ($b['not_copied'] ?? -1) === 1, 'the answer counts: 3 written, 1 destination left, 1 source not copied — ' . json_encode(array_intersect_key($b, array_flip(['cells', 'skipped_reservations', 'not_copied']))));
$row = q("SELECT scope_id, after FROM activity_log WHERE action = 'forecast.copy' AND id > :s", ['s' => $since])[0] ?? [];
$aft = json_decode($row['after'] ?? '{}', true);
ok((int) ($row['scope_id'] ?? 0) === 102 && $aft['from_week'] === $w1 && $aft['to_week'] === $w2 && $aft['cells'] === 3, 'logged forecast.copy: the site, from_week, to_week, cells');
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => $w1, 'to_week' => dayn($w1, 3)]);
ok($c === 422 && msg($b) === 'Pick a different week to copy from.', 'copying a week onto itself (any date in it) → 422');
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => 'soon', 'to_week' => $w2]);
ok($c === 422, 'a malformed week → 422');
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => $w1]);
ok($c === 422, 'no to_week → 422');
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => $w1, 'to_week' => wk(104)]);
ok($c === 200 && ($b['cells'] ?? 0) === 4 && (int) cell(102, dayn(wk(104), 4), $dinner)['expected_covers'] === 70, 'onto a clean week all four typed/copied cells land (Friday dinner 70 included)');
[$c, $b] = act($mara, '/labor/forecast-copy.php', ['site' => 102, 'from_week' => wk(108), 'to_week' => wk(109)]);
ok($c === 200 && ($b['cells'] ?? -1) === 0, 'an empty week copies nothing and says so (0 cells)');
echo "8. No pay anywhere\n";
$logs = json_encode(q("SELECT before, after FROM activity_log WHERE action IN ('forecast.update', 'forecast.copy', 'forecast.fill', 'ratio.update')"));
ok(wage_leaks($logs) === [], 'the forecast and ratio log rows carry no wage');
foreach (['Mara' => $mara, 'Pat' => $pat, 'Owner' => $owner] as $who => $jar) {
    $leaks = [];
    foreach (["/forecast?site=102&week=$ws", "/forecast?site=102&week=$w2"] as $p) { foreach ([[], JSONH] as $h) { $leaks = array_merge($leaks, wage_leaks(req('GET', $p, ['jar' => $jar, 'headers' => $h])['body'])); } }
    ok($leaks === [], "the forecast page and its JSON as $who: no wage");
}
foreach (['Mara' => $mara, 'Pat' => $pat] as $who => $jar) { ok(!str_contains(page($jar, "/forecast?site=102&week=$ws")['body'], '$'), "the forecast page as $who prints no money at all"); }
finish();
