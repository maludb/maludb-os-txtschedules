<?php
/**
 * Proof — Reservations' covers through the kernel (K7), against a STUBBED kernel endpoint (the real connection needs a super-admin's approval that does not exist yet; the kernel's own
 * bin/test_app_services.php proves the real read). Covers land in the day-part whose service name matches, typed cells are skipped, an unmapped service is listed, nothing but the covers is kept,
 * and every refusal degrades to words with the typed forecast standing.
 */
require __DIR__ . '/lib.php';
$W = reset5();
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dee = as_dee();
$ws = wk(111); $we = dayn($ws, 6); $mon = dayn($ws, 0); $tue = dayn($ws, 1); $fri = dayn($ws, 4); $sat = dayn($ws, 5);
$lunch = dpid(102, 'lunch'); $dinner = dpid(102, 'dinner');
$rows = [['date' => $fri, 'service' => 'Dinner', 'reservations' => 12, 'covers' => 47], ['date' => $fri, 'service' => 'Lunch', 'reservations' => 5, 'covers' => 18],
         ['date' => $sat, 'service' => 'dinner', 'reservations' => 9, 'covers' => 30], ['date' => $sat, 'service' => 'Brunch', 'reservations' => 4, 'covers' => 42],
         ['date' => $mon, 'service' => 'Lunch', 'reservations' => 3, 'covers' => 11]];
echo "1. Connected: the booked covers land in their day-parts\n";
kread(['mode' => 'ok', 'rows' => $rows]);
put_cell(102, $mon, $lunch, 25, 'manual');                 // a person typed Monday lunch
put_cell(102, $tue, $dinner, 61, 'manual');                // and Tuesday dinner (a day the answer does not name)
$since = last_activity_id();
[$c, $b, $raw] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => dayn($ws, 2)]);      // any date in the week
ok($c === 200 && ($b['written'] ?? -1) === 3 && ($b['skipped'] ?? -1) === 1, 'forecast_fill: 200 — 3 cells written, 1 typed cell skipped — ' . json_encode(array_intersect_key($b, array_flip(['did', 'written', 'skipped']))));
$c1 = cell(102, $fri, $dinner); $c2 = cell(102, $fri, $lunch); $c3 = cell(102, $sat, $dinner);
ok($c1 && (int) $c1['expected_covers'] === 47 && $c1['source'] === 'reservations' && (int) $c1['updated_by'] === 33, 'Friday Dinner = 47, source reservations (by Mara)');
ok($c2 && (int) $c2['expected_covers'] === 18 && $c2['source'] === 'reservations', 'Friday Lunch = 18 (Lunch → Lunch)');
ok($c3 && (int) $c3['expected_covers'] === 30 && $c3['source'] === 'reservations', 'the service name matches without regard to case ("dinner" → Dinner): Saturday 30');
ok((int) cell(102, $mon, $lunch)['expected_covers'] === 25 && cell(102, $mon, $lunch)['source'] === 'manual', 'the cell a person typed (Monday lunch 25) is skipped');
ok((int) cell(102, $tue, $dinner)['expected_covers'] === 61 && cell(102, $tue, $dinner)['source'] === 'manual' && n_cells(102) === 5, 'a day the answer does not name is left alone (Tuesday dinner 61); five cells in all');
$asked = kreads();
ok(count($asked) === 1 && $asked[0]['method'] === 'POST' && $asked[0]['body'] === ['provider' => 'reservations', 'tool' => 'covers_by_service', 'arguments' => ['from' => $ws, 'to' => $we], 'location_id' => 11],
    'the kernel was asked exactly once: provider reservations, tool covers_by_service, from ' . $ws . ' to ' . $we . ', location 11 — ' . json_encode($asked[0]['body'] ?? null));
$row = q("SELECT source, scope_id, before, after FROM activity_log WHERE action = 'forecast.fill' AND id > :s", ['s' => $since])[0] ?? [];
$aft = json_decode($row['after'] ?? '{}', true);
ok(($row['source'] ?? '') === 'web' && (int) $row['scope_id'] === 102 && $aft['written'] === 3 && $aft['skipped'] === 1 && $aft['refusal'] === null && $aft['unmapped'][0]['service'] === 'Brunch' && $aft['unmapped'][0]['covers'] === 42, 'logged forecast.fill with the site, written, skipped, unmapped and refusal');
echo "2. An unmapped service is listed, never dropped silently\n";
$jar = $mara;
$r = req('POST', '/labor/forecast-fill.php', ['jar' => $jar, 'form' => ['site' => 102, 'week_start' => $ws, 'csrf_token' => page_csrf($jar)]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=fc_filled'), 'a browser fill redirects to the forecast (notice fc_filled)');
$html = page($jar, $r['location'])['body'];
ok(str_contains($html, 'Brunch — 42 covers not mapped — set a service name on a day-part.'), 'the page lists it: "Brunch — 42 covers not mapped — set a service name on a day-part."');
ok(str_contains($html, 'booked covers, not walk-ins'), 'and says these are booked covers, not walk-ins');
ok(!str_contains(page($jar, "/forecast?site=102&week=$ws")['body'], 'not mapped'), 'shown once — the next look does not repeat it');
ok(str_contains($html, 'id="forecast-source-' . $dinner . '-' . $fri . '"') && str_contains(strip_tags(preg_match('/id="forecast-source-' . $dinner . '-' . $fri . '"[^>]*>(.*?)<\/div>/s', $html, $m) ? $m[1] : ''), 'Reservations'), 'the cell carries the tag "Reservations"');
ok(count(kreads()) === 2, 'the second fill asked the kernel again (and only once more)');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 200 && $b['written'] === 0 && str_contains($b['did'], 'Nothing needed changing'), 'a third fill: nothing changes (the same numbers)');
// map Brunch: a day-part with the service name; it is now placed
admin_sql("INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, sort_order, service_name) VALUES (102, 'smoke_brunch', 'SMOKE Brunch', '09:00', '11:00', 0, 'BRUNCH')");
$brunch = dpid(102, 'smoke_brunch');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 200 && $b['written'] === 1 && $b['unmapped'] === [] && (int) cell(102, $sat, $brunch)['expected_covers'] === 42, 'name a day-part "Brunch" (service name BRUNCH): the next fill places the 42 covers there and nothing is unmapped');
echo "3. Typed cells survive; replace_manual replaces\n";
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws, 'replace_manual' => 'yes']);
ok($c === 200 && (int) cell(102, $mon, $lunch)['expected_covers'] === 11 && cell(102, $mon, $lunch)['source'] === 'reservations' && (int) cell(102, $tue, $dinner)['expected_covers'] === 61, 'replace_manual yes: Monday lunch becomes 11 (reservations); Tuesday dinner, a day not named, still 61');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => $fri, 'day_part' => $dinner, 'expected_covers' => '52']);
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok((int) cell(102, $fri, $dinner)['expected_covers'] === 52 && cell(102, $fri, $dinner)['source'] === 'manual' && ($b['skipped'] ?? 0) === 1, 'a Reservations cell overtyped (47 → 52) becomes typed and the next fill leaves it');
put_cell(102, $sat, $dinner, 33, 'copied');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok((int) cell(102, $sat, $dinner)['expected_covers'] === 33 && ($b['skipped'] ?? 0) >= 1, 'a COPIED cell counts as typed by a person (conservative reading): it is left alone too');
// two services on one day-part add up
kread(['mode' => 'ok', 'rows' => [['date' => $wed = dayn($ws, 2), 'service' => 'Dinner', 'reservations' => 2, 'covers' => 6], ['date' => $wed, 'service' => 'DINNER', 'reservations' => 3, 'covers' => 9]]]);
act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok((int) cell(102, $wed, $dinner)['expected_covers'] === 15, 'two rows for one service on a day add up (6 + 9 = 15)');
kread(['mode' => 'ok', 'rows' => [['date' => dayn($ws, 8), 'service' => 'Dinner', 'reservations' => 1, 'covers' => 4], ['date' => dayn($ws, 3), 'service' => 'Dinner', 'reservations' => 1, 'covers' => 7]]]);
act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok(cell(102, dayn($ws, 8), $dinner) === null && (int) cell(102, dayn($ws, 3), $dinner)['expected_covers'] === 7, 'a row dated outside the week is ignored');
echo "4. What is stored is the covers, and only the covers\n";
kread(['mode' => 'ok', 'rows' => $rows]);
act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws, 'replace_manual' => 'yes']);
$dump = (string) shell_exec('sudo -n -u postgres pg_dump --data-only ' . escapeshellarg(need('DB_NAME')) . ' 2>/dev/null');
ok($dump !== '' && !str_contains($dump, 'SMOKE Reservations') && !str_contains($dump, 'reservations.covers-by-service') && !str_contains($dump, 'covers_by_service'), 'a dump of the whole database holds none of the kernel\'s answer (the restaurant name, the schema, the tool name)');
ok(!str_contains($dump, 'api/v1/apps/read'), 'nor the kernel\'s path');
echo "5. It degrades and never fails\n";
$before = n_cells(102); $snap = json_encode(q('SELECT on_date, day_part_id, expected_covers, source FROM forecast_covers WHERE scope_id = 102 ORDER BY 1, 2'));
foreach ([['no_connection', 409, 'Reservations is not connected — ask a super-admin to approve the connection in the operating system. The forecast you type stands.'],
          ['not_shared', 409, 'Reservations does not share its covers with this application.'],
          ['provider_failed', 503, 'Reservations did not answer just now. Try again in a minute — the forecast you type stands.'],
          ['garbage', 503, 'Reservations did not answer just now. Try again in a minute — the forecast you type stands.']] as [$mode, $code, $words]) {
    kread(['mode' => $mode]);
    $since = last_activity_id();
    [$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => wk(112)]);
    $row = q("SELECT after FROM activity_log WHERE action = 'forecast.fill' AND id > :s", ['s' => $since])[0] ?? [];
    ok($c === $code && msg($b) === $words && json_decode($row['after'] ?? '{}', true)['refusal'] === ($mode === 'garbage' ? 'provider_failed' : $mode), "$mode → $code \"" . substr(msg($b), 0, 60) . "…\", logged with the refusal");
}
kread(['mode' => 'ok', 'rows' => $rows, 'locations' => [10]]);
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 409 && msg($b) === 'Reservations does not serve this restaurant.', 'a restaurant Reservations does not serve: 409 "' . msg($b) . '"');
ok(json_encode(q('SELECT on_date, day_part_id, expected_covers, source FROM forecast_covers WHERE scope_id = 102 ORDER BY 1, 2')) === $snap, 'every refusal left the forecast exactly as it was (' . $before . ' cells)');
// the same, as a person in a browser: a warning on the page and the grid untouched
kread(['mode' => 'no_connection']);
$snap = json_encode(q('SELECT on_date, day_part_id, expected_covers, source FROM forecast_covers WHERE scope_id = 102 ORDER BY 1, 2'));
$r = req('POST', '/labor/forecast-fill.php', ['jar' => $mara, 'form' => ['site' => 102, 'week_start' => $ws, 'csrf_token' => page_csrf($mara)]]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=fc_fill_refused'), 'in a browser a refusal is a redirect back to the forecast, not an error page');
$html = page($mara, $r['location'])['body'];
ok(str_contains($html, 'Reservations is not connected — ask a super-admin to approve the connection in the operating system.') && str_contains($html, 'alert-warning'), 'the page says: "Reservations is not connected — ask a super-admin to approve the connection in the operating system." in a warning');
ok(json_encode(q('SELECT on_date, day_part_id, expected_covers, source FROM forecast_covers WHERE scope_id = 102 ORDER BY 1, 2')) === $snap, 'and the manual grid is untouched (' . n_cells(102) . ' cells, byte for byte)');
ok(str_contains($html, 'id="forecast-fill-btn"') && str_contains($html, 'id="forecast-grid-form"'), 'the button and the grid are still there — the forecast can be typed');
[$c, $b] = act($mara, '/labor/forecast.php', ['site' => 102, 'on_date' => dayn($ws, 6), 'day_part' => 'Lunch', 'expected_covers' => '22']);
ok($c === 200 && (int) cell(102, dayn($ws, 6), $lunch)['expected_covers'] === 22, 'while Reservations is unreachable, typing still saves');
echo "6. A restaurant with no location has no button\n";
admin_sql("UPDATE sites SET location_id = 0 WHERE scope_id = 101");
$dwnHtml = page($dee, '/forecast?site=101')['body'];
[, $d] = screen($dee, '/forecast?site=101');
ok(!str_contains($dwnHtml, 'forecast-fill-btn') && str_contains($dwnHtml, 'forecast-copy-btn') && ($d['reservations']['available'] ?? true) === false, 'Downtown with no location: no "Fill from Reservations" button, "Copy last week" stays; the JSON says reservations.available false');
$n0 = count(kreads());
[$c, $b] = act($dee, '/labor/forecast-fill.php', ['site' => 101, 'week_start' => $ws]);
ok($c === 409 && msg($b) === 'This restaurant has no location in the operating system to ask Reservations about.' && count(kreads()) === $n0, 'and the action refuses in words without asking the kernel: ' . msg($b));
admin_sql("UPDATE sites SET location_id = 10 WHERE scope_id = 101");
ok(str_contains(page($dee, '/forecast?site=101')['body'], 'forecast-fill-btn'), 'restored: the button is back');
echo "7. Who may fill\n";
kread(['mode' => 'ok', 'rows' => $rows]);
[$c, $b] = act($priya, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 403, 'staff (no schedule.build): 403');
[$c, $b] = act($dee, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 404, 'Downtown\'s manager on Airport: 404');
[$c, $b] = act($pat, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]);
ok($c === 200, 'a planner (schedule.build, no pay) may fill — the forecast is not pay');
$r = req('GET', '/labor/forecast-fill.php?site=102&week_start=' . $ws, ['jar' => $mara]);
ok($r['code'] === 405, 'a GET on the handler is 405');
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => 'next']);
ok($c === 422, 'a malformed week_start: 422');
echo "8. The answer's shape, defended\n";
$w8 = wk(113);
admin_sql("DELETE FROM forecast_covers WHERE scope_id = 102");
foreach (['negative' => 'a negative cover count', 'string' => 'a result that is not rows', 'garbage' => 'rows without a date'] as $mode => $what) {
    kread(['mode' => $mode]);
    [$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $w8]);
    ok($c === 503 && n_cells(102) === 0, "$what: refused as provider_failed (503) and nothing half-written");
}
kread(['mode' => 'list']);
[$c, $b] = act($mara, '/labor/forecast-fill.php', ['site' => 102, 'week_start' => $w8]);
ok($c === 200 && n_cells(102) === 1 && (int) cell(102, $w8, $dinner)['expected_covers'] === 8, 'a bare list of rows (no wrapper) is understood too');
kread(['mode' => 'no_connection']);
finish();
