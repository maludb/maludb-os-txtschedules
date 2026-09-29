<?php
declare(strict_types=1);

/**
 * Templates, and the two ways a draft week is filled from another (slice 2: docs/build-specs/week-builder.md): a saved template by weekday, or last
 * week (or any week) shifted by whole weeks. Both go through place_shifts(): each shift lands on its day; one whose person would break a hard rule, has
 * time off, already has a shift then or no longer works here is left OPEN and listed with the reason — nothing is ever put on a published week, and
 * nothing is put over a draft shift that is already there (it is added beside, and the count of what was there is reported).
 */

function template_site_id(PDO $pdo, int $templateId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM schedule_templates WHERE id = :id');
    $st->execute(['id' => $templateId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** The restaurant's saved weeks, newest first (mcp_templates; builders only). */
function find_templates(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT template_id, site_id, name, from_week_id, created_by, created_at, shift_count FROM mcp_templates WHERE site_id = :s ORDER BY created_at DESC, template_id DESC');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['template_id'] = (int) $r['template_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['shift_count'] = (int) $r['shift_count'];
    }
    unset($r);
    return $rows;
}

function find_template(PDO $pdo, int $templateId): ?array
{
    $st = $pdo->prepare('SELECT template_id, site_id, name, from_week_id, created_by, created_at, shift_count FROM mcp_templates WHERE template_id = :t');
    $st->execute(['t' => $templateId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['template_id'] = (int) $r['template_id'];
    $r['site_id'] = (int) $r['site_id'];
    $r['shift_count'] = (int) $r['shift_count'];
    return $r;
}

/** A template's shifts by weekday and time, with the position's name and colour and the person's name. */
function find_template_shifts(PDO $pdo, int $templateId): array
{
    $st = $pdo->prepare('SELECT x.template_shift_id, x.weekday, x.starts_at::text AS starts_at, x.ends_at::text AS ends_at, x.break_minutes, x.position_id, p.name AS position_name, p.color AS position_color,
                                x.assignee_member_id, m.display_name AS assignee_name
                           FROM mcp_template_shifts x JOIN mcp_positions p ON p.position_id = x.position_id LEFT JOIN mcp_members m ON m.member_id = x.assignee_member_id
                          WHERE x.template_id = :t ORDER BY x.weekday, x.starts_at, x.template_shift_id');
    $st->execute(['t' => $templateId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['weekday'] = (int) $r['weekday'];
        $r['position_id'] = (int) $r['position_id'];
        $r['assignee_member_id'] = $r['assignee_member_id'] === null ? null : (int) $r['assignee_member_id'];
    }
    unset($r);
    return $rows;
}

/**
 * Save a week's scheduled shifts as a template (weekday, times, position, break, the person when chosen). $id = rename an existing one (and, with $fromWeek,
 * replace its shifts). Answers template_id, shift_count, created.
 */
function save_template(PDO $pdo, int $siteId, string $name, ?int $fromWeek, ?int $id, int $by): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) {
        throw new DomainException('Give the template a name of up to 80 characters.');
    }
    $created = false;
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO schedule_templates (scope_id, name, from_week_id, created_by) VALUES (:s, :n, :w, :by) RETURNING id');
        $st->execute(['s' => $siteId, 'n' => $name, 'w' => $fromWeek, 'by' => $by]);
        $id = (int) $st->fetchColumn();
        $created = true;
    } else {
        $st = $pdo->prepare('UPDATE schedule_templates SET name = :n' . ($fromWeek !== null ? ', from_week_id = :w' : '') . ' WHERE id = :id AND scope_id = :s AND archived_at IS NULL');
        $st->execute(['n' => $name, 'id' => $id, 's' => $siteId] + ($fromWeek !== null ? ['w' => $fromWeek] : []));
        if ($st->rowCount() === 0) {
            throw new DomainException('That template is not here any more.');
        }
    }
    if ($fromWeek !== null) {
        $week = week_row($pdo, $fromWeek) ?? throw new DomainException('Week not found.');
        if ($week['site_id'] !== $siteId) {
            throw new DomainException('Week not found.');
        }
        $pdo->prepare('DELETE FROM template_shifts WHERE template_id = :t')->execute(['t' => $id]);
        $tz = new DateTimeZone($week['timezone']);
        $src = $pdo->prepare("SELECT position_id, starts_at, ends_at, break_minutes, assignee_member_id FROM shifts WHERE week_id = :w AND status = 'scheduled' ORDER BY starts_at, id");
        $src->execute(['w' => $fromWeek]);
        $ins = $pdo->prepare('INSERT INTO template_shifts (template_id, position_id, weekday, starts_at, ends_at, break_minutes, assignee_member_id) VALUES (:t, :p, :d, :a, :b, :br, :m)');
        foreach ($src->fetchAll() as $r) {
            $a = local_from_db((string) $r['starts_at'], $tz);
            $b = local_from_db((string) $r['ends_at'], $tz);
            $ins->execute(['t' => $id, 'p' => (int) $r['position_id'], 'd' => (int) $a->format('w'), 'a' => $a->format('H:i'), 'b' => $b->format('H:i'),
                           'br' => (int) $r['break_minutes'], 'm' => $r['assignee_member_id']]);
        }
    }
    $n = $pdo->prepare('SELECT count(*) FROM template_shifts WHERE template_id = :t');
    $n->execute(['t' => $id]);
    return ['template_id' => $id, 'shift_count' => (int) $n->fetchColumn(), 'created' => $created];
}

function archive_template(PDO $pdo, int $templateId, int $siteId): void
{
    $st = $pdo->prepare('UPDATE schedule_templates SET archived_at = now() WHERE id = :id AND scope_id = :s AND archived_at IS NULL');
    $st->execute(['id' => $templateId, 's' => $siteId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('That template is not here any more.');
    }
}

/**
 * Put shifts on a DRAFT week. $items: position_id, date (site-local Y-m-d), start (H:i), end (H:i), end_days (0 same day, 1 past midnight), break, assignee, note.
 * Answers placed, left_open [{date, position, when, reason}], skipped [{date, reason}], existing (draft shifts already in the week, added beside).
 */
function place_shifts(PDO $pdo, array $week, array $items, int $by): array
{
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is published.');
    }
    $tz = new DateTimeZone((string) $week['timezone']);
    $siteId = (int) $week['site_id'];
    $ex = $pdo->prepare("SELECT count(*) FROM shifts WHERE week_id = :w AND status = 'scheduled'");
    $ex->execute(['w' => $week['week_id']]);
    $existing = (int) $ex->fetchColumn();
    $pos = [];
    $q = $pdo->prepare('SELECT id, name FROM positions WHERE scope_id = :s AND archived_at IS NULL');
    $q->execute(['s' => $siteId]);
    foreach ($q->fetchAll() as $p) {
        $pos[(int) $p['id']] = (string) $p['name'];
    }
    $placed = 0;
    $left = [];
    $skipped = [];
    foreach ($items as $it) {
        $start = local_moment($it['date'] . ' ' . substr((string) $it['start'], 0, 5), $tz);
        $endDay = $start === null ? null : $start->setTime(0, 0)->modify('+' . (int) $it['end_days'] . ' days');
        $end = $endDay === null ? null : local_moment($endDay->format('Y-m-d') . ' ' . substr((string) $it['end'], 0, 5), $tz);
        if ($start === null || $end === null || $end <= $start) {
            $skipped[] = ['date' => $it['date'], 'reason' => 'The times did not make a shift.'];
            continue;
        }
        if (!isset($pos[(int) $it['position_id']])) {
            $skipped[] = ['date' => $it['date'], 'reason' => 'That position is not one of this restaurant\'s any more.'];
            continue;
        }
        $when = $start->format('D M j') . ' · ' . shift_time_range(utc_text($start), utc_text($end), (string) $week['timezone']);
        $assignee = $it['assignee'] === null ? null : (int) $it['assignee'];
        if ($assignee !== null) {
            $b = assignment_blocker($pdo, $siteId, $assignee, (int) $it['position_id'], $start, $end, (int) $it['break'], (string) $week['timezone']);
            if ($b['reason'] !== null) {
                $left[] = ['date' => $start->format('Y-m-d'), 'position' => $pos[(int) $it['position_id']], 'when' => $when, 'reason' => $b['reason']];
                $assignee = null;
            }
        }
        insert_shift($pdo, $siteId, (int) $week['week_id'], ['position_id' => (int) $it['position_id'], 'starts' => $start, 'ends' => $end, 'break' => (int) $it['break'],
                                                            'assignee' => $assignee, 'note' => $it['note'] ?? null], $by);
        $placed++;
    }
    return ['placed' => $placed, 'left_open' => $left, 'skipped' => $skipped, 'existing' => $existing];
}

/** template_apply: each template shift lands on its weekday in the DRAFT week. */
function apply_template(PDO $pdo, int $templateId, int $weekId, int $by): array
{
    $week = week_row($pdo, $weekId, true) ?? throw new DomainException('Week not found.');
    $t = $pdo->prepare('SELECT scope_id, archived_at FROM schedule_templates WHERE id = :id');
    $t->execute(['id' => $templateId]);
    $tpl = $t->fetch();
    if ($tpl === false || (int) $tpl['scope_id'] !== $week['site_id']) {
        throw new DomainException('That template is not here any more.');
    }
    if ($tpl['archived_at'] !== null) {
        throw new DomainException('That template is archived.');
    }
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is published.');
    }
    $rows = $pdo->prepare('SELECT position_id, weekday, starts_at::text AS starts_at, ends_at::text AS ends_at, break_minutes, assignee_member_id FROM template_shifts WHERE template_id = :t ORDER BY weekday, starts_at, id');
    $rows->execute(['t' => $templateId]);
    $items = [];
    foreach ($rows->fetchAll() as $r) {
        $offset = ((int) $r['weekday'] - $week['week_dow'] + 7) % 7;
        $date = (new DateTimeImmutable($week['week_start'], new DateTimeZone('UTC')))->modify("+$offset days")->format('Y-m-d');
        $items[] = ['position_id' => (int) $r['position_id'], 'date' => $date, 'start' => $r['starts_at'], 'end' => $r['ends_at'],
                    'end_days' => substr((string) $r['ends_at'], 0, 5) <= substr((string) $r['starts_at'], 0, 5) ? 1 : 0, 'break' => (int) $r['break_minutes'],
                    'assignee' => $r['assignee_member_id'] === null ? null : (int) $r['assignee_member_id'], 'note' => null];
    }
    return place_shifts($pdo, $week, $items, $by);
}

/** week_copy: another week's scheduled shifts, moved by whole weeks onto this DRAFT week (local times kept across a clock change). */
function copy_week(PDO $pdo, int $fromWeek, int $toWeek, int $by): array
{
    $to = week_row($pdo, $toWeek, true) ?? throw new DomainException('Week not found.');
    $from = week_row($pdo, $fromWeek) ?? throw new DomainException('Week not found.');
    if ($from['site_id'] !== $to['site_id']) {
        throw new DomainException('Week not found.');
    }
    if ($from['week_id'] === $to['week_id']) {
        throw new DomainException('Pick a different week to copy from.');
    }
    if ($to['status'] !== 'draft') {
        throw new DomainException('This week is published.');
    }
    $tz = new DateTimeZone($to['timezone']);
    $delta = (int) (new DateTimeImmutable($from['week_start']))->diff(new DateTimeImmutable($to['week_start']))->format('%r%a');
    $src = $pdo->prepare("SELECT position_id, starts_at, ends_at, break_minutes, assignee_member_id, note FROM shifts WHERE week_id = :w AND status = 'scheduled' ORDER BY starts_at, id");
    $src->execute(['w' => $fromWeek]);
    $items = [];
    foreach ($src->fetchAll() as $r) {
        $a = local_from_db((string) $r['starts_at'], $tz);
        $b = local_from_db((string) $r['ends_at'], $tz);
        $date = $a->setTime(0, 0)->modify(($delta >= 0 ? '+' : '') . $delta . ' days')->format('Y-m-d');
        $items[] = ['position_id' => (int) $r['position_id'], 'date' => $date, 'start' => $a->format('H:i'), 'end' => $b->format('H:i'),
                    'end_days' => (int) $a->setTime(0, 0)->diff($b->setTime(0, 0))->format('%a'), 'break' => (int) $r['break_minutes'],
                    'assignee' => $r['assignee_member_id'] === null ? null : (int) $r['assignee_member_id'], 'note' => $r['note']];
    }
    return place_shifts($pdo, $to, $items, $by) + ['from_week' => $fromWeek];
}
