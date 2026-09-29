<?php
declare(strict_types=1);
/**
 * /forecast?site=&week=&day= — covers expected per day-part for the week (screen `forecast`), the staffing ratios, and the headcount they call for beside what is scheduled (ts_staffing_needs; open
 * shifts counted apart). Typed covers, copy last week, fill from Reservations through the kernel (K7 — the button only where the restaurant has a location; the answer degrades to words). schedule.build
 * at the restaurant — a restaurant not held is "Not found." A phone gets day tabs and the day's day-parts; a desktop the grid. COST is not on this page.
 */
require_once dirname(__DIR__) . '/app/features/labor/handler.php';
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
$we = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
$prev = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
$next = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
$days = week_days($ws, $today);
$day = request_local_date('day', ($today >= $ws && $today <= $we) ? $today : $ws);
if ($day < $ws || $day > $we) {
    $day = $ws;
}
$forecast = find_forecast($pdo, $siteId, $ws, $we);
$ratios = find_ratios($pdo, $siteId);
$needs = find_needs($pdo, $siteId, $ws, $we);
$may = ['type' => true, 'ratios' => has_right('settings.manage', $siteId)];
$canFill = site_location_id($pdo, $siteId) !== null;
log_screen_view($pdo, 'forecast');
if (wants_json()) {
    respond_screen(present_forecast($siteId, $ws, $forecast, $ratios, $needs, $may, $canFill));
}
$result = take_labor_result($siteId, $ws);
render_screen('Forecast', view('labor/forecast.php', ['site' => $site, 'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.build', $s['scope_id']))),
    'ws' => $ws, 'we' => $we, 'prev' => $prev, 'next' => $next, 'days' => $days, 'day' => $day, 'today' => $today, 'forecast' => $forecast, 'ratios' => $ratios, 'needRows' => needs_rows($needs, $ratios),
    'may' => $may, 'canFill' => $canFill, 'canLabor' => has_right('labor.view', $siteId), 'result' => $result === null ? [] : fill_result_lines($result), 'notice' => labor_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'forecast', 'screen' => 'forecast', 'entity' => 'forecast', 'recordId' => (string) $siteId]);
