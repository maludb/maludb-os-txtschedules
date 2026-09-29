<?php
declare(strict_types=1);
/**
 * Action `wage_update` (log `wage.update`; an agent's call pauses — approval `other`): pay.edit at the position's restaurant sets a person's OWN hourly rate at a position (`rate`), or removes it when
 * `rate` is empty so the position's default applies (D10). THE RATE IS NEVER ECHOED: not in `did`, not in the JSON reply, not in the log row (`after`: scope, member, position, cleared).
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$member = request_member_id();
$position = request_integer('position') ?? request_integer('position_id') ?? refuse(422, 'Say which position.');
$site = people_record_site(position_site_id($pdo, $position), 'That position is not here.');
require_right('pay.edit', $site);
$rate = request_rate();
people_guard($pdo, static function () use ($pdo, $me, $member, $position, $site, $rate): void {
    $pdo->beginTransaction();
    set_wage_override($pdo, $member, $position, $rate, $me);
    log_activity($pdo, 'wage.update', 'staff', $member, ['scope_id' => $site, 'after' => ['scope' => 'employee', 'member_id' => $member, 'position_id' => $position, 'cleared' => $rate === null]]);
    $pdo->commit();
});
$who = $pdo->prepare('SELECT m.display_name, p.name FROM members m, positions p WHERE m.id = :m AND p.id = :p');
$who->execute(['m' => $member, 'p' => $position]);
$w = $who->fetch();
people_done(($rate === null ? 'Removed ' : 'Changed ') . $w['display_name'] . '\'s own rate as ' . $w['name'] . ($rate === null ? ' — the default applies' : ''), $member,
    people_land(return_path('/staff/' . $member), $rate === null ? 'st_wage_cleared' : 'st_wage_set', 'staff-position-' . $position), 'staffChanged', ['member_id' => $member, 'position_id' => $position, 'cleared' => $rate === null]);
