<?php
declare(strict_types=1);
/**
 * /team-schedule — the published week for a restaurant by day and position (screen `team-schedule`). ?site= ?week= ?day= ?position=.
 * A site the person does not hold is "Not found." Published shifts only; no cost, no phone.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('schedule.view_own', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$week = week_start_of(request_local_date('week', request_local_date('day', $today)), (int) $site['week_start']);
$weekEnd = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
$day = request_local_date('day', ($today >= $week && $today < $weekEnd) ? $today : $week);
if ($day < $week || $day >= $weekEnd) {
    $day = $week;
}
$positions = find_site_positions($pdo, $siteId);
$positionId = request_integer('position');
if ($positionId !== null && !in_array($positionId, array_map('intval', array_column($positions, 'position_id')), true)) {
    $positionId = null;
}
$shifts = find_team_schedule($pdo, $siteId, $week, $positionId);
$perDay = [];
foreach ($shifts as $s) {
    $perDay[local_dt($s['starts_at'], $tz)->format('Y-m-d')][] = $s;
}
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify("+$i days");
    $k = $d->format('Y-m-d');
    $days[] = [$k, $d->format('D'), $d->format('j'), count($perDay[$k] ?? []), $k === $today];
}
$groups = [];
foreach ($perDay[$day] ?? [] as $s) {
    $g = &$groups[$s['position_id']];
    $g ??= ['position_id' => (int) $s['position_id'], 'name' => $s['position_name'], 'color' => $s['position_color'], 'rows' => []];
    $g['rows'][] = $s;
    unset($g);
}
$groups = array_values($groups);
log_screen_view($pdo, 'team-schedule');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'week_start' => $week, 'day' => $day, 'time_zone' => $tz,
        'positions' => array_map(static fn (array $g): array => ['position_id' => $g['position_id'], 'name' => $g['name'],
            'shifts' => array_map('present_team_row', $g['rows'])], $groups)]);
}
$prev = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
render_screen('Team schedule', view('schedule/team.php', ['site' => $site, 'sites' => held_sites(), 'week' => $week, 'prev' => $prev, 'next' => $weekEnd, 'days' => $days, 'day' => $day,
    'groups' => $groups, 'positions' => $positions, 'positionId' => $positionId, 'me' => $me, 'zone' => show_zone()]),
    ['activeNav' => 'team-schedule', 'screen' => 'team-schedule', 'entity' => 'shift']);
