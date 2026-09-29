<?php
declare(strict_types=1);

/**
 * The outbox's front door (slice 6, docs/build-specs/announcements-notifications.md): every slice queues a notice through notify(), one row a channel the person has on and the kind allows;
 * the worker (bin/notifications.php) empties it. A notice carries the FACTS of the thing and a link — never another person's pay, phone or email. Nothing is queued for someone who is not an
 * active human (an agent reads through its tools; a person who left is not told), and a kind the person turned off is not queued at all.
 */

/** The kinds of notice a person may switch on or off, with the words the settings screen uses. The database's CHECK on notification_outbox.kind is the same list. */
const NOTICE_KINDS = [
    'schedule_published' => 'My schedule is published',
    'shift_changed' => 'One of my shifts changes',
    'exchange' => 'Trades and coverage requests',
    'request_decided' => 'A request is decided',
    'announcement' => 'Announcements',
    'reminder' => 'Shift reminders and certification warnings',
];

/** A person's first name for a notice ("Ana Quill" → "Ana"): another person's name in a notice is the first name only. $fallback when there is none. */
function first_name(?string $displayName, string $fallback = 'A colleague'): string
{
    $first = trim(explode(' ', trim((string) $displayName))[0] ?? '');
    return $first === '' ? $fallback : $first;
}

/** A PostgreSQL text[] as it comes over PDO ("{a,b}") → list of strings. */
function pg_text_array(?string $text): array
{
    $text = trim((string) $text, '{}');
    return $text === '' ? [] : array_map(static fn (string $s): string => trim($s, '"'), explode(',', $text));
}

/**
 * Queue a notice for one person on the channels they chose (email and text both on unless they turned one off). $dedupe, when given, is suffixed with the channel and is unique in the
 * database: the same notice is never queued twice. Answers how many rows were queued (0 = the kind is off, both channels are off, the person is not active, or it was already queued).
 */
function notify(PDO $pdo, int $memberId, int $siteId, string $kind, string $subject, string $body, ?string $reference = null, ?string $dedupe = null): int
{
    if (!isset(NOTICE_KINDS[$kind])) {
        throw new InvalidArgumentException('Unknown notice kind.');
    }
    $st = $pdo->prepare("SELECT m.status, m.member_kind, p.by_email, p.by_sms, p.kinds
                           FROM members m LEFT JOIN notification_prefs p ON p.member_id = m.id WHERE m.id = :m");
    $st->execute(['m' => $memberId]);
    $p = $st->fetch();
    if ($p === false || $p['status'] !== 'active' || $p['member_kind'] !== 'human') {
        return 0;
    }
    $email = $p['by_email'] === null ? true : (bool) $p['by_email'];
    $sms = $p['by_sms'] === null ? true : (bool) $p['by_sms'];
    if ($p['kinds'] !== null && !in_array($kind, pg_text_array((string) $p['kinds']), true)) {
        return 0;                                                   // the person turned this kind off
    }
    $ins = $pdo->prepare('INSERT INTO notification_outbox (member_id, scope_id, channel, kind, subject, body, reference, dedupe_key)
                          VALUES (:m, :s, :c, :k, :sub, :b, :r, :d) ON CONFLICT DO NOTHING');
    $queued = 0;
    foreach (array_filter(['email' => $email, 'sms' => $sms]) as $channel => $_) {
        $ins->execute(['m' => $memberId, 's' => $siteId, 'c' => $channel, 'k' => $kind, 'sub' => $channel === 'email' ? mb_substr($subject, 0, 200) : null,
                       'b' => mb_substr($body, 0, 3900), 'r' => $reference, 'd' => $dedupe === null ? null : $dedupe . ':' . $channel]);
        $queued += $ins->rowCount();
    }
    return $queued;
}

/** The oldest queued rows whose time has come, with what the sender needs of the person (the address stays here — it never reaches a log or a page). */
function queued_batch(PDO $pdo, int $limit = 100): array
{
    $st = $pdo->prepare("SELECT o.id, o.member_id, o.scope_id, o.channel, o.kind, o.subject, o.body, o.reference, o.attempts, m.email, m.display_name, m.status AS member_status
                           FROM notification_outbox o JOIN members m ON m.id = o.member_id
                          WHERE o.status = 'queued' AND o.not_before <= now() ORDER BY o.id LIMIT :n");
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function mark_sent(PDO $pdo, int $id, ?int $kernelId): void
{
    $pdo->prepare("UPDATE notification_outbox SET status = 'sent', sent_at = now(), attempts = attempts + 1, kernel_notification_id = :k, error = NULL WHERE id = :i")
        ->execute(['k' => $kernelId, 'i' => $id]);
}

function mark_skipped(PDO $pdo, int $id, string $why): void
{
    $pdo->prepare("UPDATE notification_outbox SET status = 'skipped', error = :e WHERE id = :i")->execute(['e' => mb_substr($why, 0, 200), 'i' => $id]);
}

function mark_failed(PDO $pdo, int $id, string $error): void
{
    $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = attempts + 1, error = :e WHERE id = :i")->execute(['e' => mb_substr($error, 0, 200), 'i' => $id]);
}

/** A try that did not go through: back on the queue a minute later, and failed for good at the fifth attempt. Answers the row's status now. */
function mark_retry(PDO $pdo, int $id, string $error, int $attempts): string
{
    if ($attempts + 1 >= 5) {
        mark_failed($pdo, $id, $error);
        return 'failed';
    }
    $pdo->prepare("UPDATE notification_outbox SET attempts = attempts + 1, error = :e, not_before = now() + interval '1 minute' WHERE id = :i")
        ->execute(['e' => mb_substr($error, 0, 200), 'i' => $id]);
    return 'queued';
}
