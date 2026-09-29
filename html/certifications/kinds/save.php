<?php
declare(strict_types=1);
/**
 * Action `certification_kind_save` (log `certification_kind.save`): settings.manage at the restaurant adds a certification kind (`site`) or changes one (`kind`) — name (unique among the restaurant's live kinds),
 * whether an expiry is tracked, days of warning (0–365) and the positions that need it (this restaurant's own; replaced whole). A field left out stays.
 */
require_once dirname(__DIR__, 3) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$kindId = request_integer('kind') ?? request_integer('kind_id');
$cur = null;
if ($kindId !== null) {
    $site = people_record_site(certification_kind_site_id($pdo, $kindId), 'That certification is not here.');
    $cur = find_certification_kind($pdo, $kindId) ?? refuse(404, 'That certification is not here.');
    if ($cur['archived_at'] !== null) {
        refuse(422, 'That certification is archived.');
    }
} else {
    $site = people_named_site();
}
require_right('settings.manage', $site);
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 60) {
    refuse(422, 'Give the certification a name of up to 60 characters.');
}
$warn = req_has('warn_days') ? req_val('warn_days') : (string) ($cur['warn_days'] ?? 30);
if (filter_var($warn, FILTER_VALIDATE_INT) === false || (int) $warn < 0 || (int) $warn > 365) {
    refuse(422, 'Days of warning is a whole number from 0 to 365.');
}
$list = request_list('positions');
$positions = $list === null ? ($cur['position_ids'] ?? []) : resolve_position_ids($pdo, $list, [$site]);
$f = ['name' => $name, 'track_expiry' => people_yes('track_expiry', $cur['track_expiry'] ?? true), 'warn_days' => (int) $warn, 'position_ids' => $positions];
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $kindId, $f): array {
    $pdo->beginTransaction();
    $r = save_certification_kind($pdo, $site, $kindId, $f, $me);
    log_activity($pdo, 'certification_kind.save', 'certification_kind', $r['id'], ['scope_id' => $site, 'after' => ['kind_id' => $r['id']] + $r['after']] + ($r['before'] === null ? [] : ['before' => $r['before']]));
    $pdo->commit();
    return $r;
});
people_done(($kindId === null ? 'Added ' : 'Saved ') . $f['name'], $r['id'], people_land(return_path('/certifications/?site=' . $site), 'ck_saved', 'kind-' . $r['id']), 'certificationChanged', ['kind_id' => $r['id']]);
