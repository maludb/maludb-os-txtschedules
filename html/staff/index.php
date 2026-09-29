<?php
declare(strict_types=1);
/**
 * /staff/?site=&q=&position=&on_schedule= — the restaurant's staff as cards (screen `staff-list`; schedule.build at the restaurant): name, positions, main restaurant, an expired-certification chip. A
 * name opens the person. Never a wage, an email or a phone here.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_right('schedule.build', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$q = request_string('q');
$position = request_integer('position');
$on = request_string('on_schedule');
$page = max(1, request_integer('page') ?? 1);
$found = find_staff($pdo, ['site_id' => $site, 'q' => $q, 'position_id' => $position, 'on_schedule' => in_array($on, ['yes', 'no'], true) ? $on : null], $page);
$positions = find_site_positions($pdo, $site);
log_screen_view($pdo, 'staff-list');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'page' => $found['page'], 'more' => $found['more'], 'staff' => array_map('present_staff_row', $found['rows'])]);
}
render_screen('Staff', view('staff/list.php', ['site' => $siteRow, 'rows' => $found['rows'], 'more' => $found['more'], 'page' => $page, 'q' => $q, 'position' => $position, 'on' => $on, 'positions' => $positions,
    'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.build', $s['scope_id']))), 'notice' => staff_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'staff-list', 'screen' => 'staff-list', 'entity' => 'staff']);
