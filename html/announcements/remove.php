<?php
declare(strict_types=1);
/** Action `announcement_remove` (log `announcement.remove`): the poster, or settings.manage at the restaurant, takes an announcement down. Notices already sent stay sent; it leaves everyone's list. */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('announcement') ?? request_integer('announcement_id') ?? refuse(422, 'Say which announcement.');
$a = announcement_base($pdo, $id) ?? refuse(404, 'That announcement is not here.');
$site = people_record_site($a['site_id'], 'That announcement is not here.');
if ($a['posted_by'] !== $me && !has_right('settings.manage', $site)) {
    refuse(403, 'Only the person who posted it, or a restaurant manager with settings, may remove it.');
}
people_guard($pdo, static function () use ($pdo, $me, $id, $site, $a): void {
    $pdo->beginTransaction();
    remove_announcement($pdo, $id, $me);
    log_activity($pdo, 'announcement.remove', 'announcement', $id, ['scope_id' => $site, 'after' => ['announcement_id' => $id, 'audience' => $a['audience']]]);
    $pdo->commit();
});
people_done('Removed "' . $a['title'] . '"', $id, people_land(return_path('/announcements/?site=' . $site), 'an_removed'), 'announcementChanged');
