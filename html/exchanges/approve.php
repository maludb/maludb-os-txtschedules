<?php
declare(strict_types=1);
/**
 * Action `exchange_approve` (log `exchange.approve` + `shift.assign`; an agent's call pauses): a manager (requests.approve), or a shift lead
 * for a trade today or tomorrow where the restaurant lets shift leads, approves a trade waiting for a decision. Nobody decides their own.
 */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_exchange_id();
$site = require_record_site(exchange_site_id($pdo, $id), 'That trade is no longer available.');
if (!has_right('requests.approve', $site) && !has_right('market.approve_day', $site)) {
    refuse(403, 'You may not approve requests here.');
}
$x = find_exchange($pdo, $id) ?? refuse(404, 'That trade is no longer available.');
if (($why = exchange_decide_refusal($x, $me)) !== null) {
    refuse(403, $why);
}
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $id, $note): array {
    $pdo->beginTransaction();
    $before = exchange_state($pdo, $id);
    $status = decide_exchange($pdo, $id, true, $me, $note);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.approve', $st, ['status' => $status, 'note' => $note]);
    log_shift_moves($pdo, $before);
    exchange_notify($pdo, 'decided', $st, $me, ['word' => 'approved', 'note' => $note]);
    $pdo->commit();
    return $st;
});
exchange_done('Approved the ' . exchange_did_shift($st), $id, land_with_notice(return_path('/exchanges/' . $id), 'approved'));
