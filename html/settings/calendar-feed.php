<?php
declare(strict_types=1);
/**
 * Action `calendar_feed_rotate` (log `calendar_feed.rotate`): the person makes their private calendar link, or a NEW one — the old link then answers 404. The link is shown once (the browser's next
 * page; an API caller's reply) and only its hash is kept. Always about oneself.
 */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$had = find_feed($pdo, $me) !== null;
$token = people_guard($pdo, static function () use ($pdo, $me, $had): string {
    $pdo->beginTransaction();
    $token = rotate_feed($pdo, $me);
    log_activity($pdo, 'calendar_feed.rotate', 'calendar_feed', $me, ['after' => ['replaced' => $had]]);
    $pdo->commit();
    return $token;
});
$link = app_url('/api/v1/calendar/' . $token . '.ics');
if (wants_json()) {
    emit_action_status(true, ['did' => $had ? 'Made a new calendar link (the old one is dead)' : 'Made your calendar link', 'record_id' => $me, 'refresh' => 'calendarChanged', 'link' => $link]);
    saved_go('/settings/?tab=calendar', 'calendarChanged');
}
$_SESSION['calendar_link_once'] = $token;
people_done($had ? 'Made a new calendar link — the old one no longer works' : 'Made your calendar link', $me, people_land('/settings/?tab=calendar', $had ? 'cf_rotated' : 'cf_made', 'calendar-link'), 'calendarChanged');
