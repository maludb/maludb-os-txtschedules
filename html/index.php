<?php
declare(strict_types=1);
/** / — the dashboard (screen `dashboard`): my next shift, my week, what waits for me, announcements. Reads the mcp_* views only. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
$pdo = db();
log_screen_view($pdo, 'dashboard');
$me = current_member();
$site = current_site();
$id = (int) $me['id'];
$data = ['me' => $me, 'site' => $site, 'next' => home_next_shift($pdo, $id), 'week' => $site === null ? [] : home_my_week($pdo, $id, $site['scope_id']),
         'waiting' => home_waiting($pdo, $id), 'announcements' => $site === null ? [] : home_announcements($pdo, $site['scope_id']), 'tz' => site_timezone()];
if (wants_json()) {
    respond_screen(['site' => $site === null ? null : ['site_id' => $site['scope_id'], 'name' => $site['name'], 'timezone' => $site['timezone'], 'role' => $site['role_key']],
        'next_shift' => $data['next'], 'my_week' => $data['week'], 'waiting' => $data['waiting'], 'announcements' => $data['announcements']]);
}
render_screen('Home', view('home/page.php', $data), ['activeNav' => 'dashboard', 'screen' => 'dashboard']);
