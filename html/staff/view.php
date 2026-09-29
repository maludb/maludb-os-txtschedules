<?php
declare(strict_types=1);
/**
 * /staff/{id} — one person (screen `staff-view`): main restaurant, positions, certifications, this week's hours against their limit, time-off balances, the restaurants they hold. A manager
 * (schedule.build at a restaurant they work at) sees it whole; the person sees their own page without the manager's notes or forms. PAY: each position row shows a rate only to labor.view at ITS
 * restaurant (with where it comes from: their own rate or the position's default) — the person themself sees their own effective rate and nothing else; everyone else sees no pay, not even a dash.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? refuse(404, 'Not found.');
$p = find_staff_member($pdo, $id) ?? refuse(404, 'Not found.');
if ($p['member_kind'] !== 'human') {
    refuse(404, 'Not found.');
}
$self = $id === $me;
$held = array_column(held_sites(), 'scope_id');
$theirs = array_values(array_intersect(member_site_ids($pdo, $id), $held));
$managed = array_values(array_filter($theirs, static fn (int $s): bool => has_right('schedule.build', $s)));
if (!$self) {
    if ($theirs === []) {
        refuse(404, 'Not found.');
    }
    if ($managed === []) {
        require_right('schedule.build', $theirs[0]);
    }
}
$ctx = in_array((int) current_site_id(), $theirs, true) ? (int) current_site_id() : ($theirs[0] ?? null);
$positions = staff_position_rows($p['positions'], static fn (array $pos): bool => has_right('labor.view', (int) $pos['site_id']), $self);
foreach ($positions as &$row) {
    $row['may_pay'] = has_right('pay.edit', $row['site_id']);
    $row['may_see_pay'] = has_right('labor.view', $row['site_id']);
}
unset($row);
$cards = find_certifications($pdo, $id);
$kinds = [];
$seen = [];
foreach ($theirs as $s) {
    if ($self || in_array($s, $managed, true)) {
        foreach (find_certification_kinds($pdo, $s) as $k) {
            if (!isset($seen[strtolower($k['name'])])) {
                $seen[strtolower($k['name'])] = true;
                $kinds[] = $k;
            }
        }
    }
}
$hours = [];
foreach ($theirs as $s) {
    if ($self || in_array($s, $managed, true)) {
        $row = find_site_row($pdo, $s);
        $tz = new DateTimeZone((string) $row['timezone']);
        $ws = week_start_of((new DateTimeImmutable('now', $tz))->format('Y-m-d'), (int) $row['week_start']);
        $hours[] = ['site_id' => $s, 'site_name' => $row['name'], 'week_start' => $ws] + find_member_week_hours($pdo, $id, $s, $ws);
    }
}
$balSites = $self ? $theirs : array_values(array_filter($theirs, static fn (int $s): bool => has_right('requests.approve', $s)));
$balances = find_balances($pdo, $id, $balSites);
$editCert = request_integer('cert');
$addKind = request_integer('kind');
$mayManage = $managed !== [];
$notes = $mayManage ? $p['notes'] : null;
log_screen_view($pdo, 'staff-view');
if (wants_json()) {
    respond_screen(['member_id' => $id, 'name' => $p['display_name'], 'main_site_id' => $p['main_site_id'], 'main_site' => $p['main_site_name'], 'on_schedule' => $p['on_schedule'],
        'max_hours_week' => $p['max_hours_week'], 'is_minor' => $p['is_minor'], 'minor_until' => $p['minor_until'], 'notes' => $notes,
        'positions' => array_map(static fn (array $r): array => ['position_id' => $r['position_id'], 'site_id' => $r['site_id'], 'name' => $r['name'], 'is_primary' => $r['is_primary']]
            + ($r['pay'] === null ? [] : ['pay' => array_filter(['effective_rate' => $r['pay']['rate'], 'source' => $r['pay']['source'], 'own_rate' => $r['pay']['own_rate']], static fn ($v) => $v !== null)]), $positions),
        'certifications' => array_map('present_certification', $cards),
        'hours_this_week' => array_map(static fn (array $h): array => ['site_id' => $h['site_id'], 'week_start' => $h['week_start'], 'scheduled_hours' => $h['scheduled_hours'], 'max_hours_week' => $h['max_hours_week'], 'over_limit' => $h['over_own_limit']], $hours),
        'balances' => array_map(static fn (array $b): array => ['type' => $b['name'], 'site_id' => $b['site_id'], 'balance_hours' => $b['balance_hours']], $balances),
        'restaurants' => array_map(static fn (array $r): array => ['site_id' => $r['site_id'], 'site' => $r['site_name'], 'role' => $r['role_name']], $p['restaurants']),
        'may' => ['edit' => $mayManage, 'verify' => $mayManage]]);
}
render_screen($p['display_name'], view('staff/view.php', ['p' => $p, 'self' => $self, 'me' => $me, 'positions' => $positions, 'cards' => $cards, 'kinds' => $kinds, 'hours' => $hours, 'balances' => $balances, 'managed' => $managed,
    'mayManage' => $mayManage, 'notes' => $notes, 'editCert' => $editCert, 'addKind' => $addKind, 'back' => back_link(), 'notice' => staff_notice($_GET['notice'] ?? null), 'theirs' => $theirs]),
    ['activeNav' => 'staff-list', 'screen' => 'staff-view', 'entity' => 'staff', 'recordId' => $id]);
