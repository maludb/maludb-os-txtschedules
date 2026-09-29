<?php
declare(strict_types=1);
/** Action `certification_remove` (log `certification.remove`): a person removes their own card, a manager (schedule.build at its restaurant) anyone's. The card is kept, marked removed. */
require_once dirname(dirname(__DIR__, 2)) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('certification') ?? request_integer('certification_id') ?? refuse(422, 'Say which card.');
$snap = certification_snapshot($pdo, $id) ?? refuse(404, 'That card is not here.');
$site = people_record_site($snap['site_id'], 'That card is not here.');
if ($snap['member_id'] !== $me) {
    require_right('schedule.build', $site);
}
people_guard($pdo, static function () use ($pdo, $me, $id, $site, $snap): void {
    $pdo->beginTransaction();
    remove_certification($pdo, $id, $me);
    log_activity($pdo, 'certification.remove', 'certification', $id, ['scope_id' => $site, 'after' => ['member_id' => $snap['member_id'], 'kind_id' => $snap['kind_id'], 'removed' => true]]);
    $pdo->commit();
});
people_done('Removed ' . ($snap['member_id'] === $me ? 'your' : $snap['member_name'] . '\'s') . ' ' . $snap['kind_name'] . ' card', $id,
    people_land(return_path($snap['member_id'] === $me ? '/certifications/mine' : '/staff/' . $snap['member_id']), 'st_cert_removed'), 'certificationChanged');
