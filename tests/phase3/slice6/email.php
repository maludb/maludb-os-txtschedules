<?php
/**
 * Proof — email (spec "Proof: Email"): a message reaches a fresh example.invalid address through the MaluMail STUB (the real API is never called: MALUMAIL_API_URL points at tests/fake_maludb.php);
 * a suppressed or rejected address is a permanent skip, a relay error is retried to five; a missing key leaves rows queued and the texts still go; a person with no address is skipped.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$ana = 30; $lee = 31;
$queue = fn (int $m, string $subject, string $body, string $channel = 'email', string $kind = 'reminder'): int => (int) one("INSERT INTO notification_outbox (member_id, scope_id, channel, kind, subject, body, reference) VALUES (:m, 102, :c, :k, :s, :b, :r) RETURNING id",
    ['m' => $m, 'c' => $channel, 'k' => $kind, 's' => $channel === 'email' ? $subject : null, 'b' => $body, 'r' => 'smoke:' . run_id()]);
echo "1. A message reaches a fresh address\n";
ok(str_ends_with(email_of($ana), '.' . run_id() . '@example.invalid') && !str_contains(email_of($ana), 'gmail'), 'the address is fresh to this run and example.invalid: ' . email_of($ana));
$id = $queue($ana, 'SMOKE hello <b>', "Line one\nLine two <script>alert(1)</script> https://example.invalid/x");
worker();
$row = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
$m = mail_log(email_of($ana));
ok($row['status'] === 'sent' && $row['sent_at'] !== null && (int) $row['attempts'] === 1 && count($m) === 1, 'the row is sent, timed, one attempt; the stub got one message');
ok($m[0]['from'] === 'noreply@example.invalid' && $m[0]['from_name'] === 'txtSchedules' && $m[0]['to'] === email_of($ana) && $m[0]['subject'] === 'SMOKE hello <b>' && str_contains($m[0]['text'], "Line one\nLine two"), 'from the application\'s sender, to the person, the subject and the plain text as queued');
ok(!str_contains($m[0]['html'], '<script>') && str_contains($m[0]['html'], '&lt;script&gt;') && str_contains($m[0]['html'], '<a href="https://example.invalid/x">') && str_contains($m[0]['html'], 'Line one<br>'), 'the html escapes what was written, keeps line breaks and makes the link clickable');
echo "2. Permanent refusals are skips; a relay error is retried\n";
foreach (['suppressed' => 'undeliverable', 'rejected' => 'undeliverable'] as $word => $code) {
    admin_sql("UPDATE members SET email = 'ana.$word." . run_id() . "@example.invalid' WHERE id = $ana");
    $id = $queue($ana, "SMOKE $word", 'body');
    worker();
    $row = q('SELECT status, error, attempts FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
    ok($row['status'] === 'skipped' && ($row['error'] === $code || $row['error'] === $word) && count(mail_log(email_of($ana))) === 1, "an address MaluMail calls $word: skipped ('{$row['error']}'), sent once, never retried");
}
admin_sql("UPDATE members SET email = 'ana.flaky." . run_id() . "@example.invalid' WHERE id = $ana");
$id = $queue($ana, 'SMOKE flaky', 'body');
worker();
$row = q('SELECT status, error, attempts, not_before FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
ok($row['status'] === 'queued' && $row['attempts'] == 1 && $row['error'] === 'malumail_502' && strtotime($row['not_before']) > time() + 30, 'a 502 from the relay: queued again a minute later, one attempt, the reason kept');
for ($i = 0; $i < 4; $i++) { admin_sql("UPDATE notification_outbox SET not_before = now() - interval '1 second' WHERE id = $id"); worker(); }
$row = q('SELECT status, error, attempts FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
ok($row['status'] === 'failed' && $row['attempts'] == 5 && count(mail_log(email_of($ana))) === 5, 'at the fifth attempt it is failed with the error (the stub saw five tries)');
admin_sql("UPDATE members SET email = 'ana." . run_id() . "@example.invalid' WHERE id = $ana");
echo "3. Not configured, and nobody to write to\n";
$id = $queue($lee, 'SMOKE unconfigured', 'body');
$idt = $queue($lee, '', 'a text', 'sms');
$r = worker('', 'MALUMAIL_API_KEY=');
$rows = q('SELECT id, status, attempts FROM notification_outbox WHERE id IN (:a, :b) ORDER BY id', ['a' => $id, 'b' => $idt]);
ok($rows[0]['status'] === 'queued' && (int) $rows[0]['attempts'] === 0 && $rows[1]['status'] === 'sent' && ($r['send']['unconfigured'] ?? 0) >= 1, 'without a MaluMail key the email stays queued (no attempt counted) while the text still goes');
worker();
ok(q('SELECT status FROM notification_outbox WHERE id = :i', ['i' => $id])[0]['status'] === 'sent', 'the key back: the email goes at the next pass');
admin_sql("UPDATE members SET email = NULL WHERE id = $lee");
$id = $queue($lee, 'SMOKE no address', 'body');
worker();
$row = q('SELECT status, error FROM notification_outbox WHERE id = :i', ['i' => $id])[0];
ok($row['status'] === 'skipped' && $row['error'] === 'no_email', 'a person with no address on file: the email is skipped no_email');
admin_sql("UPDATE members SET email = 'lee." . run_id() . "@example.invalid' WHERE id = $lee; UPDATE members SET status = 'inactive' WHERE id = $ana");
$id = $queue($ana, 'SMOKE inactive', 'body');
$n = count(mail_log());
worker();
ok(q('SELECT status, error FROM notification_outbox WHERE id = :i', ['i' => $id])[0] === ['status' => 'skipped', 'error' => 'inactive'] && count(mail_log()) === $n, 'a person who is no longer active: skipped inactive, nothing sent');
admin_sql("UPDATE members SET status = 'active' WHERE id = $ana; DELETE FROM notification_outbox");
$bad = trim((string) shell_exec('grep -rn "api.malumail.com" ' . escapeshellarg(dirname(__DIR__, 3) . '/app') . ' | grep -v "MALUMAIL_API_URL"'));
ok($bad === '', 'the stub is reached only through MALUMAIL_API_URL; the real address stays the default in app/mail.php');
finish();
