<?php
declare(strict_types=1);
/**
 * /templates/?site=&week= — the restaurant's saved weeks (screen `templates-list`), as cards. With ?week= (a date) each card offers to start that week from it. schedule.build at the site.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('schedule.build', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$week = request_date('week');
$week = is_string($week) ? week_start_of($week, (int) $site['week_start']) : null;
$templates = find_templates($pdo, $siteId);
log_screen_view($pdo, 'templates-list');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'templates' => array_map(static fn (array $t): array => ['template_id' => $t['template_id'], 'name' => $t['name'], 'shift_count' => $t['shift_count'], 'created_at' => json_ts($t['created_at'])], $templates)]);
}
render_screen('Templates', view('templates/list.php', ['site' => $site, 'templates' => $templates, 'week' => $week, 'notice' => null]), ['activeNav' => 'templates-list', 'screen' => 'templates-list', 'entity' => 'template']);
