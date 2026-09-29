<?php
declare(strict_types=1);
/**
 * /site/time-off?site=&edit= — the restaurant's kinds of time off and its blackout dates (screen `time-off-types`; settings.manage at the restaurant). Kinds as cards with add, edit (?edit=id, on the page) and
 * archive; blackout dates as a list with add and remove. A blackout date refuses NEW requests that touch it; requests already asked stay as they are.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/availability/present.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/timeoff/present.php';
require_login();
require_human();
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_right('settings.manage', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$types = find_time_off_types($pdo, $site, true);
$blackouts = find_blackouts($pdo, $site);
$edit = request_integer('edit');
$editing = null;
foreach ($types as $t) {
    if ($t['type_id'] === $edit && $t['archived_at'] === null) {
        $editing = $t;
    }
}
$adding = request_string('add') === 'type';
log_screen_view($pdo, 'time-off-types');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'day_hours' => (float) $siteRow['time_off_day_hours'],
        'types' => array_map(static fn (array $t): array => ['type_id' => $t['type_id'], 'name' => $t['name'], 'key' => $t['key'], 'paid' => $t['paid'], 'tracks_balance' => $t['tracks_balance'],
            'allow_negative' => $t['allow_negative'], 'archived' => $t['archived_at'] !== null], $types),
        'blackout_dates' => array_map(static fn (array $b): array => ['blackout_id' => $b['blackout_id'], 'on_date' => $b['on_date'], 'reason' => $b['reason']], $blackouts)]);
}
render_screen('Time-off types', view('timeoff/types.php', ['site' => $siteRow, 'types' => $types, 'blackouts' => $blackouts, 'editing' => $editing, 'adding' => $adding, 'notice' => time_off_notice($_GET['notice'] ?? null),
    'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('settings.manage', $s['scope_id'])))]),
    ['activeNav' => 'site-settings', 'screen' => 'time-off-types', 'entity' => 'time_off_type']);
