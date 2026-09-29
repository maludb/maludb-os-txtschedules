<?php
declare(strict_types=1);
/**
 * Action `day_part_save` (log `day_part.save`): settings.manage at the restaurant adds a day-part (`site`) or changes one (`day_part`; a field left out stays). The service name is the word Reservations
 * uses for the service — how the forecast's fill (slice 5) maps its covers. A day-part may run past midnight; two live ones may not share a name or a service name.
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('day_part') ?? request_integer('day_part_id');
$cur = null;
if ($id !== null) {
    $cur = day_part_row($pdo, $id);
    $site = people_record_site($cur === null ? null : (int) $cur['scope_id'], 'That day-part is not here.');
    require_right('settings.manage', $site);
} else {
    $site = settings_gate_site();
}
$order = req_has('sort_order') ? req_val('sort_order') : null;
if ($order !== null && $order !== '' && (filter_var($order, FILTER_VALIDATE_INT) === false || (int) $order < 0 || (int) $order > 1000)) {
    refuse(422, 'The order is a whole number from 0 to 1000.');
}
$f = ['name' => req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? ''),
      'starts_at' => req_has('starts_at') ? (string) req_val('starts_at') : (string) ($cur['starts_at'] ?? ''),
      'ends_at' => req_has('ends_at') ? (string) req_val('ends_at') : (string) ($cur['ends_at'] ?? ''),
      'service_name' => req_has('service_name') ? (string) req_val('service_name') : ($cur['service_name'] ?? null),
      'sort_order' => $order === null || $order === '' ? null : (int) $order];
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $id, $f): array {
    $pdo->beginTransaction();
    $r = save_day_part($pdo, $site, $id, $f, $me);
    log_activity($pdo, 'day_part.save', 'day_part', $r['id'], ['scope_id' => $site, 'after' => ['day_part_id' => $r['id']] + $r['after']] + ($r['before'] === null ? [] : ['before' => $r['before']]));
    $pdo->commit();
    return $r;
});
people_done(($id === null ? 'Added ' : 'Saved ') . $r['after']['name'], $r['id'], people_land(return_path(day_parts_url($site)), 'dp_saved', 'day-part-' . $r['id']), 'dayPartChanged', ['day_part_id' => $r['id']]);
