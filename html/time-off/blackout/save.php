<?php
declare(strict_types=1);
/** Action `blackout_save` (log `blackout.save`): settings.manage at the restaurant blacks a date out — no time off may be asked that touches it (requests already asked stay as they are). */
require_once dirname(__DIR__, 3) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = request_named_site();
require_right('settings.manage', $site);
$date = request_date('on_date');
if ($date === null || $date === false) {
    refuse(422, 'Give the date like 2026-12-24.');
}
$reason = (string) req_val('reason');
$id = timeoff_guard($pdo, static function () use ($pdo, $me, $site, $date, $reason): int {
    $pdo->beginTransaction();
    $id = save_blackout($pdo, $site, $date, $reason, $me);
    log_activity($pdo, 'blackout.save', 'blackout', $id, ['scope_id' => $site, 'after' => ['blackout_id' => $id, 'on_date' => $date, 'reason' => trim($reason)]]);
    $pdo->commit();
    return $id;
});
time_off_done('Blacked out ' . format_date($date) . ': ' . trim($reason), $id, land_at(return_path('/site/time-off?site=' . $site), 'to_blackout_saved', 'blackout-' . $id), ['blackout_id' => $id]);
