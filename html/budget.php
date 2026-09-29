<?php
declare(strict_types=1);
/**
 * /budget?site=&week= — the week's labor budget against scheduled hours and cost (screen `budget`), by area, in total and by day. labor.view at the restaurant — without it the screen is a 403 and
 * nothing of cost is read. Cost is paid hours x the EFFECTIVE rate; an open shift costs nothing until someone holds it; overtime multipliers are not applied (the page says so). Setting a budget is
 * settings.manage (the form shows only to those who hold it).
 */
require_once dirname(__DIR__) . '/app/features/labor/handler.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('labor.view', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$ws = week_start_of(request_local_date('week', $today), (int) $site['week_start']);
$prev = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
$next = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
$areas = find_budget($pdo, $siteId, $ws);
$byDay = find_labor_by_day($pdo, $siteId, $ws, $tz);
$may = ['set' => has_right('settings.manage', $siteId)];
log_screen_view($pdo, 'budget');
if (wants_json()) {
    respond_screen(present_budget($siteId, $ws, $areas, $byDay, (string) $site['currency'], $may));
}
render_screen('Budget', view('labor/budget.php', ['site' => $site, 'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('labor.view', $s['scope_id']))),
    'ws' => $ws, 'prev' => $prev, 'next' => $next, 'days' => week_days($ws, $today), 'areas' => $areas, 'byDay' => $byDay, 'may' => $may, 'currency' => (string) $site['currency'], 'notice' => labor_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'budget', 'screen' => 'budget', 'entity' => 'labor_budget', 'recordId' => (string) $siteId]);
