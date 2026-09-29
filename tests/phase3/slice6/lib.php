<?php
/**
 * Helpers for the Phase 3 slice 6 proofs (docs/build-specs/announcements-notifications.md, "Proof"). Run through tests/phase3/slice6/run.sh: a fresh SCRATCH database, the application on :8191, a FAKE
 * kernel (:8192 — it now answers K6's /api/v1/notify/sms.php as the proof tells it, and K7), a fake MaluDB that also STANDS IN FOR MALUMAIL (:8193, /v1/send). Nothing real is ever sent: email goes to
 * a stub, texts to the stubbed kernel, and every address is a fresh example.invalid one (per run). Builds on slice 5's helpers. Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice5/lib.php';

/** This run's own token — addresses and phones the proofs use are unique to it. */
function run_id(): string { static $r = null; return $r ??= substr(md5(uniqid('', true)), 0, 6); }

/** Start clean: the outbox, prefs, feeds, announcements gone; the kernel says yes to texts; the stubs' logs empty; every person's address fresh and unique to this run, and a fixture phone (never used). */
function reset6(): array
{
    $W = reset5();
    admin_sql("DELETE FROM notification_outbox; DELETE FROM notification_prefs; DELETE FROM calendar_feeds; DELETE FROM announcement_reads; DELETE FROM announcements;
               DELETE FROM activity_log WHERE action IN ('notification.send', 'notification.daily', 'exchange.expire', 'announcement.post', 'announcement.remove', 'announcement.read', 'prefs.save', 'calendar_feed.rotate') AND actor_member_id IS NULL;
               UPDATE site_settings SET reminder_minutes_before = 120;
               UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE note LIKE 'SMOKE s6 %' AND status = 'scheduled';");   // published shifts are never deleted: an earlier proof's shifts would overlap this one's and be reminded again
    foreach ([1, 26, 27, 28, 30, 31, 32, 33, 34, 35, 36, 37] as $m) {
        admin_sql("UPDATE members SET email = lower(regexp_replace(display_name, '[^A-Za-z]', '', 'g')) || '.' || '" . run_id() . "@example.invalid', phone = '+1555" . str_pad((string) (7000 + $m), 7, '0', STR_PAD_LEFT) . "' WHERE id = $m;");
    }
    ksms(['mode' => 'ok']);
    sms_clear();
    mail_clear();
    return $W;
}
function email_of(int $m): string { return (string) one('SELECT email FROM members WHERE id = :m', ['m' => $m]); }
function phone_of(int $m): string { return (string) one('SELECT phone FROM members WHERE id = :m', ['m' => $m]); }

/** Tell the fake kernel how K6 answers: mode ok | no_sender | not_held | no_verified_phone | opted_out | rate_limited | server_error; members = per-member modes; limit = texts before rate_limited. */
function ksms(array $cfg): void { kernel_state(function ($s) use ($cfg) { $s['sms'] = $cfg; return $s; }); }
function sms_file(): string { return need('FAKE_KERNEL_STATE') . '.sms'; }
function sms_clear(): void { @unlink(sms_file()); }
/** What the stubbed kernel was asked to text (one decoded body per request, with accepted + mode). */
function sms_log(?int $member = null): array
{
    $f = sms_file();
    $rows = is_file($f) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : [];
    return $member === null ? $rows : array_values(array_filter($rows, fn ($r) => (int) $r['member_id'] === $member));
}
function mail_file(): string { return need('FAKE_MALUMAIL_LOG'); }
function mail_clear(): void { file_put_contents(mail_file(), ''); }
/** What the MaluMail stub was asked to send: {from, to, subject, text, html}. */
function mail_log(?string $to = null): array
{
    $rows = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents(mail_file()))));
    return $to === null ? array_values($rows) : array_values(array_filter($rows, fn ($r) => $r['to'] === $to));
}

/** Run the worker (the environment is inherited). Answers its decoded report, or ['raw' => output] when it printed something else. */
function worker(string $args = '', string $env = ''): array
{
    $out = trim((string) shell_exec($env . ' php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/notifications.php') . ' ' . $args . ' 2>/dev/null'));
    $j = json_decode(strrchr("\n" . $out, "\n") ?: '', true);
    return is_array($j) ? $j : ['raw' => $out];
}
/** Outbox rows, by reference (and channel). */
function box(string $ref, ?string $channel = null): array
{
    return q('SELECT * FROM notification_outbox WHERE reference = :r' . ($channel ? ' AND channel = :c' : '') . ' ORDER BY id', ['r' => $ref] + ($channel ? ['c' => $channel] : []));
}
function box_of(int $member, ?string $kind = null): array
{
    return q('SELECT * FROM notification_outbox WHERE member_id = :m' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id', ['m' => $member] + ($kind ? ['k' => $kind] : []));
}
/** Text a notice must never carry: the fixture wages, every phone, and every address but the recipient's own. */
function notice_leaks(string $body, int $recipient): array
{
    $marks = wage_marks();
    foreach (q('SELECT id, email, phone FROM members WHERE phone IS NOT NULL') as $m) { $marks[] = $m['phone']; if ((int) $m['id'] !== $recipient) { $marks[] = $m['email']; } }
    $body = strip_ts($body);
    return array_values(array_filter($marks, fn ($w) => $w !== null && $w !== '' && str_contains($body, (string) $w)));
}
/** The people who work at a restaurant (active humans with a role there). */
function staff_at(int $site): array { return array_map('intval', array_column(q("SELECT DISTINCT r.member_id FROM member_site_roles r JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.member_kind = 'human' WHERE r.scope_id = :s ORDER BY 1", ['s' => $site]), 'member_id')); }
/** A shift (published) starting $h hours from now for a person: the note marks it this proof's. */
function shift_in(int $site, int $pos, int $member, float $h, float $len = 3): int
{
    // The fixture's own long shifts sit at fixed offsets from when the scratch database was made, so which hour of the day collides with a proof's shift changes with the clock: cancel any that would overlap
    // (a cancelled shift no longer counts; nothing is deleted) rather than let a proof pass or fail by the time it runs.
    $a = floor((time() + $h * 3600) / 900) * 900;
    admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE assignee_member_id = $member AND status = 'scheduled' AND tstzrange(starts_at, ends_at) && tstzrange(to_timestamp($a), to_timestamp(" . ($a + (int) ($len * 3600)) . "))");
    return mkshift($site, $pos, $member, $h, $len, ['note' => 'SMOKE s6 ' . run_id()]);
}
/** Active humans holding a right at a restaurant, as the application decides it (the same join the worker uses). */
function staff_with(string $right, int $site): array
{
    return array_map('intval', array_column(q("SELECT DISTINCT r.member_id FROM member_site_roles r JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'
        JOIN ts_role_rights rr ON rr.role_key = ANY (r.roles) AND rr.right_key = :r WHERE r.scope_id = :s ORDER BY 1", ['r' => $right, 's' => $site]), 'member_id'));
}
