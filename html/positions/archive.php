<?php
declare(strict_types=1);
/** Action `position_archive` (log `position.archive`): settings.manage archives a position — it stays on old shifts and leaves the pickers; refused while an upcoming scheduled shift uses it. */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('position') ?? request_integer('position_id') ?? refuse(422, 'Say which position.');
$site = people_record_site(position_site_id($pdo, $id), 'That position is not here.');
require_right('settings.manage', $site);
$name = people_guard($pdo, static function () use ($pdo, $me, $id, $site): string {
    $pdo->beginTransaction();
    $name = archive_position($pdo, $id, $me);
    log_activity($pdo, 'position.archive', 'position', $id, ['scope_id' => $site, 'after' => ['position_id' => $id, 'name' => $name, 'archived' => true]]);
    $pdo->commit();
    return $name;
});
people_done('Archived ' . $name, $id, people_land(return_path('/positions/?site=' . $site), 'ps_archived'), 'positionChanged', ['position_id' => $id]);
