<?php
declare(strict_types=1);
/** Action `time_off_type_save` (log `timeoff_type.save`): settings.manage at the restaurant adds a kind of time off (`site`) or changes one (`time_off_type`; a field left out stays). */
require_once dirname(__DIR__, 3) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$typeId = request_integer('time_off_type') ?? request_integer('type');
$cur = null;
if ($typeId !== null) {
    $site = require_record_site(time_off_type_site_id($pdo, $typeId), 'That kind of time off is not here.');
    $cur = find_time_off_type($pdo, $typeId) ?? refuse(404, 'That kind of time off is not here.');
} else {
    $site = request_named_site();
}
require_right('settings.manage', $site);
$order = req_has('sort_order') ? req_val('sort_order') : (string) ($cur['sort_order'] ?? 0);
if (filter_var($order, FILTER_VALIDATE_INT) === false || (int) $order < 0 || (int) $order > 1000) {
    refuse(422, 'The order is a whole number from 0 to 1000.');
}
$f = ['name' => req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? ''), 'paid' => req_yes('paid', $cur['paid'] ?? null), 'tracks_balance' => req_yes('tracks_balance', $cur['tracks_balance'] ?? null),
      'allow_negative' => req_yes('allow_negative', $cur['allow_negative'] ?? null), 'sort_order' => (int) $order];
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $site, $typeId, $f): array {
    $pdo->beginTransaction();
    $r = save_time_off_type($pdo, $site, $typeId, $f, $me);
    log_activity($pdo, 'timeoff_type.save', 'time_off_type', $r['id'], ['scope_id' => $site, 'after' => ['type_id' => $r['id']] + $f]
        + ($r['before'] === null ? [] : ['before' => array_intersect_key($r['before'], $f)]));
    $pdo->commit();
    return $r;
});
time_off_done(($typeId === null ? 'Added ' : 'Saved ') . $f['name'], $r['id'], land_at(return_path('/site/time-off?site=' . $site), 'to_type_saved', 'type-' . $r['id']), ['type_id' => $r['id']]);
