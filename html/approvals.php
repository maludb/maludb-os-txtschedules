<?php
declare(strict_types=1);
/**
 * /approvals — what waits for a manager (screen `approvals`): time off, availability changes and trades, each oldest first, at the
 * restaurants where I hold requests.approve — or market.approve_day for same-day ones the restaurant lets shift leads settle. ?site= ?kind=
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__) . '/app/features/availability/queries.php';
require_once dirname(__DIR__) . '/app/features/availability/present.php';
require_once dirname(__DIR__) . '/app/features/timeoff/queries.php';
require_once dirname(__DIR__) . '/app/features/timeoff/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$full = $day = [];
foreach (held_sites() as $s) {
    if (has_right('requests.approve', $s['scope_id'])) {
        $full[] = $s['scope_id'];
    } elseif (has_right('market.approve_day', $s['scope_id'])) {
        $day[] = $s['scope_id'];
    }
}
if ($full === [] && $day === []) {
    refuse(403, 'You may not approve requests here.');
}
$siteFilter = request_integer('site');
if ($siteFilter !== null) {
    $full = array_values(array_intersect($full, [$siteFilter]));
    $day = array_values(array_intersect($day, [$siteFilter]));
}
$kind = in_array(request_string('kind'), ['exchange', 'time_off', 'availability'], true) ? request_string('kind') : null;
$rows = $kind === null || $kind === 'exchange' ? find_approvals($pdo, $full, $me, $day) : [];
$timeOff = $kind === null || $kind === 'time_off' ? find_time_off_approvals($pdo, $full, $me) : [];
foreach ($timeOff as &$t) {
    $t['covered'] = request_shifts($pdo, $t['request_id']);
}
unset($t);
$avail = $kind === null || $kind === 'availability' ? find_availability_approvals($pdo, $full, $me) : [];
log_screen_view($pdo, 'approvals');
if (wants_json()) {
    respond_screen(['exchanges' => array_map(static fn (array $x): array => present_exchange($x) + ['claimants' => array_map(static fn (array $c): array => ['member_id' => (int) $c['member_id'], 'name' => $c['name']], $x['claimants'] ?? [])], $rows),
                    'time_off' => array_map(static fn (array $t): array => present_time_off($t, true, $t['covered']), $timeOff),
                    'availability' => array_map('present_availability', $avail)]);
}
render_screen('Approvals', view('exchanges/approvals.php', ['rows' => $rows, 'timeOff' => $timeOff, 'avail' => $avail, 'kind' => $kind, 'zone' => show_zone(), 'notice' => time_off_notice($_GET['notice'] ?? null) ?? notice_words($_GET['notice'] ?? null), 'sites' => held_sites()]),
    ['activeNav' => 'approvals', 'screen' => 'approvals', 'entity' => 'exchange']);
