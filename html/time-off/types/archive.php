<?php
declare(strict_types=1);
/** Action `time_off_type_archive` (log `timeoff_type.archive`): settings.manage archives a kind of time off — it stays on old requests and balances and leaves the picker. */
require_once dirname(__DIR__, 3) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$typeId = request_integer('time_off_type') ?? request_integer('type') ?? refuse(422, 'Choose the kind of time off.');
$site = require_record_site(time_off_type_site_id($pdo, $typeId), 'That kind of time off is not here.');
require_right('settings.manage', $site);
$before = timeoff_guard($pdo, static function () use ($pdo, $me, $site, $typeId): array {
    $pdo->beginTransaction();
    $before = archive_time_off_type($pdo, $typeId, $me);
    log_activity($pdo, 'timeoff_type.archive', 'time_off_type', $typeId, ['scope_id' => $site, 'after' => ['type_id' => $typeId, 'name' => $before['name'], 'archived' => true]]);
    $pdo->commit();
    return $before;
});
time_off_done('Archived ' . $before['name'], $typeId, land_with_notice(return_path('/site/time-off?site=' . $site), 'to_type_archived'), ['type_id' => $typeId]);
