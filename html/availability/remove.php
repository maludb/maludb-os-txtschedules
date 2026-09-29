<?php
declare(strict_types=1);
/** Action `availability_remove` (log `availability.remove`): take a block out of effect — the person's own, or a manager's (schedule.build) for someone at their restaurant. It stays in the record. */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_availability_id();
$b = availability_write_row($pdo, $id) ?? refuse(404, 'That block is not here.');
if (array_intersect(availability_row_sites($pdo, $b['member_id'], $b['scope_id']), array_column(held_sites(), 'scope_id')) === [] && $b['member_id'] !== $me) {
    refuse(404, 'That block is not here.');
}
if ($b['member_id'] !== $me && !availability_right_at($pdo, 'schedule.build', $b)) {
    refuse(403, 'You may not build the schedule here.');
}
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $before = remove_availability($pdo, $id, $me);
    log_availability($pdo, 'availability.remove', $before, ['status' => 'replaced'], ['status' => $before['status']]);
    $pdo->commit();
    return $before;
});
time_off_done('Removed: ' . availability_facts($r), $id, land_with_notice(return_path('/availability?member=' . $b['member_id']), 'av_removed'), ['availability_id' => $id]);
