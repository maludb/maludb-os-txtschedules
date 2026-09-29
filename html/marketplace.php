<?php
declare(strict_types=1);
/**
 * /marketplace — the shifts that are up, and whether I may take each (screen `marketplace`). Tabs: Up for grabs (offers and open shifts at my main
 * restaurant) · For me (a give or swap waiting for my answer, a coverage I was invited to) · My claims. Each card: one button, or the database's own reason
 * why not. Region #marketplace-results (Pattern B) refreshes on exchangeChanged and every 30 s. ?tab= ?site= ?kind=
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$siteId = request_integer('site') ?? (int) current_site_id();
if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
    refuse(404, 'Not found.');
}
require_right('market.trade', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$tab = in_array(request_string('tab'), ['grabs', 'forme', 'claims'], true) ? request_string('tab') : 'grabs';
$kind = in_array(request_string('kind'), ['offer', 'open', 'give', 'swap', 'coverage'], true) ? request_string('kind') : null;
$siteQuery = $siteId !== current_site_id() ? '&site=' . $siteId : '';
$all = ['grabs' => find_marketplace($pdo, $siteId, $me, 'grabs'), 'forme' => find_marketplace($pdo, $siteId, $me, 'forme'), 'claims' => find_marketplace($pdo, $siteId, $me, 'claims')];
$counts = ['grabs' => count($all['grabs']), 'forme' => count($all['forme']), 'claims' => count(array_filter($all['claims'], static fn (array $x): bool => $x['claim_status'] === 'pending'))];
$rows = [];
foreach ($all[$tab] as $x) {
    if ($kind !== null && $x['kind'] !== $kind) {
        continue;
    }
    $rows[] = [$x, in_array($tab, ['grabs', 'forme'], true) ? marketplace_check($pdo, (int) $x['exchange_id'], $me) : ['reason' => null, 'warnings' => []]];
}
if (wants_json()) {
    log_screen_view($pdo, 'marketplace');
    respond_screen(['site_id' => $siteId, 'tab' => $tab, 'counts' => $counts, 'exchanges' => array_map(static function (array $pair) use ($tab): array {
        [$x, $c] = $pair;
        return present_exchange($x) + (in_array($tab, ['grabs', 'forme'], true) ? ['may_take' => $c['reason'] === null, 'reason' => $c['reason'],
            'soft_warnings' => array_map(static fn (array $w): string => (string) $w['message'], $c['warnings'])] : ['claim_status' => $x['claim_status'] ?? null]);
    }, $rows)]);
}
$back = '/marketplace' . ($tab !== 'grabs' ? '?tab=' . $tab : '');
$region = view('exchanges/partials/marketplace-results.php', ['tab' => $tab, 'rows' => $rows, 'zone' => show_zone(), 'back' => $back, 'siteQuery' => $siteQuery]);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'marketplace-results') {
    header('Vary: HX-Request');
    echo $region;
    exit;
}
log_screen_view($pdo, 'marketplace');
$noticeKey = (string) ($_GET['notice'] ?? '');
$notice = notice_words($noticeKey);
$noticeLink = in_array($noticeKey, ['claim_approved', 'accepted'], true) ? ['/my-schedule', 'See your schedule'] : null;
render_screen('Marketplace', view('exchanges/marketplace.php', ['tab' => $tab, 'site' => $site, 'siteQuery' => $siteQuery, 'resultsHtml' => $region, 'notice' => $notice, 'noticeLink' => $noticeLink,
    'tabs' => ['grabs' => ['Up for grabs', $counts['grabs']], 'forme' => ['For me', $counts['forme']], 'claims' => ['My claims', $counts['claims']]]]),
    ['activeNav' => 'marketplace', 'screen' => 'marketplace', 'entity' => 'exchange']);
