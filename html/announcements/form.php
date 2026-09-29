<?php
declare(strict_types=1);
/** /announcements/new?site=&audience= — post an announcement (screen `announcement-add`; announce.post at the restaurant). The next page says how many people it reaches, and only then does it send. */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
require_login();
require_human();
$pdo = db();
$site = request_integer('site') ?? (int) current_site_id();
require_right('announce.post', $site);
$siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
$audience = request_string('audience');
$audience = isset(ANNOUNCE_AUDIENCES[$audience]) ? $audience : 'site';
$positions = find_site_positions($pdo, $site);
$people = find_site_people($pdo, $site);
log_screen_view($pdo, 'announcement-add');
if (wants_json()) {
    respond_screen(['site_id' => $site, 'site' => $siteRow['name'], 'audiences' => array_keys(ANNOUNCE_AUDIENCES), 'positions' => array_map(static fn (array $p): array => ['position_id' => (int) $p['position_id'], 'name' => $p['name']], $positions),
        'people' => array_map(static fn (array $p): array => ['member_id' => $p['member_id'], 'name' => $p['name']], $people)]);
}
render_screen('Post an announcement', view('announcements/form.php', ['site' => $siteRow, 'audience' => $audience, 'positions' => $positions, 'people' => $people, 'f' => [],
    'sites' => array_values(array_filter(held_sites(), static fn (array $s): bool => has_right('announce.post', $s['scope_id'])))]), ['activeNav' => 'announcements-list', 'screen' => 'announcement-add', 'entity' => 'announcement']);
