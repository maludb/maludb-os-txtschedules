<?php
declare(strict_types=1);
/**
 * /shifts/{id} — one shift and what I may do with it (screen `shift-view`). A shift the caller may not see (another restaurant, a draft) is
 * "Shift not found." — the same sentence either way. Offer, give and swap buttons show only when the restaurant allows them and I hold the shift.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exchanges/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/present.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/write.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? 0;
$s = $id > 0 ? find_shift($pdo, $id) : null;
if ($s === null) {
    respond_not_found('Shift not found.');
}
$siteId = (int) $s['site_id'];
$site = find_site_row($pdo, $siteId) ?? respond_not_found('Shift not found.');
$isHolder = $s['assignee_member_id'] !== null && (int) $s['assignee_member_id'] === $me;
$live = $s['exchange_id'] !== null ? find_exchange($pdo, (int) $s['exchange_id']) : null;
$canTrade = has_right('market.trade', $siteId);
$live_shift = $s['status'] === 'scheduled' && $s['published_at'] !== null;
$inCutoff = strtotime((string) $s['starts_at']) - (int) $site['cutoff_minutes'] * 60 < time();
$canCover = has_right('coverage.fill', $siteId) || has_right('schedule.build', $siteId);
$give = $swapShifts = [];
$swapWith = request_integer('swap_with');
if ($isHolder && $canTrade && $live_shift && $live === null && !$inCutoff && ($site['allow_give'] || $site['allow_swap'])) {
    $give = find_give_candidates($pdo, $id, $me);
    if ($swapWith !== null && $site['allow_swap'] && in_array($swapWith, array_map('intval', array_column($give, 'member_id')), true)) {
        $swapShifts = find_swap_candidates($pdo, $siteId, $swapWith);
    } else {
        $swapWith = null;
    }
}
$canBuild = has_right('schedule.build', $siteId);
$snap = $canBuild ? shift_snapshot($pdo, $id) : null;
$manage = null;
if ($snap !== null) {
    $manage = ['live' => $snap['week_status'] === 'published', 'week_start' => $snap['week_start'], 'builder' => builder_url($siteId, $snap['week_start']),
               'people' => find_schedulable_people($pdo, $siteId), 'days' => week_days($snap['week_start'], (new DateTimeImmutable('now', new DateTimeZone((string) $site['timezone'])))->format('Y-m-d')),
               'over' => strtotime((string) $s['ends_at']) < time()];
}
$history = shift_history($pdo, $id);
log_screen_view($pdo, 'shift-view');
if (wants_json()) {
    respond_screen(['shift' => present_shift($s), 'exchange' => $live === null ? null : present_exchange($live),
        'may' => ['offer' => $isHolder && $canTrade && $live_shift && $live === null && !$inCutoff && (bool) $site['allow_offer'],
                  'give' => $isHolder && $canTrade && $live_shift && $live === null && !$inCutoff && (bool) $site['allow_give'],
                  'swap' => $isHolder && $canTrade && $live_shift && $live === null && !$inCutoff && (bool) $site['allow_swap'],
                  'find_cover' => $canCover],
        'history' => array_map(static fn (array $r): array => ['when' => json_ts($r['occurred_at']), 'action' => $r['action'], 'words' => activity_words($r)], $history)]);
}
$data = ['s' => $s, 'site' => $site, 'isHolder' => $isHolder, 'live' => $live, 'canTrade' => $canTrade, 'liveShift' => $live_shift, 'inCutoff' => $inCutoff,
         'canCover' => $canCover, 'give' => $give, 'swapWith' => $swapWith, 'swapShifts' => $swapShifts, 'history' => $history, 'me' => $me,
         'back' => back_link(), 'notice' => notice_words($_GET['notice'] ?? null), 'zone' => show_zone(),
         'canApprove' => has_right('requests.approve', $siteId), 'manage' => $manage];
render_screen('Shift', view('schedule/shift.php', $data), ['activeNav' => 'my-schedule', 'screen' => 'shift-view', 'entity' => 'shift', 'recordId' => (string) $id]);
