<?php
declare(strict_types=1);
/** Action `week_create` (log `week.create`): start a restaurant's week as an empty DRAFT (any date in the week; the site's week start decides). Harmless when it exists. */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$siteId = request_build_site();
$date = request_date('week_start');
if ($date === null || $date === false) {
    refuse(422, 'Give week_start as a date like 2026-10-05.');
}
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$ws = week_start_of($date, (int) $site['week_start']);
$week = build_guard($pdo, static function () use ($pdo, $siteId, $ws, $me): array {
    $pdo->beginTransaction();
    $w = ensure_week($pdo, $siteId, $ws, $me);
    if ($w['created']) {
        log_activity($pdo, 'week.create', 'week', $w['week_id'], ['scope_id' => $siteId, 'after' => ['week_id' => $w['week_id'], 'week_start' => $ws, 'via' => 'week_create']]);
    }
    $pdo->commit();
    return $w;
});
build_done(($week['created'] ? 'Started' : 'Found') . ' the week of ' . week_label($ws), $week['week_id'], builder_url($siteId, $ws), ['week_id' => $week['week_id'], 'week_start' => $ws, 'status' => $week['status'], 'created' => $week['created']]);
