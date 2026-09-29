<?php
declare(strict_types=1);
/**
 * /time-off/new?site=&type=&from=&to=&member= — ask for time off (screen `time-off-add`; a full page, no modal). Own by default (availability.edit); an approver may ask for someone who works there (member).
 * The preview line follows the restaurant's hours a day (D13) and the person's balance; `?preview=1` answers only that line (the form asks as you type). The dates are the restaurant's local time.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/write.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/present.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/present.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/respond.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$held = array_column(held_sites(), 'scope_id');
$site = request_integer('site') ?? (int) current_site_id();
if (!in_array($site, $held, true)) {
    refuse(404, 'Not found.');
}
$member = request_integer('member') ?? $me;
if ($member === $me) {
    require_right('availability.edit', $site);
} else {
    require_right('requests.approve', $site);
    if (!in_array($site, member_site_ids($pdo, $member), true)) {
        refuse(404, 'Not found.');
    }
}
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$types = find_time_off_types($pdo, $site);
$typeId = request_integer('type') ?? request_integer('time_off_type') ?? (int) ($types[0]['type_id'] ?? 0);
$type = null;
foreach ($types as $t) {
    if ($t['type_id'] === $typeId) {
        $type = $t;
    }
}
$today = (new DateTimeImmutable('now', new DateTimeZone((string) $siteRow['timezone'])))->format('Y-m-d');
$v = ['from' => request_local_date('from', $today), 'to' => request_string('to') !== '' ? request_local_date('to', $today) : '', 'from_time' => request_string('from_time'), 'to_time' => request_string('to_time'), 'hours' => request_string('hours')];
if ($v['to'] === '' ) {
    $v['to'] = $v['from'];
}
$_GET = array_merge($_GET, ['from' => $v['from'], 'to' => $v['to']]);
$preview = time_off_preview($pdo, $site, $type, $member);
if (request_string('preview') === '1') {
    echo view('timeoff/partials/preview.php', ['p' => $preview, 'type' => $type]);
    exit;
}
$people = $member !== $me || has_right('requests.approve', $site) ? find_schedulable_people($pdo, $site) : [];
log_screen_view($pdo, 'time-off-add');
render_screen('Ask for time off', view('timeoff/form.php', ['site' => $siteRow, 'types' => $types, 'type' => $type, 'v' => $v, 'member' => $member, 'me' => $me, 'people' => $people, 'preview' => $preview,
    'multiSite' => count($held) > 1, 'sites' => held_sites(), 'canApprove' => has_right('requests.approve', $site)]),
    ['activeNav' => 'time-off', 'screen' => 'time-off-add', 'entity' => 'time_off_request']);
