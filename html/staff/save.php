<?php
declare(strict_types=1);
/**
 * Action `staff_save` (log `staff.save`): schedule.build at one of the person's restaurants changes their profile — main restaurant (pick-ups happen only there, D4; a change needs schedule.build at the new
 * one, and they must work there), the weekly-hours limit, the minor flag with the date it ends, on or off the schedule, the manager's notes, and the positions they work (replaced whole at the restaurants
 * the caller manages; the first is the main one). A field left out stays. Pay is not on this form: wage_update. The log carries ids and flags — never a rate, never the notes' words.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$member = request_member_id();
$site = person_gate_site($pdo, $member, 'schedule.build');
$cur = find_staff_for_edit($pdo, $member) ?? refuse(404, 'That person is not here.');
$held = array_column(held_sites(), 'scope_id');
$works = member_site_ids($pdo, $member);
$manage = array_values(array_filter(array_intersect($works, $held), static fn (int $s): bool => has_right('schedule.build', $s)));

$main = req_has('main_site', 'main_site_id') ? (int) req_val('main_site', 'main_site_id') : ($cur['main_site_id'] ?? $site);
if ($main !== $cur['main_site_id']) {
    if (!in_array($main, $works, true)) {
        refuse(422, 'They do not work at that restaurant, so it cannot be their main one.');
    }
    require_right('schedule.build', $main);
}
$hours = $cur['max_hours_week'];
if (req_has('max_hours_week')) {
    $h = req_val('max_hours_week');
    if ($h === '') {
        $hours = null;
    } elseif (!is_numeric($h) || (float) $h <= 0 || (float) $h > 100) {
        refuse(422, 'The weekly limit is a number of hours above 0 and up to 100 — leave it empty for no limit.');
    } else {
        $hours = round((float) $h, 2);
    }
}
$minor = people_yes('is_minor', $cur['is_minor']);
$until = req_has('minor_until') ? (req_val('minor_until') ?: null) : $cur['minor_until'];
if ($minor) {
    if ($until === null) {
        refuse(422, 'Say the date the minor rules stop applying.');
    }
    if (!valid_date_text($until)) {
        refuse(422, 'The minor end date is a date like 2028-06-30.');
    }
    if ($until < (new DateTimeImmutable('now', new DateTimeZone(site_timezone($site))))->format('Y-m-d')) {
        refuse(422, 'The minor end date has to be today or later.');
    }
} else {
    $until = null;
}
$f = ['main_site' => $main, 'max_hours_week' => $hours, 'is_minor' => $minor, 'minor_until' => $until, 'active' => people_yes('active', $cur['active']), 'position_ids' => null, 'manage_sites' => $manage, 'primary' => null];
if (req_has('notes')) {
    $n = (string) req_val('notes');
    if (mb_strlen($n) > 2000) {
        refuse(422, 'Notes may be up to 2000 characters.');
    }
    $f['notes'] = $n === '' ? null : $n;
}
$list = request_list('positions');
if ($list !== null) {
    $f['position_ids'] = resolve_position_ids($pdo, $list, $manage);
    $p = req_val('primary');
    if ($p !== null && $p !== '') {
        $f['primary'] = (int) resolve_position_ids($pdo, [$p], $manage)[0];
    }
}
people_guard($pdo, static function () use ($pdo, $me, $member, $site, $f, &$r): void {
    $pdo->beginTransaction();
    $r = save_staff($pdo, $member, $f, $me);
    log_activity($pdo, 'staff.save', 'staff', $member, ['scope_id' => $site, 'before' => $r['before'], 'after' => $r['after'] + ['member_id' => $member, 'notes_changed' => $r['notes_changed']]]);
    $pdo->commit();
});
people_done('Saved ' . $cur['display_name'] . '\'s profile', $member, people_land(return_path('/staff/' . $member), 'st_saved'), 'staffChanged', ['member_id' => $member]);
