<?php
declare(strict_types=1);
/**
 * /staff/{id}/edit — change a person's profile (screen `staff-edit`; schedule.build at a restaurant they work at): main restaurant, weekly hours limit, minor (with the date it ends), on or off the schedule,
 * notes for managers, the positions they work (one main). PAY IS NOT ON THIS FORM — it is a separate small form on the person's position row (`wage_update`, pay.edit only).
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? refuse(404, 'Not found.');
person_gate_site($pdo, $id, 'schedule.build');
$cur = find_staff_for_edit($pdo, $id) ?? refuse(404, 'Not found.');
$held = held_sites();
$heldIds = array_column($held, 'scope_id');
$works = array_values(array_intersect(member_site_ids($pdo, $id), $heldIds));
$manage = array_values(array_filter($works, static fn (int $s): bool => has_right('schedule.build', $s)));
$names = array_column($held, 'name', 'scope_id');
$byPos = [];
foreach ($manage as $s) {
    $byPos[$s] = find_site_positions($pdo, $s);
}
log_screen_view($pdo, 'staff-edit');
if (wants_json()) {
    respond_screen(['member_id' => $id, 'name' => $cur['display_name'], 'main_site_id' => $cur['main_site_id'], 'max_hours_week' => $cur['max_hours_week'], 'is_minor' => $cur['is_minor'], 'minor_until' => $cur['minor_until'],
        'active' => $cur['active'], 'positions' => array_map(static fn (array $p): array => ['position_id' => $p['position_id'], 'name' => $p['name'], 'is_primary' => $p['is_primary'], 'site_id' => $p['site_id']], $cur['positions']),
        'restaurants' => array_map(static fn (int $s): array => ['site_id' => $s, 'name' => $names[$s]], $manage)]);
}
render_screen('Change ' . $cur['display_name'], view('staff/form.php', ['cur' => $cur, 'manage' => $manage, 'works' => $works, 'names' => $names, 'byPos' => $byPos, 'back' => back_link(),
    'today' => (new DateTimeImmutable('now', new DateTimeZone(site_timezone($manage[0] ?? null))))->format('Y-m-d')]),
    ['activeNav' => 'staff-list', 'screen' => 'staff-edit', 'entity' => 'staff', 'recordId' => $id]);
