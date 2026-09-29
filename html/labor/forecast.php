<?php
declare(strict_types=1);
/**
 * Action `forecast_save` (log `forecast.update`, one row a cell): schedule.build at the restaurant types the covers expected in a day-part on a day — one cell (`on_date`, `day_part` by id, key,
 * name or service name, `expected_covers`) or the grid's many (`cells[<day-part id>][<date>]`; an empty box clears the cell, an unchanged one is left alone, so a number Reservations gave stays
 * Reservations' until somebody types over it). A typed cell is `manual` and Reservations' fill will not overwrite it. before/after carry covers and source — no pay.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = people_named_site();
require_right('schedule.build', $site);
$parts = find_day_parts($pdo, $site);
$byId = array_column($parts, null, 'day_part_id');
$work = [];                                        // [dayPartId, date, ?covers (null = clear)]
$grid = $_POST['cells'] ?? null;
if (is_array($grid)) {
    foreach ($grid as $dp => $days) {
        if (!is_array($days) || !isset($byId[(int) $dp]) || !ctype_digit((string) $dp)) {
            refuse(422, 'That day-part is not this restaurant\'s.');
        }
        foreach ($days as $date => $v) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if ($d === false || $d->format('Y-m-d') !== (string) $date) {
                refuse(422, 'Give each date as 2026-10-05.');
            }
            $work[] = [(int) $dp, (string) $date, trim((string) $v) === '' ? null : request_covers_value((string) $v)];
        }
    }
    if ($work === []) {
        refuse(422, 'Give the covers to save.');
    }
} else {
    $date = request_date('on_date');
    if ($date === null || $date === false) {
        refuse(422, 'Give on_date as a date like 2026-10-05.');
    }
    $ref = req_val('day_part', 'day_part_id') ?? refuse(422, 'Say which day-part.');
    $part = find_day_part($pdo, $site, $ref) ?? refuse(422, 'There is no day-part called ' . $ref . ' here.');
    $work[] = [$part['day_part_id'], $date, request_covers_value(req_val('expected_covers', 'covers'))];
}
$single = !is_array($grid);
$saved = people_guard($pdo, static function () use ($pdo, $me, $site, $work, $single, $byId): array {
    $pdo->beginTransaction();
    $n = 0;
    $first = null;
    $last = ['date' => null, 'covers' => null, 'part' => null];
    foreach ($work as [$dp, $date, $covers]) {
        $cur = forecast_cell($pdo, $site, $date, $dp);
        $tag = ['on_date' => $date, 'day_part' => $byId[$dp]['name']];
        if ($covers === null) {                                    // the grid's empty box
            if ($cur === null) {
                continue;
            }
            clear_forecast($pdo, $site, $date, $dp);
            log_activity($pdo, 'forecast.update', 'forecast_cover', $cur['id'], ['scope_id' => $site, 'before' => $tag + ['covers' => $cur['covers'], 'source' => $cur['source']], 'after' => $tag + ['covers' => null, 'cleared' => true]]);
            $n++;
            $first ??= $cur['id'];
            continue;
        }
        if (!$single && $cur !== null && $cur['covers'] === $covers) {
            continue;                                              // untouched in the grid: its source stays
        }
        if ($single && $cur !== null && $cur['covers'] === $covers && $cur['source'] === 'manual') {
            continue;
        }
        $r = save_forecast($pdo, $site, $date, $dp, $covers, $me);
        log_activity($pdo, 'forecast.update', 'forecast_cover', $r['id'], ['scope_id' => $site, 'after' => $tag + $r['after']] + ($r['before'] === null ? [] : ['before' => $tag + $r['before']]));
        $n++;
        $first ??= $r['id'];
        $last = ['date' => $date, 'covers' => $covers, 'part' => $byId[$dp]['name']];
    }
    $pdo->commit();
    return ['n' => $n, 'first' => $first, 'last' => $last];
});
$week = request_week_start('week', find_site_week_start($pdo, $site), false) ?? week_start_of($work[0][1], find_site_week_start($pdo, $site));
$path = return_path(forecast_url($site, $week));
if ($saved['n'] === 0) {
    people_done('Nothing changed', null, people_land($path, 'fc_unchanged'), 'forecastChanged', ['cells' => 0]);
}
people_done($saved['n'] === 1 && $saved['last']['covers'] !== null ? 'Saved ' . $saved['last']['part'] . ' on ' . (new DateTimeImmutable($saved['last']['date']))->format('D M j') . ': ' . $saved['last']['covers'] . ' covers' : 'Saved ' . $saved['n'] . ' cell' . ($saved['n'] === 1 ? '' : 's'),
    $saved['first'], people_land($path, 'fc_saved', 'forecast-cover-' . $saved['first']), 'forecastChanged', ['cells' => $saved['n']]);
