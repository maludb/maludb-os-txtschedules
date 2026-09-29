<?php
declare(strict_types=1);
/**
 * Action `coverage_request` (log `exchange.coverage`, an agent's call pauses): ask several eligible people at once; the first to say yes
 * wins. Every invitee must be in ts_coverage_candidates() — asked again here, server-side — so nobody outside the shift's main restaurant is ever asked (D12).
 */
require_once dirname(__DIR__, 2) . '/app/features/exchanges/handler.php';
exchange_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$shiftId = request_shift_id();
$site = require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
require_any_right(['coverage.fill', 'schedule.build'], $site);
$raw = $_POST['members'] ?? $_POST['invitees'] ?? [];
$invitees = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw)), static fn (int $i): bool => $i > 0)));
if ($invitees === []) {
    refuse(422, 'Pick at least one person to ask.');
}
$candidates = array_map('intval', array_column(find_coverage_candidates($pdo, $shiftId), 'member_id'));
if (array_diff($invitees, $candidates) !== []) {
    refuse(422, 'One of those people cannot be asked for this shift.');
}
$note = request_note();
$st = exchange_guard($pdo, static function () use ($pdo, $me, $shiftId, $invitees, $note): array {
    $pdo->beginTransaction();
    $id = create_exchange($pdo, 'coverage', $shiftId, $me, null, null, $note, $invitees);
    $st = exchange_state($pdo, $id);
    log_exchange($pdo, 'exchange.coverage', $st, ['kind' => 'coverage', 'invitees' => count($invitees)]);
    exchange_notify($pdo, 'coverage', $st, $me, ['invitees' => $invitees]);
    $pdo->commit();
    return $st;
});
exchange_done('Asked ' . count($invitees) . ' ' . (count($invitees) === 1 ? 'person' : 'people') . ' to cover the ' . exchange_did_shift($st), $st['exchange_id'], '/exchanges/' . $st['exchange_id']);
