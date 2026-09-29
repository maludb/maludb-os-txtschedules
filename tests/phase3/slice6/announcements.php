<?php
/**
 * Proof — announcements (spec "Proof: Announcements"): a manager posts to the restaurant, to one position, to two named people — each audience sees exactly its own; another position and another restaurant
 * see nothing; the confirm page counts the recipients; read receipts show for the poster only; removal; the words of every refusal; an agent's post is registered as pending approval.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$mara = as_member(33); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32); $joe = as_member(34); $owner = as_member(1, 102); $dee = as_dee();
$srv = $W['aSrv']; $bar = $W['aBar'];
$ids = fn (string $jar, int $site = 102): array => (function () use ($jar, $site) { [$c, $d] = screen($jar, "/announcements/?site=$site"); return $c === 200 ? array_column($d['announcements'], 'announcement_id') : [$c]; })();
$staff = staff_at(102);
echo "1. The confirm page counts the people; nothing is posted until it is confirmed\n";
$tok = csrf_of(req('GET', '/', ['jar' => $mara])['body']);
$form = ['site' => 102, 'title' => 'SMOKE Parking', 'body' => "Park behind the building.\nSee https://example.invalid/map", 'audience' => 'site'];
$r = req('POST', '/announcements/save.php', ['jar' => $mara, 'form' => $form + ['csrf_token' => $tok]]);
$want = count($staff) - 1;
ok($r['code'] === 200 && str_contains($r['body'], "This will be sent to $want people by email and text.") && str_contains($r['body'], 'id="announcement-confirm-send-btn"'), "the confirm page says \"This will be sent to $want people by email and text.\" (everyone at Airport but the poster)");
ok((int) one("SELECT count(*) FROM announcements WHERE title = 'SMOKE Parking'") === 0 && (int) one('SELECT count(*) FROM notification_outbox') === 0, 'and nothing is posted or queued yet');
ok(str_contains($r['body'], 'name="confirm" value="yes"') && str_contains($r['body'], 'Park behind the building.'), 'the page carries what will be sent and the confirming field');
echo "2. To the whole restaurant\n";
$since = last_activity_id();
[$c, $b] = act($mara, '/announcements/save.php', $form + ['pinned_until' => (new DateTimeImmutable('+3 days'))->format('Y-m-d')]);
$a1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $a1 > 0 && ($b['recipients'] ?? 0) === $want && str_ends_with($b['location'] ?? '', '#announcement-' . $a1), 'announcement_post: 200, record_id, recipients ' . $want . ', the location ends in the record id');
$rows = box("announcement:$a1");
ok(count($rows) === $want * 2 && count(array_unique(array_column($rows, 'member_id'))) === $want && !in_array(33, array_map('intval', array_column($rows, 'member_id')), true) && count(array_filter($rows, fn ($x) => $x['kind'] !== 'announcement')) === 0,
    'one email row and one text row per person, kind announcement, none for the poster (' . count($rows) . ')');
$lg = q("SELECT scope_id, source, after, actor_member_id FROM activity_log WHERE action = 'announcement.post' AND id > :s", ['s' => $since]);
$aft = json_decode($lg[0]['after'] ?? '{}', true);
ok(count($lg) === 1 && (int) $lg[0]['scope_id'] === 102 && (int) $lg[0]['actor_member_id'] === 33 && ($aft['audience'] ?? '') === 'site' && ($aft['recipients'] ?? 0) === $want && !str_contains($lg[0]['after'], 'Park behind'), 'logged announcement.post with the site, the audience and the count — and not the words');
foreach (['Priya' => $priya, 'Ana' => $ana, 'Lee' => $lee, 'Dana' => $dana, 'Mara' => $mara, 'Owner' => $owner] as $n => $jar) { ok(in_array($a1, $ids($jar), true), "$n sees it"); }
ok($ids($joe, 102) === [404], 'a person of another restaurant (Joe, Downtown) is told 404 when he asks for Airport\'s announcements');
ok($ids($joe, 101) === [], 'and sees nothing of it at his own');
ok($ids($dee, 101) === [], 'nor does the Downtown manager');
[, $d] = screen($ana, '/announcements/?site=102');
$card = array_values(array_filter($d['announcements'], fn ($x) => $x['announcement_id'] === $a1))[0];
ok($card['pinned'] === true && $card['posted_by'] === 'SMOKE Mara' && $card['read_by_me'] === false && !array_key_exists('read_count', $card) && !array_key_exists('readers', $card), 'a staff card: pinned, the poster\'s name, unread — no read count and no readers');
$html = req('GET', '/announcements/?site=102', ['jar' => $ana])['body'];
ok(str_contains($html, 'href="https://example.invalid/map"') && str_contains($html, 'Pinned until') && str_contains($html, 'id="announcement-' . $a1 . '-read-btn"') && !str_contains($html, '-readers"'), 'the page makes the link clickable, shows the pin chip and a "Got it" button, and no readers list');
echo "3. To one position\n";
[$c, $b] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Bar only', 'body' => 'Bar inventory Sunday.', 'audience' => 'position', 'position' => $bar]);
$a2 = (int) ($b['record_id'] ?? 0);
$barStaff = array_map('intval', array_column(q('SELECT DISTINCT member_id FROM staff_positions WHERE position_id = :p', ['p' => $bar]), 'member_id'));
ok($c === 200 && ($b['recipients'] ?? 0) === count(array_diff($barStaff, [33])), 'a Bar announcement reaches the Bar\'s staff (' . ($b['recipients'] ?? '?') . ')');
ok(in_array($a2, $ids($ana), true) && !in_array($a2, $ids($lee), true) && !in_array($a2, $ids($priya), true) && !in_array($a2, $ids($dana), true), 'Ana (Bar) sees it; Lee, Priya and Dana (Server only) see nothing of it');
ok(in_array($a2, $ids($mara), true) && in_array($a2, $ids($owner), true), 'the poster and the owner (announce.post) see it');
ok(!in_array($a2, $ids($joe, 101)), 'and nobody at Downtown');
echo "4. To two named people\n";
[$c, $b] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Two', 'body' => 'Just you two.', 'audience' => 'people', 'members' => [26, 31]]);
$a3 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && ($b['recipients'] ?? 0) === 2 && count(box("announcement:$a3")) === 4, 'named people: 2 recipients, 4 rows');
ok(in_array($a3, $ids($priya), true) && in_array($a3, $ids($lee), true) && !in_array($a3, $ids($ana), true) && !in_array($a3, $ids($dana), true) && in_array($a3, $ids($mara), true), 'Priya and Lee see it; Ana and Dana do not; the poster does');
$r = act($ana, '/announcements/read.php', ['announcement' => $a3]);
ok($r[0] === 404 && (int) one('SELECT count(*) FROM announcement_reads WHERE announcement_id = :a', ['a' => $a3]) === 0, 'Ana cannot mark read what was not posted to her: 404, no receipt');
echo "5. Read receipts — the poster's only\n";
$since = last_activity_id();
[$c, $b] = act($ana, '/announcements/read.php', ['announcement' => $a1]);
ok($c === 200 && ($b['record_id'] ?? 0) === $a1 && (int) one('SELECT count(*) FROM announcement_reads WHERE announcement_id = :a AND member_id = 30', ['a' => $a1]) === 1, 'Ana marks it read: 200, one receipt');
act($ana, '/announcements/read.php', ['announcement' => $a1]);
act($lee, '/announcements/read.php', ['announcement' => $a1]);
ok((int) one('SELECT count(*) FROM announcement_reads WHERE announcement_id = :a', ['a' => $a1]) === 2, 'a second tap changes nothing: still two receipts (Ana, Lee)');
$lg = q("SELECT action, scope_id, screen FROM activity_log WHERE id > :s AND actor_member_id IN (30, 31) AND route LIKE '%read.php' ORDER BY id", ['s' => $since]);
ok(array_column($lg, 'action') === ['announcement.read', 'announcement.read'] && (int) $lg[0]['scope_id'] === 102, 'logged announcement.read once each with the site — quiet: no screen.view beside them');
[, $d] = screen($mara, '/announcements/?site=102');
$card = array_values(array_filter($d['announcements'], fn ($x) => $x['announcement_id'] === $a1))[0];
ok($card['read_count'] === 2 && array_column($card['readers'], 'name') === ['SMOKE Ana', 'SMOKE Lee'], 'the poster sees "2 read" and the names, in order');
$html = req('GET', '/announcements/?site=102', ['jar' => $mara])['body'];
ok(str_contains($html, 'id="announcement-' . $a1 . '-read-count"') && str_contains($html, '2 read') && str_contains($html, 'SMOKE Lee'), 'and the page shows the count and, on tap, the names');
[, $d] = screen($lee, '/announcements/?site=102');
ok(!str_contains(json_encode($d), 'SMOKE Ana') && !str_contains(json_encode($d), 'read_count'), 'Lee\'s own view carries no other reader\'s name and no count');
[, $d] = screen($ana, '/announcements/?site=102');
ok($d['unread'] === 1 && array_values(array_filter($d['announcements'], fn ($x) => $x['announcement_id'] === $a1))[0]['read_by_me'] === true, 'Ana has one unread left (the Bar one) and the parking one now reads as read');
echo "6. Removal\n";
[$c, $b] = act($ana, '/announcements/remove.php', ['announcement' => $a1]);
ok($c === 403 && in_array($a1, $ids($mara)), 'a staff member cannot remove it: 403');
[$c, $b] = act($dana, '/announcements/remove.php', ['announcement' => $a1]);
ok($c === 403, 'nor a shift lead (not the poster, no settings.manage)');
[$c, $b] = act($owner, '/announcements/remove.php', ['announcement' => $a3]);
ok($c === 200 && !in_array($a3, $ids($priya)) && !in_array($a3, $ids($mara)), 'the owner (settings.manage) removes Mara\'s post: gone for the audience and the poster');
[$c, $b] = act($mara, '/announcements/remove.php', ['announcement' => $a2]);
ok($c === 200 && one('SELECT removed_at FROM announcements WHERE id = :a', ['a' => $a2]) !== null && !in_array($a2, $ids($ana)), 'the poster removes their own: removed_at set, not deleted');
[$c] = act($lee, '/announcements/read.php', ['announcement' => $a2]);
ok($c === 404, 'a removed announcement cannot be read: 404');
[$c] = act($joe, '/announcements/remove.php', ['announcement' => $a1]);
ok($c === 404, 'a person of another restaurant cannot even find it: 404');
$lg = q("SELECT action, scope_id FROM activity_log WHERE action = 'announcement.remove' ORDER BY id");
ok(count($lg) === 2 && (int) $lg[0]['scope_id'] === 102, 'two announcement.remove rows with the site');
echo "7. The words of every refusal\n";
$bad = [
    [['title' => '', 'body' => 'x', 'audience' => 'site'], 422, 'title'], [['title' => str_repeat('x', 121), 'body' => 'x', 'audience' => 'site'], 422, 'title'],
    [['title' => 't', 'body' => '', 'audience' => 'site'], 422, 'Write what'], [['title' => 't', 'body' => str_repeat('x', 4001), 'audience' => 'site'], 422, 'Write what'],
    [['title' => 't', 'body' => 'x', 'audience' => 'everyone'], 422, 'Choose who'], [['title' => 't', 'body' => 'x', 'audience' => 'position'], 422, 'Choose the position'],
    [['title' => 't', 'body' => 'x', 'audience' => 'position', 'position' => $W['dSrv']], 422, 'not one of'], [['title' => 't', 'body' => 'x', 'audience' => 'people'], 422, 'at least one person'],
    [['title' => 't', 'body' => 'x', 'audience' => 'people', 'members' => [34]], 422, 'work at this restaurant'],
    [['title' => 't', 'body' => 'x', 'audience' => 'site', 'pinned_until' => '2020-01-01'], 422, 'past'], [['title' => 't', 'body' => 'x', 'audience' => 'site', 'pinned_until' => 'soon'], 422, 'date']];
$n0 = (int) one('SELECT count(*) FROM announcements');
foreach ($bad as [$f, $code, $needle]) { [$c, $b] = act($mara, '/announcements/save.php', ['site' => 102] + $f); ok($c === $code && stripos(msg($b), $needle) !== false, "$code: " . msg($b)); }
ok((int) one('SELECT count(*) FROM announcements') === $n0, 'none of them posted anything');
[$c, $b] = act($ana, '/announcements/save.php', $form);
ok($c === 403, 'staff cannot post: 403');
[$c, $b] = act($dana, '/announcements/save.php', $form);
ok($c === 403, 'a shift lead cannot post: 403');
[$c, $b] = act($mara, '/announcements/save.php', ['site' => 101] + $form);
ok($c === 404, 'a manager posting at a restaurant they do not hold: 404');
[$c] = act($dee, '/announcements/save.php', ['site' => 102] + $form);
ok($c === 404, 'the Downtown manager posting at Airport: 404');
$r = req('POST', '/announcements/save.php', ['jar' => $mara, 'form' => $form, 'headers' => JSONH]);
ok($r['code'] === 403, 'no CSRF token: 403');
ok(req('GET', '/announcements/save.php', ['jar' => $mara])['code'] === 405 && req('POST', '/announcements/save.php', ['form' => $form, 'headers' => JSONH])['code'] === 401, 'a GET is 405 and an anonymous POST 401');
ok(req('GET', '/announcements/new?site=102', ['jar' => $ana])['code'] === 403 && req('GET', '/announcements/new?site=102', ['jar' => $mara])['code'] === 200 && req('GET', '/announcements/new?site=101', ['jar' => $mara])['code'] === 404, 'the post form: staff 403, manager 200, a restaurant not held 404');
echo "8. An agent's post is registered as pending approval\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['announcement_post']['approval'] ?? '') === 'external_send' && ($reg['announcement_post']['log_event'] ?? '') === 'announcement.post' && $reg['announcement_post']['built'] === true && $reg['announcement_read']['approval'] === null, 'announcement_post is external_send in the registry (the kernel pauses it — Phase 4 proves the pause); announcement_read is not');
admin_sql("DELETE FROM announcement_reads; DELETE FROM notification_outbox");
finish();
