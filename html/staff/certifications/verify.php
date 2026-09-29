<?php
declare(strict_types=1);
/** Action `certification_verify` (log `certification.verify`; an agent's call pauses — approval `other`): schedule.build at the card's restaurant marks a card verified (`verified` yes, the default) or not. */
require_once dirname(dirname(__DIR__, 2)) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('certification') ?? request_integer('certification_id') ?? refuse(422, 'Say which card.');
$snap = certification_snapshot($pdo, $id) ?? refuse(404, 'That card is not here.');
$site = people_record_site($snap['site_id'], 'That card is not here.');
require_right('schedule.build', $site);
$yes = people_yes('verified', true);
people_guard($pdo, static function () use ($pdo, $me, $id, $site, $snap, $yes): void {
    $pdo->beginTransaction();
    verify_certification($pdo, $id, $yes, $me);
    log_activity($pdo, 'certification.verify', 'certification', $id, ['scope_id' => $site, 'after' => ['member_id' => $snap['member_id'], 'kind_id' => $snap['kind_id'], 'verified' => $yes]]);
    $pdo->commit();
});
people_done(($yes ? 'Verified ' : 'Marked as not checked: ') . $snap['member_name'] . '\'s ' . $snap['kind_name'] . ' card', $id,
    people_land(return_path('/certifications/?site=' . $site), $yes ? 'st_cert_verified' : 'st_cert_unverified', 'certification-' . $id), 'certificationChanged', ['verified' => $yes]);
