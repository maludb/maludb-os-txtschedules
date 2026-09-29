<?php
declare(strict_types=1);
/**
 * Action `position_rate_update` (log `wage.update`; an agent's call pauses — approval `other`): pay.edit at the restaurant sets a position's DEFAULT hourly rate — what everyone at it earns unless they have a
 * rate of their own (D10) — or clears it when `rate` is empty. Cost is always computed at the current effective rate, so it moves from now on. THE RATE IS NEVER ECHOED, and the log says only that it changed.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('position') ?? request_integer('position_id') ?? refuse(422, 'Say which position.');
$site = people_record_site(position_site_id($pdo, $id), 'That position is not here.');
require_right('pay.edit', $site);
$rate = request_rate();
$name = people_guard($pdo, static function () use ($pdo, $me, $id, $site, $rate): string {
    $pdo->beginTransaction();
    $p = find_position_for_edit($pdo, $id) ?? throw new DomainException('Not found.');
    set_position_rate($pdo, $id, $rate, $me);
    log_activity($pdo, 'wage.update', 'position', $id, ['scope_id' => $site, 'after' => ['scope' => 'position_default', 'position_id' => $id, 'cleared' => $rate === null]]);
    $pdo->commit();
    return $p['name'];
});
people_done(($rate === null ? 'Cleared' : 'Changed') . ' the default rate for ' . $name, $id, people_land(return_path('/positions/?site=' . $site), $rate === null ? 'ps_rate_cleared' : 'ps_rate_set', 'position-' . $id),
    'positionChanged', ['position_id' => $id, 'cleared' => $rate === null]);
