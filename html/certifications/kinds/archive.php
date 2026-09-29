<?php
declare(strict_types=1);
/** Action `certification_kind_archive` (log `certification_kind.archive`): settings.manage archives a kind — it leaves the pickers and the rule; cards already entered stay. */
require_once dirname(__DIR__, 3) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('kind') ?? request_integer('kind_id') ?? refuse(422, 'Say which certification.');
$site = people_record_site(certification_kind_site_id($pdo, $id), 'That certification is not here.');
require_right('settings.manage', $site);
$name = people_guard($pdo, static function () use ($pdo, $me, $id, $site): string {
    $pdo->beginTransaction();
    $name = archive_certification_kind($pdo, $id, $me);
    log_activity($pdo, 'certification_kind.archive', 'certification_kind', $id, ['scope_id' => $site, 'after' => ['kind_id' => $id, 'name' => $name, 'archived' => true]]);
    $pdo->commit();
    return $name;
});
people_done('Archived ' . $name, $id, people_land(return_path('/certifications/?site=' . $site), 'ck_archived'), 'certificationChanged', ['kind_id' => $id]);
