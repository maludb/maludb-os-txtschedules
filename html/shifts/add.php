<?php
declare(strict_types=1);
/** Action `shift_add` (log `shift.add`): a shift in a PUBLISHED week — live at once, its holder told (`shift_changed`). Same fields and rules as shift_create; an agent's call is paused by the kernel first (other). */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$siteId = request_build_site();
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$r = build_guard($pdo, static function () use ($pdo, $me, $siteId, $site): array {
    $f = shift_fields_from_request(null, (string) $site['timezone']);
    $pdo->beginTransaction();
    $r = add_live_shift($pdo, $siteId, $f, $me);
    log_shift($pdo, 'shift.add', $r['shift_id'], $siteId, shift_log_state($r['shift']) + ['week_id' => $r['week_id'], 'notified' => $r['notified']]);
    $pdo->commit();
    return $r;
});
build_done('Added a ' . shift_did($r['shift']) . ' — live now', $r['shift_id'], '/shifts/' . $r['shift_id'], ['shift_id' => $r['shift_id'], 'week_id' => $r['week_id'], 'notified' => count($r['notified']), 'overridden' => $r['overridden']]);
