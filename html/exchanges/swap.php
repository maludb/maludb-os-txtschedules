<?php
declare(strict_types=1);
/** Action `shift_swap` (log `exchange.swap`): the holder's shift for a named colleague's shift; the colleague accepts or refuses. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = request_shift_id();
$site = require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
require_right('market.trade', $site);
$to = request_integer('colleague') ?? request_integer('to_member_id');
$swap = request_integer('swap_shift') ?? request_integer('swap_shift_id');
if ($to === null || $swap === null) {
    refuse(422, 'Pick a colleague and one of their shifts.');
}
if (!in_array($to, array_map('intval', array_column(find_give_candidates($pdo, $shiftId, $me), 'member_id')), true)) {
    refuse(422, 'Pick a colleague here who works that position.');
}
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $shiftId, $to, $swap, $note): array {
    $pdo->beginTransaction();
    $id = create_exchange($pdo, 'swap', $shiftId, $me, $to, $swap, $note);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.swap', $st, ['kind' => 'swap', 'to_member_id' => $to, 'swap_shift_id' => $swap]);
    exchange_notify($pdo, 'asked', $st, $me);
    $pdo->commit();
    return $st;
});
exchange_done('Asked ' . ($st['to_name'] ?? 'a colleague') . ' to swap your ' . exchange_did_shift($st), $st['exchange_id'], '/exchanges/' . $st['exchange_id']);
