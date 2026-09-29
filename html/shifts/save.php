<?php
declare(strict_types=1);
/**
 * Actions `shift_create` (log `shift.create`) and `shift_update` (log `shift.update`) — a DRAFT week's shifts only; a published week's go through shift_add / shift_change.
 * `shift` present = update (an absent field keeps what the shift has; `date` alone moves it to that day); else create at `site`. The rules engine is asked: a hard rule is a 422 in its
 * sentence and nothing is saved; a soft rule is a 422 until `override_reason` comes, then it is saved with one `rule.override` row each. The site is the shift's (an update) or the named one
 * (a create) — never the session's. The location is the shift's own page, ending in its id.
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (req_has('shift', 'shift_id')) {
    $shiftId = 0;
    $siteId = request_shift_site($pdo, $shiftId);
    require_right('schedule.build', $siteId);
    $r = build_guard($pdo, static function () use ($pdo, $me, $shiftId): array {
        $pdo->beginTransaction();
        $r = update_shift($pdo, $shiftId, $me);
        log_shift($pdo, 'shift.update', $shiftId, (int) $r['shift']['site_id'], $r['after'] + ['week_id' => (int) $r['shift']['week_id']], $r['before']);
        $pdo->commit();
        return $r;
    });
    build_done('Changed the ' . shift_did($r['shift']), $shiftId, '/shifts/' . $shiftId, ['shift_id' => $shiftId, 'changed' => array_keys($r['after']), 'overridden' => $r['overridden']]);
}
$siteId = request_build_site();
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$r = build_guard($pdo, static function () use ($pdo, $me, $siteId, $site): array {
    $f = shift_fields_from_request(null, (string) $site['timezone']);
    $pdo->beginTransaction();
    $r = create_shift($pdo, $siteId, $f, $me);
    log_shift($pdo, 'shift.create', $r['shift_id'], $siteId, ['position_id' => $f['position_id'], 'starts_at' => json_ts(utc_text($f['starts'])), 'ends_at' => json_ts(utc_text($f['ends'])),
        'assignee_member_id' => $f['assignee'], 'week_id' => $r['week_id']]);
    $pdo->commit();
    return $r + ['f' => $f];
});
build_done('Added a shift on ' . $r['f']['starts']->format('D M j') . ' ' . shift_time_range(utc_text($r['f']['starts']), utc_text($r['f']['ends']), (string) $site['timezone']), $r['shift_id'], '/shifts/' . $r['shift_id'],
    ['shift_id' => $r['shift_id'], 'week_id' => $r['week_id'], 'overridden' => $r['overridden']]);
