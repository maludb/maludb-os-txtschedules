<?php
declare(strict_types=1);

/**
 * Publishing a week, and clearing a draft (slice 2). Publishing re-asks the rules server-side (the page is only a summary): a HARD warning stops it, a SOFT one
 * needs the manager's reason and is recorded as one `rule.override` row each BEFORE the week is published; then each person with a shift is told ONCE.
 */

/** week_publish: the DRAFT week goes live. Answers shift_count, people_told, open_left, warnings [{shift_id, member_id, rule_key}], overridden. */
function publish_week(PDO $pdo, int $weekId, int $by, ?string $reason): array
{
    $week = week_row($pdo, $weekId, true) ?? throw new DomainException('Week not found.');
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is already published.');
    }
    $siteId = $week['site_id'];
    $tz = (string) $week['timezone'];
    $sh = $pdo->prepare("SELECT s.id, s.starts_at, s.ends_at, s.assignee_member_id, p.name AS position_name FROM shifts s JOIN positions p ON p.id = s.position_id
                          WHERE s.week_id = :w AND s.status = 'scheduled' ORDER BY s.starts_at, s.id");
    $sh->execute(['w' => $weekId]);
    $shifts = $sh->fetchAll();
    if ($shifts === []) {
        throw new DomainException('There are no shifts to publish.');
    }
    $warnings = week_warnings($pdo, $weekId);
    $hard = array_values(array_filter($warnings, static fn (array $w): bool => $w['severity'] === 'hard'));
    if ($hard !== []) {
        throw new DomainException('Fix this before publishing — ' . $hard[0]['display_name'] . ': ' . $hard[0]['message']);
    }
    $soft = array_values(array_filter($warnings, static fn (array $w): bool => $w['severity'] === 'soft'));
    if ($soft !== []) {
        if ($reason === null) {
            throw new DomainException('This week has ' . count($soft) . ' warning' . (count($soft) === 1 ? '' : 's') . ' (' . $soft[0]['display_name'] . ': ' . $soft[0]['message'] . '). Give a reason (override_reason) to publish anyway.');
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new DomainException('Give a reason of a few words (3 to 500 characters) to publish anyway.');
        }
        $list = array_map(static fn (array $w): array => $w + ['site_id' => $siteId], $soft);
        record_overrides($pdo, $list, $reason, null, $by, 'publish');
    }
    $n = $pdo->prepare('SELECT ts_publish_week(:w, :by)');
    $n->execute(['w' => $weekId, 'by' => $by]);
    $count = (int) $n->fetchColumn();
    // one notice each, listing their shifts
    $mine = [];
    $open = 0;
    foreach ($shifts as $s) {
        if ($s['assignee_member_id'] === null) {
            $open++;
            continue;
        }
        $mine[(int) $s['assignee_member_id']][] = shift_when((string) $s['starts_at'], (string) $s['ends_at'], $tz) . ' ' . $s['position_name'];
    }
    $range = (new DateTimeImmutable($week['week_start']))->format('M j');
    foreach ($mine as $mid => $lines) {
        notify($pdo, $mid, $siteId, 'schedule_published', 'Your schedule for the week of ' . $range . ' is published',
            'The schedule for the week of ' . $range . ' at ' . $week['site_name'] . ' is published. Your shifts: ' . implode('; ', $lines) . '. '
            . app_url('/my-schedule?week=' . $week['week_start']), 'week:' . $weekId, 'publish:' . $weekId . ':' . $mid);
    }
    return ['shift_count' => $count, 'people_told' => count($mine), 'open_left' => $open, 'overridden' => count($soft),
            'warnings' => array_map(static fn (array $w): array => ['shift_id' => $w['shift_id'], 'member_id' => $w['member_id'], 'rule_key' => $w['rule_key']], $soft), 'week' => $week];
}

/** week_clear: the DRAFT week's shifts are removed (a published shift never is). Answers how many. */
function clear_week(PDO $pdo, int $weekId, int $by): int
{
    $week = week_row($pdo, $weekId, true) ?? throw new DomainException('Week not found.');
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is published.');
    }
    $st = $pdo->prepare('DELETE FROM shifts WHERE week_id = :w AND published_at IS NULL');
    $st->execute(['w' => $weekId]);
    return $st->rowCount();
}
