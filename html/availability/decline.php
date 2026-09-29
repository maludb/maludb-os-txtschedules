<?php
declare(strict_types=1);
/** Action `availability_decline` (log `availability.decline`): a manager (requests.approve at the block's restaurant) declines a pending change; nobody decides their own. The person is told. */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_availability_id();
$b = availability_write_row($pdo, $id) ?? refuse(404, 'That block is not here.');
if (array_intersect(availability_row_sites($pdo, $b['member_id'], $b['scope_id']), array_column(held_sites(), 'scope_id')) === []) {
    refuse(404, 'That block is not here.');
}
if (!availability_right_at($pdo, 'requests.approve', $b)) {
    refuse(403, 'You may not approve requests here.');
}
if ($b['member_id'] === $me) {
    refuse(403, 'You cannot decide your own availability.');
}
$note = request_note();
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $id, $note): array {
    $pdo->beginTransaction();
    $r = decide_availability($pdo, $id, false, $me, $note);
    $row = availability_write_row($pdo, $id);
    $name = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
    $name->execute(['m' => $row['member_id']]);
    $row['member_name'] = (string) $name->fetchColumn();
    log_availability($pdo, 'availability.decline', $row, ['status' => $r['status'], 'note' => $note, 'replaced' => $r['replaced']], ['status' => 'pending']);
    availability_notify($pdo, 'decided', $row, $me, ['word' => 'declined', 'note' => $note]);
    $pdo->commit();
    return $r + ['row' => $row];
});
time_off_done('Declined the change: ' . availability_facts($r['row']), $id, land_with_notice(return_path('/approvals'), 'av_declined'), ['availability_id' => $id, 'status' => $r['status'], 'replaced' => $r['replaced']]);
