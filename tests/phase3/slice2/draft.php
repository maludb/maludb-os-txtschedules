<?php
/** Proof — a draft is invisible to staff; publishing makes it visible, tells each person once, and writes week.publish (spec "Proof", 1); week_create; nothing to publish. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people();
$mara = as_member(33); $priya = as_member(26); $ana = as_member(30); $dana = as_member(32); $lee = as_member(31);
$ws = wk(31);
$s1 = fx(102, srv(), 26, $ws, 1); $s2 = fx(102, srv(), 30, $ws, 2); $s3 = fx(102, srv(), null, $ws, 3, '11:00', '15:00');

echo "1. A draft week is the managers'\n";
[$c, $d] = screen($priya, "/my-schedule?week=$ws");
ok($c === 200 && !in_array($s1, array_column($d['shifts'] ?? [], 'shift_id'), true), 'Priya\'s My schedule for that week shows none of her draft shift');
[$c, $d] = screen($priya, "/my-schedule?view=list");
ok(!in_array($s1, array_column($d['shifts'] ?? [], 'shift_id'), true), 'nor does her list of what is coming up');
$seen = [];
foreach (['/team-schedule?week=' . $ws . '&day=' . dayn($ws, 1), '/team-schedule?week=' . $ws . '&day=' . dayn($ws, 3)] as $p) {
    [, $d] = screen($priya, $p);
    foreach ($d['positions'] ?? [] as $g) { foreach ($g['shifts'] as $s) { $seen[] = $s['shift_id']; } }
}
ok(array_intersect($seen, [$s1, $s2, $s3]) === [], 'the team schedule of those days shows none of the draft shifts (assigned or open)');
[, $d] = screen($priya, '/marketplace');
$ids = array_map(fn ($e) => $e['shift_id'] ?? 0, $d['exchanges'] ?? []);
ok(array_intersect($ids, [$s1, $s2, $s3]) === [], 'the marketplace shows none of them');
[, $d] = screen($dana, '/coverage');
ok(!in_array($s3, array_map(fn ($s) => $s['shift_id'], $d['open_shifts'] ?? []), true), 'a shift lead\'s open-shift list to cover shows not the draft open shift');
$r = page($priya, "/shifts/$s1");
ok($r['code'] === 404 && str_contains($r['body'], 'Shift not found.'), 'Priya opening her own draft shift by URL: 404 "Shift not found."');
[$c, $d] = builder($mara, 102, $ws);
$ids = array_column($d['shifts'] ?? [], 'shift_id');
ok($c === 200 && $d['state'] === 'draft' && count(array_intersect($ids, [$s1, $s2, $s3])) === 3, 'the manager\'s builder shows all three, state draft');
$r = page($mara, "/shifts/$s1");
ok($r['code'] === 200, 'and the manager opens the draft shift');

echo "2. week_create\n";
$w2 = wk(32);
$since = last_activity_id();
[$c, $b] = bp($mara, '/weeks/save.php', ['site' => 102, 'week_start' => dayn($w2, 3)]);
ok($c === 200 && ($b['created'] ?? false) && $b['week_start'] === $w2 && $b['location'] === "/builder?site=102&week=$w2" && ($b['record_id'] ?? 0) > 0, 'a date in the week starts it: the Monday, an empty draft, record_id and a location to the builder');
[$c, $b2] = bp($mara, '/weeks/save.php', ['site' => 102, 'week_start' => $w2]);
ok($c === 200 && ($b2['created'] ?? true) === false && $b2['record_id'] === $b['record_id'], 'asking again finds the same week');
ok(count(activity('week.create', $since)) === 1 && (int) activity('week.create', $since)[0]['scope_id'] === 102, 'one week.create row, with the site');
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $b['record_id']]);
ok($c === 422 && msg($b) === 'There are no shifts to publish.', 'publishing an empty week: 422 "There are no shifts to publish."');

echo "3. Publish\n";
$wid = week_id_of(102, $ws);
[$c, $sum] = screen($mara, "/weeks/publish-confirm?site=102&week=$ws");
ok($c === 200 && $sum['shifts'] === 3 && $sum['people_told'] === 2 && $sum['open_left'] === 1 && $sum['hard'] === 0, 'the summary page: 3 shifts, 2 people told, 1 open left, no hard warning');
$r = page($mara, "/weeks/publish-confirm?site=102&week=$ws");
ok($r['code'] === 200 && str_contains($r['body'], 'id="publish-form-save-btn"') && str_contains($r['body'], 'Publish and tell staff') && str_contains($r['body'], 'data-screen="week-publish"'), 'the page: Publish and tell staff, stamped week-publish');
$since = last_activity_id(); $ob = last_outbox_id();
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $wid]);
ok($c === 200 && $b['shift_count'] === 3 && $b['people_told'] === 2 && $b['open_left'] === 1, 'week_publish: 3 shifts published, 2 people told, 1 open left');
ok(week_status(102, $ws) === 'published' && (int) one('SELECT count(*) FROM shifts WHERE week_id = :w AND published_at IS NOT NULL', ['w' => $wid]) === 3, 'the week and its three shifts are published');
$log = activity('week.publish', $since);
$after = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && (int) $log[0]['scope_id'] === 102 && (int) $log[0]['actor_member_id'] === 33 && $after['shift_count'] === 3 && $after['people_told'] === 2 && $after['overridden'] === 0 && $log[0]['source'] === 'web', 'one week.publish row: the site, the manager, source web, shift_count 3, people_told 2');
ok(publish_notices($wid) === [26 => 2, 30 => 2], 'Priya and Ana are each told ONCE (one row a channel: email and text) — nobody else: ' . json_encode(publish_notices($wid)));
$n = q("SELECT * FROM notification_outbox WHERE reference = :r AND kind = 'schedule_published' AND member_id = 26 AND channel = 'email'", ['r' => "week:$wid"])[0] ?? [];
ok(str_contains((string) ($n['body'] ?? ''), 'Server') && str_contains((string) ($n['body'] ?? ''), 'SMOKE Airport') && ($n['dedupe_key'] ?? '') === "publish:$wid:26:email" && !str_contains((string) $n['body'], '21.37'), 'the notice lists her shift, names the restaurant, dedupes on publish:{week}:{member}, and carries no pay');
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $wid]);
ok($c === 422 && msg($b) === 'This week is already published.' && publish_notices($wid) === [26 => 2, 30 => 2], 'publishing again: 422, and nobody is told twice');
[, $d] = screen($priya, "/my-schedule?week=$ws");
ok(in_array($s1, array_column($d['shifts'] ?? [], 'shift_id'), true), 'now Priya\'s My schedule shows the shift');
[, $d] = screen($priya, '/team-schedule?week=' . $ws . '&day=' . dayn($ws, 3));
$open = array_filter(array_merge(...array_map(fn ($g) => $g['shifts'], $d['positions'] ?? [])), fn ($s) => $s['shift_id'] === $s3 && $s['is_open']);
ok(count($open) === 1, 'and the team schedule shows the open shift as open');
$r = page($priya, "/shifts/$s1");
ok($r['code'] === 200, 'and she can open it');
[$c] = bp($priya, '/weeks/publish.php', ['week' => week_id_of(102, $w2)]);
ok($c === 403, 'Priya publishing a week: 403');
finish();
