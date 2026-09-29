<?php
declare(strict_types=1);
/** Action `time_off_decline` (log `timeoff.decline`): requests.approve at the request's restaurant declines a pending request. No balance moves. Nobody decides their own; the person is told. */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_time_off_id();
$site = require_record_site(time_off_site_id($pdo, $id), 'Request not found.');
require_right('requests.approve', $site);
$note = request_note();
$s = timeoff_guard($pdo, static function () use ($pdo, $me, $id, $note): array {
    $pdo->beginTransaction();
    $s0 = time_off_snapshot($pdo, $id, true) ?? throw new DomainException('Request not found.');
    if ($s0['member_id'] === $me) {
        $pdo->rollBack();
        refuse(403, 'You cannot decide your own time off.');
    }
    decide_time_off($pdo, $id, false, $me, $note);
    $s = time_off_snapshot($pdo, $id);
    log_time_off($pdo, 'timeoff.decline', $s, ['status' => 'declined', 'note' => $note], ['status' => 'pending']);
    time_off_notify($pdo, 'decided', $s, $me, ['word' => 'declined', 'note' => $note]);
    $pdo->commit();
    return $s;
});
time_off_done('Declined ' . $s['member_name'] . '\'s ' . time_off_facts($s), $id, land_with_notice(return_path('/time-off/' . $id), 'to_declined'), ['request_id' => $id, 'status' => 'declined']);
