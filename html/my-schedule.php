<?php
declare(strict_types=1);
/**
 * /my-schedule — my published shifts by week (a strip of seven day chips) or as a list (screen `my-schedule`). ?week=YYYY-MM-DD ?view=list.
 * Only mine and only published (mcp_shifts). Times in each shift's own restaurant zone, the zone named when I hold two.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_login();
require_human();
require_right('schedule.view_own');
$pdo = db();
$me = (int) current_member_id();
$site = find_site_row($pdo, (int) current_site_id()) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$view = request_string('view') === 'list' ? 'list' : 'week';
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$week = week_start_of(request_local_date('week', $today), (int) $site['week_start']);
$weekEnd = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
if ($view === 'week') {
    $shifts = find_my_shifts($pdo, $me, local_midnight_utc($week, $tz), local_midnight_utc($weekEnd, $tz));
    $nextShift = $shifts === [] ? find_next_shift_after($pdo, $me, local_midnight_utc($weekEnd, $tz)) : null;
    if ($shifts === [] && $nextShift === null) {
        $nextShift = null;
    }
} else {
    $shifts = find_my_shifts($pdo, $me, gmdate('Y-m-d H:i:s+00'), gmdate('Y-m-d H:i:s+00', time() + 60 * 86400));
    $nextShift = null;
}
$works = [];
foreach ($shifts as $s) {
    $works[local_dt($s['starts_at'], $s['timezone'])->format('Y-m-d')] = true;
}
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify("+$i days");
    $k = $d->format('Y-m-d');
    $days[] = [$k, $d->format('D'), $d->format('j'), isset($works[$k]), $k === $today];
}
$prev = (new DateTimeImmutable($week, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
$next = $weekEnd;
log_screen_view($pdo, 'my-schedule');
if (wants_json()) {
    respond_screen(['view' => $view, 'week_start' => $week, 'shifts' => array_map('present_shift', $shifts),
                    'next_shift' => $nextShift === null ? null : present_shift($nextShift + ['others' => []])]);
}
$data = ['view' => $view, 'shifts' => $shifts, 'days' => $days, 'week' => $week, 'prev' => $prev, 'next' => $next, 'next_shift' => $nextShift,
         'notice' => notice_words($_GET['notice'] ?? null), 'zone' => show_zone(), 'showSite' => count(held_sites()) > 1];
render_screen('My schedule', view('schedule/my.php', $data), ['activeNav' => 'my-schedule', 'screen' => 'my-schedule', 'entity' => 'shift']);
