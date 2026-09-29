<?php
declare(strict_types=1);
/** /certifications/kinds/new?site= and /certifications/kinds/{id}/edit — add or change a certification kind (screens `certification-kind-add`, `certification-kind-edit`; settings.manage at the restaurant). */
require_once dirname(__DIR__, 3) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id');
$cur = null;
if ($id !== null) {
    $site = people_record_site(certification_kind_site_id($pdo, $id), 'Not found.');
    $cur = find_certification_kind($pdo, $id) ?? refuse(404, 'Not found.');
    if ($cur['archived_at'] !== null) {
        refuse(404, 'Not found.');
    }
} else {
    $site = people_named_site();
}
require_right('settings.manage', $site);
$positions = find_site_positions($pdo, $site);
$screen = $cur === null ? 'certification-kind-add' : 'certification-kind-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['site_id' => $site, 'kind' => $cur === null ? null : ['kind_id' => $cur['kind_id'], 'name' => $cur['name'], 'track_expiry' => $cur['track_expiry'], 'warn_days' => $cur['warn_days'], 'position_ids' => $cur['position_ids']],
        'positions' => array_map(static fn (array $p): array => ['position_id' => (int) $p['position_id'], 'name' => $p['name']], $positions)]);
}
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
render_screen($cur === null ? 'Add a certification' : 'Change ' . $cur['name'], view('certifications/kind-form.php', ['site' => $siteRow, 'cur' => $cur, 'positions' => $positions]),
    ['activeNav' => 'certifications', 'screen' => $screen, 'entity' => 'certification_kind', 'recordId' => $id ?? '']);
