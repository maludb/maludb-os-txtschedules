<?php
/**
 * Proof — both channels for a reminder (D12; spec "Proof: Both channels for a reminder"): a person with both on and a verified phone gets one reminder email and one text for a shift inside their lead time and
 * NOT a second on the next minute (the dedupe key); their own lead time beats the restaurant's; a kind, a channel or both turned off queue nothing for it; a draft, a cancelled, an open, an unpublished and
 * an inactive person's shift are never reminded; the reminder's words carry the shift's facts and a link.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$srv = $W['aSrv'];
$lee = 31; $ana = 30; $dana = 32; $marco = 27; $priya = 26;
$sh = [];
act(as_member($priya), '/settings/prefs.php', ['kinds' => ['schedule_published', 'exchange']]);   // Priya has a fixture shift inside the lead time and turned reminders off
echo "1. Both on, a verified phone: one email and one text, once\n";
$sh['lee'] = shift_in(102, $srv, $lee, 1.5, 3);
$r = worker();
ok(($r['remind'] ?? 0) >= 2, 'the worker queued the reminders (' . json_encode($r) . ')');
$rows = box("shift:{$sh['lee']}");
ok(count($rows) === 2 && array_column($rows, 'channel') === ['email', 'sms'] && count(array_unique(array_column($rows, 'member_id'))) === 1 && (int) $rows[0]['member_id'] === $lee, 'one row a channel for Lee — email and sms');
ok(array_column($rows, 'dedupe_key') === ["reminder:{$sh['lee']}:$lee:email", "reminder:{$sh['lee']}:$lee:sms"] && $rows[0]['kind'] === 'reminder', 'the keys are reminder:{shift}:{member}:{channel}');
ok(array_column($rows, 'status') === ['sent', 'sent'] && $rows[1]['kernel_notification_id'] !== null, 'the same pass sent both; the text row keeps the kernel\'s notification id');
$texts = array_values(array_filter(sms_log($lee), fn ($x) => str_contains($x['text'], '/shifts/' . $sh['lee'])));
$mails = array_values(array_filter(mail_log(email_of($lee)), fn ($x) => str_contains($x['text'], '/shifts/' . $sh['lee'])));
ok(count($texts) === 1 && count($mails) === 1, 'the stubbed kernel got ONE text and the MaluMail stub ONE email for the shift');
$line = $texts[0]['text'] ?? '';
ok(preg_match('#^Server at SMOKE Airport (today|tomorrow) \d{1,2}:\d{2}( [ap]m)?.\d{1,2}:\d{2} [ap]m\. http\S*/shifts/' . $sh['lee'] . '$#u', $line) === 1, 'the text reads "Server at SMOKE Airport today 5:00–11:00 pm. …/shifts/N": ' . $line);
ok(($texts[0]['reference'] ?? '') === "shift:{$sh['lee']}" && !isset($texts[0]['phone']) && (int) $texts[0]['member_id'] === $lee && array_keys($texts[0]) === ['member_id', 'text', 'reference', 'accepted', 'mode'], 'the request names the member, the text and a reference — never a phone number');
ok(str_starts_with($mails[0]['subject'], 'Shift reminder: Server at SMOKE Airport') && $mails[0]['from'] === 'noreply@example.invalid' && str_contains($mails[0]['html'], '<a href="http'), 'the email: subject, the application\'s sender, a link in the html');
$lg = q("SELECT scope_id, source, after, actor_member_id FROM activity_log WHERE action = 'notification.send' AND entity_id IN (" . implode(',', array_column($rows, 'id')) . ")");
$aft = array_map(fn ($x) => json_decode($x['after'], true), $lg);
ok(count($lg) === 2 && (int) $lg[0]['scope_id'] === 102 && $lg[0]['source'] === 'cron' && $lg[0]['actor_member_id'] === null && count(array_filter($aft, fn ($a) => $a['outcome'] === 'sent' && $a['kind'] === 'reminder')) === 2 && !str_contains(json_encode($lg), '/shifts/'),
    'two notification.send rows: source cron, the site, kind/channel/outcome — no body, no address');
echo "2. Not a second time\n";
$n = (int) one('SELECT count(*) FROM notification_outbox');
worker(); worker();
ok((int) one('SELECT count(*) FROM notification_outbox') === $n && count(array_filter(sms_log($lee), fn ($x) => str_contains($x['text'], '/shifts/' . $sh['lee']))) === 1 && count(array_filter(mail_log(email_of($lee)), fn ($x) => str_contains($x['text'], '/shifts/' . $sh['lee']))) === 1,
    'two more passes queue nothing and send nothing again');
echo "3. Lead time: the person\'s own beats the restaurant's\n";
$sh['far'] = shift_in(102, $srv, $ana, 3.2, 3);                      // outside 120 minutes
worker();
ok(box("shift:{$sh['far']}") === [], 'a shift 3 hours away is outside the restaurant\'s 2 hours: no reminder');
act(as_member($ana), '/settings/prefs.php', ['reminder_minutes' => '240', 'by_sms' => 'no']);
worker();
$rows = box("shift:{$sh['far']}");
ok(array_column($rows, 'channel') === ['email'] && $rows[0]['status'] === 'sent', 'Ana asks for 4 hours and turns texts off: her 3-hour shift is reminded — by email only');
ok(count(sms_log($ana)) === 0, 'and the kernel was never asked to text her');
$sh['dana'] = shift_in(102, $srv, $dana, 1.5, 3);
act(as_member($dana), '/settings/prefs.php', ['reminder_minutes' => '30']);
admin_sql("UPDATE schedule_weeks SET status = 'draft' WHERE id = (SELECT week_id FROM shifts WHERE id = {$sh['dana']})");
worker();
ok(box("shift:{$sh['dana']}") === [], 'Dana asks for 30 minutes (her shift is 90 away), and the week is a draft besides: nothing');
admin_sql("UPDATE schedule_weeks SET status = 'published' WHERE id = (SELECT week_id FROM shifts WHERE id = {$sh['dana']})");
worker();
ok(box("shift:{$sh['dana']}") === [], 'the week published: still nothing — 90 minutes is outside her 30');
act(as_member($dana), '/settings/prefs.php', ['reminder_minutes' => '']);
worker();
ok(count(box("shift:{$sh['dana']}")) === 2, 'she goes back to the restaurant\'s lead time: both channels queued');
echo "4. A kind, a channel, both channels off\n";
$sh['marco'] = shift_in(102, $srv, $marco, 1.7, 2);
act(as_member($marco), '/settings/prefs.php', ['by_email' => 'no', 'by_sms' => 'no']);
worker();
ok(box("shift:{$sh['marco']}") === [], 'a person with both channels off gets nothing queued');
$priyaShifts = array_column(q("SELECT id FROM shifts WHERE assignee_member_id = 26 AND status = 'scheduled' AND starts_at BETWEEN now() AND now() + interval '2 hours'"), 'id');
ok(count($priyaShifts) >= 1, 'Priya has a fixture shift inside the lead time (' . implode(',', $priyaShifts) . ')');
worker();
$got = 0; foreach ($priyaShifts as $s) { $got += count(box("shift:$s")); }
ok($got === 0 && box_of($priya, 'reminder') === [], 'Priya turned "reminders" off: nothing is queued for her shift');
echo "5. Shifts that are never reminded\n";
$sh['cancel'] = shift_in(102, $srv, $lee, 6, 2);
$sh['cancel2'] = shift_in(102, $srv, $lee, 9, 2);
act(as_member($lee), '/settings/prefs.php', ['reminder_minutes' => '2880']);
admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE id = {$sh['cancel']}");
admin_sql("UPDATE shifts SET published_at = NULL WHERE id = {$sh['cancel2']}");
$sh['open'] = mkshift(102, $srv, null, 5, 3, ['note' => 'SMOKE s6 open']);
worker();
ok(box("shift:{$sh['cancel']}") === [] && box("shift:{$sh['cancel2']}") === [] && box("shift:{$sh['open']}") === [], 'a cancelled shift, an unpublished shift and an open shift are not reminded');
$dt = shift_in(101, $W['dSrv'], 28, 2, 2);
admin_sql("UPDATE members SET status = 'inactive' WHERE id = 28");
worker();
ok(box("shift:$dt") === [], 'a person who is no longer active is not reminded');
admin_sql("UPDATE members SET status = 'active' WHERE id = 28");
worker();
$rows = box("shift:$dt");
ok(count($rows) === 2, 'reactivated: reminded (a Downtown shift has its own restaurant\'s name in it)');
ok(str_contains($rows[0]['body'], 'SMOKE Downtown'), 'the reminder names the shift\'s own restaurant: ' . $rows[0]['body']);
echo "6. A person who works in two time zones sees the zone\n";
$sh['tz'] = shift_in(102, $srv, 1, 1.0, 2);                           // the owner holds Airport (Chicago) and Downtown
$zones = (int) one('SELECT count(DISTINCT s.timezone) FROM member_site_roles r JOIN sites s ON s.scope_id = r.scope_id WHERE r.member_id = 1');
worker();
$b = box("shift:{$sh['tz']}")[0]['body'] ?? '';
ok($zones < 2 || preg_match('/\d [ap]m (CDT|CST|CT)\./', $b) === 1, 'the owner holds ' . $zones . ' zone(s); a reminder says the zone when there are two: ' . $b);
admin_sql("DELETE FROM notification_outbox");
finish();
