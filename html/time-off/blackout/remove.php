<?php
declare(strict_types=1);
/** Action `blackout_remove` (log `blackout.remove`): settings.manage opens a blacked-out date again. */
require_once dirname(__DIR__, 3) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('blackout') ?? request_integer('blackout_id') ?? refuse(422, 'Say which date.');
$site = require_record_site(blackout_site_id($pdo, $id), 'That date is not blacked out.');
require_right('settings.manage', $site);
$row = timeoff_guard($pdo, static function () use ($pdo, $me, $site, $id): array {
    $pdo->beginTransaction();
    $row = remove_blackout($pdo, $id, $me);
    log_activity($pdo, 'blackout.remove', 'blackout', $id, ['scope_id' => $site, 'before' => ['on_date' => $row['on_date'], 'reason' => $row['reason']], 'after' => ['blackout_id' => $id, 'removed' => true]]);
    $pdo->commit();
    return $row;
});
time_off_done('Opened ' . format_date($row['on_date']) . ' again', $id, land_with_notice(return_path('/site/time-off?site=' . $site), 'to_blackout_removed'), ['blackout_id' => $id]);
