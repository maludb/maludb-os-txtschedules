<?php
declare(strict_types=1);
/** Action `shift_offer` (log `exchange.offer`): the holder puts their shift up for grabs. The site is the shift's, never the session's. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = request_shift_id();
$site = require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
require_right('market.trade', $site);
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $shiftId, $note): array {
    $pdo->beginTransaction();
    $id = create_exchange($pdo, 'offer', $shiftId, $me, null, null, $note);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.offer', $st, ['kind' => 'offer', 'to_member_id' => null, 'swap_shift_id' => null]);
    $pdo->commit();
    return $st;
});
exchange_done('Offered your ' . exchange_did_shift($st), $st['exchange_id'], '/exchanges/' . $st['exchange_id']);
