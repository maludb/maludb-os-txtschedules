<?php
declare(strict_types=1);

/**
 * Announcements (slice 6; no staff chat, D7): a manager posts to the whole restaurant, to a position or to named people; each person sees what was posted to them; a poster sees who has read.
 * Reads go through `mcp_announcements` (the caller's audience; read counts only for `announce.post`); writes are base-table statements the handler gates at the announcement's restaurant.
 */

const ANNOUNCE_AUDIENCES = ['site' => 'The whole restaurant', 'position' => 'One position', 'people' => 'Named people'];

/** The restaurant's local date today (a pin runs through its last day). */
function site_today(PDO $pdo, int $siteId): string
{
    $st = $pdo->prepare('SELECT timezone FROM sites WHERE scope_id = :s');
    $st->execute(['s' => $siteId]);
    $tz = (string) ($st->fetchColumn() ?: 'UTC');
    return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
}

/** The live announcements for a restaurant the caller may see, pinned first then newest (mcp_announcements). $memberId is the caller (the view already knows). */
function find_announcements(PDO $pdo, int $siteId, int $memberId, int $limit = 25): array
{
    $today = site_today($pdo, $siteId);
    $st = $pdo->prepare('SELECT announcement_id, site_id, title, body, audience, position_id, pinned_until, posted_by, posted_by_name, created_at, read_by_me, read_count,
                                (pinned_until IS NOT NULL AND pinned_until >= :today) AS pinned
                           FROM mcp_announcements WHERE site_id = :s
                          ORDER BY (pinned_until IS NOT NULL AND pinned_until >= :today2) DESC, created_at DESC, announcement_id DESC LIMIT :n');
    $st->bindValue('today', $today);
    $st->bindValue('today2', $today);
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['announcement_id'] = (int) $r['announcement_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['position_id'] = $r['position_id'] === null ? null : (int) $r['position_id'];
        $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
        $r['read_by_me'] = (bool) $r['read_by_me'];
        $r['pinned'] = (bool) $r['pinned'];
        $r['read_count'] = $r['read_count'] === null ? null : (int) $r['read_count'];
    }
    unset($r);
    return $rows;
}

/** One announcement as the caller may see it (the view), or null — also for one that was removed or is not for them. */
function find_announcement(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT announcement_id, site_id, title, audience, position_id, pinned_until, posted_by, posted_by_name, created_at, read_by_me, read_count FROM mcp_announcements WHERE announcement_id = :i');
    $st->execute(['i' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['announcement_id'] = (int) $r['announcement_id'];
    $r['site_id'] = (int) $r['site_id'];
    $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
    return $r;
}

/** The base row of an announcement — for the poster's or a settings manager's removal (a removed one is null). */
function announcement_base(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, scope_id, title, audience, position_id, posted_by FROM announcements WHERE id = :i AND removed_at IS NULL');
    $st->execute(['i' => $id]);
    $r = $st->fetch();
    return $r === false ? null : ['id' => (int) $r['id'], 'site_id' => (int) $r['scope_id'], 'title' => $r['title'], 'audience' => $r['audience'], 'position_id' => $r['position_id'] === null ? null : (int) $r['position_id'],
        'posted_by' => $r['posted_by'] === null ? null : (int) $r['posted_by']];
}

/** Who has read an announcement: [{member_id, name, read_at}] — the poster's list (the handler asks announce.post first). */
function find_announcement_readers(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT m.id AS member_id, m.display_name AS name, r.read_at FROM announcement_reads r JOIN members m ON m.id = r.member_id WHERE r.announcement_id = :i ORDER BY r.read_at, m.id');
    $st->execute(['i' => $id]);
    return $st->fetchAll();
}

/** The restaurant's staff a notice may reach: active humans holding a role there. [{member_id, name}] by name. */
function find_site_people(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT m.id AS member_id, m.display_name AS name FROM member_site_roles r
                           JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.member_kind = 'human' AND m.capability IS NOT NULL
                          WHERE r.scope_id = :s ORDER BY lower(m.display_name), m.id");
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
    }
    unset($r);
    return $rows;
}

/**
 * Who an announcement reaches — resolved on the server, never trusted from the form: the restaurant's staff (active humans holding a role there), narrowed to a position's holders or to the
 * named people (who must be staff here). The poster is not told their own post. Answers member ids.
 */
function announcement_recipients(PDO $pdo, array $a): array
{
    $site = (int) ($a['scope_id'] ?? $a['site_id']);
    $sql = "SELECT DISTINCT m.id FROM member_site_roles r
              JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.member_kind = 'human' AND m.capability IS NOT NULL
             WHERE r.scope_id = :s";
    $args = ['s' => $site];
    if ($a['audience'] === 'position') {
        $sql .= ' AND EXISTS (SELECT 1 FROM staff_positions sp WHERE sp.member_id = m.id AND sp.position_id = :p)';
        $args['p'] = (int) $a['position_id'];
    } elseif ($a['audience'] === 'people') {
        $sql .= ' AND m.id = ANY (CAST(:ids AS bigint[]))';
        $args['ids'] = '{' . implode(',', array_map('intval', $a['member_ids'] ?? [])) . '}';
    }
    if (!empty($a['posted_by'])) {
        $sql .= ' AND m.id <> :by';
        $args['by'] = (int) $a['posted_by'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY m.id');
    $st->execute($args);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** How the recipients' own choices split: ['people' => n, 'email' => n, 'sms' => n, 'nobody' => n told by neither] for the confirm page. */
function announcement_reach(PDO $pdo, array $memberIds): array
{
    $out = ['people' => count($memberIds), 'email' => 0, 'sms' => 0, 'nobody' => 0];
    if ($memberIds === []) {
        return $out;
    }
    $st = $pdo->prepare("SELECT COALESCE(p.by_email, true) AS e, COALESCE(p.by_sms, true) AS s, (p.kinds IS NULL OR 'announcement' = ANY (p.kinds)) AS on_
                           FROM members m LEFT JOIN notification_prefs p ON p.member_id = m.id WHERE m.id = ANY (CAST(:ids AS bigint[]))");
    $st->execute(['ids' => '{' . implode(',', $memberIds) . '}']);
    foreach ($st->fetchAll() as $r) {
        $e = $r['on_'] && $r['e'];
        $s = $r['on_'] && $r['s'];
        $out['email'] += $e ? 1 : 0;
        $out['sms'] += $s ? 1 : 0;
        $out['nobody'] += ($e || $s) ? 0 : 1;
    }
    return $out;
}

/**
 * Post an announcement: the row, then one notice per recipient and channel (kind `announcement`). $f: site_id, title, body, audience, position_id, member_ids, pinned_until.
 * Answers ['id', 'recipients' => count, 'queued' => rows]. Validation is the handler's; the database's CHECKs are the backstop.
 */
function post_announcement(PDO $pdo, array $f, int $by): array
{
    $ins = $pdo->prepare('INSERT INTO announcements (scope_id, title, body, audience, position_id, member_ids, pinned_until, posted_by)
                          VALUES (:s, :t, :b, :a, :p, CAST(:m AS bigint[]), :pin, :by) RETURNING id');
    $ins->execute(['s' => $f['site_id'], 't' => $f['title'], 'b' => $f['body'], 'a' => $f['audience'], 'p' => $f['audience'] === 'position' ? $f['position_id'] : null,
                   'm' => '{' . ($f['audience'] === 'people' ? implode(',', array_map('intval', $f['member_ids'])) : '') . '}', 'pin' => $f['pinned_until'], 'by' => $by]);
    $id = (int) $ins->fetchColumn();
    $recipients = announcement_recipients($pdo, ['site_id' => $f['site_id'], 'audience' => $f['audience'], 'position_id' => $f['position_id'], 'member_ids' => $f['member_ids'], 'posted_by' => $by]);
    $site = $pdo->prepare('SELECT name FROM sites WHERE scope_id = :s');
    $site->execute(['s' => $f['site_id']]);
    $siteName = (string) $site->fetchColumn();
    $link = app_url('/announcements/?site=' . (int) $f['site_id'] . '#announcement-' . $id);
    $queued = 0;
    foreach ($recipients as $m) {
        $queued += notify($pdo, $m, (int) $f['site_id'], 'announcement', $f['title'], $siteName . ': ' . $f['title'] . "\n\n" . $f['body'] . "\n\n" . $link, 'announcement:' . $id);
    }
    return ['id' => $id, 'recipients' => count($recipients), 'queued' => $queued];
}

function remove_announcement(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('UPDATE announcements SET removed_at = now() WHERE id = :i AND removed_at IS NULL')->execute(['i' => $id]);
}

/** The person has read it — once (a second tap changes nothing). Answers whether this was the first time. */
function mark_read(PDO $pdo, int $id, int $memberId): bool
{
    $st = $pdo->prepare('INSERT INTO announcement_reads (announcement_id, member_id) VALUES (:a, :m) ON CONFLICT DO NOTHING');
    $st->execute(['a' => $id, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

/** How many live announcements at the restaurant the caller has not read (the menu badge and the home line). */
function count_unread_announcements(PDO $pdo, int $siteId): int
{
    $st = $pdo->prepare('SELECT count(*) FROM mcp_announcements WHERE site_id = :s AND NOT read_by_me');
    $st->execute(['s' => $siteId]);
    return (int) $st->fetchColumn();
}
