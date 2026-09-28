<?php
declare(strict_types=1);

/**
 * The dashboard's reads (screen `dashboard`) — through the mcp_* views only, so the site rule is the database's. Empty
 * until slice 1 gives people shifts; written now so the shape of the home screen is fixed (docs/build-specs/sso-shell.md).
 */

/** The member's next published shift, or null. */
function home_next_shift(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare("SELECT shift_id, site_id, position_name, position_color, starts_at, ends_at, break_minutes, paid_hours
                           FROM mcp_shifts
                          WHERE assignee_member_id = :m AND status = 'scheduled' AND published_at IS NOT NULL AND ends_at > now()
                          ORDER BY starts_at LIMIT 1");
    $st->execute(['m' => $memberId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** The member's published shifts at a site over the next seven days, soonest first. */
function home_my_week(PDO $pdo, int $memberId, int $siteId): array
{
    $st = $pdo->prepare("SELECT shift_id, position_name, position_color, starts_at, ends_at, paid_hours
                           FROM mcp_shifts
                          WHERE assignee_member_id = :m AND site_id = :s AND status = 'scheduled' AND published_at IS NOT NULL
                            AND ends_at > now() AND starts_at < now() + interval '7 days'
                          ORDER BY starts_at");
    $st->execute(['m' => $memberId, 's' => $siteId]);
    return $st->fetchAll();
}

/** What waits for the member: trades offered to them to accept, and — for a manager — what waits for approval. */
function home_waiting(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT exchange_id, kind, shift_starts_at, from_name FROM mcp_exchanges
                          WHERE to_member_id = :m AND status = 'pending_acceptance' ORDER BY created_at");
    $st->execute(['m' => $memberId]);
    $items = [];
    foreach ($st->fetchAll() as $x) {
        $items[] = ['kind' => 'trade', 'text' => $x['from_name'] . ' asked you to take a shift', 'when' => $x['shift_starts_at'], 'href' => '/exchanges/' . (int) $x['exchange_id']];
    }
    return $items;
}

/** The live announcements for the site, newest first (a few, for the home screen). */
function home_announcements(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT announcement_id, title, body, posted_by_name, created_at, read_by_me FROM mcp_announcements
                          WHERE site_id = :s AND (pinned_until IS NULL OR pinned_until >= CURRENT_DATE)
                          ORDER BY created_at DESC LIMIT 5");
    $st->execute(['s' => $siteId]);
    return $st->fetchAll();
}
