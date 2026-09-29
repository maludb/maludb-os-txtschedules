<?php
declare(strict_types=1);
/** Action `shift_give` (log `exchange.give`): the holder offers a shift to one named colleague, who accepts or refuses. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = request_shift_id();
$site = require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
require_right('market.trade', $site);
$to = request_integer('colleague') ?? request_integer('to_member_id');
if ($to === null || !in_array($to, array_map('intval', array_column(find_give_candidates($pdo, $shiftId, $me), 'member_id')), true)) {
    refuse(422, 'Pick a colleague here who works that position.');
}
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $shiftId, $to, $note): array {
    $pdo->beginTransaction();
    $id = create_exchange($pdo, 'give', $shiftId, $me, $to, null, $note);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.give', $st, ['kind' => 'give', 'to_member_id' => $to, 'swap_shift_id' => null]);
    exchange_notify($pdo, 'asked', $st, $me);
    $pdo->commit();
    return $st;
});
exchange_done('Asked ' . ($st['to_name'] ?? 'a colleague') . ' to take your ' . exchange_did_shift($st), $st['exchange_id'], '/exchanges/' . $st['exchange_id']);
