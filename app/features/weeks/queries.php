<?php
declare(strict_types=1);

/**
 * The week builder's reads (slice 2: docs/build-specs/week-builder.md). Everything through the mcp_* views (drafts and cancelled shifts
 * included — they are the managers'), plus the three gated functions the views cannot be (ts_week_warnings, ts_staffing_needs,
 * ts_check_assignment). Times come back in UTC; the caller shows them in the site's zone. `cost` is read only where the view gives it
 * (labor.view at the site) and is never passed on to a person who does not hold that right.
 */

/** The site a week belongs to — the one base-table read: a write derives its site from the record, never the session. */
function week_site_id(PDO $pdo, int $weekId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM schedule_weeks WHERE id = :id');
    $st->execute(['id' => $weekId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** A week's row by its id (base table: the handler has already been allowed to act at its site). */
function week_row(PDO $pdo, int $weekId, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT w.id AS week_id, w.scope_id AS site_id, w.week_start::text AS week_start, w.status, w.published_at, w.published_by,
                                st.timezone, st.name AS site_name, ss.week_start AS week_dow
                           FROM schedule_weeks w JOIN sites st ON st.scope_id = w.scope_id JOIN site_settings ss ON ss.scope_id = w.scope_id
                          WHERE w.id = :id' . ($lock ? ' FOR UPDATE OF w' : ''));
    $st->execute(['id' => $weekId]);
    $row = $st->fetch();
    if ($row === false) {
        return null;
    }
    $row['week_id'] = (int) $row['week_id'];
    $row['site_id'] = (int) $row['site_id'];
    $row['week_dow'] = (int) $row['week_dow'];
    return $row;
}

/** The week starting on a date at a site (through the view: a draft only for a builder), or null. */
function find_week(PDO $pdo, int $siteId, string $weekStart): ?array
{
    $st = $pdo->prepare('SELECT week_id, site_id, week_start::text AS week_start, status, published_at, published_by FROM mcp_schedule_weeks WHERE site_id = :s AND week_start = :w');
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    $row = $st->fetch();
    if ($row === false) {
        return null;
    }
    $row['week_id'] = (int) $row['week_id'];
    $row['site_id'] = (int) $row['site_id'];
    return $row;
}

/** The week starting on a date, made as an empty DRAFT the first time it is needed. Adds `created` (true when this call made it). */
function ensure_week(PDO $pdo, int $siteId, string $weekStart, int $by): array
{
    $ins = $pdo->prepare('INSERT INTO schedule_weeks (scope_id, week_start) VALUES (:s, :w) ON CONFLICT (scope_id, week_start) DO NOTHING RETURNING id');
    $ins->execute(['s' => $siteId, 'w' => $weekStart]);
    $created = $ins->fetchColumn() !== false;
    $st = $pdo->prepare('SELECT id FROM schedule_weeks WHERE scope_id = :s AND week_start = :w');
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    $row = week_row($pdo, (int) $st->fetchColumn());
    $row['created'] = $created;
    return $row;
}

/** A week's shifts — drafts and cancelled ones included — with the site's zone. cost is NULL without labor.view (the view's rule). */
function find_week_shifts(PDO $pdo, int $weekId): array
{
    $st = $pdo->prepare('SELECT s.shift_id, s.site_id, s.week_id, s.position_id, s.position_name, s.position_color, s.starts_at, s.ends_at, s.break_minutes, s.paid_hours,
                                s.assignee_member_id, s.assignee_name, s.is_open, s.status, s.note, s.published_at, s.changed_after_publish_at, s.cancelled_at, s.cancel_reason,
                                s.cost, st.timezone
                           FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id WHERE s.week_id = :w ORDER BY s.starts_at, s.shift_id');
    $st->execute(['w' => $weekId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['shift_id'] = (int) $r['shift_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['position_id'] = (int) $r['position_id'];
        $r['assignee_member_id'] = $r['assignee_member_id'] === null ? null : (int) $r['assignee_member_id'];
        $r['is_open'] = (bool) $r['is_open'];
    }
    unset($r);
    return $rows;
}

/** Hours per person for a week (mcp_hours_weekly): scheduled paid hours against the person's limit and the overtime threshold. Keyed by member id. */
function week_hours(PDO $pdo, int $siteId, string $weekStart): array
{
    $st = $pdo->prepare('SELECT member_id, display_name, shifts, scheduled_hours, max_hours_week, overtime_weekly_hours, over_overtime, near_overtime, over_own_limit
                           FROM mcp_hours_weekly WHERE site_id = :s AND week_start = :w ORDER BY display_name');
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['member_id']] = $r;
    }
    return $out;
}

/** The week's labor against the budget, all areas together (mcp_labor_weekly) — labor.view only; null without it or when there is nothing yet. */
function week_labor(PDO $pdo, int $siteId, string $weekStart): ?array
{
    $st = $pdo->prepare("SELECT scheduled_hours, scheduled_cost, budget_hours, budget_amount FROM mcp_labor_weekly WHERE site_id = :s AND week_start = :w AND area = 'all'");
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Recommended against scheduled headcount per day-part and position (ts_staffing_needs; rows only when the site has a forecast set up). */
function week_needs(PDO $pdo, int $siteId, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT on_date::text AS on_date, day_part_id, day_part, position_id, position_name, expected_covers, recommended, scheduled, open_shifts FROM ts_staffing_needs(:s, :a, :b)');
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    return $st->fetchAll();
}

/** Every warning a week has (ts_week_warnings), each with the person and the rule's sentence. */
function week_warnings(PDO $pdo, int $weekId): array
{
    $st = $pdo->prepare('SELECT shift_id, member_id, display_name, rule_key, severity, message FROM ts_week_warnings(:w)');
    $st->execute(['w' => $weekId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['shift_id'] = (int) $r['shift_id'];
        $r['member_id'] = (int) $r['member_id'];
    }
    unset($r);
    return $rows;
}

/** What the rules say about giving this person this shift (ts_check_assignment; nothing for a caller with no right to ask). Rows: rule_key, severity, message. */
function check_assignment(PDO $pdo, int $memberId, int $siteId, int $positionId, string $startsUtc, string $endsUtc, int $break, ?int $ignoreShift = null): array
{
    $st = $pdo->prepare('SELECT rule_key, severity, message FROM ts_check_assignment(:m, :s, :p, :a, :b, :br, :ig)');
    $st->execute(['m' => $memberId, 's' => $siteId, 'p' => $positionId, 'a' => $startsUtc, 'b' => $endsUtc, 'br' => $break, 'ig' => $ignoreShift]);
    return $st->fetchAll();
}

/** The people who work at a restaurant and are on the schedule (a role there, active): member_id, display_name. */
function find_schedulable_people(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT member_id, display_name, main_site_id FROM mcp_members
                          WHERE member_kind = 'human' AND COALESCE(on_schedule, true) AND :s = ANY (site_ids) ORDER BY display_name, member_id");
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
    }
    unset($r);
    return $rows;
}

/** The position ids a person works at a restaurant (mcp_staff_positions). */
function find_member_positions(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT member_id, position_id FROM mcp_staff_positions WHERE site_id = :s');
    $st->execute(['s' => $siteId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['member_id']][] = (int) $r['position_id'];
    }
    return $out;
}

/** The overrides already recorded on a week's shifts, newest first (mcp_rule_overrides) — for the publish page. */
function week_overrides(PDO $pdo, int $siteId, string $weekStart): array
{
    $st = $pdo->prepare("SELECT o.override_id, o.shift_id, o.member_id, o.rule_key, o.message, o.reason, o.context, o.created_at
                           FROM mcp_rule_overrides o JOIN mcp_shifts s ON s.shift_id = o.shift_id JOIN schedule_weeks w ON w.id = s.week_id
                          WHERE o.site_id = :s AND w.week_start = :w ORDER BY o.created_at DESC, o.override_id DESC");
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    return $st->fetchAll();
}
