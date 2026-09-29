<?php
declare(strict_types=1);
/** Action `exchange_cancel` (log `exchange.cancel`): withdraw a trade — the person who made it, or someone who builds schedules or approves requests here. The shift stays with its holder. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_exchange_id();
$site = require_record_site(exchange_site_id($pdo, $id), 'That trade is no longer available.');
$before = exchange_state($pdo, $id) ?? refuse(404, 'That trade is no longer available.');
$mine = $before['from_member_id'] === $me || $before['created_by'] === $me;
if (!$mine && !has_right('schedule.build', $site) && !has_right('requests.approve', $site)) {
    refuse(403, 'Only the person who made a trade, or a manager, can withdraw it.');
}
$st = exchange_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $status = cancel_exchange($pdo, $id, $me);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.cancel', $st, ['status' => $status]);
    $pdo->commit();
    return $st;
});
exchange_done('Withdrew the ' . exchange_did_shift($st), $id, land_with_notice(return_path('/shifts/' . $st['shift_id']), 'cancelled'));
