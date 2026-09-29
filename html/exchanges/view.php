<?php
declare(strict_types=1);
/**
 * /exchanges/{id} — one trade in full (screen `exchange-view`): the shift(s), from, to, kind, state, the warnings, the claims (only those who decide),
 * and to decide it. A trade the caller may not open is "That trade is no longer available." — every case the same sentence.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/respond.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? 0;
$x = $id > 0 ? find_exchange($pdo, $id) : null;
if ($x === null) {
    respond_not_found('That trade is no longer available.');
}
$siteId = (int) $x['site_id'];
$decideWhy = exchange_decide_refusal($x, $me);
$canDecide = $decideWhy === null;
$live = in_array($x['status'], ['open', 'pending_acceptance', 'pending_approval'], true);
$mine = $x['from_member_id'] === $me;
$toMe = $x['to_member_id'] === $me;
$claims = $canDecide || has_right('requests.approve', $siteId) ? exchange_claims($pdo, $id) : null;
$check = null;
if ($live && !$mine && has_right('market.trade', $siteId) && (($x['status'] === 'open' && in_array($x['kind'], ['offer', 'open', 'coverage'], true)) || ($toMe && $x['status'] === 'pending_acceptance'))) {
    $check = marketplace_check($pdo, $id, $me);
}
$myClaim = null;
foreach (exchange_claims($pdo, $id) as $c) {
    if ($c['member_id'] === $me) {
        $myClaim = $c;
    }
}
$history = exchange_history($pdo, $id);
log_screen_view($pdo, 'exchange-view');
if (wants_json()) {
    respond_screen(['exchange' => present_exchange($x, $claims),
        'may' => ['decide' => $canDecide && $x['status'] === 'pending_approval', 'choose' => $canDecide && $x['status'] === 'open' && $x['claim_mode'] === 'manager_chooses',
                  'accept' => $toMe && $x['status'] === 'pending_acceptance' && $check !== null && $check['reason'] === null, 'take' => $check !== null && $check['reason'] === null && $x['status'] === 'open',
                  'reason' => $check['reason'] ?? null, 'withdraw' => $live && ($mine || has_right('schedule.build', $siteId) || has_right('requests.approve', $siteId))],
        'history' => array_map(static fn (array $r): array => ['when' => json_ts($r['occurred_at']), 'action' => $r['action'], 'words' => activity_words($r)], $history)]);
}
render_screen('Trade', view('exchanges/view.php', ['x' => $x, 'claims' => $claims, 'canDecide' => $canDecide, 'decideWhy' => $decideWhy, 'live' => $live, 'mine' => $mine, 'toMe' => $toMe,
    'check' => $check, 'myClaim' => $myClaim, 'history' => $history, 'me' => $me, 'back' => back_link(), 'notice' => notice_words($_GET['notice'] ?? null), 'zone' => show_zone(),
    'canCancel' => $live && ($mine || has_right('schedule.build', $siteId) || has_right('requests.approve', $siteId))]),
    ['activeNav' => 'marketplace', 'screen' => 'exchange-view', 'entity' => 'exchange', 'recordId' => (string) $id]);
