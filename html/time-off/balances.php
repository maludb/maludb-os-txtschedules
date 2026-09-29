<?php
declare(strict_types=1);
/**
 * /time-off/balances?member=&site= — balances per kind of time off in hours, and the ledger behind each (screen `balances`): a person's own; an approver reads their restaurant's staff (requests.approve there);
 * only pay.edit adjusts (`balance_adjust`, with a reason). No accrual rules: a balance moves by a grant, an approval, a cancellation or an adjustment. Hours — never pay.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/present.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$member = request_integer('member') ?? $me;
$siteFilter = request_integer('site');
$held = array_column(held_sites(), 'scope_id');
$theirs = array_values(array_intersect(member_site_ids($pdo, $member), $held));
if ($siteFilter !== null && !in_array($siteFilter, $theirs, true)) {
    refuse(404, 'Not found.');
}
if ($member === $me) {
    require_right('availability.edit');
    $sites = $theirs;
} else {
    if ($theirs === []) {
        refuse(404, 'Not found.');
    }
    $sites = array_values(array_filter($theirs, static fn (int $s): bool => has_right('requests.approve', $s)));
    if ($sites === []) {
        require_right('requests.approve', $theirs[0]);
    }
}
if ($siteFilter !== null) {
    $sites = array_values(array_intersect($sites, [$siteFilter]));
    if ($sites === []) {
        refuse(403, 'You may not approve requests here.');
    }
}
$who = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
$who->execute(['m' => $member]);
$name = (string) ($who->fetchColumn() ?: refuse(404, 'Not found.'));
$balances = find_balances($pdo, $member, $sites);
foreach ($balances as &$b) {
    $b['ledger'] = balance_ledger($pdo, $member, $b['type_id'], 30);
    $b['may_adjust'] = has_right('pay.edit', $b['site_id']) && $b['archived_at'] === null;
}
unset($b);
$people = [];
$cur = (int) current_site_id();
if (has_right('requests.approve', $cur)) {
    $people = find_schedulable_people($pdo, $cur);
}
log_screen_view($pdo, 'balances');
if (wants_json()) {
    respond_screen(['member' => ['member_id' => $member, 'name' => $name], 'balances' => array_map(static fn (array $b): array => ['type_id' => $b['type_id'], 'type' => $b['name'], 'site_id' => $b['site_id'], 'site' => $b['site_name'],
        'balance_hours' => $b['balance_hours'], 'allow_negative' => $b['allow_negative'],
        'ledger' => array_map(static fn (array $l): array => ['ledger_id' => $l['ledger_id'], 'at' => json_ts((string) $l['created_at']), 'delta_hours' => $l['delta_hours'], 'reason' => $l['reason'],
            'request_id' => $l['request_id'], 'by' => $l['recorded_by_name'], 'note' => $l['note']], $b['ledger'])], $balances)]);
}
render_screen('Balances', view('timeoff/balances.php', ['member' => $member, 'me' => $me, 'name' => $name, 'balances' => $balances, 'people' => $people, 'siteFilter' => $siteFilter, 'multi' => count($sites) > 1,
    'notice' => time_off_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'time-off', 'screen' => 'balances', 'entity' => 'time_off_balance']);
