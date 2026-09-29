<?php
declare(strict_types=1);
/** /requests — my offers, swaps, gives and claims, by state (screen `my-requests`). ?state=waiting|decided|all. Time off and availability join it in slice 3. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_login();
require_human();
require_right('schedule.view_own');
$pdo = db();
$me = (int) current_member_id();
$state = in_array(request_string('state'), ['waiting', 'decided', 'all'], true) ? request_string('state') : 'waiting';
$r = find_my_requests($pdo, $me, $state);
log_screen_view($pdo, 'my-requests');
if (wants_json()) {
    respond_screen(['state' => $state, 'trades' => array_map(static fn (array $x): array => present_exchange($x), $r['mine']),
                    'claims' => array_map(static fn (array $x): array => present_exchange($x) + ['claim_status' => $x['claim_status']], $r['claims'])]);
}
render_screen('My requests', view('exchanges/requests.php', ['state' => $state, 'mine' => $r['mine'], 'claims' => $r['claims'], 'zone' => show_zone(), 'notice' => notice_words($_GET['notice'] ?? null)]),
    ['activeNav' => 'my-requests', 'screen' => 'my-requests', 'entity' => 'exchange']);
