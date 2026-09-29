<?php
declare(strict_types=1);
/**
 * /time-off?member=&site=&status=&from=&to=&on=&page= — time off asked for and decided (screen `time-off`): mine by default; an approver adds "Everyone" (member=all, at a restaurant they approve for) and
 * "who is off on a date" (on=). member=<id> narrows to one person the caller approves for. A person who does not approve here only ever sees their own.
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
require_right('availability.edit');
$pdo = db();
$me = (int) current_member_id();
$held = array_column(held_sites(), 'scope_id');
$approveSites = array_values(array_filter($held, static fn (int $s): bool => has_right('requests.approve', $s)));
$isApprover = $approveSites !== [];
$siteFilter = request_integer('site');
if ($siteFilter !== null && !in_array($siteFilter, $held, true)) {
    refuse(404, 'Not found.');
}
$memberRaw = request_string('member');
$status = in_array(request_string('status'), ['pending', 'approved', 'declined', 'cancelled'], true) ? request_string('status') : null;
$from = request_date('from');
$to = request_date('to');
$on = request_date('on');
if ($from === false || $to === false || $on === false) {
    refuse(422, 'Give the dates like 2026-10-09.');
}
$page = max(1, request_integer('page') ?? 1);
$filters = ['status' => $status, 'from' => $from, 'to' => $to];
$view = 'mine';
$who = null;
if ($on !== null) {                                    // who is off that day: approvers see the restaurant, everyone else only their own
    $site = $siteFilter ?? (int) current_site_id();
    if (has_right('requests.approve', $site)) {
        $view = 'day';
        $filters = ['on' => $on, 'site_id' => $site, 'site_ids' => [$site]];
    } else {
        $filters = ['on' => $on, 'member_id' => $me];
    }
} elseif ($memberRaw === 'all') {
    $site = $siteFilter ?? (int) current_site_id();
    require_right('requests.approve', $site);
    $view = 'all';
    $filters += ['site_id' => $site, 'site_ids' => [$site]];
} elseif ($memberRaw !== '' && ctype_digit($memberRaw) && (int) $memberRaw !== $me) {
    $who = (int) $memberRaw;
    $shared = array_values(array_intersect(member_site_ids($pdo, $who), $held));
    if ($shared === []) {
        refuse(404, 'Not found.');
    }
    $sites = array_values(array_intersect($shared, $approveSites));
    if ($sites === []) {
        require_right('requests.approve', $shared[0]);
    }
    $view = 'person';
    $filters += ['member_id' => $who, 'site_ids' => $siteFilter !== null ? array_values(array_intersect($sites, [$siteFilter])) : $sites];
} else {
    $filters += ['member_id' => $me] + ($siteFilter !== null ? ['site_id' => $siteFilter] : []);
}
$res = find_time_off($pdo, $filters, $page);
$rows = $res['rows'];
log_screen_view($pdo, 'time-off');
$canSeeBalance = static fn (array $r): bool => $r['member_id'] === $me || has_right('requests.approve', $r['site_id']);
if (wants_json()) {
    respond_screen(['view' => $view, 'page' => $res['page'], 'more' => $res['more'], 'on' => $on, 'requests' => array_map(static fn (array $r): array => present_time_off($r, $canSeeBalance($r)), $rows)]);
}
$query = array_filter(['member' => $memberRaw !== '' ? $memberRaw : null, 'site' => $siteFilter, 'status' => $status, 'from' => $from, 'to' => $to, 'on' => $on], static fn ($v) => $v !== null);
render_screen('Time off', view('timeoff/index.php', ['rows' => $rows, 'view' => $view, 'who' => $who, 'status' => $status, 'from' => $from, 'to' => $to, 'on' => $on, 'page' => $res['page'], 'more' => $res['more'],
    'query' => $query, 'isApprover' => $isApprover, 'me' => $me, 'zone' => show_zone(), 'notice' => time_off_notice($_GET['notice'] ?? null),
    'canAdmin' => array_filter($held, static fn (int $s): bool => has_right('settings.manage', $s)) !== [], 'site' => $siteFilter ?? (int) current_site_id()]),
    ['activeNav' => 'time-off', 'screen' => 'time-off', 'entity' => 'time_off_request']);
