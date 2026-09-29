<?php
declare(strict_types=1);
/**
 * Action `forecast_fill` (log `forecast.fill`): schedule.build at the restaurant fills a week's forecast from Reservations' BOOKED covers, asked through the kernel (K7). It degrades, never
 * fails: with no approved connection (or none to this restaurant, or a provider that will not answer) the forecast stands, the page says why, and an agent is told in the same words (409 / 503).
 * Cells a person typed or copied are skipped unless `replace_manual` is yes. The log carries counts only — never the kernel's answer.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = people_named_site();
require_right('schedule.build', $site);
$ws = request_week_start('week_start', find_site_week_start($pdo, $site));
$replace = people_yes('replace_manual', false);
$loc = site_location_id($pdo, $site);
$to = (new DateTimeImmutable($ws, new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
$answer = $loc === null ? ['refusal' => 'no_location'] : fetch_reservation_covers($loc, $ws, $to);       // the kernel call happens BEFORE any transaction
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $ws, $replace, $answer): array {
    $pdo->beginTransaction();
    $r = fill_forecast($pdo, $site, $ws, $replace, $me, $answer);
    log_activity($pdo, 'forecast.fill', 'forecast', null, ['scope_id' => $site, 'after' => ['week_start' => $ws, 'written' => $r['written'], 'skipped' => $r['skipped'], 'unchanged' => $r['unchanged'],
        'unmapped' => $r['unmapped'], 'refusal' => $r['refusal'], 'replace_manual' => $replace]]);
    $pdo->commit();
    return $r;
});
$path = return_path(forecast_url($site, $ws));
if ($r['refusal'] !== null) {
    if (wants_json()) {
        refuse(in_array($r['refusal'], ['provider_failed'], true) ? 503 : 409, reservation_refusal_words($r['refusal']));
    }
    emit_action_status(false, ['error' => reservation_refusal_words($r['refusal']), 'refusal' => $r['refusal']]);
    remember_labor_result(['site_id' => $site, 'week_start' => $ws] + $r);
    saved_go(people_land($path, 'fc_fill_refused'), 'forecastChanged');
}
remember_labor_result(['site_id' => $site, 'week_start' => $ws] + $r);
people_done($r['written'] === 0 ? 'Nothing needed changing — Reservations\' covers are already in' : 'Filled ' . $r['written'] . ' cell' . ($r['written'] === 1 ? '' : 's') . ' from Reservations',
    null, people_land($path, 'fc_filled'), 'forecastChanged', ['written' => $r['written'], 'skipped' => $r['skipped'], 'unmapped' => $r['unmapped']]);
