<?php
declare(strict_types=1);
/**
 * Action `time_off_approve` (log `timeoff.approve`; an agent's call pauses): requests.approve at the request's restaurant approves a pending request — the balance draws down through ts_time_off_post(),
 * refused with "Not enough …" when it would go below zero and the kind does not allow it. `open_shifts` = yes also turns each covered published shift's holder off (shift.change, via time_off; the holder is told).
 * Nobody decides their own.
 */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_time_off_id();
$site = require_record_site(time_off_site_id($pdo, $id), 'Request not found.');
require_right('requests.approve', $site);
$note = request_note();
$open = req_yes('open_shifts') || req_yes('also_open_shifts');
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $id, $note, $open): array {
    $pdo->beginTransaction();
    $s0 = time_off_snapshot($pdo, $id, true) ?? throw new DomainException('Request not found.');
    if ($s0['member_id'] === $me) {
        $pdo->rollBack();
        refuse(403, 'You cannot decide your own time off.');
    }
    $d = decide_time_off($pdo, $id, true, $me, $note);
    $opened = $open ? open_covered_shifts($pdo, $s0, $me) : [];
    $s = time_off_snapshot($pdo, $id);
    log_time_off($pdo, 'timeoff.approve', $s, ['status' => 'approved', 'hours' => $s['hours'], 'balance_hours' => $d['balance_hours'], 'shifts_opened' => $opened, 'note' => $note], ['status' => 'pending']);
    time_off_notify($pdo, 'decided', $s, $me, ['word' => 'approved', 'note' => $note]);
    $pdo->commit();
    return ['s' => $s, 'balance_hours' => $d['balance_hours'], 'opened' => $opened];
});
time_off_done('Approved ' . $r['s']['member_name'] . '\'s ' . time_off_facts($r['s']) . ($r['opened'] !== [] ? ' ' . count($r['opened']) . ' shift(s) opened.' : ''), $id,
    land_with_notice(return_path('/time-off/' . $id), $r['opened'] !== [] ? 'to_approved_opened' : 'to_approved'),
    ['request_id' => $id, 'status' => 'approved', 'balance_hours' => $r['balance_hours'], 'shifts_opened' => count($r['opened'])]);
