<?php
declare(strict_types=1);
/**
 * Action `announcement_post` (log `announcement.post`; an agent's call pauses for a person — approval `external_send`): announce.post at the restaurant posts a title (≤ 120) and a body (≤ 4,000)
 * to the whole restaurant, a position or named people (who must be its staff), pinned until a date if asked. A person at the browser first sees "This will be sent to N people …" and confirms
 * (`confirm=yes`); an API caller posts directly. Recipients are resolved here, never taken from the form; each is queued one notice per channel they have on.
 */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = people_named_site();
require_right('announce.post', $site);
$title = request_string('title');
$body = trim((string) req_val('body'));
if ($title === '' || mb_strlen($title) > 120) {
    refuse(422, 'Give the announcement a title of up to 120 characters.');
}
if ($body === '' || mb_strlen($body) > 4000) {
    refuse(422, 'Write what you want to say — up to 4,000 characters.');
}
$audience = request_string('audience', 'site');
if (!isset(ANNOUNCE_AUDIENCES[$audience])) {
    refuse(422, 'Choose who it is for: the restaurant, a position or named people.');
}
$positionId = null;
$memberIds = [];
if ($audience === 'position') {
    $ref = request_string('position');
    if ($ref === '') {
        refuse(422, 'Choose the position.');
    }
    $positionId = resolve_position_ids($pdo, [$ref], [$site])[0];
    $own = $pdo->prepare('SELECT 1 FROM positions WHERE id = :p AND scope_id = :s AND archived_at IS NULL');
    $own->execute(['p' => $positionId, 's' => $site]);
    if ($own->fetchColumn() === false) {
        refuse(422, 'That is not one of this restaurant\'s positions.');
    }
} elseif ($audience === 'people') {
    $named = array_values(array_unique(array_map('intval', array_filter(request_list('members') ?? [], 'ctype_digit'))));
    if ($named === []) {
        refuse(422, 'Choose at least one person.');
    }
    $staff = array_column(find_site_people($pdo, $site), 'member_id');
    if (array_diff($named, $staff) !== []) {
        refuse(422, 'Everyone named must work at this restaurant.');
    }
    $memberIds = $named;
}
$pin = request_date('pinned_until');
if ($pin === false) {
    refuse(422, 'Give "pinned until" as a date like 2026-10-05.');
}
if ($pin !== null && $pin < site_today($pdo, $site)) {
    refuse(422, '"Pinned until" cannot be in the past.');
}
$f = ['site_id' => $site, 'title' => $title, 'body' => $body, 'audience' => $audience, 'position_id' => $positionId, 'member_ids' => $memberIds, 'pinned_until' => $pin];
$recipients = announcement_recipients($pdo, $f + ['posted_by' => $me]);
if ($audience !== 'site' && $recipients === []) {
    refuse(422, 'Nobody is in that audience.');
}
if (!wants_json() && !people_yes('confirm')) {
    $reach = announcement_reach($pdo, $recipients);
    $siteRow = find_site_row($pdo, $site) ?? refuse(404, 'Not found.');
    $pos = $positionId === null ? null : (array_column(find_site_positions($pdo, $site), 'name', 'position_id')[$positionId] ?? null);
    $named = array_values(array_filter(find_site_people($pdo, $site), static fn (array $p): bool => in_array($p['member_id'], $memberIds, true)));
    render_screen('Post an announcement', view('announcements/confirm.php', ['site' => $siteRow, 'f' => $f, 'reach' => $reach, 'positionName' => $pos, 'named' => $named]),
        ['activeNav' => 'announcements-list', 'screen' => 'announcement-add', 'entity' => 'announcement']);
    exit;
}
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $f): array {
    $pdo->beginTransaction();
    $r = post_announcement($pdo, $f, $me);
    log_activity($pdo, 'announcement.post', 'announcement', $r['id'], ['scope_id' => $site, 'after' => ['announcement_id' => $r['id'], 'audience' => $f['audience'], 'position_id' => $f['position_id'],
        'recipients' => $r['recipients'], 'pinned_until' => $f['pinned_until']]]);
    $pdo->commit();
    return $r;
});
people_done('Posted "' . $title . '" to ' . $r['recipients'] . ' ' . ($r['recipients'] === 1 ? 'person' : 'people'), $r['id'],
    people_land(return_path('/announcements/?site=' . $site), 'an_posted', 'announcement-' . $r['id']), 'announcementChanged', ['announcement_id' => $r['id'], 'recipients' => $r['recipients']]);
