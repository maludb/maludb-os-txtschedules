<?php
/** Proof — see only what is mine or published (spec "Proof", 1) and time zones' first half: the cast's pages, no wage on any of them. */
require __DIR__ . '/lib.php';
$W = world();
$priya = as_member(26); $marco = as_member(27, 101); $ana = as_member(30);

echo "1. Priya (staff, main Airport)\n";
[$c, $d] = screen($priya, '/my-schedule?view=list');
$ids = array_map(fn ($s) => $s['shift_id'], $d['shifts'] ?? []);
ok($c === 200 && in_array($W['p1'], $ids, true) && in_array($W['p4'], $ids, true), 'her published shifts are on My schedule (' . count($ids) . ')');
ok(!in_array($W['pdraft'], $ids, true), 'her draft shift is not (a draft is the managers\' until it is published)');
ok(array_diff($ids, [$W['p1'], $W['p2'], $W['p3'], $W['p4'], $W['p5'], $W['p6']]) === [], 'and nobody else\'s and no other restaurant\'s');
[$c, $d] = screen($priya, '/team-schedule');
ok($c === 200 && $d['site_id'] === 102, 'the team schedule shows Airport');
$everything = [];
foreach (array_merge($d['positions'] ?? [], []) as $g) { foreach ($g['shifts'] as $s) { $everything[] = $s['shift_id']; } }
$r = page($priya, '/team-schedule?site=101');
ok($r['code'] === 404 && str_contains($r['body'], 'Not found.'), 'the team schedule of Downtown, a restaurant she does not hold: 404 "Not found."');
$r = page($priya, '/shifts/' . $W['j1']);
ok($r['code'] === 404 && str_contains($r['body'], 'Shift not found.'), 'a direct URL to a Downtown shift: 404 "Shift not found."');
$r = page($priya, '/shifts/' . $W['pdraft']);
ok($r['code'] === 404 && str_contains($r['body'], 'Shift not found.'), 'her own DRAFT by URL: 404 "Shift not found." — the same sentence as a shift at another restaurant');
$rj = req('GET', '/shifts/' . $W['j1'], ['jar' => $priya, 'headers' => JSONH]);
ok($rj['code'] === 404 && msg(json_decode($rj['body'], true)) === 'Shift not found.', 'and the same in JSON');
$r = page($priya, '/shifts/' . $W['p1']);
ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="shift-view"') && str_contains($r['body'], 'data-record-id="' . $W['p1'] . '"'), 'her own shift: 200, stamped data-screen="shift-view" data-record-id');
ok(str_contains($r['body'], 'Working with') === false && str_contains($r['body'], 'Also on: SMOKE Ana'), 'it names who else is on (SMOKE Ana overlaps) — names only');

echo "2. Marco (manager Downtown, staff Airport) sees both\n";
$body = '';
foreach (['/', '/my-schedule', '/my-schedule?view=list', '/team-schedule', '/team-schedule?site=102', '/marketplace', '/requests', '/shifts/' . $W['m1'], '/shifts/' . $W['m2'], '/approvals', '/coverage'] as $p) {
    $r = page($marco, $p); $body .= $r['body'];
    ok(in_array($r['code'], [200], true), "Marco: $p answers 200");
}
[$c, $d] = screen($marco, '/team-schedule?site=101');
ok($c === 200 && $d['site_id'] === 101, 'the Downtown team schedule');

echo "3. No page of the slice carries a wage\n";
$all = $body;
foreach ([[$priya, ['/', '/my-schedule?view=list', '/team-schedule', '/marketplace', '/requests', '/shifts/' . $W['p1'], '/shifts/' . $W['p4']]], [$ana, ['/', '/my-schedule?view=list', '/team-schedule', '/marketplace', '/requests', '/shifts/' . $W['a1']]]] as [$jar, $paths]) {
    foreach ($paths as $p) { $all .= page($jar, $p)['body'] . req('GET', $p, ['jar' => $jar, 'headers' => JSONH])['body']; }
}
$owner = as_member(1, 102);
foreach (['/approvals', '/coverage?shift=' . $W['o1'], '/team-schedule', '/shifts/' . $W['p1'], '/marketplace'] as $p) { $all .= page($owner, $p)['body'] . req('GET', $p, ['jar' => $owner, 'headers' => JSONH])['body']; }
ok(leaks($all) === [], 'grep of ' . number_format(strlen($all)) . ' bytes of rendered pages and JSON (staff, manager, owner): none of the fixture rates (21.37, 24.61, 23.19, 22.83)' . (leaks($all) ? ' — LEAKED ' . implode(',', leaks($all)) : ''));
ok(!preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)[:<]/', $all), 'no PHP warning, notice or error printed on any page');
ok(!preg_match('/"cost"|"wage|\$\s?\d+\.\d\d/i', $all), 'no cost, wage key or dollar amount either');
finish();
