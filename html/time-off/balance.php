<?php
declare(strict_types=1);
/**
 * Action `balance_adjust` (log `balance.adjust`; an agent's call pauses): pay.edit at the kind's restaurant moves a person's balance by hand — a positive number of hours is recorded as a grant, a negative one as an
 * adjustment — with a reason. Only through ts_time_off_post(); hours, never pay. There are no accrual rules: a balance moves by this, an approval or a cancellation.
 */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$typeId = request_integer('time_off_type') ?? request_integer('type') ?? refuse(422, 'Choose the kind of time off.');
$site = require_record_site(time_off_type_site_id($pdo, $typeId), 'That kind of time off is not here.');
require_right('pay.edit', $site);
$member = request_integer('member') ?? refuse(422, 'Say whose balance.');
if (!in_array($site, member_site_ids($pdo, $member), true)) {
    refuse(404, 'Not found.');
}
$type = find_time_off_type($pdo, $typeId) ?? refuse(404, 'That kind of time off is not here.');
if (!$type['tracks_balance']) {
    refuse(422, 'That kind of time off does not keep a balance.');
}
$raw = req_val('delta_hours', 'delta');
if ($raw === null || !is_numeric($raw) || (float) $raw == 0.0 || abs((float) $raw) > 2000) {
    refuse(422, 'Give the change in hours: a positive number grants, a negative one removes.');
}
$delta = round((float) $raw, 2);
$reason = trim((string) req_val('reason'));
if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
    refuse(422, 'Give a reason of a few words (3 to 500 characters).');
}
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $member, $typeId, $delta, $reason, $site, $type): array {
    $pdo->beginTransaction();
    $done = adjust_balance($pdo, $member, $typeId, $delta, $reason, $me);
    $bal = $done['balance'];
    $lid = $done['ledger_id'];
    $who = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
    $who->execute(['m' => $member]);
    log_activity($pdo, 'balance.adjust', 'time_off_type', $typeId, ['scope_id' => $site, 'after' => ['member_id' => $member, 'type' => $type['name'], 'delta_hours' => $delta, 'reason' => $reason, 'balance_hours' => $bal, 'ledger_id' => $lid]]);
    $pdo->commit();
    return ['balance' => $bal, 'ledger_id' => $lid, 'name' => (string) $who->fetchColumn()];
});
time_off_done(($delta > 0 ? 'Granted ' : 'Removed ') . hours_label(abs($delta)) . ' of ' . strtolower($type['name']) . ' ' . ($delta > 0 ? 'to ' : 'from ') . $r['name'] . ' — now ' . hours_label($r['balance']), $r['ledger_id'],
    land_at(return_path('/time-off/balances?member=' . $member . '&site=' . $site), 'to_adjusted', 'ledger-' . $r['ledger_id']), ['balance_hours' => $r['balance'], 'delta_hours' => $delta, 'member_id' => $member]);
