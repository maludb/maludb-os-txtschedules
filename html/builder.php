<?php
declare(strict_types=1);
/**
 * /builder?site=&week=&view=&position=&day= — the week as a grid (screen `builder`): people (or positions) down, the seven days across; open shifts in their own row; drafts and
 * cancelled shifts included (they are the managers'); hours per person against their limit and the overtime threshold; with labor.view the cost and the week's labor against the
 * budget; the staffing needs where the restaurant has a forecast. A phone gets day tabs and one column of shifts. schedule.build at the site — a site not held is "Not found."
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__) . '/app/features/weeks/present.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('schedule.build', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$ws = week_start_of(request_local_date('week', $today), (int) $site['week_start']);
$weekEnd = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
$prev = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
$next = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
$week = find_week($pdo, $siteId, $ws);
$prevWeek = find_week($pdo, $siteId, $prev);
$view = request_string('view') === 'positions' ? 'positions' : 'people';
$positions = find_site_positions($pdo, $siteId);
$positionId = request_integer('position');
if ($positionId !== null && !in_array($positionId, array_map('intval', array_column($positions, 'position_id')), true)) {
    $positionId = null;
}
$canLabor = has_right('labor.view', $siteId);
$allShifts = $week === null ? [] : find_week_shifts($pdo, $week['week_id']);
$shifts = $positionId === null ? $allShifts : array_values(array_filter($allShifts, static fn (array $s): bool => $s['position_id'] === $positionId));
$warnRows = $week === null ? [] : week_warnings($pdo, $week['week_id']);
$warn = warnings_by_shift($warnRows);
$hours = week_hours($pdo, $siteId, $ws);
$labor = $canLabor ? week_labor($pdo, $siteId, $ws) : null;
$needs = needs_by_day(week_needs($pdo, $siteId, $ws, $weekEnd));
$people = find_schedulable_people($pdo, $siteId);
$days = week_days($ws, $today);
$day = request_local_date('day', ($today >= $ws && $today <= $weekEnd) ? $today : $ws);
if ($day < $ws || $day > $weekEnd) {
    $day = $ws;
}
$badge = week_badge($week, $allShifts, $tz);
$result = wants_json() ? null : take_result($siteId, $ws);      // a JSON caller had the outcome in the action's own answer; only a screen shows it, once
if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'builder', 'scope_id' => $siteId, 'after' => ['week' => $ws]]);
}
if (wants_json()) {
    $scheduled = array_values(array_filter($allShifts, static fn (array $s): bool => $s['status'] === 'scheduled'));
    $payload = ['site_id' => $siteId, 'week_start' => $ws, 'time_zone' => $tz, 'week' => $week === null ? null : ['week_id' => $week['week_id'], 'status' => $week['status'], 'published_at' => json_ts($week['published_at'])],
        'state' => $badge[2], 'shifts' => array_map(static fn (array $s): array => present_builder_shift($s, $warn[$s['shift_id']] ?? []), $shifts),
        'hours' => array_map(static fn (array $h): array => ['member_id' => (int) $h['member_id'], 'name' => $h['display_name'], 'scheduled_hours' => (float) $h['scheduled_hours'],
            'max_hours_week' => $h['max_hours_week'] === null ? null : (float) $h['max_hours_week'], 'overtime_weekly_hours' => (float) $h['overtime_weekly_hours'], 'over_overtime' => (bool) $h['over_overtime']], array_values($hours)),
        'warnings' => array_map(static fn (array $w): array => ['shift_id' => $w['shift_id'], 'member_id' => $w['member_id'], 'name' => $w['display_name'], 'rule' => $w['rule_key'], 'severity' => $w['severity'], 'message' => $w['message']], $warnRows),
        'open_shifts' => count(array_filter($scheduled, static fn (array $s): bool => $s['is_open'])),
        'needs' => $needs];
    if ($canLabor) {
        $payload['labor'] = $labor === null ? null : ['scheduled_hours' => (float) $labor['scheduled_hours'], 'scheduled_cost' => (float) $labor['scheduled_cost'],
            'budget_hours' => $labor['budget_hours'] === null ? null : (float) $labor['budget_hours'], 'budget_amount' => $labor['budget_amount'] === null ? null : (float) $labor['budget_amount']];
    }
    respond_screen($payload);
}
render_screen('Builder', view('builder/grid.php', ['site' => $site, 'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.build', $s['scope_id']))),
    'ws' => $ws, 'prev' => $prev, 'next' => $next, 'week' => $week, 'prevWeek' => $prevWeek, 'view' => $view, 'positions' => $positions, 'positionId' => $positionId, 'canLabor' => $canLabor,
    'shifts' => $shifts, 'allShifts' => $allShifts, 'warn' => $warn, 'warnRows' => $warnRows, 'hours' => $hours, 'labor' => $labor, 'needs' => $needs, 'people' => $people, 'days' => $days,
    'day' => $day, 'badge' => $badge, 'result' => $result, 'today' => $today, 'notice' => notice_words($_GET['notice'] ?? null), 'currency' => (string) $site['currency']]),
    ['activeNav' => 'builder', 'screen' => 'builder', 'entity' => 'week', 'recordId' => $week === null ? '' : (string) $week['week_id']]);
