<?php
declare(strict_types=1);
/** Action `exchange_accept` (log `exchange.accept`; on a move also `shift.assign`): the named colleague says yes to a give or a swap. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_exchange_id();
$site = require_record_site(exchange_site_id($pdo, $id), 'That trade is no longer available.');
require_right('market.trade', $site);
$result = exchange_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $before = exchange_state($pdo, $id);
    $status = accept_exchange($pdo, $id, $me, true);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.accept', $st, ['status' => $status]);
    exchange_notify($pdo, 'accepted', $st, $me);
    if ($status === 'approved') {
        log_shift_moves($pdo, $before);
    } elseif ($status === 'pending_approval') {
        $sameDay = exchange_is_same_day(['timezone' => $st['timezone'], 'shift_starts_at' => $st['shift_starts_at'], 'swap_starts_at' => $st['swap_starts_at']]);
        exchange_notify($pdo, 'needs_manager', $st, $me, ['approvers' => find_approver_ids($pdo, (int) $st['site_id'], $sameDay, $me)]);
    }
    $pdo->commit();
    return ['status' => $status, 'st' => $st];
});
exchange_done('Accepted the ' . exchange_did_shift($result['st']), $id,
    land_with_notice(return_path($result['status'] === 'approved' ? '/shifts/' . $result['st']['shift_id'] : '/exchanges/' . $id), $result['status'] === 'approved' ? 'accepted' : 'accepted_pending'),
    ['outcome' => $result['status']]);
