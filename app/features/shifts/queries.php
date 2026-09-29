<?php
declare(strict_types=1);

/**
 * Shifts, read for people (slice 1: docs/build-specs/shifts-marketplace.md). Everything through the mcp_* views — the
 * site rule is the database's, tested once per statement. NO query here selects `cost`: this slice shows no pay.
 * Times come back in UTC; the caller shows them in the site's zone (`timezone` rides on every row).
 */

/** The columns every shift read carries (never cost). */
const SHIFT_COLUMNS = 's.shift_id, s.site_id, st.name AS site_name, st.timezone, s.position_id, s.position_name, s.position_color,
    s.starts_at, s.ends_at, s.break_minutes, s.paid_hours, s.assignee_member_id, s.assignee_name, s.is_open, s.status, s.note,
    s.published_at, s.changed_after_publish_at, s.cancelled_at, s.cancel_reason';

/** The live exchange on a shift, if any (one at a time — db/010's unique index) and the names of who else is on. */
const SHIFT_JOINS = "JOIN mcp_sites st ON st.site_id = s.site_id
    LEFT JOIN LATERAL (SELECT x.exchange_id, x.kind AS ex_kind, x.status AS ex_status, x.to_name AS ex_to_name, x.from_name AS ex_from_name
                         FROM mcp_exchanges x WHERE x.shift_id = s.shift_id AND x.status IN ('open', 'pending_acceptance', 'pending_approval')
                         ORDER BY x.exchange_id DESC LIMIT 1) lx ON true";
const SHIFT_LIVE = 'lx.exchange_id, lx.ex_kind, lx.ex_status, lx.ex_to_name, lx.ex_from_name';
const SHIFT_OTHERS = "(SELECT COALESCE(json_agg(o.assignee_name ORDER BY o.starts_at, o.assignee_name), '[]'::json)
                         FROM mcp_shifts o
                        WHERE o.site_id = s.site_id AND o.shift_id <> s.shift_id AND o.status = 'scheduled' AND o.published_at IS NOT NULL
                          AND o.assignee_member_id IS NOT NULL AND o.assignee_member_id IS DISTINCT FROM s.assignee_member_id
                          AND tstzrange(o.starts_at, o.ends_at) && tstzrange(s.starts_at, s.ends_at)) AS others";

function decode_shift_rows(array $rows): array
{
    foreach ($rows as &$r) {
        $r['shift_id'] = (int) $r['shift_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['others'] = json_decode((string) ($r['others'] ?? '[]'), true) ?: [];
        $r['exchange_id'] = isset($r['exchange_id']) ? (int) $r['exchange_id'] : null;
    }
    unset($r);
    return $rows;
}

/** The member's own published, scheduled shifts starting in [$from, $to) (UTC timestamps), soonest first, each with `others`. */
function find_my_shifts(PDO $pdo, int $memberId, string $from, string $to, int $limit = 0): array
{
    $st = $pdo->prepare('SELECT ' . SHIFT_COLUMNS . ', ' . SHIFT_LIVE . ', ' . SHIFT_OTHERS . '
                           FROM mcp_shifts s ' . SHIFT_JOINS . "
                          WHERE s.assignee_member_id = :m AND s.published_at IS NOT NULL AND s.status = 'scheduled'
                            AND s.starts_at >= :from AND s.starts_at < :to
                          ORDER BY s.starts_at, s.shift_id" . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    $st->execute(['m' => $memberId, 'from' => $from, 'to' => $to]);
    return decode_shift_rows($st->fetchAll());
}

/** The member's next shift after $after (UTC), or null — for the empty week's "next: …" line. */
function find_next_shift_after(PDO $pdo, int $memberId, string $after): ?array
{
    $st = $pdo->prepare('SELECT ' . SHIFT_COLUMNS . ', ' . SHIFT_LIVE . '
                           FROM mcp_shifts s ' . SHIFT_JOINS . "
                          WHERE s.assignee_member_id = :m AND s.published_at IS NOT NULL AND s.status = 'scheduled' AND s.starts_at >= :after
                          ORDER BY s.starts_at LIMIT 1");
    $st->execute(['m' => $memberId, 'after' => $after]);
    $row = $st->fetch();
    return $row === false ? null : decode_shift_rows([$row])[0];
}

/** The published week (starting on the site's local date $weekStart) at a site — no cost, no phone: who works and which shifts are open. */
function find_team_schedule(PDO $pdo, int $siteId, string $weekStart, ?int $positionId = null): array
{
    $sql = 'SELECT ' . SHIFT_COLUMNS . ', ' . SHIFT_LIVE . '
              FROM mcp_shifts s ' . SHIFT_JOINS . "
             WHERE s.site_id = :site AND s.published_at IS NOT NULL AND s.status = 'scheduled'
               AND s.starts_at >= (CAST(:ws1 AS date)::timestamp AT TIME ZONE st.timezone)
               AND s.starts_at <  ((CAST(:ws2 AS date) + 7)::timestamp AT TIME ZONE st.timezone)"
        . ($positionId !== null ? ' AND s.position_id = :pos' : '') . ' ORDER BY s.starts_at, s.position_name, s.assignee_name NULLS LAST, s.shift_id';
    $args = ['site' => $siteId, 'ws1' => $weekStart, 'ws2' => $weekStart];
    if ($positionId !== null) {
        $args['pos'] = $positionId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return decode_shift_rows($st->fetchAll());
}

/** The positions worked in a published week at a site (for the team schedule's filter). */
function find_site_positions(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT position_id, name, color FROM mcp_positions WHERE site_id = :s AND archived_at IS NULL ORDER BY sort_order, name');
    $st->execute(['s' => $siteId]);
    return $st->fetchAll();
}

/** One shift the caller may see (published at a held site, or theirs, or a builder's), with its live exchange; null otherwise. */
function find_shift(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . SHIFT_COLUMNS . ', ' . SHIFT_LIVE . ', ' . SHIFT_OTHERS . '
                           FROM mcp_shifts s ' . SHIFT_JOINS . ' WHERE s.shift_id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : decode_shift_rows([$row])[0];
}

/** The site a shift belongs to — the one base-table read: a write derives its site from the record (never the session). */
function shift_site_id(PDO $pdo, int $shiftId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM shifts WHERE id = :id');
    $st->execute(['id' => $shiftId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** What happened to a shift, newest first (mcp_activity_log: the caller's own rows, and every row where they build). */
function shift_history(PDO $pdo, int $id, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT activity_id, occurred_at, actor_member_id, actor_name, source, action, entity_type, entity_id, before, after
                           FROM mcp_activity_log
                          WHERE action <> 'screen.view'
                            AND ((entity_type = 'shift' AND entity_id = :id1) OR (entity_type = 'exchange' AND (after->>'shift_id') = :id2))
                          ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(['id1' => $id, 'id2' => (string) $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['after'] = $r['after'] === null ? [] : (json_decode((string) $r['after'], true) ?: []);
        $r['before'] = $r['before'] === null ? [] : (json_decode((string) $r['before'], true) ?: []);
    }
    unset($r);
    return $rows;
}

/** Colleagues who could take a shift as a gift or swap: at the shift's restaurant (their main), on the schedule, holding the position. */
function find_give_candidates(PDO $pdo, int $shiftId, int $memberId): array
{
    $st = $pdo->prepare("SELECT DISTINCT m.member_id, m.display_name
                           FROM mcp_shifts s
                           JOIN mcp_staff_positions p ON p.position_id = s.position_id AND p.site_id = s.site_id
                           JOIN mcp_members m ON m.member_id = p.member_id
                          WHERE s.shift_id = :s AND m.member_id <> :me AND m.main_site_id = s.site_id AND m.on_schedule AND m.member_kind = 'human'
                          ORDER BY m.display_name");
    $st->execute(['s' => $shiftId, 'me' => $memberId]);
    return $st->fetchAll();
}

/** A colleague's published shifts at a site still far enough off to swap for (the cutoff is the database's word at the POST). */
function find_swap_candidates(PDO $pdo, int $siteId, int $colleagueId): array
{
    $st = $pdo->prepare('SELECT ' . SHIFT_COLUMNS . '
                           FROM mcp_shifts s ' . "JOIN mcp_sites st ON st.site_id = s.site_id
                          WHERE s.site_id = :site AND s.assignee_member_id = :c AND s.published_at IS NOT NULL AND s.status = 'scheduled'
                            AND s.starts_at > now() ORDER BY s.starts_at LIMIT 30");
    $st->execute(['site' => $siteId, 'c' => $colleagueId]);
    return decode_shift_rows($st->fetchAll());
}

/** Open (unassigned), upcoming published shifts at a site — what a shift lead may cover or open up. */
function find_open_shifts(PDO $pdo, int $siteId, int $limit = 40): array
{
    $st = $pdo->prepare('SELECT ' . SHIFT_COLUMNS . ', ' . SHIFT_LIVE . '
                           FROM mcp_shifts s ' . SHIFT_JOINS . " WHERE s.site_id = :site AND s.is_open AND s.published_at IS NOT NULL
                            AND s.status = 'scheduled' AND s.starts_at > now() ORDER BY s.starts_at LIMIT " . max(1, min(100, $limit)));
    $st->execute(['site' => $siteId]);
    return decode_shift_rows($st->fetchAll());
}

/** A restaurant the caller holds, with its trade settings (mcp_sites): week start, allow_*, approval_*, cutoff, claim mode. */
function find_site_row(PDO $pdo, int $siteId): ?array
{
    $st = $pdo->prepare('SELECT site_id, name, timezone, currency, week_start, allow_offer, allow_pickup, allow_swap, allow_give, approval_pickup, approval_swap, approval_give,
                                cutoff_minutes, shift_lead_approves_same_day, claim_mode, offer_expires, my_role, time_off_day_hours FROM mcp_sites WHERE site_id = :s');
    $st->execute(['s' => $siteId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
