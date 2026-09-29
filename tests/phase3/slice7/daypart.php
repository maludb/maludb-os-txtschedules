<?php
/**
 * Proof — day-parts (spec "Proof": day-parts): adding Brunch with the service name Brunch lets slice 5's K7 fill map Reservations' Brunch covers; archiving one keeps the old forecasts; names and service names
 * are checked; a day-part may run past midnight; rights; logs.
 */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = admin_jar(102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dee = as_dee(); $dex = as_dex(); $sol = as_setter();
$ws = wk(180); $fri = dayn($ws, 4); $sat = dayn($ws, 5);
$lunch = dpid(102, 'lunch'); $dinner = dpid(102, 'dinner');

echo "1. The screen\n";
[$c, $d] = screen($owner, '/site/day-parts?site=102');
ok($c === 200 && array_column($d['day_parts'], 'name') === ['Lunch', 'Dinner'] && $d['day_parts'][0]['starts_at'] === '11:00' && $d['day_parts'][1]['service_name'] === 'Dinner', 'the admin sees the two shipped day-parts with their service names (JSON)');
$html = page($owner, '/site/day-parts?site=102')['body'];
ok(str_contains($html, 'id="day-parts-header"') && str_contains($html, 'id="day-part-' . $lunch . '-service"') && str_contains(html_entity_decode($html), 'Reservations calls this Lunch') && str_contains($html, 'id="day-parts-add-btn"') && !str_contains($html, 'is not built yet'), 'cards with "Reservations calls this Lunch", an Add button, no placeholder');

echo "2. Add Brunch: the K7 fill maps Reservations' Brunch covers\n";
$rows = [['date' => $sat, 'service' => 'Brunch', 'reservations' => 6, 'covers' => 42], ['date' => $fri, 'service' => 'Dinner', 'reservations' => 12, 'covers' => 47]];
kread(['mode' => 'ok', 'rows' => $rows]);
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 200 && $b['written'] === 1 && ($b['unmapped'][0]['service'] ?? '') === 'Brunch' && $b['unmapped'][0]['covers'] === 42, 'before: the fill writes Dinner and lists Brunch\' 42 covers as not mapped');
$since = last_activity_id();
[$c, $b] = act($owner, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Brunch', 'starts_at' => '09:00', 'ends_at' => '11:00', 'service_name' => 'Brunch']);
$bid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $bid > 0 && str_ends_with($b['location'], '#day-part-' . $bid) && str_contains($b['location'], 'notice=dp_saved'), 'day_part_save: 200, the reply carries record_id and the location ends in the record');
$row = q('SELECT key, name, to_char(starts_at, \'HH24:MI\') AS a, to_char(ends_at, \'HH24:MI\') AS b, service_name, sort_order, archived_at FROM day_parts WHERE id = :i', ['i' => $bid])[0];
ok($row['key'] === 'smoke_brunch' && $row['a'] === '09:00' && $row['b'] === '11:00' && $row['service_name'] === 'Brunch' && (int) $row['sort_order'] === 3 && $row['archived_at'] === null, 'stored: key smoke_brunch, 09:00–11:00, service Brunch, put last (3)');
$lg = q("SELECT scope_id, source, after FROM activity_log WHERE action = 'day_part.save' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && (int) $lg[0]['scope_id'] === 102 && $lg[0]['source'] === 'web' && json_decode($lg[0]['after'], true)['service_name'] === 'Brunch' && (int) json_decode($lg[0]['after'], true)['day_part_id'] === $bid, 'logged day_part.save with the site and the record');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
$cell = cell(102, $sat, $bid);
ok($c === 200 && $b['unmapped'] === [] && $cell && (int) $cell['expected_covers'] === 42 && $cell['source'] === 'reservations', 'after: the next fill puts the 42 covers in Brunch on Saturday and nothing is unmapped');
[, $fd] = screen($mara, "/forecast?site=102&week=$ws");
ok(in_array('SMOKE Brunch', array_column($fd['day_parts'] ?? [], 'name'), true), 'the forecast screen lists the new day-part');
echo "   — change it\n";
[$c, $b] = act($owner, '/site/day-part.php', ['day_part' => $bid, 'name' => 'SMOKE Brunch club']);
$row = q('SELECT name, service_name, to_char(starts_at, \'HH24:MI\') AS a FROM day_parts WHERE id = :i', ['i' => $bid])[0];
ok($c === 200 && $row['name'] === 'SMOKE Brunch club' && $row['service_name'] === 'Brunch' && $row['a'] === '09:00', 'a change sends what changes; the fields left out stay');
$lg = q("SELECT before, after FROM activity_log WHERE action = 'day_part.save' AND entity_id = :i ORDER BY id DESC LIMIT 1", ['i' => $bid])[0];
ok(json_decode($lg['before'], true)['name'] === 'SMOKE Brunch' && json_decode($lg['after'], true)['name'] === 'SMOKE Brunch club', 'the log has before and after');
[$c, $b] = act($owner, '/site/day-part.php', ['day_part' => $bid, 'service_name' => '']);
ok($c === 200 && one('SELECT service_name FROM day_parts WHERE id = :i', ['i' => $bid]) === null, 'an empty service name clears it (Reservations\' covers no longer land here)');
[$c, $b] = act($owner, '/site/day-part.php', ['day_part' => $bid, 'service_name' => 'Brunch', 'name' => 'SMOKE Brunch']);

echo "3. Names, times and service names are checked\n";
$n0 = (int) one('SELECT count(*) FROM day_parts WHERE scope_id = 102');
$cases = [[['name' => '', 'starts_at' => '10:00', 'ends_at' => '12:00'], 'A day-part needs a name of up to 40 characters.'], [['name' => str_repeat('x', 41), 'starts_at' => '10:00', 'ends_at' => '12:00'], null],
          [['name' => 'SMOKE Bad', 'starts_at' => '25:00', 'ends_at' => '12:00'], 'Give the start and the end as times like 11:00 and 15:00.'], [['name' => 'SMOKE Bad', 'starts_at' => '10:00', 'ends_at' => 'noon'], null],
          [['name' => 'SMOKE Bad', 'starts_at' => '10:00', 'ends_at' => '10:00'], 'A day-part cannot start and end at the same time.'], [['name' => 'lunch', 'starts_at' => '10:00', 'ends_at' => '12:00'], 'There is already a day-part called Lunch.'],
          [['name' => 'SMOKE Other', 'starts_at' => '10:00', 'ends_at' => '12:00', 'service_name' => 'dinner'], 'Dinner already uses that service name.'], [['name' => 'SMOKE Bad', 'starts_at' => '10:00', 'ends_at' => '12:00', 'sort_order' => '-1'], null],
          [['name' => 'SMOKE Bad', 'starts_at' => '10:00', 'ends_at' => '12:00', 'sort_order' => '1001'], null], [['name' => 'SMOKE Bad', 'starts_at' => '10:00', 'ends_at' => '12:00', 'service_name' => str_repeat('s', 41)], null]];
$miss = [];
foreach ($cases as [$f, $words]) { [$c, $b] = act($owner, '/site/day-part.php', ['site' => 102] + $f); if ($c !== 422 || ($words !== null && msg($b) !== $words)) { $miss[] = json_encode($f) . " → $c " . msg($b); } }
ok($miss === [] && (int) one('SELECT count(*) FROM day_parts WHERE scope_id = 102') === $n0, count($cases) . ' bad saves (no name, a name over 40, 25:00, "noon", equal times, a duplicate name and service name, order out of range) are each 422 and none saved' . ($miss ? ' — ' . implode('; ', $miss) : ''));
[$c, $b] = act($owner, '/site/day-part.php', ['day_part' => $lunch, 'name' => 'Dinner']);
ok($c === 422 && msg($b) === 'There is already a day-part called Dinner.', 'renaming Lunch to Dinner: 422');
[$c, $b] = act($owner, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Late', 'starts_at' => '22:00', 'ends_at' => '02:00']);
$late = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $late > 0, 'a day-part that runs past midnight (22:00–02:00) is accepted');
ok(str_contains(page($owner, '/site/day-parts?site=102')['body'], 'past midnight'), 'and the card says "past midnight"');
[$c, $b] = act($owner, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Late', 'starts_at' => '20:00', 'ends_at' => '23:00']);
ok($c === 422, 'a second live day-part with the same name is refused');
[$c, $b] = act($owner, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Late!', 'starts_at' => '20:00', 'ends_at' => '23:00']);
ok($c === 200 && one('SELECT key FROM day_parts WHERE id = :i', ['i' => (int) $b['record_id']]) === 'smoke_late_2', 'a name that makes the same key gets its own (smoke_late_2)');
admin_sql("DELETE FROM day_parts WHERE key = 'smoke_late_2'");

echo "4. Archive keeps the old forecasts\n";
put_cell(102, $fri, $bid, 30, 'manual');
$cells = n_cells(102);
$since = last_activity_id();
[$c, $b] = act($owner, '/site/day-part-archive.php', ['day_part' => $bid]);
ok($c === 200 && str_contains($b['location'], 'notice=dp_archived') && (int) $b['record_id'] === $bid && one('SELECT archived_at FROM day_parts WHERE id = :i', ['i' => $bid]) !== null, 'day_part_archive: 200, archived');
ok(n_cells(102) === $cells && (int) cell(102, $fri, $bid)['expected_covers'] === 30 && (int) cell(102, $sat, $bid)['expected_covers'] === 42, 'the forecast rows of the archived day-part are all still there (' . $cells . ' cells)');
[, $fd] = screen($mara, "/forecast?site=102&week=$ws");
ok(!in_array('SMOKE Brunch', array_column($fd['day_parts'] ?? [], 'name'), true) && in_array('Dinner', array_column($fd['day_parts'] ?? [], 'name'), true), 'the forecast screen no longer lists it; Lunch and Dinner stay');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok(($b['unmapped'][0]['service'] ?? '') === 'Brunch', 'an archived day-part is not mapped: Brunch is unmapped again');
$lg = q("SELECT scope_id, before, after FROM activity_log WHERE action = 'day_part.archive' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && (int) $lg[0]['scope_id'] === 102 && json_decode($lg[0]['after'], true) === ['archived' => true] && json_decode($lg[0]['before'], true)['name'] === 'SMOKE Brunch', 'logged day_part.archive with the site');
[$c, $b] = act($owner, '/site/day-part-archive.php', ['day_part' => $bid]);
ok($c === 404, 'archiving it again: 404');
[$c, $b] = act($owner, '/site/day-part.php', ['day_part' => $bid, 'name' => 'x']);
ok($c === 404, 'and an archived day-part cannot be edited: 404');
ok(str_contains(page($owner, '/site/day-parts?site=102')['body'], 'id="day-part-' . $bid . '-archived"'), 'the page lists it under Archived');
[$c, $b] = act($owner, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Brunch', 'starts_at' => '09:30', 'ends_at' => '11:30', 'service_name' => 'Brunch']);
$again = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $again > $bid && one('SELECT key FROM day_parts WHERE id = :i', ['i' => $again]) === 'smoke_brunch_2', 'a new Brunch is allowed once the old one is archived (its own key)');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($b['unmapped'] === [] && (int) cell(102, $sat, $again)['expected_covers'] === 42, 'and the fill maps Brunch to the new one');

echo "5. Rights\n";
$snap = json_encode(q('SELECT id, scope_id, name, starts_at, ends_at, service_name, sort_order, archived_at FROM day_parts ORDER BY id'));
foreach (['Mara' => $mara, 'Pat' => $pat, 'Priya' => $priya] as $who => $jar) {
    [$c1, $b1] = act($jar, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Nope', 'starts_at' => '10:00', 'ends_at' => '11:00']); [$c2] = act($jar, '/site/day-part-archive.php', ['day_part' => $again]);
    ok($c1 === 403 && $c2 === 403 && msg($b1) === 'You may not change this restaurant\'s settings here.', "$who: day_part_save and day_part_archive → 403");
}
[$c1] = act($dex, '/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Nope', 'starts_at' => '10:00', 'ends_at' => '11:00']); [$c2] = act($dex, '/site/day-part-archive.php', ['day_part' => $again]); [$c3] = act($dex, '/site/day-part.php', ['day_part' => $again, 'name' => 'Hijack']);
ok($c1 === 404 && $c2 === 404 && $c3 === 404, 'Downtown\'s admin: Airport\'s day-parts do not exist — 404 for a create, an archive and a change');
[$c, $b] = act($dex, '/site/day-part.php', ['site' => 101, 'name' => 'SMOKE Breakfast', 'starts_at' => '07:00', 'ends_at' => '10:00', 'service_name' => 'Brunch']);
ok($c === 200 && (int) one("SELECT count(*) FROM day_parts WHERE scope_id = 101 AND service_name = 'Brunch'") === 1, 'he adds one at Downtown — a service name Airport already uses is fine there (names are per restaurant)');
$r = req('POST', '/site/day-part.php', ['jar' => $owner, 'headers' => JSONH, 'form' => ['site' => 102, 'name' => 'SMOKE Nope']]);
ok($r['code'] === 403 && req('POST', '/site/day-part-archive.php', ['headers' => JSONH, 'form' => ['day_part' => $again]])['code'] === 401 && req('GET', '/site/day-part.php?site=102', ['jar' => $owner, 'headers' => JSONH])['code'] === 405 && req('GET', '/site/day-part-archive.php', ['jar' => $owner, 'headers' => JSONH])['code'] === 405, 'no CSRF token: 403; no session: 401; a GET: 405');
$snap2 = json_encode(q('SELECT id, scope_id, name, starts_at, ends_at, service_name, sort_order, archived_at FROM day_parts WHERE scope_id = 102 ORDER BY id'));
ok(strlen($snap) > 0 && $snap2 === json_encode(array_values(array_filter(json_decode($snap, true), fn ($r) => (int) $r['scope_id'] === 102))), 'no refused call changed a day-part at Airport');
ok(req('GET', '/site/day-parts?site=102', ['jar' => $mara])['code'] === 403 && req('GET', '/site/day-parts?site=102', ['jar' => $dee])['code'] === 404 && req('GET', '/site/day-parts?site=102', ['jar' => $sol])['code'] === 200, 'the screen: a manager 403, another restaurant\'s manager 404, Sol 200');
reset7();
finish();
