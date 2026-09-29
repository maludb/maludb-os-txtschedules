<?php
declare(strict_types=1);
/**
 * /reports/?report=&site=&from=&to=&page=&format= — the reports (screen `reports`): each a table, 50 rows a page, and `?format=csv` — the whole table as a download, logged `report.export`.
 * Each report has its own right at the restaurant (Hours, Open shifts, Trades, Overtime, Overrides: schedule.build; Labor against budget: labor.view; Time off: requests.approve); a report asked without it is a 403.
 * Cost columns exist only for labor.view. No report carries a wage rate.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/csv.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
require_site($siteId);
$avail = available_reports($siteId);
if ($avail === []) {
    require_right('schedule.build', $siteId);                       // says why: nothing here is theirs
}
$key = request_string('report');
if ($key !== '' && !isset(REPORTS[$key])) {
    refuse(404, 'There is no such report.');
}
if ($key !== '' && !isset($avail[$key])) {
    require_right(REPORTS[$key][1], $siteId);                        // a 403 in the right's own words
}
$site = report_site($pdo, $siteId);
$tz = new DateTimeZone((string) $site['timezone']);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$week = week_start_of($today, (int) (find_site_row($pdo, $siteId)['week_start'] ?? 1));
$defFrom = (new DateTimeImmutable($week))->modify('-21 days')->format('Y-m-d');
$defTo = (new DateTimeImmutable($week))->modify('+13 days')->format('Y-m-d');
foreach (['from', 'to'] as $d) {
    if (request_date($d) === false) {
        refuse(422, 'Give ' . $d . ' as a date like 2026-10-05.');
    }
}
try {
    [$from, $to] = report_range(request_date('from'), request_date('to'), $defFrom, $defTo);
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
$table = null;
$total = 0;
$page = max(1, request_integer('page') ?? 1);
if ($key !== '') {
    try {
        $table = run_report($pdo, $key, $siteId, $from, $to, has_right('labor.view', $siteId));
    } catch (DomainException $e) {
        refuse(422, $e->getMessage());
    }
    $total = count($table['rows']);
    if (request_string('format') === 'csv') {
        log_activity($pdo, 'report.export', 'report', null, ['scope_id' => $siteId, 'after' => ['report' => $key, 'site' => $siteId, 'from' => $from, 'to' => $to, 'rows' => $total]]);
        stream_csv('txtschedules-' . $key . '-' . $from . '-to-' . $to, $table['columns'], $table['rows']);
    }
    $pages = max(1, (int) ceil($total / REPORT_PAGE));
    $page = min($page, $pages);
    $table['rows'] = array_slice($table['rows'], ($page - 1) * REPORT_PAGE, REPORT_PAGE);
}
log_activity($pdo, 'screen.view', null, null, ['screen' => 'reports', 'scope_id' => $siteId] + ($key !== '' ? ['after' => ['report' => $key]] : []));
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'site' => $site['name'], 'from' => $from, 'to' => $to, 'reports' => array_map(static fn (string $k, array $r): array => ['report' => $k, 'title' => $r[0], 'about' => $r[2]], array_keys($avail), $avail)]
        + ($table === null ? [] : ['result' => present_report($key, $table, $page, $total, $from, $to)]));
}
render_screen('Reports', view('reports/index.php', ['site' => $site, 'siteId' => $siteId, 'avail' => $avail, 'key' => $key, 'table' => $table, 'from' => $from, 'to' => $to, 'page' => $page, 'total' => $total,
    'sites' => array_values(array_filter(held_sites(), static fn (array $x): bool => available_reports($x['scope_id']) !== []))]),
    ['activeNav' => 'reports', 'screen' => 'reports', 'entity' => 'report', 'recordId' => $key]);
