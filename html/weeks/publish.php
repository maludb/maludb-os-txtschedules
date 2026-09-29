<?php
declare(strict_types=1);
/**
 * Action `week_publish` (log `week.publish`; before it one `rule.override` row per soft warning): the draft goes live and each person with a shift is told once. The warnings
 * are re-asked here, server-side: a hard one stops it, a soft one needs `override_reason`. An agent's call is paused by the kernel first (external_send).
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_week_id() ?? refuse(422, 'Say which week (week).');
$site = require_record_site(week_site_id($pdo, $id), 'Week not found.');
require_right('schedule.build', $site);
$reason = req_val('override_reason');
$r = build_guard($pdo, static function () use ($pdo, $me, $id, $reason): array {
    $pdo->beginTransaction();
    $r = publish_week($pdo, $id, $me, $reason === '' ? null : $reason);
    log_activity($pdo, 'week.publish', 'week', $id, ['scope_id' => $r['week']['site_id'], 'after' => ['week_id' => $id, 'shift_count' => $r['shift_count'], 'warnings' => $r['warnings'],
        'overridden' => $r['overridden'], 'people_told' => $r['people_told']]]);
    $pdo->commit();
    return $r;
});
$w = $r['week'];
build_done('Published the week of ' . week_label($w['week_start']) . ' — ' . $r['shift_count'] . ' shifts, ' . $r['people_told'] . ' people told', $id, builder_url($w['site_id'], $w['week_start']),
    ['week_id' => $id, 'shift_count' => $r['shift_count'], 'people_told' => $r['people_told'], 'open_left' => $r['open_left'], 'overridden' => $r['overridden']]);
