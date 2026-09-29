<?php
declare(strict_types=1);
/**
 * Action `forecast_copy` (log `forecast.copy`): schedule.build at the restaurant copies the covers typed (or copied) in one week into another (`from_week`, `to_week` — any date in each; the
 * restaurant's own week start decides the weeks). A copied cell is marked `copied`; Reservations' cells are never copied and never overwritten. before is not kept — the log carries the
 * counts, and undoing is copying again or typing.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = people_named_site();
require_right('schedule.build', $site);
$ws0 = find_site_week_start($pdo, $site);
$from = request_week_start('from_week', $ws0);
$to = request_week_start('to_week', $ws0);
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $from, $to): array {
    $pdo->beginTransaction();
    $r = copy_forecast($pdo, $site, $from, $to, $me);
    log_activity($pdo, 'forecast.copy', 'forecast', null, ['scope_id' => $site, 'after' => ['from_week' => $from, 'to_week' => $to, 'cells' => $r['cells'], 'skipped_reservations' => $r['skipped_reservations'], 'not_copied' => $r['not_copied']]]);
    $pdo->commit();
    return $r;
});
people_done('Copied ' . $r['cells'] . ' cell' . ($r['cells'] === 1 ? '' : 's') . ' from the week of ' . (new DateTimeImmutable($from))->format('M j') . ' to the week of ' . (new DateTimeImmutable($to))->format('M j'),
    null, people_land(return_path(forecast_url($site, $to)), 'fc_copied'), 'forecastChanged', ['cells' => $r['cells'], 'skipped_reservations' => $r['skipped_reservations'], 'not_copied' => $r['not_copied']]);
