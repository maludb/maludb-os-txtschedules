<?php
declare(strict_types=1);
/**
 * /weeks/publish-confirm?site=&week= — the publish summary page (screen `week-publish`): the week's shifts, the people who will be told, the open shifts left, the hours, the cost (labor.view only),
 * EVERY soft warning with the person and rule and a reason box when there are any, then "Publish and tell staff" (week_publish). A hard warning stops it here too. schedule.build at the site.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
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
$week = find_week($pdo, $siteId, $ws) ?? refuse(404, 'That week has not been started.');
$shifts = array_values(array_filter(find_week_shifts($pdo, $week['week_id']), static fn (array $s): bool => $s['status'] === 'scheduled'));
$warn = $week['status'] === 'draft' ? week_warnings($pdo, $week['week_id']) : [];
$canLabor = has_right('labor.view', $siteId);
$people = [];
$hours = 0.0;
$cost = 0.0;
$open = 0;
foreach ($shifts as $s) {
    $hours += (float) $s['paid_hours'];
    if ($s['assignee_member_id'] === null) { $open++; } else { $people[$s['assignee_member_id']] = true; }
    if ($canLabor && $s['cost'] !== null) { $cost += (float) $s['cost']; }
}
$summary = ['week_id' => $week['week_id'], 'week_start' => $ws, 'status' => $week['status'], 'shifts' => count($shifts), 'people_told' => count($people), 'open_left' => $open, 'hours' => round($hours, 2),
            'soft' => count(array_filter($warn, static fn (array $w): bool => $w['severity'] === 'soft')), 'hard' => count(array_filter($warn, static fn (array $w): bool => $w['severity'] === 'hard'))];
log_screen_view($pdo, 'week-publish');
if (wants_json()) {
    respond_screen($summary + ($canLabor ? ['cost' => round($cost, 2)] : []) + ['warnings' => array_map(static fn (array $w): array => ['shift_id' => $w['shift_id'], 'name' => $w['display_name'], 'rule' => $w['rule_key'], 'severity' => $w['severity'], 'message' => $w['message']], $warn)]);
}
render_screen('Publish the week', view('builder/publish.php', ['site' => $site, 'week' => $week, 'ws' => $ws, 'summary' => $summary, 'warn' => $warn, 'shifts' => $shifts, 'canLabor' => $canLabor, 'cost' => $cost, 'currency' => (string) $site['currency']]),
    ['activeNav' => 'builder', 'screen' => 'week-publish', 'entity' => 'week', 'recordId' => (string) $week['week_id']]);
