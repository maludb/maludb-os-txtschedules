<?php
/**
 * Proof — K6 (spec "Proof: Both channels ... with the kernel returning rate_limited, no_verified_phone, no_sender or opted_out the email is still sent and the text row is skipped with the code"; "K6 seen from the
 * kernel"): every refusal of the stubbed kernel is a SKIP, never a stop, and the email is an independent row; the 31st text of a day; an unreachable kernel is retried and fails at the fifth try; a person with
 * no address and no text is noted failed; nothing here holds a Twilio key or a phone number.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$srv = $W['aSrv'];
$lee = 31;
act(as_member($lee), '/settings/prefs.php', ['reminder_minutes' => '2880']);
$hour = 6;
$next = function () use (&$hour, $srv, $lee): int { $id = shift_in(102, $srv, $lee, $hour, 2); $hour += 2.5; return $id; };
echo "1. Each refusal is a skip; the email still goes\n";
foreach (['no_verified_phone', 'no_sender', 'opted_out', 'not_held', 'rate_limited'] as $mode) {
    ksms(['mode' => $mode]);
    $s = $next();
    $before = count(mail_log(email_of($lee)));
    $r = worker();
    $rows = box("shift:$s");
    $em = array_values(array_filter($rows, fn ($x) => $x['channel'] === 'email'))[0] ?? null;
    $tx = array_values(array_filter($rows, fn ($x) => $x['channel'] === 'sms'))[0] ?? null;
    ok($em !== null && $tx !== null && $em['status'] === 'sent' && $tx['status'] === 'skipped' && $tx['error'] === $mode && $tx['kernel_notification_id'] === null, "$mode: the text row is skipped with the code, the email row is sent");
    ok(count(mail_log(email_of($lee))) === $before + 1 && count(array_filter(sms_log($lee), fn ($x) => str_contains($x['reference'], "shift:$s") && $x['mode'] === $mode)) === 1, "$mode: the email reached the stub and the kernel was asked exactly once");
    ok(($r['send']['failed'] ?? 1) === 0 && isset($r['forecast']), "$mode: the worker went on to its other steps (" . json_encode($r['send'] ?? $r) . ')');
}
$lg = q("SELECT after FROM activity_log WHERE action = 'notification.send' AND after->>'code' IS NOT NULL AND (after->>'member_id')::int = 31 ORDER BY id");
ok(count($lg) === 5 && json_decode($lg[0]['after'], true)['outcome'] === 'skipped', 'each skip is a notification.send row with the code and outcome skipped for Lee (' . count($lg) . ')');
echo "2. The 31st text of a day\n";
ksms(['mode' => 'ok', 'limit' => 30]);
sms_clear();
$f = fopen(sms_file(), 'a'); for ($i = 0; $i < 30; $i++) { fwrite($f, json_encode(['member_id' => $lee, 'text' => 'earlier', 'reference' => 'x', 'accepted' => true, 'mode' => 'ok']) . "\n"); } fclose($f);
$s = $next();
worker();
$rows = box("shift:$s");
ok(array_column($rows, 'status') === ['sent', 'skipped'] && $rows[1]['error'] === 'rate_limited', 'with 30 texts already today the 31st is refused rate_limited: skipped; the email is sent');
ksms(['mode' => 'ok']);
$s = $next();
worker();
ok(array_column(box("shift:$s"), 'status') === ['sent', 'sent'], 'and with the limit lifted the next reminder goes by both again');
echo "3. A kernel that does not answer is retried, one try a minute, and fails at the fifth\n";
ksms(['mode' => 'server_error']);
$s = $next();
worker();
$tx = box("shift:$s", 'sms')[0];
ok($tx['status'] === 'queued' && (int) $tx['attempts'] === 1 && $tx['error'] === 'kernel_500' && strtotime($tx['not_before']) > time() + 30, 'a 500: the text stays queued, one attempt counted, the next try a minute away');
$sent = count(mail_log(email_of($lee)));
worker();
ok(box("shift:$s", 'sms')[0]['attempts'] == 1, 'the next pass, inside the minute, does not try again');
for ($i = 0; $i < 4; $i++) { admin_sql("UPDATE notification_outbox SET not_before = now() - interval '1 second' WHERE id = {$tx['id']}"); worker(); }
$tx = box("shift:$s", 'sms')[0];
ok($tx['status'] === 'failed' && (int) $tx['attempts'] === 5 && $tx['error'] === 'kernel_500', 'after five tries it is failed, with the reason');
$em = box("shift:$s", 'email')[0];
ok($em['status'] === 'sent' && count(mail_log(email_of($lee))) === $sent, 'and the email row was sent at the first pass, once — independent of the text');
ksms(['mode' => 'ok']);
echo "4. Nobody reachable\n";
$s = $next();
admin_sql("UPDATE members SET email = NULL WHERE id = $lee");
ksms(['mode' => 'no_verified_phone']);
worker();
$rows = box("shift:$s");
ok(array_column($rows, 'status') === ['skipped', 'failed'] && $rows[0]['error'] === 'no_email' && str_starts_with($rows[1]['error'], 'no email on file and no_verified_phone'), 'no address and no verified phone: the email is skipped no_email and the text failed with both reasons');
admin_sql("UPDATE members SET email = '" . 'lee.' . run_id() . "@example.invalid' WHERE id = $lee");
ksms(['mode' => 'ok']);
$s = $next();
admin_sql("UPDATE members SET email = NULL WHERE id = $lee");
worker();
$rows = box("shift:$s");
ok(array_column($rows, 'status') === ['skipped', 'sent'], 'no address but a working phone: the text goes, the email is only skipped');
admin_sql("UPDATE members SET email = 'lee." . run_id() . "@example.invalid' WHERE id = $lee");
echo "5. What the kernel is asked, and what this application holds\n";
$all = sms_log();
ok(count($all) > 5 && count(array_filter($all, fn ($x) => array_keys($x) !== ['member_id', 'text', 'reference', 'accepted', 'mode'])) === 0, 'every request to the kernel is exactly {member_id, text, reference}');
ok(count(array_filter($all, fn ($x) => mb_strlen($x['text']) > 480)) === 0 && count(array_filter($all, fn ($x) => preg_match('/\+?\d{7,}/', $x['text']))) === 0, 'no text is over 480 characters, and none carries a phone number');
$root = dirname(__DIR__, 3);
$grep = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && grep -rnE "TWILIO_[A-Z]+|\\bAC[0-9a-f]{32}\\b" app html bin config deploy maludb-os.json 2>/dev/null'));
ok($grep === '', 'no Twilio key, account id or auth token anywhere in app, html, bin, config or deploy' . ($grep ? ": $grep" : ''));
$dump = (string) shell_exec('sudo -n -u postgres pg_dump -a -t notification_outbox -t notification_prefs -t announcements -t announcement_reads -t calendar_feeds ' . escapeshellarg(need('DB_NAME')));
$phones = array_column(q('SELECT phone FROM members WHERE phone IS NOT NULL'), 'phone');
ok($phones !== [] && count(array_filter($phones, fn ($p) => str_contains($dump, $p))) === 0 && strlen($dump) > 1000, 'a dump of the five tables holds none of the ' . count($phones) . ' fixture phone numbers');
$code = (string) shell_exec('cd ' . escapeshellarg($root) . ' && cat app/features/notify/*.php app/features/announcements/*.php app/features/calendar/*.php bin/notifications.php html/announcements/*.php html/settings/*.php');
ok(!preg_match('/m\.phone|members\.phone|\[.phone.\]/', $code), 'no code of the slice reads a phone column');
admin_sql("DELETE FROM notification_outbox");
finish();
