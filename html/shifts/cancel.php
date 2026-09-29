<?php
declare(strict_types=1);
/** Action `shift_cancel` (log `shift.cancel`): a shift of a PUBLISHED week is cancelled and kept (never deleted); its live trade is withdrawn and its holder told. Needs a `reason`. An agent's call is paused by the kernel first (other). */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = 0;
$siteId = request_shift_site($pdo, $shiftId);
require_right('schedule.build', $siteId);
$reason = req_val('reason') ?? '';
$r = build_guard($pdo, static function () use ($pdo, $me, $shiftId, $reason): array {
    $pdo->beginTransaction();
    $r = cancel_live_shift($pdo, $shiftId, $reason, $me);
    log_shift($pdo, 'shift.cancel', $shiftId, (int) $r['shift']['site_id'], ['reason' => $reason, 'exchange_cancelled' => $r['exchange_cancelled'], 'notified' => $r['notified'], 'week_id' => (int) $r['shift']['week_id']],
        ['assignee_member_id' => $r['shift']['assignee_member_id'], 'assignee_name' => $r['shift']['assignee_name']]);
    $pdo->commit();
    return $r;
});
build_done('Cancelled the ' . shift_did($r['shift']), $shiftId, '/shifts/' . $shiftId, ['shift_id' => $shiftId, 'notified' => count($r['notified']), 'exchange_cancelled' => $r['exchange_cancelled']]);
