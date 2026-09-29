<?php
declare(strict_types=1);
/**
 * /builder/day?site=&date= — one day by the hour, for the floor (screen `day-view`): every scheduled shift that touches the day as a bar on a time axis, grouped by position,
 * and how many people are on each hour. Drafts included (a builder's view). schedule.build at the site.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('schedule.build', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tz = (string) $site['timezone'];
$zone = new DateTimeZone($tz);
$today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
$date = request_local_date('date', $today);
$from = local_midnight_utc($date, $tz);
$to = local_midnight_utc((new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d'), $tz);
$st = $pdo->prepare("SELECT s.shift_id, s.position_id, s.position_name, s.position_color, s.starts_at, s.ends_at, s.assignee_member_id, s.assignee_name, s.is_open, s.published_at, s.paid_hours
                       FROM mcp_shifts s WHERE s.site_id = :s AND s.status = 'scheduled' AND s.starts_at < :b AND s.ends_at > :a ORDER BY s.position_name, s.starts_at, s.shift_id");
$st->execute(['s' => $siteId, 'a' => $from, 'b' => $to]);
$rows = $st->fetchAll();
$dayStart = local_dt($from, $tz);
$mins = static fn (string $utc): int => (int) round((strtotime($utc) - $dayStart->getTimestamp()) / 60);
// the axis: whole hours around the shifts, at least eight, within 0..30 h of the day's start
$lo = 10 * 60;
$hi = 24 * 60;
foreach ($rows as $r) {
    $lo = min($lo, max(0, intdiv($mins((string) $r['starts_at']), 60) * 60));
    $hi = max($hi, min(30 * 60, (int) ceil($mins((string) $r['ends_at']) / 60) * 60));
}
if ($hi - $lo < 8 * 60) {
    $hi = $lo + 8 * 60;
}
$hours = [];
for ($m = $lo; $m < $hi; $m += 60) {
    $on = 0;
    foreach ($rows as $r) {
        if ($r['assignee_member_id'] !== null && $mins((string) $r['starts_at']) < $m + 60 && $mins((string) $r['ends_at']) > $m) { $on++; }
    }
    $hours[] = ['minute' => $m, 'label' => $dayStart->modify('+' . $m . ' minutes')->format('ga'), 'on' => $on];
}
$groups = [];
foreach ($rows as $r) {
    $g = &$groups[$r['position_id']];
    $g ??= ['position_id' => (int) $r['position_id'], 'name' => $r['position_name'], 'color' => $r['position_color'], 'rows' => []];
    $g['rows'][] = $r;
    unset($g);
}
log_screen_view($pdo, 'day-view');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'date' => $date, 'time_zone' => $tz, 'hours' => array_map(static fn (array $h): array => ['hour' => $h['label'], 'people_on' => $h['on']], $hours),
        'positions' => array_map(static fn (array $g): array => ['position_id' => $g['position_id'], 'name' => $g['name'], 'shifts' => array_map(static fn (array $r): array => [
            'shift_id' => (int) $r['shift_id'], 'starts_at' => json_ts((string) $r['starts_at']), 'ends_at' => json_ts((string) $r['ends_at']), 'holder' => $r['assignee_name'], 'is_open' => (bool) $r['is_open']], $g['rows'])], array_values($groups))]);
}
$prev = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
$next = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
render_screen('Day view', view('builder/day.php', ['site' => $site, 'date' => $date, 'prev' => $prev, 'next' => $next, 'groups' => array_values($groups), 'hours' => $hours, 'lo' => $lo, 'hi' => $hi,
    'mins' => $mins, 'week' => week_start_of($date, (int) $site['week_start'])]), ['activeNav' => 'builder', 'screen' => 'day-view', 'entity' => 'week']);
