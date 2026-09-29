<?php
declare(strict_types=1);
/**
 * /approvals — what waits for a manager (screen `approvals`): trades now (time off and availability join it in slice 3), oldest first, at the
 * restaurants where I hold requests.approve — or market.approve_day for same-day ones the restaurant lets shift leads settle. ?site= ?kind=
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
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
$rows = find_approvals($pdo, $full, $me, $day);
log_screen_view($pdo, 'approvals');
if (wants_json()) {
    respond_screen(['exchanges' => array_map(static fn (array $x): array => present_exchange($x) + ['claimants' => array_map(static fn (array $c): array => ['member_id' => (int) $c['member_id'], 'name' => $c['name']], $x['claimants'] ?? [])], $rows)]);
}
render_screen('Approvals', view('exchanges/approvals.php', ['rows' => $rows, 'zone' => show_zone(), 'notice' => notice_words($_GET['notice'] ?? null), 'sites' => held_sites()]),
    ['activeNav' => 'approvals', 'screen' => 'approvals', 'entity' => 'exchange']);
