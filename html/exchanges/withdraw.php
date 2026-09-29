<?php
declare(strict_types=1);
/** Action `claim_withdraw` (log `exchange.withdraw`): take back my own waiting claim (manager-chooses mode); only a pending claim. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_exchange_id();
$site = require_record_site(exchange_site_id($pdo, $id), 'That trade is no longer available.');
require_right('market.trade', $site);
$st = exchange_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    withdraw_claim($pdo, $id, $me);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.withdraw', $st, ['status' => 'withdrawn']);
    $pdo->commit();
    return $st;
});
exchange_done('Withdrew your claim on the ' . exchange_did_shift($st), $id, land_with_notice(return_path('/marketplace?tab=claims'), 'withdrawn'));
