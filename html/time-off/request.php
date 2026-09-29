<?php
declare(strict_types=1);
/**
 * Action `time_off_request` (log `timeoff.request`): ask for time off — one's own (availability.edit at the restaurant that offers the kind), or an approver's for a person who works there. The dates
 * are the restaurant's local time; hours left empty are counted by the database at the restaurant's hours a day (D13). The database refuses a blackout date and a kind not offered. Approvers are told.
 */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$typeId = request_integer('time_off_type') ?? request_integer('type') ?? request_integer('type_id') ?? refuse(422, 'Choose the kind of time off.');
$site = require_record_site(time_off_type_site_id($pdo, $typeId), 'That kind of time off is not offered here.');
$named = request_integer('site');
if ($named !== null && $named !== $site) {
    refuse(422, 'That kind of time off belongs to a different restaurant.');
}
$member = request_integer('member') ?? $me;
$approver = has_right('requests.approve', $site);
if ($member === $me) {
    require_right('availability.edit', $site);
} else {
    require_right('requests.approve', $site);
    if (!in_array($site, member_site_ids($pdo, $member), true)) {
        refuse(422, 'That person does not work at this restaurant.');
    }
}
$tz = new DateTimeZone(site_timezone($site));
[$start, $end] = time_off_range_from_request($tz);
if (!$approver && $end <= new DateTimeImmutable('now')) {
    refuse(422, 'That time is already over.');
}
$f = ['member_id' => $member, 'site_id' => $site, 'type_id' => $typeId, 'starts' => $start, 'ends' => $end, 'hours' => request_time_off_hours(), 'note' => request_note()];
$s = timeoff_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $r = create_time_off($pdo, $f, $me);
    $s = time_off_snapshot($pdo, $r['id']);
    log_time_off($pdo, 'timeoff.request', $s, ['starts_at' => json_ts((string) $s['starts_at']), 'ends_at' => json_ts((string) $s['ends_at']), 'hours' => $s['hours'], 'status' => 'pending']);
    time_off_notify($pdo, 'requested', $s, $me);
    $pdo->commit();
    return $s;
});
time_off_done('Asked for ' . time_off_facts($s), $s['request_id'], '/time-off/' . $s['request_id'],
    ['request_id' => $s['request_id'], 'hours' => $s['hours'], 'status' => 'pending']);
