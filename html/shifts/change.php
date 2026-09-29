<?php
declare(strict_types=1);
/**
 * Action `shift_change` (log `shift.change`): change a shift of a PUBLISHED week (any field; an absent one stays). The database stamps `changed_after_publish_at`; the holder — the old one and
 * the new one on a reassignment, nobody else — is told; a trade going on it is withdrawn. An agent's call is paused by the kernel first (other).
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = 0;
$siteId = request_shift_site($pdo, $shiftId);
require_right('schedule.build', $siteId);
$r = build_guard($pdo, static function () use ($pdo, $me, $shiftId): array {
    $pdo->beginTransaction();
    $r = change_live_shift($pdo, $shiftId, $me);
    log_shift($pdo, 'shift.change', $shiftId, (int) $r['shift']['site_id'], $r['after'] + ['notified' => $r['notified'], 'exchange_cancelled' => $r['exchange_cancelled'], 'week_id' => (int) $r['shift']['week_id']], $r['before']);
    $pdo->commit();
    return $r;
});
build_done('Changed the ' . shift_did($r['shift']) . ' — ' . count($r['notified']) . ' told', $shiftId, '/shifts/' . $shiftId,
    ['shift_id' => $shiftId, 'changed' => array_keys($r['after']), 'notified' => count($r['notified']), 'exchange_cancelled' => $r['exchange_cancelled'], 'overridden' => $r['overridden']]);
