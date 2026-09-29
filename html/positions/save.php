<?php
declare(strict_types=1);
/**
 * Action `position_save` (log `position.save`): settings.manage at the restaurant adds a position (`site`) or changes one (`position`) — name (unique among live positions), area, colour, order, and the
 * certifications it needs (this restaurant's own). A field left out stays. THE DEFAULT RATE IS NOT HERE: position_rate_update. The log carries no rate.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('position') ?? request_integer('position_id');
$cur = null;
if ($id !== null) {
    $site = people_record_site(position_site_id($pdo, $id), 'That position is not here.');
    $cur = find_position_for_edit($pdo, $id) ?? refuse(404, 'That position is not here.');
    if ($cur['archived_at'] !== null) {
        refuse(422, 'That position is archived.');
    }
} else {
    $site = people_named_site();
}
require_right('settings.manage', $site);
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 60) {
    refuse(422, 'Give the position a name of up to 60 characters.');
}
$area = req_has('area') ? (string) req_val('area') : (string) ($cur['area'] ?? 'front');
if (!isset(POSITION_AREAS[$area])) {
    refuse(422, 'The area is front, kitchen, bar, management or other.');
}
$color = req_has('color') ? (string) req_val('color') : (string) ($cur['color'] ?? '#6c757d');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    refuse(422, 'The colour is like #3454d1.');
}
$order = req_has('sort_order') ? req_val('sort_order') : (string) ($cur['sort_order'] ?? 0);
if (filter_var($order, FILTER_VALIDATE_INT) === false || (int) $order < 0 || (int) $order > 1000) {
    refuse(422, 'The order is a whole number from 0 to 1000.');
}
$list = request_list('certifications');
$kinds = $list === null ? ($cur['kind_ids'] ?? []) : resolve_kind_ids($pdo, $list, $site);
$f = ['name' => $name, 'area' => $area, 'color' => strtolower($color), 'sort_order' => (int) $order, 'kind_ids' => $kinds];
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $id, $f): array {
    $pdo->beginTransaction();
    $r = save_position($pdo, $site, $id, $f, $me);
    log_activity($pdo, 'position.save', 'position', $r['id'], ['scope_id' => $site, 'after' => ['position_id' => $r['id']] + $r['after']] + ($r['before'] === null ? [] : ['before' => $r['before']]));
    $pdo->commit();
    return $r;
});
people_done(($id === null ? 'Added ' : 'Saved ') . $f['name'], $r['id'], people_land(return_path('/positions/?site=' . $site), 'ps_saved', 'position-' . $r['id']), 'positionChanged', ['position_id' => $r['id']]);
