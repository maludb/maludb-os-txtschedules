<?php
declare(strict_types=1);
/**
 * Action `template_save` (log `template.save`): save a week's shifts as a template (weekday, times, position, break, the person when chosen). `template` renames an existing one
 * (and, with `week`, replaces its shifts). The location is the template's page, ending in its id.
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$siteId = request_build_site();
$name = req_val('name') ?? '';
$tplId = request_integer('template');
if ($tplId !== null && template_site_id($pdo, $tplId) !== $siteId) {
    refuse(404, 'That template is not here any more.');
}
$weekId = request_week_id();
if ($weekId === null && req_val('week_start') !== null && req_val('week_start') !== '') {
    $site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
    $d = request_date('week_start');
    $weekId = is_string($d) ? (find_week($pdo, $siteId, week_start_of($d, (int) $site['week_start']))['week_id'] ?? refuse(422, 'That week has no shifts yet.')) : refuse(422, 'Give week_start as a date like 2026-10-05.');
}
if ($weekId !== null && week_site_id($pdo, $weekId) !== $siteId) {
    refuse(404, 'Week not found.');
}
if ($weekId === null && $tplId === null) {
    refuse(422, 'Say which week to save as a template (week).');
}
$r = build_guard($pdo, static function () use ($pdo, $me, $siteId, $name, $weekId, $tplId): array {
    $pdo->beginTransaction();
    $r = save_template($pdo, $siteId, $name, $weekId, $tplId, $me);
    log_activity($pdo, 'template.save', 'template', $r['template_id'], ['scope_id' => $siteId, 'after' => ['template_id' => $r['template_id'], 'name' => $name, 'shift_count' => $r['shift_count'], 'from_week' => $weekId, 'created' => $r['created']]]);
    $pdo->commit();
    return $r;
});
build_done(($r['created'] ? 'Saved' : 'Updated') . ' the template ' . $name . ' (' . $r['shift_count'] . ' shifts)', $r['template_id'], '/templates/' . $r['template_id'], ['template_id' => $r['template_id'], 'shift_count' => $r['shift_count']]);
