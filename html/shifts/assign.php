<?php
declare(strict_types=1);
/**
 * Action `shift_assign` (log `shift.assign`): put a person on a DRAFT shift, or empty the field to leave it open. The rules are asked as for a save (a hard rule refuses; a soft one needs
 * `override_reason`). schedule.build at the shift's site: a shift lead fills gaps of the PUBLISHED schedule through coverage (a trade), never by editing a draft — 403 here.
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = 0;
$siteId = request_shift_site($pdo, $shiftId);
require_right('schedule.build', $siteId);
if (!req_has('assignee', 'assignee_member_id')) {
    refuse(422, 'Say who (assignee) — empty leaves the shift open.');
}
$who = req_val('assignee', 'assignee_member_id');
$member = $who === null || $who === '' || $who === '0' ? null : (filter_var($who, FILTER_VALIDATE_INT) !== false && (int) $who > 0 ? (int) $who : refuse(422, 'Pick a person from the list, or leave the shift open.'));
$reason = req_val('override_reason');
$r = build_guard($pdo, static function () use ($pdo, $me, $shiftId, $member, $reason): array {
    $pdo->beginTransaction();
    $r = assign_shift($pdo, $shiftId, $member, $me, $reason === '' ? null : $reason);
    log_shift($pdo, 'shift.assign', $shiftId, (int) $r['shift']['site_id'],
        ['assignee_member_id' => $member, 'assignee_name' => $r['assignee_name'], 'via' => 'builder', 'week_id' => (int) $r['shift']['week_id']],
        ['assignee_member_id' => $r['shift']['assignee_member_id'], 'assignee_name' => $r['shift']['assignee_name']]);
    $pdo->commit();
    return $r;
});
build_done(($r['assignee_name'] === null ? 'Left the ' . shift_did($r['shift']) . ' open' : 'Put ' . $r['assignee_name'] . ' on the ' . shift_did($r['shift'])), $shiftId, '/shifts/' . $shiftId,
    ['shift_id' => $shiftId, 'assignee_member_id' => $member, 'overridden' => $r['overridden']]);
