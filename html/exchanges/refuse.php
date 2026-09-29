<?php
declare(strict_types=1);
/** Action `exchange_refuse` (log `exchange.refuse`): the named colleague says no to a give or a swap. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_exchange_id();
$site = require_record_site(exchange_site_id($pdo, $id), 'That trade is no longer available.');
require_right('market.trade', $site);
$st = exchange_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $status = accept_exchange($pdo, $id, $me, false);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.refuse', $st, ['status' => $status]);
    exchange_notify($pdo, 'refused', $st, $me);
    $pdo->commit();
    return $st;
});
exchange_done('Refused the ' . exchange_did_shift($st), $id, land_with_notice(return_path('/marketplace?tab=forme'), 'refused'));
