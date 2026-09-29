<?php
declare(strict_types=1);
/** /positions/new?site= and /positions/{id}/edit — add or change a position (screens `position-add`, `position-edit`; settings.manage at the restaurant). The default rate is not on this form. */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id');
$cur = null;
if ($id !== null) {
    $site = people_record_site(position_site_id($pdo, $id), 'Not found.');
    $cur = find_position_for_edit($pdo, $id) ?? refuse(404, 'Not found.');
    if ($cur['archived_at'] !== null) {
        refuse(404, 'Not found.');
    }
} else {
    $site = people_named_site();
}
require_right('settings.manage', $site);
$kinds = find_certification_kinds($pdo, $site);
$screen = $cur === null ? 'position-add' : 'position-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['site_id' => $site, 'position' => $cur === null ? null : ['position_id' => $cur['position_id'], 'name' => $cur['name'], 'area' => $cur['area'], 'color' => $cur['color'], 'sort_order' => $cur['sort_order'], 'certification_kind_ids' => $cur['kind_ids']],
        'areas' => array_keys(POSITION_AREAS), 'certifications' => array_map(static fn (array $k): array => ['kind_id' => $k['kind_id'], 'name' => $k['name']], $kinds)]);
}
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
render_screen($cur === null ? 'Add a position' : 'Change ' . $cur['name'], view('positions/form.php', ['site' => $siteRow, 'cur' => $cur, 'kinds' => $kinds]),
    ['activeNav' => 'positions-list', 'screen' => $screen, 'entity' => 'position', 'recordId' => $id ?? '']);
