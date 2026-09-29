<?php
declare(strict_types=1);

/**
 * The reports (slice 7: docs/build-specs/settings-rules-reports.md). Each is a table — columns and rows of DISPLAY text, so the page and the CSV are exactly the same cells — read through the mcp_* views
 * (the same numbers the tools answer) except Trades, which reads the exchanges table because it is asked for by whoever builds, and a planner without approval rights sees no exchange through the view.
 * The right to ask each report is checked by the caller AT the restaurant (report_right()); a COST column exists only when $withCost (labor.view) and no report carries a wage rate — a cost is
 * paid hours times the effective rate, which the database computes and this code never sees. Dates are the restaurant's own days; a range is at most a year.
 */

const REPORT_MAX_DAYS = 366;
const REPORT_PAGE = 50;

/** key => [label, the right that opens it, what it shows]. In the order the page lists them. */
const REPORTS = [
    'hours'     => ['Hours', 'schedule.build', 'Paid hours per person and week, with the positions worked, against the overtime line and each person\'s own limit.'],
    'labor'     => ['Labor against budget', 'labor.view', 'Scheduled hours and cost against the budget, by week and area.'],
    'open'      => ['Open shifts unfilled', 'schedule.build', 'Shifts still open or cancelled, by week and position, and how long they stayed open.'],
    'trades'    => ['Trades', 'schedule.build', 'Offers, gives, swaps and coverage: counts by state, the time to a decision, who picks up most and who offers most.'],
    'overtime'  => ['Overtime', 'schedule.build', 'People over the overtime line by week, with the hours over.'],
    'overrides' => ['Overrides', 'schedule.build', 'Every rule a manager went ahead of, with who and why.'],
    'time-off'  => ['Time off', 'requests.approve', 'Approved time off by person and kind, and the balances.'],
];

/** The reports the caller may ask at the restaurant: key => [label, right, blurb]. */
function available_reports(int $siteId): array
{
    return array_filter(REPORTS, static fn (array $r): bool => has_right($r[1], $siteId));
}

/** The restaurant's zone and currency and overtime multiplier (mcp_sites). */
function report_site(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT name, timezone, currency, overtime_multiplier FROM mcp_sites WHERE site_id = :s');
    $st->execute(['s' => $siteId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/** A number as a report shows it: 12, 12.5 — never 12.00; null → empty. */
function rnum($v, int $dec = 2): string
{
    return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, $dec, '.', ''), '0'), '.');
}

/** Money as a plain number with two decimals (the currency is in the column's name). */
function rmoney($v): string
{
    return $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
}

/** A range of days: [from, to] as Y-m-d, inclusive, from <= to and at most REPORT_MAX_DAYS. Refuses in words. */
function report_range(?string $from, ?string $to, string $defaultFrom, string $defaultTo): array
{
    $from ??= $defaultFrom;
    $to ??= $defaultTo;
    if ($from > $to) {
        throw new DomainException('The range ends before it starts.');
    }
    $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') + 1;
    if ($days > REPORT_MAX_DAYS) {
        throw new DomainException('A report covers at most a year.');
    }
    return [$from, $to];
}

function col(string $key, string $label, bool $num = false): array
{
    return ['key' => $key, 'label' => $label, 'num' => $num];
}

/** Hours: per person and week — paid hours (mcp_hours_weekly) with the positions worked. */
function report_hours(PDO $pdo, int $siteId, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT week_start::text AS week, member_id, display_name, shifts, scheduled_hours, max_hours_week, overtime_weekly_hours, over_overtime, near_overtime, over_own_limit
                           FROM mcp_hours_weekly WHERE site_id = :s AND week_start <= CAST(:b AS date) AND week_start + 6 >= CAST(:a AS date) ORDER BY week_start, lower(display_name), member_id');
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    $rows = $st->fetchAll();
    $pos = $pdo->prepare("SELECT w.week_start::text AS week, s.assignee_member_id AS member_id, s.position_name, sum(s.paid_hours) AS hrs
                            FROM mcp_shifts s JOIN mcp_schedule_weeks w ON w.week_id = s.week_id
                           WHERE s.site_id = :s AND s.status = 'scheduled' AND s.assignee_member_id IS NOT NULL AND w.week_start <= CAST(:b AS date) AND w.week_start + 6 >= CAST(:a AS date)
                           GROUP BY w.week_start, s.assignee_member_id, s.position_name ORDER BY sum(s.paid_hours) DESC, s.position_name");
    $pos->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    $by = [];
    foreach ($pos->fetchAll() as $p) {
        $by[$p['week'] . '/' . $p['member_id']][] = $p['position_name'] . ' ' . rnum($p['hrs']) . ' h';
    }
    $out = [];
    foreach ($rows as $r) {
        $flags = [];
        if (in_array($r['over_overtime'], [true, 't'], true)) {
            $flags[] = 'Over the overtime line';
        } elseif (in_array($r['near_overtime'], [true, 't'], true)) {
            $flags[] = 'Near the overtime line';
        }
        if (in_array($r['over_own_limit'], [true, 't'], true)) {
            $flags[] = 'Over their own limit';
        }
        $out[] = ['week' => $r['week'], 'person' => $r['display_name'], 'positions' => implode(', ', $by[$r['week'] . '/' . $r['member_id']] ?? []), 'shifts' => (string) (int) $r['shifts'],
                  'hours' => rnum($r['scheduled_hours']), 'own_limit' => rnum($r['max_hours_week']), 'overtime_after' => rnum($r['overtime_weekly_hours']), 'flag' => implode('; ', $flags)];
    }
    return ['columns' => [col('week', 'Week of'), col('person', 'Person'), col('positions', 'Positions'), col('shifts', 'Shifts', true), col('hours', 'Paid hours', true),
                          col('own_limit', 'Own limit (h)', true), col('overtime_after', 'Overtime after (h)', true), col('flag', 'Flag')], 'rows' => $out];
}

/** Labor against budget (mcp_labor_weekly — labor.view at the restaurant). Overtime multipliers are not applied. */
function report_labor(PDO $pdo, int $siteId, string $from, string $to): array
{
    $cur = report_site($pdo, $siteId)['currency'];
    $st = $pdo->prepare("SELECT week_start::text AS week, area, scheduled_hours, scheduled_cost, budget_hours, budget_amount FROM mcp_labor_weekly
                          WHERE site_id = :s AND week_start <= CAST(:b AS date) AND week_start + 6 >= CAST(:a AS date) ORDER BY week_start, (area <> 'all'), area");
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $over = static fn ($x, $b): string => $b === null ? '' : rnum((float) $x - (float) $b);
        $out[] = ['week' => $r['week'], 'area' => $r['area'] === 'all' ? 'Total' : (BUDGET_AREAS[$r['area']] ?? $r['area']), 'hours' => rnum($r['scheduled_hours']), 'budget_hours' => rnum($r['budget_hours']),
                  'hours_over' => $over($r['scheduled_hours'], $r['budget_hours']), 'cost' => rmoney($r['scheduled_cost']), 'budget_amount' => rmoney($r['budget_amount']),
                  'cost_over' => $r['budget_amount'] === null ? '' : rmoney((float) $r['scheduled_cost'] - (float) $r['budget_amount'])];
    }
    return ['columns' => [col('week', 'Week of'), col('area', 'Area'), col('hours', 'Scheduled hours', true), col('budget_hours', 'Budget hours', true), col('hours_over', 'Hours over budget', true),
                          col('cost', "Scheduled cost ($cur)", true), col('budget_amount', "Budget amount ($cur)", true), col('cost_over', "Over budget ($cur)", true)], 'rows' => $out];
}

/** Open shifts unfilled: open (unassigned, scheduled) and cancelled shifts starting in the range, by week and position, and how long each stayed open (published until filled-or-cancelled-or-started). */
function report_open_shifts(PDO $pdo, int $siteId, string $from, string $to): array
{
    $tz = report_site($pdo, $siteId)['timezone'];
    $st = $pdo->prepare("SELECT w.week_start::text AS week, s.position_name, s.starts_at, s.ends_at, s.status, s.published_at,
                                CASE WHEN s.published_at IS NULL THEN NULL
                                     ELSE round(GREATEST(EXTRACT(EPOCH FROM (LEAST(COALESCE(s.cancelled_at, now()), s.starts_at) - s.published_at)) / 3600.0, 0)::numeric, 1) END AS open_hours
                           FROM mcp_shifts s JOIN mcp_schedule_weeks w ON w.week_id = s.week_id
                          WHERE s.site_id = :s AND ((s.status = 'scheduled' AND s.assignee_member_id IS NULL) OR s.status = 'cancelled')
                            AND (s.starts_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date)
                          ORDER BY w.week_start, lower(s.position_name), s.starts_at, s.shift_id");
    $st->execute(['s' => $siteId, 'tz' => $tz, 'a' => $from, 'b' => $to]);
    $zone = new DateTimeZone($tz);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $a = (new DateTimeImmutable($r['starts_at']))->setTimezone($zone);
        $b = (new DateTimeImmutable($r['ends_at']))->setTimezone($zone);
        $state = $r['status'] === 'cancelled' ? 'Cancelled' : ($r['published_at'] === null ? 'Open, not published' : 'Open');
        $out[] = ['week' => $r['week'], 'position' => $r['position_name'], 'shift' => $a->format('D Y-m-d H:i') . '–' . $b->format('H:i'), 'state' => $state, 'open_hours' => rnum($r['open_hours'], 1)];
    }
    return ['columns' => [col('week', 'Week of'), col('position', 'Position'), col('shift', 'Shift'), col('state', 'State'), col('open_hours', 'Open for (hours)', true)], 'rows' => $out];
}

/** The words for an exchange's kind and state. */
const TRADE_KINDS = ['offer' => 'Offer', 'open' => 'Open shift', 'give' => 'Give', 'swap' => 'Swap', 'coverage' => 'Coverage'];
const TRADE_STATES = ['open' => 'Open', 'pending_acceptance' => 'Waiting for the colleague', 'pending_approval' => 'Waiting for a manager', 'approved' => 'Done', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];

/**
 * Trades started in the range: counts by kind and state with the average hours to a decision (approved or declined trades — a cancellation is not a decision), then who picked up most (a taken offer, open shift or coverage) and who offered most (top five each).
 * One flat table: Measure, Who or what, Count, Average hours to a decision. Reads the exchanges table — the caller has asked schedule.build at the restaurant.
 */
function report_trades(PDO $pdo, int $siteId, string $from, string $to): array
{
    $tz = report_site($pdo, $siteId)['timezone'];
    $args = ['s' => $siteId, 'tz' => $tz, 'a' => $from, 'b' => $to];
    $st = $pdo->prepare("SELECT x.kind, x.status, count(*) AS n, round(avg(EXTRACT(EPOCH FROM (x.decided_at - x.created_at)) / 3600.0) FILTER (WHERE x.decided_at IS NOT NULL AND x.status IN ('approved', 'declined'))::numeric, 1) AS avg_h
                           FROM exchanges x WHERE x.scope_id = :s AND (x.created_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date) GROUP BY x.kind, x.status");
    $st->execute($args);
    $rows = $st->fetchAll();
    $ko = array_flip(array_keys(TRADE_KINDS));
    $so = array_flip(array_keys(TRADE_STATES));
    usort($rows, static fn (array $x, array $y): int => [$ko[$x['kind']], $so[$x['status']]] <=> [$ko[$y['kind']], $so[$y['status']]]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['measure' => 'By kind and state', 'item' => TRADE_KINDS[$r['kind']] . ' — ' . TRADE_STATES[$r['status']], 'count' => (string) (int) $r['n'], 'avg_hours' => rnum($r['avg_h'], 1)];
    }
    $top = function (string $sql, string $measure) use ($pdo, $args, &$out): void {
        $q = $pdo->prepare($sql);
        $q->execute($args);
        foreach ($q->fetchAll() as $r) {
            $out[] = ['measure' => $measure, 'item' => $r['display_name'], 'count' => (string) (int) $r['n'], 'avg_hours' => ''];
        }
    };
    $top("SELECT m.display_name, count(*) AS n FROM exchanges x JOIN members m ON m.id = x.to_member_id
           WHERE x.scope_id = :s AND x.status = 'approved' AND x.kind IN ('offer', 'open', 'coverage') AND (x.created_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date)
           GROUP BY m.id, m.display_name ORDER BY count(*) DESC, lower(m.display_name) LIMIT 5", 'Picked up most');
    $top("SELECT m.display_name, count(*) AS n FROM exchanges x JOIN members m ON m.id = x.from_member_id
           WHERE x.scope_id = :s AND x.kind = 'offer' AND (x.created_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date)
           GROUP BY m.id, m.display_name ORDER BY count(*) DESC, lower(m.display_name) LIMIT 5", 'Offered most');
    return ['columns' => [col('measure', 'Measure'), col('item', 'Who or what'), col('count', 'Count', true), col('avg_hours', 'Average hours to a decision', true)], 'rows' => $out];
}

/**
 * Overtime: people over the line by week (mcp_hours_weekly), with the hours over. With $withCost (labor.view) also the extra the overtime hours cost: hours over x the week's average cost per hour x
 * (the multiplier - 1) — a cost the database computes from the effective rates; no rate is read here.
 */
function report_overtime(PDO $pdo, int $siteId, string $from, string $to, bool $withCost = false): array
{
    $site = report_site($pdo, $siteId);
    $st = $pdo->prepare("SELECT h.week_start::text AS week, h.display_name, h.scheduled_hours, h.overtime_weekly_hours, round((h.scheduled_hours - h.overtime_weekly_hours)::numeric, 2) AS hours_over,
                                CASE WHEN CAST(:cost AS boolean) THEN
                                    (SELECT round(((h.scheduled_hours - h.overtime_weekly_hours) * CAST(:mult AS numeric) - (h.scheduled_hours - h.overtime_weekly_hours))::numeric * sum(x.cost) / NULLIF(sum(x.paid_hours), 0), 2)
                                       FROM mcp_shifts x JOIN mcp_schedule_weeks w ON w.week_id = x.week_id
                                      WHERE x.site_id = h.site_id AND x.assignee_member_id = h.member_id AND x.status = 'scheduled' AND w.week_start = h.week_start) END AS extra_cost
                           FROM mcp_hours_weekly h
                          WHERE h.site_id = :s AND h.over_overtime AND h.week_start <= CAST(:b AS date) AND h.week_start + 6 >= CAST(:a AS date) ORDER BY h.week_start, lower(h.display_name), h.member_id");
    $st->execute(['s' => $siteId, 'a' => $from, 'b' => $to, 'cost' => $withCost ? 't' : 'f', 'mult' => (string) $site['overtime_multiplier']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $row = ['week' => $r['week'], 'person' => $r['display_name'], 'hours' => rnum($r['scheduled_hours']), 'after' => rnum($r['overtime_weekly_hours']), 'over' => rnum($r['hours_over'])];
        if ($withCost) {
            $row['extra'] = rmoney($r['extra_cost']);
        }
        $out[] = $row;
    }
    $cols = [col('week', 'Week of'), col('person', 'Person'), col('hours', 'Paid hours', true), col('after', 'Overtime after (h)', true), col('over', 'Hours over', true)];
    if ($withCost) {
        $cols[] = col('extra', 'Extra cost of the overtime (' . $site['currency'] . ')', true);
    }
    return ['columns' => $cols, 'rows' => $out];
}

/** Overrides: every rule override in the range (mcp_rule_overrides), newest first — the compliance report. */
function report_overrides(PDO $pdo, int $siteId, string $from, string $to): array
{
    $tz = report_site($pdo, $siteId)['timezone'];
    $st = $pdo->prepare("SELECT to_char(o.created_at AT TIME ZONE :tz, 'YYYY-MM-DD HH24:MI') AS at, k.name AS rule_name, p.display_name AS person, b.display_name AS by_name, o.reason, o.message, o.context
                           FROM mcp_rule_overrides o JOIN rule_kinds k ON k.key = o.rule_key
                           LEFT JOIN mcp_members p ON p.member_id = o.member_id LEFT JOIN mcp_members b ON b.member_id = o.overridden_by
                          WHERE o.site_id = :s AND (o.created_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY o.created_at DESC, o.override_id DESC");
    $st->execute(['s' => $siteId, 'tz' => $tz, 'a' => $from, 'b' => $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['at' => $r['at'], 'rule' => $r['rule_name'], 'person' => (string) $r['person'], 'by' => (string) $r['by_name'], 'reason' => $r['reason'], 'message' => $r['message'], 'context' => $r['context']];
    }
    return ['columns' => [col('at', 'When'), col('rule', 'Rule'), col('person', 'About'), col('by', 'Went ahead'), col('reason', 'Why'), col('message', 'The warning'), col('context', 'Where')], 'rows' => $out];
}

/** Time off: approved requests starting in the range by person and kind, with the balance now (mcp_time_off_balances) — requests.approve at the restaurant. */
function report_time_off(PDO $pdo, int $siteId, string $from, string $to): array
{
    $tz = report_site($pdo, $siteId)['timezone'];
    $st = $pdo->prepare("WITH r AS (SELECT q.member_id, q.type_id, count(*) AS n, sum(q.hours) AS h FROM mcp_time_off_requests q
                                     WHERE q.site_id = :s AND q.status = 'approved' AND (q.starts_at AT TIME ZONE :tz)::date BETWEEN CAST(:a AS date) AND CAST(:b AS date) GROUP BY q.member_id, q.type_id),
                              b AS (SELECT member_id, type_id, balance_hours FROM mcp_time_off_balances WHERE site_id = :s)
                         SELECT m.display_name, t.name AS type_name, t.tracks_balance, r.n, r.h, b.balance_hours
                           FROM r FULL JOIN b ON b.member_id = r.member_id AND b.type_id = r.type_id
                           JOIN mcp_time_off_types t ON t.type_id = COALESCE(r.type_id, b.type_id)
                           JOIN mcp_members m ON m.member_id = COALESCE(r.member_id, b.member_id)
                          ORDER BY lower(m.display_name), t.name");
    $st->execute(['s' => $siteId, 'tz' => $tz, 'a' => $from, 'b' => $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['person' => $r['display_name'], 'type' => $r['type_name'], 'requests' => (string) (int) $r['n'], 'hours' => rnum($r['h']), 'balance' => in_array($r['tracks_balance'], [true, 't'], true) ? rnum($r['balance_hours']) : ''];
    }
    return ['columns' => [col('person', 'Person'), col('type', 'Kind'), col('requests', 'Approved requests', true), col('hours', 'Approved hours', true), col('balance', 'Balance now (h)', true)], 'rows' => $out];
}

/** Run one report; the caller has asked its right (REPORTS[$key][1]) at the restaurant. */
function run_report(PDO $pdo, string $key, int $siteId, string $from, string $to, bool $withCost): array
{
    return match ($key) {
        'hours' => report_hours($pdo, $siteId, $from, $to),
        'labor' => report_labor($pdo, $siteId, $from, $to),
        'open' => report_open_shifts($pdo, $siteId, $from, $to),
        'trades' => report_trades($pdo, $siteId, $from, $to),
        'overtime' => report_overtime($pdo, $siteId, $from, $to, $withCost),
        'overrides' => report_overrides($pdo, $siteId, $from, $to),
        'time-off' => report_time_off($pdo, $siteId, $from, $to),
        default => throw new DomainException('There is no report called ' . $key . '.'),
    };
}
