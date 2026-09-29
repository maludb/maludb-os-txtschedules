<?php
declare(strict_types=1);
/**
 * Action `shift_pickup` (log `exchange.claim`; on a move also `shift.assign`): take an offered, open or coverage shift. The database decides:
 * main restaurant, cutoff, overlap, hard rules, one winner. approved | pending_approval | claimed, or the database's sentence as a 422.
 */
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
    $outcome = claim_exchange($pdo, $id, $me);
    $st = exchange_state($pdo, $id);
    $warnings = claim_warnings($pdo, $id, $me);
    log_exchange($pdo, 'exchange.claim', $st, ['outcome' => $outcome, 'warnings' => $warnings]);
    if ($outcome === 'approved') {
        log_shift_moves($pdo, $before);
        exchange_notify($pdo, 'taken', $st, $me);
        if ($st['kind'] === 'coverage') {
            $inv = $pdo->prepare('SELECT member_id FROM exchange_invitees WHERE exchange_id = :x AND member_id <> :m');
            $inv->execute(['x' => $id, 'm' => $me]);
            exchange_notify($pdo, 'gone', $st, $me, ['invitees' => array_map('intval', $inv->fetchAll(PDO::FETCH_COLUMN))]);
        }
    } elseif ($outcome === 'pending_approval') {
        $sameDay = exchange_is_same_day(['timezone' => $st['timezone'], 'shift_starts_at' => $st['shift_starts_at'], 'swap_starts_at' => $st['swap_starts_at']]);
        exchange_notify($pdo, 'needs_manager', $st, $me, ['approvers' => find_approver_ids($pdo, (int) $st['site_id'], $sameDay, $me)]);
    }
    $pdo->commit();
    return ['outcome' => $outcome, 'st' => $st];
});
$notice = ['approved' => 'claim_approved', 'pending_approval' => 'claim_pending', 'claimed' => 'claim_noted'][$result['outcome']] ?? 'claim_approved';
$words = notice_words($notice)[1];
$land = return_path($result['outcome'] === 'approved' ? '/shifts/' . $result['st']['shift_id'] : '/marketplace');
exchange_done($words, $id, land_with_notice($land, $notice), ['outcome' => $result['outcome']]);
