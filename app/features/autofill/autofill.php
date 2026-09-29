<?php
declare(strict_types=1);

/**
 * Auto-fill (`week_autofill`, slice 2): for each OPEN shift of a DRAFT week — earliest first — pick from the restaurant's own staff (main restaurant) who hold
 * the position, are active, are free, break no hard rule, and are neither on approved time off nor marked unavailable. Among those it prefers a person whose
 * approved availability says PREFERRED then, then one with no soft warning, then the fewest hours that week, then the name (then the id): deterministic — the
 * same week gives the same result. It writes a DRAFT (`shift.assign` rows, `after.via = 'autofill'`); nothing is published or overridden, so a shift filled with a soft
 * warning still asks for its reason at publishing. Answers what it did: filled, still open (with why), warnings left, hours per person.
 */

/** The hours a person already has in a week (paid, scheduled), drafts included. */
function member_week_hours(PDO $pdo, int $weekId, int $memberId): float
{
    $st = $pdo->prepare("SELECT COALESCE(sum(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 3600 - break_minutes / 60.0), 0) FROM shifts WHERE week_id = :w AND assignee_member_id = :m AND status = 'scheduled'");
    $st->execute(['w' => $weekId, 'm' => $memberId]);
    return (float) $st->fetchColumn();
}

function autofill_week(PDO $pdo, int $weekId, ?int $templateId, int $by): array
{
    $week = week_row($pdo, $weekId, true) ?? throw new DomainException('Week not found.');
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is published.');
    }
    $siteId = $week['site_id'];
    $tzName = (string) $week['timezone'];
    $tz = new DateTimeZone($tzName);
    // Seed: a template the caller named; else, when the week is empty, the last published week before it.
    $seeded = null;
    $count = $pdo->prepare("SELECT count(*) FROM shifts WHERE week_id = :w AND status = 'scheduled'");
    $count->execute(['w' => $weekId]);
    if ($templateId !== null) {
        $r = apply_template($pdo, $templateId, $weekId, $by);
        $seeded = ['from' => 'template', 'template_id' => $templateId, 'placed' => $r['placed'], 'left_open' => count($r['left_open'])];
    } elseif ((int) $count->fetchColumn() === 0) {
        $last = $pdo->prepare("SELECT id FROM schedule_weeks WHERE scope_id = :s AND status = 'published' AND week_start < :w ORDER BY week_start DESC LIMIT 1");
        $last->execute(['s' => $siteId, 'w' => $week['week_start']]);
        $from = $last->fetchColumn();
        if ($from !== false) {
            $r = copy_week($pdo, (int) $from, $weekId, $by);
            $seeded = ['from' => 'last_published_week', 'week_id' => (int) $from, 'placed' => $r['placed'], 'left_open' => count($r['left_open'])];
        }
    }
    $pool = $pdo->prepare("SELECT sp.member_id, m.display_name FROM staff_profiles sp JOIN members m ON m.id = sp.member_id
                            WHERE sp.main_scope_id = :s AND sp.active AND m.status = 'active' AND m.member_kind = 'human' ORDER BY lower(m.display_name), sp.member_id");
    $pool->execute(['s' => $siteId]);
    $people = $pool->fetchAll();
    $holds = $pdo->prepare('SELECT position_id FROM staff_positions WHERE member_id = :m');
    $held = [];
    foreach ($people as $p) {
        $holds->execute(['m' => $p['member_id']]);
        $held[(int) $p['member_id']] = array_map('intval', $holds->fetchAll(PDO::FETCH_COLUMN));
    }
    $open = $pdo->prepare("SELECT s.id, s.position_id, s.starts_at, s.ends_at, s.break_minutes, p.name AS position_name FROM shifts s JOIN positions p ON p.id = s.position_id
                            WHERE s.week_id = :w AND s.status = 'scheduled' AND s.assignee_member_id IS NULL ORDER BY s.starts_at, s.id");
    $open->execute(['w' => $weekId]);
    $filled = [];
    $still = [];
    $warnings = 0;
    foreach ($open->fetchAll() as $sh) {
        $start = local_from_db((string) $sh['starts_at'], $tz);
        $end = local_from_db((string) $sh['ends_at'], $tz);
        $cands = [];
        foreach ($people as $p) {
            $mid = (int) $p['member_id'];
            if (!in_array((int) $sh['position_id'], $held[$mid], true)) {
                continue;
            }
            $b = assignment_blocker($pdo, $siteId, $mid, (int) $sh['position_id'], $start, $end, (int) $sh['break_minutes'], $tzName);
            if ($b['reason'] !== null || availability_overlaps($pdo, $mid, $siteId, 'unavailable', $start, $end, $tzName)) {
                continue;
            }
            $cands[] = ['member_id' => $mid, 'name' => (string) $p['display_name'], 'soft' => $b['soft'],
                        'preferred' => availability_overlaps($pdo, $mid, $siteId, 'preferred', $start, $end, $tzName) ? 0 : 1,
                        'hours' => member_week_hours($pdo, $weekId, $mid)];
        }
        usort($cands, static fn (array $x, array $y): int => [$x['preferred'], count($x['soft']), $x['hours'], mb_strtolower($x['name']), $x['member_id']]
            <=> [$y['preferred'], count($y['soft']), $y['hours'], mb_strtolower($y['name']), $y['member_id']]);
        if ($cands === []) {
            $still[] = ['shift_id' => (int) $sh['id'], 'position' => $sh['position_name'], 'when' => $start->format('D M j') . ' · ' . shift_time_range((string) $sh['starts_at'], (string) $sh['ends_at'], $tzName),
                        'reason' => 'Nobody who works ' . $sh['position_name'] . ' is free and allowed.'];
            continue;
        }
        $pick = $cands[0];
        $pdo->prepare('UPDATE shifts SET assignee_member_id = :m WHERE id = :id')->execute(['m' => $pick['member_id'], 'id' => (int) $sh['id']]);
        log_activity($pdo, 'shift.assign', 'shift', (int) $sh['id'], ['scope_id' => $siteId,
            'before' => ['assignee_member_id' => null, 'assignee_name' => null],
            'after' => ['assignee_member_id' => $pick['member_id'], 'assignee_name' => $pick['name'], 'via' => 'autofill', 'week_id' => $weekId]]);
        $warnings += count($pick['soft']);
        $filled[] = ['shift_id' => (int) $sh['id'], 'member_id' => $pick['member_id'], 'name' => $pick['name'], 'position' => $sh['position_name'],
                     'when' => $start->format('D M j') . ' · ' . shift_time_range((string) $sh['starts_at'], (string) $sh['ends_at'], $tzName),
                     'warnings' => array_column($pick['soft'], 'message')];
    }
    $hours = [];
    $h = $pdo->prepare("SELECT m.id AS member_id, m.display_name, round(sum(EXTRACT(EPOCH FROM (s.ends_at - s.starts_at)) / 3600 - s.break_minutes / 60.0)::numeric, 2) AS hours
                          FROM shifts s JOIN members m ON m.id = s.assignee_member_id WHERE s.week_id = :w AND s.status = 'scheduled' GROUP BY m.id, m.display_name ORDER BY lower(m.display_name), m.id");
    $h->execute(['w' => $weekId]);
    foreach ($h->fetchAll() as $r) {
        $hours[] = ['member_id' => (int) $r['member_id'], 'name' => $r['display_name'], 'hours' => (float) $r['hours']];
    }
    return ['filled' => $filled, 'still_open' => $still, 'warnings' => $warnings, 'hours' => $hours, 'seeded' => $seeded];
}
