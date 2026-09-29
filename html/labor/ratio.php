<?php
declare(strict_types=1);
/**
 * Action `ratio_save` (log `ratio.update`): settings.manage at the position's restaurant sets one person per N covers (`covers_per_staff`; empty = no recommendation from covers) and a minimum
 * (`min_staff`) for a position. The recommended headcount is the larger of the minimum and ceil(covers ÷ N). before/after carry the two numbers — no pay.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$pid = request_integer('position') ?? request_integer('position_id') ?? refuse(422, 'Say which position.');
$site = people_record_site(position_site_id($pdo, $pid), 'That position is not here.');
require_right('settings.manage', $site);
$per = request_decimal_value(req_val('covers_per_staff'), 0.01, 9999, 'One person per N covers: N is more than 0 and at most 9999.');
$minText = req_val('min_staff');
$min = $minText === null || $minText === '' ? 0 : (preg_match('/^\d{1,3}$/', $minText) && (int) $minText <= 200 ? (int) $minText : refuse(422, 'The minimum is a whole number from 0 to 200.'));
$name = people_guard($pdo, static function () use ($pdo, $me, $site, $pid, $per, $min): string {
    $pdo->beginTransaction();
    $r = save_ratio($pdo, $site, $pid, $per, $min, $me);
    $p = find_position_for_edit($pdo, $pid) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'ratio.update', 'position', $pid, ['scope_id' => $site, 'after' => ['position_id' => $pid] + $r['after']] + ($r['before'] === null ? [] : ['before' => ['position_id' => $pid] + $r['before']]));
    $pdo->commit();
    return $p['name'];
});
$week = request_week_start('week', find_site_week_start($pdo, $site), false) ?? week_start_of((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'), find_site_week_start($pdo, $site));
people_done('Saved the ratio for ' . $name . ($per === null ? ($min > 0 ? ': at least ' . $min : ': none') : ': one per ' . rtrim(rtrim(number_format($per, 2, '.', ''), '0'), '.') . ' covers' . ($min > 0 ? ', at least ' . $min : '')), $pid,
    people_land(return_path(forecast_url($site, $week)), 'rt_saved', 'ratio-' . $pid), 'ratioChanged', ['position_id' => $pid]);
