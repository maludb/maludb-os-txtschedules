<?php
/** Proof — rights (spec "Proof", 8): staff and a shift lead are refused every builder URL and POST; a manager of Downtown does not see Airport at all; an action token acts as its member. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people();
$mara = as_member(33); $priya = as_member(26); $dana = as_member(32); $dee = as_dee(); $marco = as_member(27, 101); $owner = as_member(1, 102);
$srv = srv();
$ws = wk(49);
$s = fx(102, $srv, 30, $ws, 1); $wid = week_id_of(102, $ws);
[$c, $b] = bp($mara, '/templates/save.php', ['site' => 102, 'name' => 'SMOKE Rights', 'week' => $wid]);
$tid = (int) $b['record_id'];
$pubW = wk(50); $ps = fx(102, $srv, 26, $pubW, 2); bp($mara, '/weeks/publish.php', ['week' => week_id_of(102, $pubW)]);

$gets = ["/builder?site=102&week=$ws", '/builder/day?site=102&date=' . dayn($ws, 1), '/shifts/new?site=102&date=' . dayn($ws, 1), "/shifts/$s/edit", '/templates/?site=102', "/templates/$tid", "/weeks/publish-confirm?site=102&week=$ws", "/shifts/check.php?site=102&position=$srv&date=" . dayn($ws, 1)];
$posts = [['/weeks/save.php', ['site' => 102, 'week_start' => wk(51)]], ['/weeks/copy.php', ['week' => $wid, 'from_week' => $wid]], ['/weeks/autofill.php', ['week' => $wid]], ['/weeks/clear.php', ['week' => $wid]], ['/weeks/publish.php', ['week' => $wid]],
          ['/shifts/save.php', ['site' => 102, 'position' => $srv, 'date' => dayn($ws, 3), 'starts' => '12:00', 'ends' => '16:00']], ['/shifts/save.php', ['shift' => $s, 'note' => 'x']], ['/shifts/assign.php', ['shift' => $s, 'assignee' => '']],
          ['/shifts/delete.php', ['shift' => $s]], ['/shifts/add.php', ['site' => 102, 'position' => $srv, 'date' => dayn($pubW, 3), 'starts' => '12:00', 'ends' => '16:00']], ['/shifts/change.php', ['shift' => $ps, 'note' => 'x']],
          ['/shifts/cancel.php', ['shift' => $ps, 'reason' => 'SMOKE nope']], ['/templates/save.php', ['site' => 102, 'name' => 'SMOKE x', 'week' => $wid]], ['/templates/apply.php', ['template' => $tid, 'site' => 102, 'week_start' => wk(51)]], ['/templates/archive.php', ['template' => $tid]]];
$count = fn () => [(int) one('SELECT count(*) FROM shifts'), (int) one('SELECT count(*) FROM schedule_weeks'), (int) one('SELECT count(*) FROM schedule_templates'), (string) one("SELECT string_agg(id::text || status, ',' ORDER BY id) FROM schedule_weeks")];
$state = $count();

echo "1. Staff and a shift lead: 403 on every builder URL and POST\n";
foreach (['Priya (staff)' => $priya, 'Dana (shift lead)' => $dana] as $who => $jar) {
    $bad = [];
    foreach ($gets as $p) { $r = page($jar, $p); if ($r['code'] !== 403) { $bad[] = "$p {$r['code']}"; } $j = req('GET', $p, ['jar' => $jar, 'headers' => JSONH]); if ($j['code'] !== 403) { $bad[] = "json $p {$j['code']}"; } }
    ok($bad === [], "$who: all " . count($gets) . ' builder URLs answer 403 (page and JSON)' . ($bad ? ' — ' . implode('; ', $bad) : ''));
    $bad = [];
    foreach ($posts as [$path, $form]) { [$c, $b] = bp($jar, $path, $form); if ($c !== 403) { $bad[] = "$path $c"; } }
    ok($bad === [], "$who: all " . count($posts) . ' builder POSTs answer 403' . ($bad ? ' — ' . implode('; ', $bad) : ''));
}
[$c, $b] = bp($dana, '/shifts/assign.php', ['shift' => $s, 'assignee' => 31]);
ok($c === 403 && msg($b) === 'You may not build the schedule here.', 'a shift lead\'s shift_assign of a draft: 403 "' . msg($b) . '" (a lead fills gaps of the PUBLISHED schedule through coverage)');
ok($count() === $state, 'and nothing changed in the database');
$r = page($priya, '/');
ok(!str_contains($r['body'], 'id="nav-builder"') && !str_contains($r['body'], 'id="nav-templates-list"'), 'the menu does not even list Builder or Templates for staff');
$r = page($mara, '/');
ok(str_contains($r['body'], 'id="nav-builder"') && str_contains($r['body'], 'id="nav-templates-list"'), 'the manager\'s menu lists them');

echo "2. A manager of Downtown does not see Airport\n";
$bad = [];
foreach ($gets as $p) { $r = page($dee, $p); if ($r['code'] !== 404) { $bad[] = "$p {$r['code']}"; } }
ok($bad === [], 'Dee (manager at Downtown only): every Airport URL answers 404' . ($bad ? ' — ' . implode('; ', $bad) : ''));
$want = ['/weeks/save.php' => 'Not found.', '/weeks/copy.php' => 'Week not found.', '/weeks/autofill.php' => 'Week not found.', '/weeks/clear.php' => 'Week not found.', '/weeks/publish.php' => 'Week not found.', '/shifts/assign.php' => 'Shift not found.',
         '/shifts/delete.php' => 'Shift not found.', '/shifts/change.php' => 'Shift not found.', '/shifts/cancel.php' => 'Shift not found.', '/templates/apply.php' => 'That template is not here any more.', '/templates/archive.php' => 'That template is not here any more.'];
$bad = [];
foreach ($posts as [$path, $form]) {
    [$c, $b] = bp($dee, $path, $form);
    if ($c !== 404 || (isset($want[$path]) && msg($b) !== $want[$path])) { $bad[] = "$path $c " . msg($b); }
}
ok($bad === [], 'and every Airport POST is 404, in the same words as a missing record' . ($bad ? ' — ' . implode('; ', $bad) : ''));
[$c, $b] = screen($dee, '/builder?site=101&week=' . $ws);
ok($c === 200 && ($b['shifts'] ?? []) === [] && !in_array($s, array_column($b['shifts'] ?? [], 'shift_id'), true), 'her own restaurant\'s builder works (Downtown, nothing there)');
ok($count() === $state, 'nothing changed');

echo "3. Marco: a manager at Downtown, only staff at Airport\n";
$r = page($marco, "/builder?site=102&week=$ws");
ok($r['code'] === 403, 'the Airport builder: 403 (he holds Airport as staff)');
$r = page($marco, "/builder?site=101&week=$ws");
ok($r['code'] === 200, 'the Downtown builder: 200');
[$c, $b] = bp($marco, '/shifts/save.php', ['shift' => $s, 'note' => 'x']);
ok($c === 403, 'and he cannot edit an Airport shift: 403');

echo "4. Action tokens act as their member\n";
$key = need('ACTION_TOKEN_KEY');
$person = fn (int $m, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl)) . '.' . hash_hmac('sha256', $p, $key);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
$form = ['site' => 102, 'position' => $srv, 'starts_at' => at($ws, 2, '12:00'), 'ends_at' => at($ws, 2, '16:00')];
[$c, $b] = $post('/shifts/save.php', $form, ['X-Action-Token: ' . $person(26)]);
ok($c === 403 && msg($b) === 'You may not build the schedule here.', 'an action token for Priya (no schedule.build at Airport): shift_create → 403');
[$c, $b] = $post('/shifts/save.php', $form, ['X-Action-Token: ' . $person(32)]);
ok($c === 403, 'and for a shift lead: 403');
$n0 = (int) one('SELECT count(*) FROM shifts');
[$c, $b, $r] = $post('/shifts/save.php', $form, ['X-Action-Token: ' . $person(33)]);
ok($c === 200 && preg_match('~^/shifts/\d+$~', (string) $b['location']) && (int) one('SELECT count(*) FROM shifts') === $n0 + 1 && !str_contains($r['headers'], 'Set-Cookie'), 'a token for Mara (manager): shift_create → 200, no CSRF token, no cookie, the location ends in the id');
$row = q("SELECT source, actor_member_id, scope_id FROM activity_log WHERE action = 'shift.create' ORDER BY id DESC LIMIT 1")[0];
ok($row['source'] === 'assistant' && (int) $row['actor_member_id'] === 33 && (int) $row['scope_id'] === 102, 'the row is source assistant (a person\'s own token), her id, the site');
[$c, $b] = $post('/shifts/save.php', $form, ['X-Action-Token: ' . $person(33, -5)]);
ok($c === 401, 'an expired token: 401');
[$c, $b] = $post('/shifts/save.php', ['site' => 101] + $form, ['X-Action-Token: ' . $person(33)]);
ok($c === 404, 'Mara\'s token naming Downtown, which she does not hold: 404');
$r = req('POST', '/shifts/save.php', ['headers' => JSONH, 'form' => $form]);
ok($r['code'] === 401, 'no login and no token: 401');
$r = req('POST', '/shifts/save.php', ['jar' => $mara, 'headers' => JSONH, 'form' => $form]);
ok($r['code'] === 403 && msg(json_decode($r['body'], true)) === 'CSRF validation failed.', 'a browser session without the CSRF token: 403');
finish();
