<?php
declare(strict_types=1);
/** Action `shift_delete` (log `shift.delete`): remove a DRAFT shift. A published shift is 422 "A published shift is cancelled, never deleted." (the database's trigger says so too). */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = 0;
$siteId = request_shift_site($pdo, $shiftId);
require_right('schedule.build', $siteId);
$s = build_guard($pdo, static function () use ($pdo, $me, $shiftId): array {
    $pdo->beginTransaction();
    $s = delete_shift($pdo, $shiftId, $me);
    log_shift($pdo, 'shift.delete', $shiftId, (int) $s['site_id'], ['week_id' => (int) $s['week_id']], shift_log_state($s));
    $pdo->commit();
    return $s;
});
build_done('Deleted the ' . shift_did($s), $shiftId, builder_url((int) $s['site_id'], (string) $s['week_start']), ['shift_id' => $shiftId]);
