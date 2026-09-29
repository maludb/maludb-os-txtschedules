<?php
declare(strict_types=1);
/**
 * /announcements/?site= — what managers posted for a restaurant (screen `announcements-list`; schedule.view_own at it): the ones whose audience includes the person, pinned first, unread marked; a poster
 * (announce.post) also sees how many have read each and by name on tap, and can remove it. No replies, no reactions, no chat (D7).
 */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$site = request_integer('site') ?? (int) current_site_id();
require_right('schedule.view_own', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$items = find_announcements($pdo, $site, $me);
$mayPost = has_right('announce.post', $site);
$mayManage = has_right('settings.manage', $site);
$readers = [];
if ($mayPost) {
    foreach ($items as $a) {
        $readers[$a['announcement_id']] = find_announcement_readers($pdo, $a['announcement_id']);
    }
}
$unread = count(array_filter($items, static fn (array $a): bool => !$a['read_by_me'] && $a['posted_by'] !== $me));
log_screen_view($pdo, 'announcements-list');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'unread' => $unread, 'may_post' => $mayPost,
        'announcements' => array_map(static fn (array $a): array => present_announcement($a, $mayPost ? $readers[$a['announcement_id']] : null), $items)]);
}
render_screen('Announcements', view('announcements/list.php', ['site' => $siteRow, 'items' => $items, 'readers' => $readers, 'mayPost' => $mayPost, 'mayManage' => $mayManage, 'me' => $me, 'unread' => $unread,
    'notice' => notify_notice($_GET['notice'] ?? null), 'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('schedule.view_own', $s['scope_id'])))]),
    ['activeNav' => 'announcements-list', 'screen' => 'announcements-list', 'entity' => 'announcement']);
