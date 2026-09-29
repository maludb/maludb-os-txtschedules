<?php
/**
 * Proof — nothing sensitive in a notice (spec "Proof: Nothing sensitive in a notice"): a run through every kind of step that queues a notice — a trade offered, taken, given and accepted, time off
 * asked and decided, an announcement, a reminder, a certification warning — then EVERY queued body and subject is searched for the fixture wages, every phone number and every other person's email
 * address; an exchange notice names the other person by FIRST name only.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$priya = as_member(26); $lee = as_member(31); $mara = as_member(33); $ana = as_member(30);
admin_sql("UPDATE members SET display_name = CASE id WHEN 26 THEN 'Priya Vandersloot' WHEN 30 THEN 'Ana Quillfeather' WHEN 31 THEN 'Lee Marchbanks' WHEN 33 THEN 'Mara Oldcastle' END WHERE id IN (26, 30, 31, 33)");
$surnames = ['Vandersloot', 'Quillfeather', 'Marchbanks'];
echo "1. Trades\n";
$x = offer($priya, $W['p1']);
[$c, $b] = claim($lee, $x);
ok($c === 200 && $x > 0, 'Priya offers a shift and Lee picks it up (status ' . status_of($x) . ')');
if (status_of($x) === 'pending_approval') { act($mara, '/exchanges/approve.php', ['exchange' => $x]); }
[$c, $b] = act($priya, '/exchanges/give.php', ['shift' => $W['p2'], 'colleague' => 31]);
$xg = (int) ($b['record_id'] ?? 0);
act($lee, '/exchanges/accept.php', ['exchange' => $xg]);
if (status_of($xg) === 'pending_approval') { act($mara, '/exchanges/approve.php', ['exchange' => $xg]); }
$ex = array_merge(outbox($x), outbox($xg));
ok(count($ex) >= 4, 'the trades queued ' . count($ex) . ' notices');
$named = array_values(array_filter($ex, fn ($r) => str_contains($r['body'], 'Priya') || str_contains($r['body'], 'Lee')));
ok(count($named) >= 2 && count(array_filter($ex, fn ($r) => array_filter($surnames, fn ($s) => str_contains($r['body'] . $r['subject'], $s)))) === 0, 'notices name the other person by first name ("Lee took your shift") — never a surname (' . count($named) . ' name someone)');
echo "2. Time off\n";
$type = (int) one("SELECT id FROM time_off_types WHERE scope_id = 102 AND key = 'unpaid'");
$day = (new DateTimeImmutable('+40 days'))->format('Y-m-d');
[$c, $b] = req_off($ana, $type, $day, $day);
$r = (int) ($b['record_id'] ?? 0);
act($mara, '/time-off/approve.php', ['request' => $r, 'note' => 'SMOKE enjoy']);
ok($r > 0 && count(outbox_ref("time_off:$r")) >= 2, 'time off asked and approved queued notices for the managers and Ana (' . count(outbox_ref("time_off:$r")) . ')');
echo "3. An announcement, a reminder, a certification warning\n";
act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Notice', 'body' => 'Meeting Monday at 9.', 'audience' => 'site']);
shift_in(102, $W['aSrv'], 31, 1.5, 2);
{
    $kind = (int) one("SELECT id FROM certification_kinds WHERE scope_id = 102 AND key = 'food_handler'");
    admin_sql("INSERT INTO certifications (member_id, kind_id, expires_on, verified_at, verified_by) VALUES (30, $kind, current_date + 5, now(), 33)");
}
worker();
$all = q("SELECT o.*, m.email AS to_email FROM notification_outbox o JOIN members m ON m.id = o.member_id ORDER BY o.id");
$kinds = array_count_values(array_column($all, 'kind'));
ok(count($all) > 20 && count($kinds) >= 4, 'in all ' . count($all) . ' queued notices of ' . count($kinds) . ' kinds (' . json_encode($kinds) . ')');
$leaks = [];
foreach ($all as $n) { foreach (notice_leaks($n['subject'] . "\n" . $n['body'], (int) $n['member_id']) as $l) { $leaks[] = $n['id'] . ':' . $l; } }
ok($leaks === [], 'no queued subject or body holds a fixture wage, a phone number or another person\'s email address' . ($leaks ? ' — ' . implode(', ', array_slice($leaks, 0, 5)) : ''));
$sent = array_map(fn ($r) => json_encode($r), array_merge(sms_log(), mail_log()));
$l2 = []; foreach ($sent as $s) { foreach (wage_marks() as $w) { if (str_contains(strip_ts($s), $w)) { $l2[] = $w; } } }
ok($l2 === [] && count($sent) > 10, 'nor does anything the stubs received (' . count($sent) . ' messages)');
ok(count(array_filter($all, fn ($n) => preg_match('/\$\s?\d|per hour|hourly|wage|rate of/i', $n['body']))) === 0, 'no notice speaks of money at all');
$logs = (string) one("SELECT string_agg(coalesce(after::text, '') || coalesce(before::text, ''), ' ') FROM activity_log WHERE action IN ('notification.send', 'announcement.post', 'prefs.save')");
$l3 = []; foreach (wage_marks() as $w) { if (str_contains(strip_ts($logs), $w)) { $l3[] = $w; } }
ok($l3 === [] && !str_contains($logs, 'Meeting Monday'), 'and the activity rows of the slice carry no wage and no announcement text');
admin_sql("UPDATE members SET display_name = CASE id WHEN 26 THEN 'SMOKE Priya' WHEN 30 THEN 'SMOKE Ana' WHEN 31 THEN 'SMOKE Lee' WHEN 33 THEN 'SMOKE Mara' END WHERE id IN (26, 30, 31, 33); DELETE FROM certifications; DELETE FROM notification_outbox");
finish();
