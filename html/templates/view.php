<?php
declare(strict_types=1);
/**
 * /templates/{id} — one template by weekday, and "Start a week from it" (screen `template-view`). schedule.build at its site; a template of another restaurant is "That template is not here any more."
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? 0;
$siteId = $id > 0 ? template_site_id($pdo, $id) : null;
if ($siteId === null || !in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    respond_not_found('That template is not here any more.');
}
require_right('schedule.build', $siteId);
$tpl = find_template($pdo, $id) ?? respond_not_found('That template is not here any more.');
$site = find_site_row($pdo, $siteId) ?? respond_not_found('Not found.');
$rows = find_template_shifts($pdo, $id);
$tz = (string) $site['timezone'];
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$cur = week_start_of($today, (int) $site['week_start']);
$weeks = [];
for ($i = 0; $i < 9; $i++) {
    $ws = (new DateTimeImmutable($cur, new DateTimeZone('UTC')))->modify("+$i weeks")->format('Y-m-d');
    $w = find_week($pdo, $siteId, $ws);
    $weeks[] = ['week_start' => $ws, 'label' => week_label($ws), 'state' => $w === null ? 'new' : $w['status']];
}
$want = request_date('week');
$want = is_string($want) ? week_start_of($want, (int) $site['week_start']) : null;
log_screen_view($pdo, 'template-view');
if (wants_json()) {
    respond_screen(['template_id' => $id, 'name' => $tpl['name'], 'shifts' => array_map(static fn (array $r): array => ['weekday' => $r['weekday'], 'starts' => substr($r['starts_at'], 0, 5), 'ends' => substr($r['ends_at'], 0, 5),
        'position' => $r['position_name'], 'break_minutes' => (int) $r['break_minutes'], 'assignee' => $r['assignee_name']], $rows)]);
}
render_screen($tpl['name'], view('templates/view.php', ['site' => $site, 'tpl' => $tpl, 'rows' => $rows, 'weeks' => $weeks, 'want' => $want]),
    ['activeNav' => 'templates-list', 'screen' => 'template-view', 'entity' => 'template', 'recordId' => (string) $id]);
