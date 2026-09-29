<?php
declare(strict_types=1);
/**
 * /availability?member=&site=&add= — a person's weekly availability (screen `availability`): the effective blocks by day (Sunday to Saturday), what waits for a manager apart, and the form to add a block.
 * Own by default (availability.edit); a manager (schedule.build at a restaurant the person works at) reads and enters anyone's. `add` = a weekday number opens the form on that day.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__) . '/app/features/availability/queries.php';
require_once dirname(__DIR__) . '/app/features/availability/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$member = request_integer('member') ?? $me;
$siteFilter = request_integer('site');
$held = array_column(held_sites(), 'scope_id');
$theirs = array_values(array_intersect(member_site_ids($pdo, $member), $held));
if ($member === $me) {
    require_right('availability.edit');
    $editable = array_values(array_filter($theirs, static fn (int $s): bool => has_right('availability.edit', $s)));
} else {
    if ($theirs === []) {
        refuse(404, 'Not found.');
    }
    $editable = array_values(array_filter($theirs, static fn (int $s): bool => has_right('schedule.build', $s)));
    if ($editable === []) {
        require_right('schedule.build', $theirs[0]);
    }
}
if ($siteFilter !== null && !in_array($siteFilter, $theirs, true)) {
    refuse(404, 'Not found.');
}
$who = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
$who->execute(['m' => $member]);
$name = (string) ($who->fetchColumn() ?: refuse(404, 'Not found.'));
$all = find_availability($pdo, $member, $siteFilter);
$tz = site_timezone($siteFilter ?? (in_array((int) current_site_id(), $theirs, true) ? (int) current_site_id() : ($theirs[0] ?? null)));
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$effective = array_values(array_filter($all, static fn (array $b): bool => $b['status'] === 'approved' && ($b['effective_to'] === null || $b['effective_to'] >= $today)));
$pending = array_values(array_filter($all, static fn (array $b): bool => $b['status'] === 'pending'));
// what the caller may do with each block
$may = [];
foreach ($all as $b) {
    $may[$b['availability_id']] = ['remove' => $member === $me || availability_right_at($pdo, 'schedule.build', ['member_id' => $member, 'scope_id' => $b['site_id']]),
                                   'decide' => $member !== $me && availability_right_at($pdo, 'requests.approve', ['member_id' => $member, 'scope_id' => $b['site_id']])];
}
$add = request_integer('add');
$add = $add !== null && $add >= 0 && $add <= 6 ? $add : null;
$people = [];
if (array_filter($held, static fn (int $s): bool => has_right('schedule.build', $s)) !== []) {
    $cur = (int) current_site_id();
    $people = has_right('schedule.build', $cur) ? find_schedulable_people($pdo, $cur) : [];
}
log_screen_view($pdo, 'availability');
if (wants_json()) {
    respond_screen(['member' => ['member_id' => $member, 'name' => $name], 'site_id' => $siteFilter, 'blocks' => array_map('present_availability', $effective), 'pending' => array_map('present_availability', $pending)]);
}
$siteChoices = array_values(array_filter(held_sites(), static fn (array $s): bool => in_array($s['scope_id'], $editable, true)));
render_screen('Availability', view('availability/index.php', ['member' => $member, 'me' => $me, 'name' => $name, 'effective' => $effective, 'pending' => $pending, 'may' => $may, 'add' => $add, 'today' => $today,
    'siteFilter' => $siteFilter, 'siteChoices' => $siteChoices, 'people' => $people, 'notice' => availability_notice($_GET['notice'] ?? null), 'multi' => count($theirs) > 1, 'needsApproval' => availability_needs_approval($pdo, $editable)]),
    ['activeNav' => 'availability', 'screen' => 'availability', 'entity' => 'availability']);
