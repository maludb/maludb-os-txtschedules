<?php
declare(strict_types=1);
/**
 * /time-off/{id} — one request in full (screen `time-off-view`): who, what kind, the dates in the restaurant's zone, hours, the decision, the shifts it touches, the balance before and after (to the person and the
 * approvers), and to decide or cancel it. A request the caller may not open is "That request is not available." — every case the same sentence.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/present.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? 0;
$r = $id > 0 ? find_time_off_request($pdo, $id) : null;
if ($r === null) {
    respond_not_found('That request is not available.');
}
$site = (int) $r['site_id'];
$mine = $r['member_id'] === $me;
$approver = has_right('requests.approve', $site);
$live = in_array($r['status'], ['pending', 'approved'], true);
$canDecide = $approver && $r['status'] === 'pending' && !$mine;
$canCancel = $live && ($approver || ($mine && strtotime((string) $r['ends_at']) >= time()));
$withBalance = $mine || $approver;
$shifts = ($mine || $approver || has_right('schedule.build', $site)) && $live ? request_shifts($pdo, $id) : [];
$history = time_off_history($pdo, $id);
log_screen_view($pdo, 'time-off-view');
if (wants_json()) {
    respond_screen(['request' => present_time_off($r, $withBalance, $live ? $shifts : null), 'may' => ['decide' => $canDecide, 'cancel' => $canCancel],
        'history' => array_map(static fn (array $h): array => ['when' => json_ts($h['occurred_at']), 'action' => $h['action'], 'words' => activity_words($h)], $history)]);
}
render_screen('Time off', view('timeoff/view.php', ['r' => $r, 'mine' => $mine, 'approver' => $approver, 'canDecide' => $canDecide, 'canCancel' => $canCancel, 'withBalance' => $withBalance, 'shifts' => $shifts,
    'history' => $history, 'back' => back_link(), 'notice' => time_off_notice($_GET['notice'] ?? null), 'zone' => show_zone()]),
    ['activeNav' => 'time-off', 'screen' => 'time-off-view', 'entity' => 'time_off_request', 'recordId' => (string) $id]);
