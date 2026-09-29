<?php
declare(strict_types=1);
/**
 * Action `certification_update` (log `certification.update`): a person corrects their own card, or a manager (schedule.build at its restaurant) anyone's — issued_on, expires_on, reference; a field left out stays.
 * A person editing a verified card clears its verification; a manager editing it verifies it (D15).
 */
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
$f = [];
foreach (['issued_on', 'expires_on'] as $k) {
    if (req_has($k)) {
        $f[$k] = req_val($k) ?: null;
    }
}
if (req_has('reference')) {
    $ref = req_val('reference') ?: null;
    if ($ref !== null && mb_strlen($ref) > 120) {
        refuse(422, 'The reference may be up to 120 characters.');
    }
    $f['reference'] = $ref;
}
$r = people_guard($pdo, static function () use ($pdo, $me, $id, $site, $f): array {
    $pdo->beginTransaction();
    $r = update_certification($pdo, $id, $f, $me);
    log_activity($pdo, 'certification.update', 'certification', $id, ['scope_id' => $site, 'before' => $r['before'], 'after' => $r['after']]);
    $pdo->commit();
    return $r;
});
people_done('Changed ' . ($snap['member_id'] === $me ? 'your' : $snap['member_name'] . '\'s') . ' ' . $snap['kind_name'] . ' card', $id,
    people_land(return_path($snap['member_id'] === $me ? '/certifications/mine' : '/staff/' . $snap['member_id']), 'st_cert_updated', 'certification-' . $id), 'certificationChanged', ['verified' => $r['after']['verified']]);
