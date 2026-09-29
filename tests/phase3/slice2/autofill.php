<?php
/** Proof — auto-fill (spec "Proof", 5): the same result twice, never a hard rule, preferred availability then no soft warning then the fewest hours then the name, and it says what it left open. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people(); only(null);
$mara = as_member(33);
$srv = srv(); $bar = (int) $W['aBar'];
$assignees = fn (array $ids) => array_map(fn ($i) => ($v = one('SELECT assignee_member_id FROM shifts WHERE id = :i', ['i' => $i])) === null ? null : (int) $v, $ids);
$run = fn (array $form) => bp($GLOBALS['mara'], '/weeks/autofill.php', $form);

echo "1. Order of preference, and the same result twice\n";
only([26, 30, 31, 32, 33]);
$A = wk(42);
time_off(26, dayn($A, 1));                         // Priya: approved time off on Tuesday
availability(30, 2, 'unavailable');                // Ana: marked unavailable on Tuesdays (DOW 2)
availability(31, 2, 'preferred', '16:00', '23:59'); // Lee prefers Tuesday evenings
fx(102, $srv, 32, $A, 0, '17:00', '23:00');        // Dana already has six hours on Monday
$o2 = fx(102, $srv, null, $A, 1, '17:00', '23:00'); $o3 = fx(102, $srv, null, $A, 2, '17:00', '23:00'); $o4 = fx(102, $srv, null, $A, 3, '11:00', '15:00'); $o5 = fx(102, $srv, null, $A, 4, '11:00', '15:00');
$aw = week_id_of(102, $A);
$since = last_activity_id();
[$c, $b] = $run(['week' => $aw]);
ok($c === 200 && count($b['filled']) === 4 && $b['still_open'] === [], 'week_autofill: 200, 4 filled, none left open');
$got = $assignees([$o2, $o3, $o4, $o5]);
ok($got === [31, 30, 33, 26], 'Tuesday evening → Lee (PREFERRED, though Priya is on leave and Ana unavailable); Wednesday → Ana (nobody has hours: the name comes first, Ana before Mara before Priya); Thursday → Mara (0 h, before Priya by name); Friday → Priya (the fewest hours): ' . json_encode($got));
$hrs = array_column($b['hours'], 'hours', 'member_id');
ok($hrs[31] == 6 && $hrs[30] == 6 && $hrs[33] == 4 && $hrs[26] == 4 && $hrs[32] == 6, 'the summary\'s hours per person: ' . json_encode($hrs));
$lg = activity('shift.assign', $since);
$vias = array_map(fn ($r) => json_decode((string) $r['after'], true)['via'] ?? '', $lg);
ok(count($lg) === 4 && $vias === array_fill(0, 4, 'autofill') && count(array_filter($lg, fn ($r) => (int) $r['scope_id'] === 102 && $r['source'] === 'web')) === 4, 'four shift.assign rows, after.via = autofill, with the site');
$wl = activity('week.autofill', $since); $after = json_decode((string) $wl[0]['after'], true);
ok(count($wl) === 1 && $after['filled'] === 4 && $after['open'] === 0 && $after['warnings'] === 0, 'one week.autofill row: filled 4, open 0, warnings 0');
ok((int) one("SELECT count(*) FROM shifts WHERE week_id = :w AND published_at IS NOT NULL", ['w' => $aw]) === 0, 'it wrote a DRAFT: nothing published');
// again, from the same starting point
q('UPDATE shifts SET assignee_member_id = NULL WHERE id IN (' . implode(',', [$o2, $o3, $o4, $o5]) . ')');
[$c, $b2] = $run(['week' => $aw]);
ok($c === 200 && $assignees([$o2, $o3, $o4, $o5]) === $got && array_map(fn ($f) => $f['member_id'], $b2['filled']) === array_map(fn ($f) => $f['member_id'], $b['filled']), 'run again from the same week: the very same people, in the same order');
[$c, $b3] = $run(['week' => $aw]);
ok($c === 200 && $b3['filled'] === [] && $b3['still_open'] === [], 'and with nothing open it does nothing');

echo "2. It never breaks a hard rule, and says what it left open\n";
only([30, 31]);
$B = wk(43);
admin_sql("UPDATE staff_profiles SET is_minor = true, minor_until = current_date + 365 WHERE member_id = 31");
time_off(30, dayn($B, 4));
$s1 = fx(102, $srv, null, $B, 3, '17:00', '23:00'); $s2 = fx(102, $srv, null, $B, 4, '17:00', '23:00'); $s3 = fx(102, $bar, null, $B, 3, '17:00', '21:00');
[$c, $b] = $run(['site' => 102, 'week_start' => $B]);
ok($c === 200 && $assignees([$s1]) === [30], 'Thursday until 23:00: Ana — Lee is a minor (hard: nothing past 22:00) and is never chosen');
$so = array_values(array_filter($b['still_open'], fn ($o) => $o['shift_id'] === $s2))[0] ?? [];
ok($assignees([$s2]) === [null] && str_contains($so['reason'] ?? '', 'Nobody who works Server is free and allowed.'), 'Friday: Ana has leave, Lee is barred: left open, and it says why: "' . ($so['reason'] ?? '?') . '"');
ok($assignees([$s3]) === [null] && count(array_filter($b['still_open'], fn ($o) => $o['position'] === 'Bar')) === 1, 'the Bar shift (only Ana works Bar, and she already has Thursday until 23:00) is left open too');
admin_sql("UPDATE staff_profiles SET is_minor = false, minor_until = NULL WHERE member_id = 31");
ok((int) one("SELECT count(*) FROM shifts WHERE week_id = :w AND assignee_member_id = 31", ['w' => week_id_of(102, $B)]) === 0, 'Lee got no shift of that week');

echo "3. A soft warning is avoided when anyone else fits — and reported when nobody does\n";
only([30, 31]);
$C = wk(44);
reset_people(); admin_sql("UPDATE staff_profiles SET max_hours_week = 3 WHERE member_id = 30");
fx(102, $srv, 31, $C, 0, '12:00', '20:00');                         // Lee: 8 hours already
$t1 = fx(102, $srv, null, $C, 1, '11:00', '15:00');                 // Tuesday: Ana has 0 h but a 4-hour shift is over her own limit of 3 (soft); Lee has 8 h and no warning
time_off(31, dayn($C, 2));
$t2 = fx(102, $srv, null, $C, 2, '11:00', '15:00');                 // Wednesday: Lee is on leave — only Ana fits, with her warning
[$c, $b] = $run(['week' => week_id_of(102, $C)]);
ok($c === 200 && $assignees([$t1]) === [31], 'Tuesday: Lee (8 h, no warning) is chosen over Ana (0 h, a soft warning) — soft warnings are avoided when anyone else fits');
ok($assignees([$t2]) === [30] && $b['warnings'] >= 1, 'Wednesday: only Ana fits — she is put on it, and the summary counts the warning (' . $b['warnings'] . ')');
$f = array_values(array_filter($b['filled'], fn ($x) => $x['shift_id'] === $t2))[0] ?? [];
ok(($f['warnings'] ?? []) !== [] && str_contains(implode(' ', $f['warnings']), 'limit is 3'), 'and names it: "' . implode(' ', $f['warnings'] ?? []) . '"');
$d = jshifts($mara, 102, $C);
ok(count($d[$t2]['warnings']) >= 1 && $d[$t2]['holder']['member_id'] === 30, 'the builder shows the ⚠ on that shift');
$page = page($mara, "/builder?site=102&week=$C")['body'];
ok(str_contains($page, 'id="builder-result"') && str_contains($page, 'Auto-fill: 2 filled') && str_contains($page, 'limit is 3'), 'the builder\'s result card: "Auto-fill: 2 filled, 0 still open, 1 warning left" and the sentence');
reset_people();

echo "4. An empty week is started from the last published week — or from a template\n";
only(null);
$D = wk(45); $E = wk(46);
fx(102, $srv, null, $D, 1, '17:00', '23:00'); fx(102, $srv, null, $D, 3, '11:00', '15:00');
[$c] = bp($mara, '/weeks/publish.php', ['week' => week_id_of(102, $D)]);
ok($c === 200, 'a week with two open shifts is published');
[$c, $b] = $run(['site' => 102, 'week_start' => $E]);
ok($c === 200 && ($b['seeded']['from'] ?? '') === 'last_published_week' && $b['seeded']['placed'] === 2 && count($b['filled']) === 2, 'auto-fill of an EMPTY week seeds it from the last published week (2 placed) and fills them (2)');
ok((int) one('SELECT count(*) FROM shifts WHERE week_id = :w AND assignee_member_id IS NOT NULL', ['w' => week_id_of(102, $E)]) === 2, 'both now have a person');
$page = page($mara, "/builder?site=102&week=$E")['body'];
ok(str_contains($page, 'First the week was started from the last published week (2 shifts placed'), 'the builder\'s result card says where the week started from');
[$c, $b] = bp($mara, '/templates/save.php', ['site' => 102, 'name' => 'SMOKE Seed', 'week' => week_id_of(102, $D)]);
$tid = (int) $b['record_id'];
$F = wk(47);
[$c, $b] = $run(['site' => 102, 'week_start' => $F, 'template' => $tid]);
ok($c === 200 && ($b['seeded']['from'] ?? '') === 'template' && $b['seeded']['placed'] === 2, 'with `template` it starts from that instead');
only(null); reset_people();
finish();
