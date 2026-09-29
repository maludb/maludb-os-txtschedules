<?php
declare(strict_types=1);
/**
 * /shifts/new?site=&position=&date=&assignee= and /shifts/{id}/edit — add or change a shift (screens `shift-add`, `shift-edit`; a full page, no modal). A draft week's form posts shift_create / shift_update;
 * a published week's posts shift_add / shift_change and says "This is live: staff will be told." The live check lists the rules' sentences under the person; a hard one blocks Save, a soft one asks
 * for the reason. schedule.build at the shift's or the named site (a shift lead: 403).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/write.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id');
$snap = null;
if ($id !== null) {
    $siteId = shift_site_id($pdo, $id);
    if ($siteId === null || !in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Shift not found.');
    }
    require_right('schedule.build', $siteId);
    $snap = shift_snapshot($pdo, $id) ?? refuse(404, 'Shift not found.');
    if ($snap['status'] !== 'scheduled') {
        refuse(422, 'That shift is cancelled.');
    }
} else {
    $siteId = request_integer('site') ?? (int) current_site_id();
    if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Not found.');
    }
    require_right('schedule.build', $siteId);
}
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$positions = find_site_positions($pdo, $siteId);
$people = find_schedulable_people($pdo, $siteId);
if ($snap !== null) {
    $zone = new DateTimeZone($tz);
    $s0 = local_from_db((string) $snap['starts_at'], $zone);
    $e0 = local_from_db((string) $snap['ends_at'], $zone);
    $v = ['position_id' => (int) $snap['position_id'], 'date' => $s0->format('Y-m-d'), 'starts' => $s0->format('H:i'), 'ends' => $e0->format('H:i'), 'break' => (int) $snap['break_minutes'],
          'assignee' => $snap['assignee_member_id'], 'note' => (string) ($snap['note'] ?? '')];
    $live = $snap['week_status'] === 'published';
} else {
    $date = request_date('date');
    $date = is_string($date) ? $date : $today;
    $pos = request_integer('position');
    $pos = $pos !== null && in_array($pos, array_map('intval', array_column($positions, 'position_id')), true) ? $pos : (int) ($positions[0]['position_id'] ?? 0);
    $who = request_integer('assignee');
    $v = ['position_id' => $pos, 'date' => $date, 'starts' => '17:00', 'ends' => '23:00', 'break' => 0,
          'assignee' => $who !== null && in_array($who, array_column($people, 'member_id'), true) ? $who : null, 'note' => ''];
    $week = find_week($pdo, $siteId, week_start_of($date, (int) $site['week_start']));
    $live = $week !== null && $week['status'] === 'published';
}
// the first render of the live check: the values as the form shows them
$_GET = ['position' => (string) $v['position_id'], 'date' => $v['date'], 'starts' => $v['starts'], 'ends' => $v['ends'], 'break' => (string) $v['break'], 'assignee' => $v['assignee'] === null ? '' : (string) $v['assignee']];
$check = $v['position_id'] > 0 ? shift_form_findings($pdo, $siteId, $tz, $snap) : ['error' => 'Add a position to this restaurant first.'];
log_screen_view($pdo, $id === null ? 'shift-add' : 'shift-edit');
$ws = week_start_of($v['date'], (int) $site['week_start']);
render_screen($id === null ? 'Add a shift' : 'Change a shift', view('shifts/form.php', ['site' => $site, 'snap' => $snap, 'v' => $v, 'positions' => $positions, 'people' => $people, 'live' => $live,
    'check' => $check, 'builder' => builder_url($siteId, $ws), 'edit' => $id !== null]),
    ['activeNav' => 'builder', 'screen' => $id === null ? 'shift-add' : 'shift-edit', 'entity' => 'shift', 'recordId' => $id === null ? '' : (string) $id]);
