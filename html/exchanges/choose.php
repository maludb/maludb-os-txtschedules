<?php
declare(strict_types=1);
/** Action `exchange_choose` (log `exchange.choose` + `shift.assign`; an agent's call pauses): in manager-chooses mode, give the shift to one claimant. */
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
$member = request_integer('member') ?? request_integer('member_id');
if ($member === null) {
    refuse(422, 'Say who gets it.');
}
$st = exchange_guard($pdo, static function () use ($pdo, $me, $id, $member): array {
    $pdo->beginTransaction();
    $before = exchange_state($pdo, $id);
    $status = choose_claim($pdo, $id, $member, $me);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.choose', $st, ['status' => $status, 'to_member_id' => $member]);
    log_shift_moves($pdo, $before);
    exchange_notify($pdo, 'decided', $st, $me, ['word' => 'given to ' . ($st['to_name'] ?? 'someone')]);
    $pdo->commit();
    return $st;
});
exchange_done('Gave the ' . exchange_did_shift($st) . ' to ' . ($st['to_name'] ?? 'the claimant'), $id, land_with_notice(return_path('/exchanges/' . $id), 'chosen'));
