<?php
declare(strict_types=1);

/**
 * The private calendar feed (slice 6, FR-M3): a URL with a 48-hex token; only its SHA-256 is kept; a new link replaces the old (which then answers 404); the feed carries the person's OWN published
 * shifts for the next 60 days — a title (position — restaurant), the time in UTC, the restaurant's name as the location, a link. No other person's name, no pay, no note.
 */

function feed_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Make (or replace) the person's link. Answers the token — the one and only time it exists in the clear. */
function rotate_feed(PDO $pdo, int $memberId): string
{
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO calendar_feeds (member_id, token_hash) VALUES (:m, :h)
                   ON CONFLICT (member_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now(), last_used_at = NULL, revoked_at = NULL')
        ->execute(['m' => $memberId, 'h' => feed_hash($token)]);
    return $token;
}

/** Has the person a live link? Answers ['made' => date] or null. */
function find_feed(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT created_at, last_used_at FROM calendar_feeds WHERE member_id = :m AND revoked_at IS NULL');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    return $r === false ? null : ['made' => $r['created_at'], 'last_used' => $r['last_used_at']];
}

/** The person a token belongs to (active, link not revoked), or null — a wrong token and a replaced one look the same. */
function feed_for_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare("SELECT f.member_id, m.display_name FROM calendar_feeds f JOIN members m ON m.id = f.member_id AND m.status = 'active'
                          WHERE f.token_hash = :h AND f.revoked_at IS NULL");
    $st->execute(['h' => feed_hash($token)]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $pdo->prepare('UPDATE calendar_feeds SET last_used_at = now() WHERE member_id = :m')->execute(['m' => (int) $r['member_id']]);
    return ['member_id' => (int) $r['member_id'], 'name' => $r['display_name']];
}

/** RFC 5545 text escaping. */
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
}

/** A content line folded at 75 octets (continuation lines start with one space), CRLF-terminated. */
function ics_line(string $line): string
{
    $out = '';
    $max = 75;
    while (strlen($line) > $max) {
        $cut = $max;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;                                                 // never split a UTF-8 character
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $max = 74;                                                  // a continuation line spends one octet on its leading space
    }
    return $out . $line . "\r\n";
}

/** The person's own published, scheduled shifts from now to 60 days ahead, soonest first (position, restaurant name, times). */
function feed_shifts(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT s.id, s.starts_at, s.ends_at, s.updated_at, p.name AS position_name, st.name AS site_name
                           FROM shifts s JOIN schedule_weeks w ON w.id = s.week_id AND w.status = 'published'
                           JOIN positions p ON p.id = s.position_id JOIN sites st ON st.scope_id = s.scope_id AND st.removed_at IS NULL
                          WHERE s.assignee_member_id = :m AND s.status = 'scheduled' AND s.published_at IS NOT NULL
                            AND s.ends_at >= now() AND s.starts_at < now() + interval '60 days' ORDER BY s.starts_at, s.id");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

function ics_utc(string $ts): string
{
    return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

/** The calendar as text (CRLF lines). */
function ics_for(PDO $pdo, int $memberId): string
{
    $out = ics_line('BEGIN:VCALENDAR') . ics_line('VERSION:2.0') . ics_line('PRODID:-//txtSchedules//My shifts//EN') . ics_line('CALSCALE:GREGORIAN') . ics_line('METHOD:PUBLISH')
        . ics_line('X-WR-CALNAME:' . ics_text('My shifts')) . ics_line('REFRESH-INTERVAL;VALUE=DURATION:PT1H') . ics_line('X-PUBLISHED-TTL:PT1H');
    $stamp = gmdate('Ymd\THis\Z');
    foreach (feed_shifts($pdo, $memberId) as $s) {
        $out .= ics_line('BEGIN:VEVENT') . ics_line('UID:shift-' . (int) $s['id'] . '@txtschedules') . ics_line('DTSTAMP:' . $stamp)
            . ics_line('DTSTART:' . ics_utc((string) $s['starts_at'])) . ics_line('DTEND:' . ics_utc((string) $s['ends_at']))
            . ics_line('LAST-MODIFIED:' . ics_utc((string) $s['updated_at']))
            . ics_line('SUMMARY:' . ics_text($s['position_name'] . ' — ' . $s['site_name'])) . ics_line('LOCATION:' . ics_text((string) $s['site_name']))
            . ics_line('URL:' . app_url('/shifts/' . (int) $s['id'])) . ics_line('END:VEVENT');
    }
    return $out . ics_line('END:VCALENDAR');
}
