<?php
declare(strict_types=1);
/**
 * /positions/?site= — a restaurant's positions as cards (screen `positions-list`; schedule.build at the restaurant): colour, area, how many people work it, the certifications it needs. PAY: the default
 * hourly rate — and how many people it reaches — only for labor.view; its form only for pay.edit. Add / change / archive are settings.manage.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_right('schedule.build', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$rows = find_positions($pdo, $site);
$may = ['manage' => has_right('settings.manage', $site), 'labor' => has_right('labor.view', $site), 'pay' => has_right('pay.edit', $site)];
foreach ($rows as &$r) {
    $r['reach'] = $may['labor'] ? people_at_position($pdo, $r['position_id']) : null;
    if (!$may['labor']) {
        $r['default_wage_rate'] = null;          // never printed, never returned: without labor.view a NULL must not say "no rate set"
    }
}
unset($r);
log_screen_view($pdo, 'positions-list');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'positions' => array_map(static fn (array $r): array => ['position_id' => $r['position_id'], 'name' => $r['name'], 'color' => $r['color'], 'area' => $r['area'],
        'people' => $r['people'], 'certifications' => array_column($r['certifications'], 'name')]
        + ($may['labor'] ? ['default_rate' => $r['default_wage_rate'], 'people_with_own_rate' => $r['reach']['own'], 'people_on_default' => $r['reach']['without']] : []), $rows), 'may' => ['manage' => $may['manage'], 'set_rate' => $may['pay']]]);
}
render_screen('Positions', view('positions/list.php', ['site' => $siteRow, 'rows' => $rows, 'may' => $may, 'notice' => staff_notice($_GET['notice'] ?? null),
    'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.build', $s['scope_id'])))]),
    ['activeNav' => 'positions-list', 'screen' => 'positions-list', 'entity' => 'position']);
