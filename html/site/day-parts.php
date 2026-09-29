<?php
declare(strict_types=1);
/**
 * /site/day-parts?site=&edit=&add= — the restaurant's day-parts (screen `day-parts`; settings.manage at the restaurant): cards with name, start-end and the service name Reservations uses; add and change on the page
 * (`day_part_save`), archive with a confirm (`day_part_archive`; the old forecasts are kept).
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
require_right('settings.manage', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$parts = find_all_day_parts($pdo, $siteId);
$edit = request_integer('edit');
$editing = null;
foreach ($parts as $d) {
    if ($d['day_part_id'] === $edit && !$d['archived']) {
        $editing = $d;
    }
}
$adding = request_string('add') !== '';
log_screen_view($pdo, 'day-parts');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'site' => $site['name'], 'day_parts' => array_map('present_day_part', $parts)]);
}
render_screen('Day-parts', view('site/day-parts.php', ['site' => $site, 'parts' => $parts, 'editing' => $editing, 'adding' => $adding, 'notice' => site_notice($_GET['notice'] ?? null),
    'sites' => array_values(array_filter(held_sites(), static fn (array $x): bool => has_right('settings.manage', $x['scope_id'])))]),
    ['activeNav' => 'day-parts', 'screen' => 'day-parts', 'entity' => 'day_part', 'recordId' => (string) $siteId]);
