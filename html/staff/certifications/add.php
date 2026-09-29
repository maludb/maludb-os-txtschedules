<?php
declare(strict_types=1);
/**
 * Action `certification_add` (log `certification.add`): a person enters their OWN card, or a manager (schedule.build at the kind's restaurant) enters anyone's. One row for each of the person's restaurants
 * where a kind of that name exists, so a food handler card is typed once. A card a person enters waits for a manager to check it (and counts for the rule meanwhile); one a manager enters is verified at once (D15).
 */
require_once dirname(dirname(__DIR__, 2)) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$member = request_member_id(true);
$kind = req_val('kind', 'kind_id') ?? '';
if ($kind === '') {
    refuse(422, 'Choose the certification.');
}
if ($member !== $me) {
    person_gate_site($pdo, $member, 'schedule.build');
}
$issued = req_val('issued_on') ?: null;
$expires = req_val('expires_on') ?: null;
$ref = req_val('reference') ?: null;
if ($ref !== null && mb_strlen($ref) > 120) {
    refuse(422, 'The reference may be up to 120 characters.');
}
$rows = people_guard($pdo, static function () use ($pdo, $me, $member, $kind, $issued, $expires, $ref): array {
    $pdo->beginTransaction();
    $rows = add_certification($pdo, $member, ctype_digit($kind) ? (int) $kind : $kind, $issued, $expires, $ref, $me);
    foreach ($rows as $c) {
        log_activity($pdo, 'certification.add', 'certification', $c['id'], ['scope_id' => $c['site_id'], 'after' => ['member_id' => $member, 'kind_id' => $c['kind_id'], 'expires_on' => $expires, 'verified' => $c['verified']]]);
    }
    $pdo->commit();
    return $rows;
});
$first = $rows[0];
$name = $pdo->prepare('SELECT k.name, m.display_name FROM certification_kinds k, members m WHERE k.id = :k AND m.id = :m');
$name->execute(['k' => $first['kind_id'], 'm' => $member]);
$n = $name->fetch();
people_done('Added ' . ($member === $me ? 'your' : $n['display_name'] . '\'s') . ' ' . $n['name'] . ' card' . ($first['verified'] ? ' — verified' : ' — waiting for a manager to check'), $first['id'],
    people_land(return_path($member === $me ? '/certifications/mine' : '/staff/' . $member), $first['verified'] ? 'st_cert_added_verified' : 'st_cert_added', 'certification-' . $first['id']), 'certificationChanged',
    ['certification_ids' => array_column($rows, 'id'), 'verified' => $first['verified']]);
