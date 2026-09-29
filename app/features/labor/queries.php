<?php
declare(strict_types=1);

/**
 * Labor and forecast (slice 5: docs/build-specs/labor-forecast.md). Reads go through the mcp_* views and ts_staffing_needs() (schedule.build at the restaurant); the writes go to the base
 * tables. COST (scheduled cost, a budget amount) is read only from mcp_labor_weekly / mcp_labor_budgets / mcp_shifts.cost — the views blank or hide it without labor.view — and the SCREENS that print
 * it ask has_right('labor.view') first. A function here opens no transaction of its own, logs nothing, and refuses with a DomainException (the handler's guard makes it a 422).
 */

const BUDGET_AREAS = ['all' => 'Everyone', 'front' => 'Front of house', 'kitchen' => 'Kitchen', 'bar' => 'Bar', 'management' => 'Management', 'other' => 'Other'];

/** A restaurant's live day-parts in order (mcp_day_parts): day_part_id, key, name, starts_at, ends_at, service_name. */
function find_day_parts(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT day_part_id, key, name, starts_at::text AS starts_at, ends_at::text AS ends_at, sort_order, service_name FROM mcp_day_parts WHERE site_id = :s ORDER BY sort_order, day_part_id');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['day_part_id'] = (int) $r['day_part_id'];
    }
    unset($r);
    return $rows;
}

/** One day-part of the restaurant by id, key, name or service name (case-insensitive); null when there is none. */
function find_day_part(PDO $pdo, int $siteId, string $ref): ?array
{
    $ref = trim($ref);
    foreach (find_day_parts($pdo, $siteId) as $d) {
        if ((ctype_digit($ref) && $d['day_part_id'] === (int) $ref) || strcasecmp($d['key'], $ref) === 0 || strcasecmp($d['name'], $ref) === 0
            || ($d['service_name'] !== null && strcasecmp($d['service_name'], $ref) === 0)) {
            return $d;
        }
    }
    return null;
}

/**
 * The expected covers of a date range: ['day_parts' => the live day-parts, 'cells' => [day_part_id][Y-m-d] => ['id' => the row's id, 'covers' => int, 'source' => manual|copied|reservations]].
 * The id comes from the base table (the view has none).
 */
function find_forecast(PDO $pdo, int $siteId, string $from, string $to): array
{
    $parts = find_day_parts($pdo, $siteId);
    $st = $pdo->prepare('SELECT b.id, v.on_date::text AS on_date, v.day_part_id, v.expected_covers, v.source
                           FROM mcp_forecast_covers v JOIN forecast_covers b ON b.scope_id = v.site_id AND b.on_date = v.on_date AND b.day_part_id = v.day_part_id
                          WHERE v.site_id = :s AND v.on_date BETWEEN :a AND :b');
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    $live = array_column($parts, null, 'day_part_id');
    $cells = [];
    foreach ($st->fetchAll() as $r) {
        if (isset($live[(int) $r['day_part_id']])) {
            $cells[(int) $r['day_part_id']][$r['on_date']] = ['id' => (int) $r['id'], 'covers' => (int) $r['expected_covers'], 'source' => $r['source']];
        }
    }
    return ['day_parts' => $parts, 'cells' => $cells];
}

/** Recommended against scheduled headcount per date, day-part and position (ts_staffing_needs — the caller must build at the site, else no rows). */
function find_needs(PDO $pdo, int $siteId, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT on_date::text AS on_date, day_part_id, day_part, position_id, position_name, expected_covers, recommended, scheduled, open_shifts FROM ts_staffing_needs(:s, :a, :b)');
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        foreach (['day_part_id', 'position_id', 'recommended', 'scheduled', 'open_shifts'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['expected_covers'] = $r['expected_covers'] === null ? null : (int) $r['expected_covers'];
    }
    unset($r);
    return $rows;
}

/** Every live position of the restaurant with its staffing ratio (null / 0 when none is set): position_id, name, color, area, covers_per_staff, min_staff. */
function find_ratios(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT p.position_id, p.name, p.color, p.area, r.covers_per_staff, COALESCE(r.min_staff, 0) AS min_staff
                           FROM mcp_positions p LEFT JOIN mcp_staffing_ratios r ON r.site_id = p.site_id AND r.position_id = p.position_id
                          WHERE p.site_id = :s AND p.archived_at IS NULL ORDER BY p.sort_order, lower(p.name), p.position_id');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['position_id'] = (int) $r['position_id'];
        $r['covers_per_staff'] = $r['covers_per_staff'] === null ? null : (float) $r['covers_per_staff'];
        $r['min_staff'] = (int) $r['min_staff'];
    }
    unset($r);
    return $rows;
}

/** The base row of one forecast cell (id, covers, source), or null. */
function forecast_cell(PDO $pdo, int $siteId, string $date, int $dayPartId): ?array
{
    $st = $pdo->prepare('SELECT id, expected_covers AS covers, source FROM forecast_covers WHERE scope_id = :s AND on_date = :d AND day_part_id = :p');
    $st->execute(['s' => $siteId, 'd' => $date, 'p' => $dayPartId]);
    $r = $st->fetch();
    return $r === false ? null : ['id' => (int) $r['id'], 'covers' => (int) $r['covers'], 'source' => $r['source']];
}

/** A typed cell: `manual`. Answers ['id', 'before' => ?['covers','source'], 'after' => ['covers','source']]. */
function save_forecast(PDO $pdo, int $siteId, string $date, int $dayPartId, int $covers, int $by): array
{
    if ($covers < 0 || $covers > 100000) {
        throw new DomainException('Covers are a whole number from 0 to 100000.');
    }
    $part = $pdo->prepare('SELECT 1 FROM day_parts WHERE id = :p AND scope_id = :s AND archived_at IS NULL');
    $part->execute(['p' => $dayPartId, 's' => $siteId]);
    if ($part->fetchColumn() === false) {
        throw new DomainException('That day-part is not this restaurant\'s.');
    }
    $before = forecast_cell($pdo, $siteId, $date, $dayPartId);
    $st = $pdo->prepare("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source, updated_by) VALUES (:s, :d, :p, :c, 'manual', :u)
                         ON CONFLICT (scope_id, on_date, day_part_id) DO UPDATE SET expected_covers = EXCLUDED.expected_covers, source = 'manual', updated_by = EXCLUDED.updated_by, updated_at = now() RETURNING id");
    $st->execute(['s' => $siteId, 'd' => $date, 'p' => $dayPartId, 'c' => $covers, 'u' => $by]);
    return ['id' => (int) $st->fetchColumn(), 'before' => $before === null ? null : ['covers' => $before['covers'], 'source' => $before['source']], 'after' => ['covers' => $covers, 'source' => 'manual']];
}

/** Empty a cell (the typed number removed). Answers the state it had, or null when there was none. */
function clear_forecast(PDO $pdo, int $siteId, string $date, int $dayPartId): ?array
{
    $before = forecast_cell($pdo, $siteId, $date, $dayPartId);
    if ($before === null) {
        return null;
    }
    $pdo->prepare('DELETE FROM forecast_covers WHERE id = :i')->execute(['i' => $before['id']]);
    return $before;
}

/**
 * Copy one week's TYPED cells (manual or copied) to another week, day for day, marked `copied`. A cell Reservations filled is never copied and a destination cell Reservations filled is never
 * overwritten. Answers ['cells' => written, 'skipped_reservations' => destinations left alone, 'not_copied' => reservation cells at the source, 'first_id' => ?int].
 */
function copy_forecast(PDO $pdo, int $siteId, string $fromWeek, string $toWeek, int $by): array
{
    if ($fromWeek === $toWeek) {
        throw new DomainException('Pick a different week to copy from.');
    }
    $offset = (int) (new DateTimeImmutable($fromWeek, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($toWeek, new DateTimeZone('UTC')))->format('%r%a');
    $src = $pdo->prepare('SELECT f.on_date::text AS on_date, f.day_part_id, f.expected_covers, f.source FROM forecast_covers f JOIN day_parts d ON d.id = f.day_part_id AND d.archived_at IS NULL
                           WHERE f.scope_id = :s AND f.on_date BETWEEN CAST(:a AS date) AND CAST(:a AS date) + 6 ORDER BY f.on_date, d.sort_order');
    $src->execute(['s' => $siteId, 'a' => $fromWeek]);
    $up = $pdo->prepare("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source, updated_by) VALUES (:s, CAST(:d AS date) + CAST(:o AS integer), :p, :c, 'copied', :u)
                         ON CONFLICT (scope_id, on_date, day_part_id) DO UPDATE SET expected_covers = EXCLUDED.expected_covers, source = 'copied', updated_by = EXCLUDED.updated_by, updated_at = now()
                          WHERE forecast_covers.source <> 'reservations' RETURNING id");
    $out = ['cells' => 0, 'skipped_reservations' => 0, 'not_copied' => 0, 'first_id' => null];
    foreach ($src->fetchAll() as $r) {
        if ($r['source'] === 'reservations') {
            $out['not_copied']++;
            continue;
        }
        $up->bindValue('s', $siteId, PDO::PARAM_INT);
        $up->bindValue('d', $r['on_date']);
        $up->bindValue('o', $offset, PDO::PARAM_INT);
        $up->bindValue('p', (int) $r['day_part_id'], PDO::PARAM_INT);
        $up->bindValue('c', (int) $r['expected_covers'], PDO::PARAM_INT);
        $up->bindValue('u', $by, PDO::PARAM_INT);
        $up->execute();
        $id = $up->fetchColumn();
        if ($id === false) {
            $out['skipped_reservations']++;
        } else {
            $out['cells']++;
            $out['first_id'] ??= (int) $id;
        }
    }
    return $out;
}

/** The ratio row of a position (base table): [covers_per_staff, min_staff] or null. */
function ratio_row(PDO $pdo, int $siteId, int $positionId): ?array
{
    $st = $pdo->prepare('SELECT covers_per_staff, min_staff FROM staffing_ratios WHERE scope_id = :s AND position_id = :p');
    $st->execute(['s' => $siteId, 'p' => $positionId]);
    $r = $st->fetch();
    return $r === false ? null : ['covers_per_staff' => $r['covers_per_staff'] === null ? null : (float) $r['covers_per_staff'], 'min_staff' => (int) $r['min_staff']];
}

/** One person per N covers (null = no ratio) and a minimum, for a position of the restaurant. Answers ['before' => ?array, 'after' => array]. */
function save_ratio(PDO $pdo, int $siteId, int $positionId, ?float $coversPerStaff, int $minStaff, int $by): array
{
    if ($coversPerStaff !== null && ($coversPerStaff <= 0 || $coversPerStaff > 9999)) {
        throw new DomainException('One person per N covers: N is more than 0 and at most 9999.');
    }
    if ($minStaff < 0 || $minStaff > 200) {
        throw new DomainException('The minimum is a whole number from 0 to 200.');
    }
    $p = $pdo->prepare('SELECT 1 FROM positions WHERE id = :p AND scope_id = :s AND archived_at IS NULL');
    $p->execute(['p' => $positionId, 's' => $siteId]);
    if ($p->fetchColumn() === false) {
        throw new DomainException('That position is not this restaurant\'s.');
    }
    $before = ratio_row($pdo, $siteId, $positionId);
    $st = $pdo->prepare('INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff, updated_by) VALUES (:s, :p, :c, :m, :u)
                         ON CONFLICT (scope_id, position_id) DO UPDATE SET covers_per_staff = EXCLUDED.covers_per_staff, min_staff = EXCLUDED.min_staff, updated_by = EXCLUDED.updated_by, updated_at = now()');
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('p', $positionId, PDO::PARAM_INT);
    $st->bindValue('c', $coversPerStaff === null ? null : number_format($coversPerStaff, 2, '.', ''));
    $st->bindValue('m', $minStaff, PDO::PARAM_INT);
    $st->bindValue('u', $by, PDO::PARAM_INT);
    $st->execute();
    return ['before' => $before, 'after' => ['covers_per_staff' => $coversPerStaff === null ? null : round($coversPerStaff, 2), 'min_staff' => $minStaff]];
}

/**
 * The week's scheduled labor against the budget, per area and 'all' (mcp_labor_weekly — labor.view at the restaurant, else no rows), keyed by area:
 * area, scheduled_hours, scheduled_cost, budget_hours, budget_amount, budget_id. Only areas with shifts or a budget appear.
 */
function find_budget(PDO $pdo, int $siteId, string $weekStart): array
{
    $st = $pdo->prepare('SELECT w.area, w.scheduled_hours, w.scheduled_cost, w.budget_hours, w.budget_amount, b.budget_id
                           FROM mcp_labor_weekly w LEFT JOIN mcp_labor_budgets b ON b.site_id = w.site_id AND b.week_start = w.week_start AND b.area = w.area
                          WHERE w.site_id = :s AND w.week_start = :w');
    $st->execute(['s' => $siteId, 'w' => $weekStart]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['area']] = ['area' => $r['area'], 'scheduled_hours' => (float) $r['scheduled_hours'], 'scheduled_cost' => (float) $r['scheduled_cost'],
                            'budget_hours' => $r['budget_hours'] === null ? null : (float) $r['budget_hours'], 'budget_amount' => $r['budget_amount'] === null ? null : (float) $r['budget_amount'],
                            'budget_id' => $r['budget_id'] === null ? null : (int) $r['budget_id']];
    }
    return $out;
}

/** The base row of a budget (id, hours, amount) for an area and week, or null. */
function budget_row(PDO $pdo, int $siteId, string $weekStart, string $area): ?array
{
    $st = $pdo->prepare('SELECT id, budget_hours, budget_amount FROM labor_budgets WHERE scope_id = :s AND week_start = :w AND area = :a');
    $st->execute(['s' => $siteId, 'w' => $weekStart, 'a' => $area]);
    $r = $st->fetch();
    return $r === false ? null : ['id' => (int) $r['id'], 'hours' => $r['budget_hours'] === null ? null : (float) $r['budget_hours'], 'amount' => $r['budget_amount'] === null ? null : (float) $r['budget_amount']];
}

/**
 * Set a week's budget for an area — hours and/or an amount; both empty removes it. Answers ['id' => ?int, 'before' => ?array, 'after' => ?array]. A budget is a plan; the caller holds settings.manage
 * (and labor.view, because an amount is a cost figure).
 */
function save_budget(PDO $pdo, int $siteId, string $weekStart, string $area, ?float $hours, ?float $amount, int $by): array
{
    if (!isset(BUDGET_AREAS[$area])) {
        throw new DomainException('The area is all, front, kitchen, bar, management or other.');
    }
    if (($hours !== null && ($hours < 0 || $hours > 99999)) || ($amount !== null && ($amount < 0 || $amount > 9999999))) {
        throw new DomainException('Hours are from 0 to 99999 and an amount from 0 to 9999999.');
    }
    $before = budget_row($pdo, $siteId, $weekStart, $area);
    $was = $before === null ? null : ['hours' => $before['hours'], 'amount' => $before['amount']];
    if ($hours === null && $amount === null) {
        if ($before !== null) {
            $pdo->prepare('DELETE FROM labor_budgets WHERE id = :i')->execute(['i' => $before['id']]);
        }
        return ['id' => $before['id'] ?? null, 'before' => $was, 'after' => null];
    }
    $st = $pdo->prepare('INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount, updated_by) VALUES (:s, :w, :a, :h, :m, :u)
                         ON CONFLICT (scope_id, week_start, area) DO UPDATE SET budget_hours = EXCLUDED.budget_hours, budget_amount = EXCLUDED.budget_amount, updated_by = EXCLUDED.updated_by, updated_at = now() RETURNING id');
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('w', $weekStart);
    $st->bindValue('a', $area);
    $st->bindValue('h', $hours === null ? null : number_format($hours, 2, '.', ''));
    $st->bindValue('m', $amount === null ? null : number_format($amount, 2, '.', ''));
    $st->bindValue('u', $by, PDO::PARAM_INT);
    $st->execute();
    return ['id' => (int) $st->fetchColumn(), 'before' => $was, 'after' => ['hours' => $hours === null ? null : round($hours, 2), 'amount' => $amount === null ? null : round($amount, 2)]];
}

/**
 * The week's scheduled hours and cost per local day (mcp_shifts; cost only where the view gives it — the caller has labor.view), keyed by date: hours, cost, shifts, open.
 * Same rules as the weekly view: scheduled shifts, drafts included; an open shift adds hours and no cost.
 */
function find_labor_by_day(PDO $pdo, int $siteId, string $weekStart, string $tz): array
{
    $st = $pdo->prepare("SELECT to_char(s.starts_at AT TIME ZONE :tz, 'YYYY-MM-DD') AS d, sum(s.paid_hours) AS hours, sum(s.cost) AS cost, count(*) AS shifts, count(*) FILTER (WHERE s.is_open) AS open
                           FROM mcp_shifts s JOIN schedule_weeks w ON w.id = s.week_id
                          WHERE s.site_id = :s AND w.week_start = :w AND s.status = 'scheduled' GROUP BY 1 ORDER BY 1");
    $st->execute(['tz' => $tz, 's' => $siteId, 'w' => $weekStart]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['d']] = ['hours' => round((float) $r['hours'], 2), 'cost' => $r['cost'] === null ? null : round((float) $r['cost'], 2), 'shifts' => (int) $r['shifts'], 'open' => (int) $r['open']];
    }
    return $out;
}

/** The restaurant's first day of the week (0 = Sunday … 6 = Saturday) from its settings (mcp_sites). */
function find_site_week_start(PDO $pdo, int $siteId): int
{
    $st = $pdo->prepare('SELECT week_start FROM mcp_sites WHERE site_id = :s');
    $st->execute(['s' => $siteId]);
    $v = $st->fetchColumn();
    return $v === false ? 1 : (int) $v;
}
