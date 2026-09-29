<?php
declare(strict_types=1);

/**
 * The dashboard's reads (screen `dashboard`) — through the mcp_* views only, so the site rule is the database's:
 * my next shift with who else is on, my week, what waits for me (trades asked of me, trades waiting for my decision), announcements.
 */
require_once dirname(__DIR__) . '/shifts/queries.php';
require_once dirname(__DIR__) . '/exchanges/queries.php';

/** The member's next published shift that has not ended, with `others` and its zone, or null. */
function home_next_shift(PDO $pdo, int $memberId): ?array
{
    foreach (find_my_shifts($pdo, $memberId, gmdate('Y-m-d H:i:s+00', time() - 6 * 3600), gmdate('Y-m-d H:i:s+00', time() + 90 * 86400), 8) as $s) {
        if (strtotime((string) $s['ends_at']) > time()) {
            return $s;
        }
    }
    return null;
}

/** The member's published shifts at a site over the next seven days, soonest first. */
function home_my_week(PDO $pdo, int $memberId, int $siteId): array
{
    $rows = find_my_shifts($pdo, $memberId, gmdate('Y-m-d H:i:s+00'), gmdate('Y-m-d H:i:s+00', time() + 7 * 86400));
    return array_values(array_filter($rows, static fn (array $s): bool => $s['site_id'] === $siteId));
}

/** What waits for the member: trades asked of them, coverage they were invited to, and — for someone who decides — trades waiting. [{kind, text, when, href}] */
function home_waiting(PDO $pdo, int $memberId, ?int $siteId = null): array
{
    $items = [];
    $st = $pdo->prepare("SELECT exchange_id, kind, shift_starts_at, from_name, site_id FROM mcp_exchanges
                          WHERE to_member_id = :m AND kind IN ('give', 'swap') AND status = 'pending_acceptance' AND expires_at > now() ORDER BY created_at");
    $st->execute(['m' => $memberId]);
    foreach ($st->fetchAll() as $x) {
        $items[] = ['kind' => 'trade', 'text' => $x['from_name'] . ' asked you to ' . ($x['kind'] === 'swap' ? 'swap a shift' : 'take a shift'), 'when' => $x['shift_starts_at'], 'href' => '/exchanges/' . (int) $x['exchange_id']];
    }
    $st = $pdo->prepare("SELECT x.exchange_id, x.shift_starts_at FROM mcp_exchanges x
                          WHERE x.kind = 'coverage' AND x.status = 'open' AND x.expires_at > now()
                            AND EXISTS (SELECT 1 FROM exchange_invitees i WHERE i.exchange_id = x.exchange_id AND i.member_id = :m) ORDER BY x.created_at");
    $st->execute(['m' => $memberId]);
    foreach ($st->fetchAll() as $x) {
        $items[] = ['kind' => 'coverage', 'text' => 'You were asked to cover a shift', 'when' => $x['shift_starts_at'], 'href' => '/exchanges/' . (int) $x['exchange_id']];
    }
    if ($siteId !== null && ($n = count_approvals($pdo, $siteId, $memberId)) > 0) {
        $items[] = ['kind' => 'approvals', 'text' => $n . ($n === 1 ? ' request waits' : ' requests wait') . ' for your decision', 'when' => null, 'href' => '/approvals'];
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
