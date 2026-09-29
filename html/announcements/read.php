<?php
declare(strict_types=1);
/** Action `announcement_read` (log `announcement.read`, quiet): the person marks an announcement that was posted to THEM as read — once; a second tap changes nothing. What is not theirs does not exist to them. */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('announcement') ?? request_integer('announcement_id') ?? refuse(422, 'Say which announcement.');
$a = find_announcement($pdo, $id) ?? refuse(404, 'That announcement is not here.');
$site = people_record_site($a['site_id'], 'That announcement is not here.');
$first = people_guard($pdo, static function () use ($pdo, $me, $id, $site): bool {
    $pdo->beginTransaction();
    $first = mark_read($pdo, $id, $me);
    if ($first) {
        log_activity($pdo, 'announcement.read', 'announcement', $id, ['scope_id' => $site, 'after' => ['announcement_id' => $id]]);
    }
    $pdo->commit();
    return $first;
});
people_done('Marked "' . $a['title'] . '" as read', $id, people_land(return_path('/announcements/?site=' . $site), 'an_read', 'announcement-' . $id), 'announcementChanged', ['first' => $first]);
