<?php
declare(strict_types=1);
// The application switcher's proof for a kit application (K31; app/switcher.php, shared/app-switcher.php): in process, the kernel
// asked as member 1 (the first super-admin) with this install's application token — a read — and the partial rendered from the
// answer and from fixtures. Never writes anywhere. Run by scripts/prove-switcher.sh from a checkout whose config/.env names a scratch database.
require_once dirname(__DIR__) . '/app/bootstrap.php';
$ok = static function (bool $c, string $l): void { echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; };
$key = (string) env('APP_KEY', '');
$ok(os_switcher_enabled() && $key !== '', "this is an OS application ($key) with a token");
$_SESSION['member_id'] = 1;
$ok(os_switcher_member_id() === 1, 'the kernel member id is the session\'s member id');
$feed = os_my_applications(true);
$ok(is_array($feed) && !empty($feed['applications']), 'the kernel answered the feed (' . count($feed['applications'] ?? []) . ' applications)');
if (!is_array($feed)) { exit(1); }
$ok(isset($_SESSION['os_apps']['feed']) && $_SESSION['os_apps']['at'] > time() - 5, 'the answer is cached in the session');
$ok(os_my_applications() === $feed, 'a second call within five minutes is the cache');
$keys = array_column($feed['applications'], 'key');
$ok(in_array($key, $keys, true) && in_array('helpdesk', $keys, true), 'the feed names this application and helpdesk');
$current = array_values(array_filter($feed['applications'], static fn ($a) => !empty($a['current'])));
$ok(count($current) === 1 && $current[0]['key'] === $key, 'exactly one row is current: ' . $key);
if ($current === []) { echo "FAIL no current row: the rest cannot run\n"; exit(1); }
$html = view('shared/app-switcher.php', ['feed' => $feed]);
$base = rtrim(os_switcher_launcher_url(), '/');
$ok($base !== '' && str_starts_with($base, 'http'), 'OS_LAUNCHER_URL is the base of every link (' . $base . ')');
$hd = array_values(array_filter($feed['applications'], static fn ($a) => $a['key'] === 'helpdesk'));
if ($key === 'helpdesk') {
    $ok(!str_contains($html, 'header-helpdesk-btn'), 'inside the Help Desk the button is not shown');
} else {
    $ok($hd !== [] && str_contains($html, 'id="header-helpdesk-btn"') && str_contains($html, 'href="' . $base . '/launch/' . $hd[0]['id'] . '"'), 'the Helpdesk button links to the launcher\'s /launch/<helpdesk id>');
}
$ok(str_contains($html, 'id="header-apps-toggle"') && str_contains($html, 'id="header-apps-menu"') && str_contains($html, 'id="header-apps-list"'), 'the applications menu is there');
$ok(str_contains($html, '<span class="dropdown-item active" id="header-apps-item-' . $current[0]['id']) && !str_contains($html, 'href="' . $base . '/launch/' . $current[0]['id'] . '"') && !str_contains($html, 'href="' . $base . '/launch/' . $current[0]['id'] . '?'), 'the current application is marked and not a link (its scopes too)');
foreach ($feed['applications'] as $a) {
    if (!empty($a['current'])) { continue; }
    if (!empty($a['scopes'])) {
        $s = $a['scopes'][0];
        $ok(str_contains($html, 'id="header-apps-item-' . $a['id'] . '-' . $s['id'] . '"') && str_contains($html, $base . '/launch/' . $a['id'] . '?scope=' . $s['id']), 'a scoped application has one row per scope with ?scope= (' . $a['name'] . ')');
    } else {
        $ok(str_contains($html, 'href="' . $base . '/launch/' . $a['id'] . '"'), 'a row links to /launch/<id> (' . $a['name'] . ')');
    }
}
$ok(str_contains($html, 'id="header-apps-launcher"') && str_contains($html, 'href="' . e(os_switcher_launcher_url()) . '"'), 'the foot links to the launcher');
$ok(empty($feed['os_url']) || str_contains($html, 'id="header-apps-os"'), 'the operating system link shows for a super-admin');
$ok(!preg_match('/hx-(get|post)=/', $html), 'no link is an HTMX swap');
$asHelpdesk = $feed; foreach ($asHelpdesk['applications'] as $i => $a) { $asHelpdesk['applications'][$i]['current'] = $a['key'] === 'helpdesk'; }
$ok(!str_contains(view('shared/app-switcher.php', ['feed' => $asHelpdesk]), 'header-helpdesk-btn'), 'inside the Help Desk the button is not shown (fixture)');
$noHd = $feed; $noHd['applications'] = array_values(array_filter($noHd['applications'], static fn ($a) => $a['key'] !== 'helpdesk'));
$ok(!str_contains(view('shared/app-switcher.php', ['feed' => $noHd]), 'header-helpdesk-btn'), 'a person without the Help Desk sees no button');
$ok(trim(view('shared/app-switcher.php', ['feed' => ['applications' => []]])) === '', 'an empty feed renders nothing');
