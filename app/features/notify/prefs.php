<?php
declare(strict_types=1);

/** A person's notification choices (slice 6): channels, which kinds, how long before a shift. A person with no row has the defaults — both channels, every kind, the restaurant's lead time. */

const REMINDER_CHOICES = [30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 180 => '3 hours', 240 => '4 hours', 480 => '8 hours', 720 => '12 hours', 1440 => '1 day', 2880 => '2 days'];

function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT by_email, by_sms, kinds, reminder_minutes FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        return ['by_email' => true, 'by_sms' => true, 'kinds' => array_keys(NOTICE_KINDS), 'reminder_minutes' => null, 'saved' => false];
    }
    return ['by_email' => (bool) $r['by_email'], 'by_sms' => (bool) $r['by_sms'], 'kinds' => pg_text_array((string) $r['kinds']),
            'reminder_minutes' => $r['reminder_minutes'] === null ? null : (int) $r['reminder_minutes'], 'saved' => true];
}

/** Save the choices ($f keys present are changed; absent are kept). Answers ['before' => …, 'after' => …]. */
function save_prefs(PDO $pdo, int $memberId, array $f): array
{
    $before = find_prefs($pdo, $memberId);
    $after = array_intersect_key($f, $before) + $before;
    unset($before['saved'], $after['saved']);
    $pdo->prepare('INSERT INTO notification_prefs (member_id, by_email, by_sms, kinds, reminder_minutes) VALUES (:m, :e, :s, CAST(:k AS text[]), :r)
                   ON CONFLICT (member_id) DO UPDATE SET by_email = EXCLUDED.by_email, by_sms = EXCLUDED.by_sms, kinds = EXCLUDED.kinds, reminder_minutes = EXCLUDED.reminder_minutes, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['by_email'] ? 't' : 'f', 's' => $after['by_sms'] ? 't' : 'f', 'k' => '{' . implode(',', $after['kinds']) . '}', 'r' => $after['reminder_minutes']]);
    return ['before' => $before, 'after' => $after];
}

/** The restaurant's default lead time (minutes) for the person's restaurants — the smallest they hold if there are several. Null when they hold none. */
function default_reminder_minutes(PDO $pdo, int $memberId): ?int
{
    $st = $pdo->prepare('SELECT min(ss.reminder_minutes_before) FROM member_site_roles r JOIN site_settings ss ON ss.scope_id = r.scope_id WHERE r.member_id = :m');
    $st->execute(['m' => $memberId]);
    $v = $st->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

/**
 * What the kernel last told us about this person's texts, from our own outbox (this application never sees a phone number): 'no_verified_phone', 'opted_out', 'no_sender' or null when the
 * latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, error FROM notification_outbox WHERE member_id = :m AND channel = 'sms' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    $e = (string) $r['error'];
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains($e, $code)) {
            return $code;
        }
    }
    return null;
}
