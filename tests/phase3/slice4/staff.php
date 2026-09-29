<?php
/**
 * Proof — people (spec "Proof": Main restaurant; Rights; staff list and profile): the list is a manager's, a name opens the person, the profile saves hours, the minor flag, on/off the schedule and the positions
 * (one main); changing the main restaurant moves where a person may pick up shifts; a manager without schedule.build at the new one is refused; a person who works one restaurant cannot be given another.
 */
require __DIR__ . '/lib.php';
$W = reset4();
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $lee = as_member(31); $ana = as_member(30); $dee = as_dee(); $marco = as_member(27, 101); $dana = as_member(32);
$since = last_activity_id();
echo "1. The list is a manager's\n";
[$c, $d] = screen($mara, '/staff/?site=102');
$names = array_column($d['staff'] ?? [], 'name');
ok($c === 200 && array_diff(['SMOKE Priya', 'SMOKE Ana', 'SMOKE Lee', 'SMOKE Dana', 'SMOKE Mara'], $names) === [] && !in_array('SMOKE Joe', $names, true) && !in_array('SMOKE Dee', $names, true), 'Mara sees Airport\'s people, not Downtown\'s: ' . implode(', ', $names));
$pri = array_values(array_filter($d['staff'], fn ($r) => $r['name'] === 'SMOKE Priya'))[0];
ok($pri['main_site_id'] === 102 && $pri['positions'][0]['name'] === 'Server' && $pri['on_schedule'] === true && !str_contains(json_encode($d), 'email') && !str_contains(json_encode($d), 'phone'), 'a row: name, main restaurant, positions, on the schedule — no email, no phone');
$html = page($mara, '/staff/?site=102')['body'];
ok(str_contains($html, 'id="staff-card-26-name"') && preg_match('#href="/staff/26\?back=#', $html) && str_contains($html, 'SMOKE Priya'), 'a name is a link to the person (with the way back)');
[$c, $d] = screen($mara, '/staff/?site=102&q=lee');
ok(array_column($d['staff'], 'name') === ['SMOKE Lee'], 'search by name: "lee" → Lee only');
[$c, $d] = screen($mara, '/staff/?site=102&position=' . $W['aBar']);
ok(array_column($d['staff'], 'name') === ['SMOKE Ana'], 'filter by position: Bar → Ana only');
admin_sql('UPDATE staff_profiles SET active = false WHERE member_id = 31');
[$c, $d] = screen($mara, '/staff/?site=102&on_schedule=no');
ok(array_column($d['staff'], 'name') === ['SMOKE Lee'], 'filter off the schedule → Lee');
[$c, $d] = screen($mara, '/staff/?site=102&on_schedule=yes');
ok(!in_array('SMOKE Lee', array_column($d['staff'], 'name'), true), 'on the schedule → not Lee');
admin_sql('UPDATE staff_profiles SET active = true WHERE member_id = 31');
$codes = [];
foreach (['Priya' => $priya, 'Lee' => $lee, 'Dana (shift lead)' => $dana] as $who => $jar) { $codes[$who] = [req('GET', '/staff/?site=102', ['jar' => $jar])['code'], req('GET', '/staff/30', ['jar' => $jar])['code'], req('GET', '/staff/30/edit', ['jar' => $jar])['code']]; }
ok(array_unique(array_merge(...array_values($codes)), SORT_REGULAR) === [403], 'staff and a shift lead: the list, another person\'s page and its form are all 403 (' . json_encode($codes) . ')');
$codes = [req('GET', '/staff/?site=102', ['jar' => $dee])['code'], req('GET', '/staff/26', ['jar' => $dee])['code'], req('GET', '/staff/26/edit', ['jar' => $dee])['code'], req('GET', '/staff/999999', ['jar' => $owner])['code']];
ok($codes === [404, 404, 404, 404], 'a manager of Downtown: Airport\'s list, an Airport person and their form are 404 — and so is a person who does not exist');
[$c, $b] = act($dee, '/staff/save.php', ['member' => 26, 'max_hours_week' => 12]);
ok($c === 404 && one('SELECT max_hours_week FROM staff_profiles WHERE member_id = 26') === null, 'Dee\'s save on Priya: 404, nothing changed');
[$c, $b] = act($pat, '/staff/save.php', ['member' => 26, 'max_hours_week' => 20]);
ok($c === 200 && one('SELECT max_hours_week FROM staff_profiles WHERE member_id = 26') == 20, 'Pat (schedule.build, no labor.view) may save a profile — pay is not on it');
admin_sql('UPDATE staff_profiles SET max_hours_week = NULL WHERE member_id = 26');
foreach (['Priya' => $priya, 'Lee' => $lee, 'Dana' => $dana] as $who => $jar) { [$c] = act($jar, '/staff/save.php', ['member' => 30, 'max_hours_week' => 12]); ok($c === 403, "$who: staff_save → 403"); }

echo "2. A person's own page: theirs, without the manager's parts\n";
admin_sql("UPDATE staff_profiles SET notes = 'SMOKE keep an eye on late arrivals' WHERE member_id = 26");
$mine = page($priya, '/staff/26');
ok($mine['code'] === 200 && !str_contains($mine['body'], 'late arrivals') && !str_contains($mine['body'], 'staff-view-edit-btn') && str_contains($mine['body'], 'certification-form-field-kind'), 'Priya sees her page: no manager\'s notes, no edit button, her own add-card form');
[$c, $d] = screen($priya, '/staff/26');
ok($d['notes'] === null && $d['may']['edit'] === false, 'her JSON: notes null, may.edit false');
$mp = page($mara, '/staff/26');
ok(str_contains($mp['body'], 'late arrivals') && str_contains($mp['body'], 'staff-view-edit-btn'), 'Mara sees the notes and the edit button');

echo "3. The profile saves\n";
[$c, $b] = act($mara, '/staff/save.php', ['member' => 26, 'max_hours_week' => '32.5', 'is_minor' => 'yes', 'minor_until' => today_plus(200), 'notes' => 'SMOKE updated note']);
$r = q('SELECT max_hours_week, is_minor, minor_until::text AS mu, notes, active, main_scope_id FROM staff_profiles WHERE member_id = 26')[0];
ok($c === 200 && $r['max_hours_week'] == 32.5 && $r['is_minor'] === true && $r['mu'] === today_plus(200) && $r['notes'] === 'SMOKE updated note' && $r['active'] === true && (int) $r['main_scope_id'] === 102, 'hours 32.5, minor until a date, the note; what was left out (active, main) stayed');
ok(($b['location'] ?? '') === '/staff/26?notice=st_saved', 'it lands on the person with a banner: ' . ($b['location'] ?? ''));
$row = q("SELECT scope_id, before, after, entity_type FROM activity_log WHERE action = 'staff.save' AND entity_id = 26 ORDER BY id DESC LIMIT 1")[0];
$af = json_decode($row['after'], true); $bf = json_decode($row['before'], true);
ok((int) $row['scope_id'] === 102 && $af['is_minor'] === true && $bf['is_minor'] === false && $af['max_hours_week'] == 32.5 && $bf['max_hours_week'] === null && $af['notes_changed'] === true && !str_contains($row['after'], 'updated note'), 'staff.save: the site, before/after of main, hours, minor, active, positions; the notes only as "notes_changed" — not their words');
$pg = page($mara, '/staff/26')['body'];
ok(str_contains($pg, 'Minor until') && str_contains($pg, 'Up to 32.5 h a week'), 'the page says "Minor until …" and the limit');
foreach ([['max_hours_week' => 'lots'], ['max_hours_week' => '0'], ['max_hours_week' => '140'], ['is_minor' => 'yes', 'minor_until' => ''], ['is_minor' => 'yes', 'minor_until' => today_plus(-3)], ['is_minor' => 'yes', 'minor_until' => 'soon'], ['main_site' => 999]] as $bad) {
    [$c, $b] = act($mara, '/staff/save.php', ['member' => 30] + $bad); ok($c === 422, 'invalid ' . json_encode($bad) . ': 422 (' . msg($b) . ')');
}
[$c, $b] = act($mara, '/staff/save.php', ['member' => 26, 'is_minor' => 'no']);
ok($c === 200 && one('SELECT minor_until FROM staff_profiles WHERE member_id = 26') === null, 'no longer a minor: the end date is cleared');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 26, 'active' => 'no', 'max_hours_week' => '']);
ok($c === 200 && one('SELECT active FROM staff_profiles WHERE member_id = 26') === false && one('SELECT max_hours_week FROM staff_profiles WHERE member_id = 26') === null, 'off the schedule; an empty limit removes it');
act($mara, '/staff/save.php', ['member' => 26, 'active' => 'yes']);
echo "3b. Positions\n";
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => ['Server', 'Bar'], 'primary' => 'Bar']);
$sp = q('SELECT position_id, is_primary FROM staff_positions WHERE member_id = 31 ORDER BY position_id');
ok($c === 200 && count($sp) === 2 && array_sum(array_map(fn ($x) => (int) $x['is_primary'], $sp)) === 1 && array_values(array_filter($sp, fn ($x) => $x['is_primary']))[0]['position_id'] === $W['aBar'], 'positions by name, Bar the main one');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => [$W['aSrv']]]);
$sp = q('SELECT position_id, is_primary FROM staff_positions WHERE member_id = 31');
ok($c === 200 && count($sp) === 1 && (int) $sp[0]['position_id'] === $W['aSrv'] && $sp[0]['is_primary'] === true, 'replaced whole: only Server now, and it is the main one (the first)');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 26, 'positions' => [$W['aSrv']]]);
ok((float) one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $W['aSrv']]) === 24.61, 'keeping a position keeps the person\'s own rate on it');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => [$W['dSrv']]]);
ok($c === 422, 'a position of another restaurant: 422 (' . msg($b) . ')');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => ['Nonexistent']]);
ok($c === 422 && str_contains(msg($b), 'There is no position called Nonexistent'), 'an unknown position name: 422 "' . msg($b) . '"');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => [$W['aSrv']], 'primary' => $W['aBar']]);
ok($c === 422, 'a main position they do not work: 422 (' . msg($b) . ')');
$text = json_encode(q("SELECT before, after FROM activity_log WHERE action = 'staff.save' AND id > :s", ['s' => $since]));
ok(wage_leaks($text) === [] && !str_contains($text, 'wage'), 'no staff.save row holds a wage');
[$c, $b] = act($mara, '/staff/save.php', ['member' => 31, 'positions' => ['']]);
ok($c === 200 && (int) one('SELECT count(*) FROM staff_positions WHERE member_id = 31') === 0, 'none checked removes them all');
act($mara, '/staff/save.php', ['member' => 31, 'positions' => [$W['aSrv']]]);

echo "4. Main restaurant (D4)\n";
$offA = offer($ana, mkshift(102, srv(), 30, 400, 6));                  // an Airport offer
$dtOpen = mkshift(101, $W['dSrv'], null, 410, 6);                      // an open Downtown shift
$mk = fn (string $jar, string $tab): array => (function () use ($jar, $tab) { [, $d] = screen($jar, '/marketplace?tab=' . $tab); return $d['exchanges'] ?? []; })();
$cardAt = function (string $jar, int $x, int $site): ?array { [, $d] = screen($jar, '/marketplace?tab=grabs&site=' . $site); foreach ($d['exchanges'] ?? [] as $e) { if ($e['exchange_id'] === $x) { return $e; } } return null; };
$joe = as_member(34); $offD = offer($joe, mkshift(101, $W['dSrv'], 34, 420, 6));
$was = $cardAt($marco, $offD, 101);
ok($was !== null && $was['may_take'] === true, 'Marco (main Downtown) may take Downtown\'s offer');
ok($cardAt($marco, $offA, 102) === null, 'Marco (main Downtown): Airport\'s offer is not on his marketplace');
[$c, $b] = act($marco, '/staff/save.php', ['member' => 27, 'main_site' => 102]);
ok($c === 403 && msg($b) === 'You may not build the schedule here.' && (int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 27') === 101, 'Marco (manager at Downtown, staff at Airport) moving himself to Airport: 403 "' . msg($b) . '" — a manager needs schedule.build at the NEW restaurant');
[$c, $b] = act($owner, '/staff/save.php', ['member' => 26, 'main_site' => 101]);
ok($c === 422 && str_contains(msg($b), 'do not work at that restaurant') && (int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 26') === 102, 'Priya works one restaurant: main Downtown is 422 "' . msg($b) . '"');
[$c, $b] = act($owner, '/staff/save.php', ['member' => 27, 'main_site' => 102]);
ok($c === 200 && (int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 27') === 102, 'the owner moves Marco\'s main restaurant to Airport: 200');
$old = $cardAt($marco, $offD, 101);
ok($old === null || ($old['may_take'] === false && $old['reason'] === 'You can pick up shifts only at your main restaurant.'), 'at the old restaurant he can no longer take what is offered (as a manager there he still SEES it, disabled): ' . ($old === null ? 'gone' : $old['reason']));
$card = $cardAt($marco, $offA, 102);
ok($card !== null && $card['may_take'] === true, 'Airport\'s offer is now on Marco\'s marketplace and he may take it');
[$c, $b] = act($marco, '/exchanges/claim.php', ['exchange' => $offA]);
ok($c === 200, 'and his claim goes through (' . ($c === 200 ? 'ok' : msg($b)) . ')');
act($owner, '/staff/save.php', ['member' => 27, 'main_site' => 101]);
ok((int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 27') === 101, 'moved back to Downtown');
$row = q("SELECT before, after FROM activity_log WHERE action = 'staff.save' AND entity_id = 27 ORDER BY id LIMIT 1")[0];
ok(json_decode($row['before'], true)['main_scope_id'] === 101 && json_decode($row['after'], true)['main_scope_id'] === 102, 'the log says main 101 → 102');
[$c, $b] = act($owner, '/staff/save.php', ['member' => 999999]);
ok($c === 404, 'a person who is not here: 404');

echo "5. The person's page, whole\n";
$ws = wk(92);
pub_shift(102, $W['aSrv'], 30, $ws, 1, '11:00', '15:00');
$now = new DateTimeImmutable('now', new DateTimeZone(TZA));
[$c, $d] = screen($mara, '/staff/30');
ok($c === 200 && $d['name'] === 'SMOKE Ana' && count($d['positions']) === 2 && $d['restaurants'][0]['site'] === 'SMOKE Airport' && isset($d['hours_this_week'][0]) && array_key_exists('balances', $d) && $d['may']['edit'] === true, 'the JSON: name, both positions, restaurants, hours this week, balances, may.edit');
$pg = page($mara, '/staff/30')['body'];
foreach (['staff-view-positions', 'staff-view-certifications', 'staff-view-hours', 'staff-view-restaurants', 'staff-view-time-off', 'certification-form'] as $id) { ok(str_contains($pg, 'id="' . $id . '"'), "the page has #$id"); }
ok(str_contains($pg, 'Hours this week') && str_contains($pg, 'scheduled'), 'hours this week against the limit');
finish();
