<?php
declare(strict_types=1);
/**
 * Action `time_off_cancel` (log `timeoff.cancel`): the person who asked (a request still waiting or approved and not over), or an approver at the restaurant, cancels it. An approved one gives its hours
 * back through ts_time_off_post() (`request_cancelled` in the ledger). The other side is told. Shifts an approval opened stay open.
 */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_time_off_id();
$site = require_record_site(time_off_site_id($pdo, $id), 'Request not found.');
$s0 = time_off_snapshot($pdo, $id) ?? refuse(404, 'Request not found.');
$approver = has_right('requests.approve', $site);
if ($s0['member_id'] !== $me && !$approver) {
    refuse(403, 'Only the person who asked, or a manager, can cancel time off.');
}
if (!$approver && strtotime((string) $s0['ends_at']) < time()) {
    refuse(422, 'That time off is over.');
}
$s = timeoff_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $before = time_off_snapshot($pdo, $id, true) ?? throw new DomainException('Request not found.');
    $status = cancel_time_off($pdo, $id, $me);
    $s = time_off_snapshot($pdo, $id);
    $back = $before['status'] === 'approved' && $before['tracks_balance'] ? $before['hours'] : 0.0;
    log_time_off($pdo, 'timeoff.cancel', $s, ['status' => $status, 'hours_returned' => $back], ['status' => $before['status']]);
    time_off_notify($pdo, 'cancelled', $s, $me, ['was' => $before['status']]);
    $pdo->commit();
    return $s;
});
time_off_done('Cancelled ' . time_off_facts($s), $id, land_with_notice(return_path('/time-off/' . $id), 'to_cancelled'), ['request_id' => $id, 'status' => 'cancelled']);
