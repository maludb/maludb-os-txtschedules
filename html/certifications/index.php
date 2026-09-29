<?php
declare(strict_types=1);
/**
 * /certifications/?site=&state=&position= — a restaurant's certification kinds and the manager's list (screen `certifications`; schedule.build at the restaurant): who is EXPIRED, DUE within the kind's
 * warning days, MISSING one for a position they work, or has one TO VERIFY — most urgent first; each row's action is Verify or Add the card. Kinds are the restaurant's own (settings.manage adds and changes them).
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_right('schedule.build', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$state = request_string('state');
$state = isset(CERT_STATES[$state]) ? $state : null;
$position = request_integer('position');
$kinds = find_certification_kinds($pdo, $site);
$positions = find_site_positions($pdo, $site);
$posNames = array_column($positions, 'name', 'position_id');
$due = find_certifications_due($pdo, $site, $state, $position);
$counts = array_fill_keys(array_keys(CERT_STATES), 0);
foreach (find_certifications_due($pdo, $site, null, $position) as $r) {
    $counts[$r['state']]++;
}
$may = ['manage' => has_right('settings.manage', $site)];
log_screen_view($pdo, 'certifications');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'counts' => $counts, 'due' => array_map('present_cert_due', $due),
        'kinds' => array_map(static fn (array $k): array => present_cert_kind($k, $posNames), $kinds), 'may' => $may]);
}
render_screen('Certifications', view('certifications/list.php', ['site' => $siteRow, 'kinds' => $kinds, 'due' => $due, 'counts' => $counts, 'state' => $state, 'position' => $position, 'positions' => $positions, 'posNames' => $posNames, 'may' => $may,
    'notice' => staff_notice($_GET['notice'] ?? null), 'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.build', $s['scope_id'])))]),
    ['activeNav' => 'certifications', 'screen' => 'certifications', 'entity' => 'certification']);
