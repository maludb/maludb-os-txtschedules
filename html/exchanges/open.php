<?php
declare(strict_types=1);
/** Action `shift_open` (log `exchange.open`): an unassigned shift goes up for anyone at the main restaurant to take. A shift lead or a builder. */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = request_shift_id();
$site = require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
require_any_right(['coverage.fill', 'schedule.build'], $site);
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $shiftId, $note): array {
    $pdo->beginTransaction();
    $id = create_exchange($pdo, 'open', $shiftId, $me, null, null, $note);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.open', $st, ['kind' => 'open']);
    $pdo->commit();
    return $st;
});
exchange_done('Opened the ' . exchange_did_shift($st) . ' for anyone to take', $st['exchange_id'], '/exchanges/' . $st['exchange_id']);
