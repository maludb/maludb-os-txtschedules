<?php
declare(strict_types=1);
/**
 * Action `availability_submit` (log `availability.submit`): a weekly block — available, unavailable or preferred — from a date. Own (availability.edit at the restaurant), or a manager's for a person
 * who works where they build (schedule.build; a manager who may approve enters it approved). The restaurant's `availability_needs_approval` decides whether it waits for a manager; an approved
 * block replaces the older ones it overlaps. `site` empty = every restaurant the person works at.
 */
require_once dirname(__DIR__, 2) . '/app/features/timeoff/handler.php';
timeoff_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$member = request_integer('member') ?? $me;
$raw = req_val('site');
if ($raw !== null && $raw !== '' && filter_var($raw, FILTER_VALIDATE_INT) === false) {
    refuse(422, 'Say which restaurant.');
}
$site = $raw === null || $raw === '' ? null : (int) $raw;
$held = array_column(held_sites(), 'scope_id');
$theirs = array_values(array_intersect(member_site_ids($pdo, $member), $held));
if ($site !== null) {
    require_record_site($site, 'Not found.');
    if (!in_array($site, $theirs, true)) {
        refuse(404, 'Not found.');
    }
    $sites = [$site];
} else {
    $sites = $theirs;
    if ($sites === []) {
        refuse(404, 'Not found.');
    }
}
$right = $member === $me ? 'availability.edit' : 'schedule.build';
$allowed = array_values(array_filter($sites, static fn (int $s): bool => has_right($right, $s)));
if ($allowed === []) {
    require_right($right, $sites[0]);
}
$approveNow = $member !== $me && array_filter($sites, static fn (int $s): bool => has_right('requests.approve', $s)) !== [];
$tz = new DateTimeZone(site_timezone($site ?? (in_array((int) current_site_id(), $sites, true) ? (int) current_site_id() : $sites[0])));
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$weekday = request_integer('weekday');
if ($weekday === null || $weekday < 0 || $weekday > 6) {
    refuse(422, 'Choose a day of the week.');
}
$from = req_val('starts_at', 'starts') ?? '';
$to = req_val('ends_at', 'ends') ?? '';
if ($from === '' && $to === '') {
    $from = $to = '00:00';
} elseif ($from === '' || $to === '') {
    refuse(422, 'Give both a start and an end, or leave both empty for the whole day.');
}
$eff = request_date('effective_from');
$effTo = request_date('effective_to');
if ($eff === false || $effTo === false) {
    refuse(422, 'Give the dates like 2026-10-09.');
}
$f = ['member_id' => $member, 'site_id' => $site, 'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to, 'kind' => req_val('kind') ?? '', 'effective_from' => $eff ?? $today, 'effective_to' => $effTo,
      'approve_now' => $approveNow];
$r = timeoff_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $r = submit_availability($pdo, $f, $me);
    $row = availability_write_row($pdo, $r['id']);
    $name = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
    $name->execute(['m' => $f['member_id']]);
    $row['member_name'] = (string) $name->fetchColumn();
    log_availability($pdo, 'availability.submit', $row, ['status' => $r['status'], 'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to'], 'replaced' => $r['replaced']]);
    if ($r['status'] === 'pending') {
        availability_notify($pdo, 'submitted', $row, $me);
    }
    $pdo->commit();
    return $r + ['row' => $row];
});
$path = return_path('/availability?member=' . $member);
time_off_done(($r['status'] === 'pending' ? 'Sent for approval: ' : 'Saved: ') . availability_facts($r['row']), $r['id'], land_at($path, $r['status'] === 'pending' ? 'av_pending' : 'av_saved', 'availability-block-' . $r['id']),
    ['availability_id' => $r['id'], 'status' => $r['status'], 'replaced' => $r['replaced']]);
