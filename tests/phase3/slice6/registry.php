<?php
/** Proof — the manifest and the shell: the five actions and three screens of the slice are in the registry, built, their files exist; the placeholders are gone; the worker's units are in deploy/ and the manifest lists them; the owner's root steps name them; nothing was migrated. */
require __DIR__ . '/lib.php';
$W = reset6();
$root = dirname(__DIR__, 3);
$priya = as_member(26); $mara = as_member(33);
$out = []; exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $code);
ok($code === 0, 'bin/build_action_registry.php --check: the registry is current with the manifest (' . trim(implode(' ', $out)) . ')');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$acts = ['announcement_post' => '/announcements/', 'announcement_remove' => '/announcements/', 'announcement_read' => '/announcements/', 'prefs_save' => '/settings/', 'calendar_feed_rotate' => '/settings/'];
$bad = [];
foreach ($acts as $a => $base) { $r = $reg['actions'][$a] ?? null; if ($r === null || $r['built'] !== true || !is_file($root . '/html' . $r['endpoint']) || !str_starts_with($r['endpoint'], $base)) { $bad[] = $a; } }
ok($bad === [], 'all five actions are built and their endpoint files exist' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$bad = [];
foreach (['announcements-list', 'announcement-add', 'settings'] as $s) { $r = $reg['screens'][$s] ?? null; if ($r === null || $r['built'] !== true) { $bad[] = $s; } }
ok($bad === [], 'the three screens are built in the registry');
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) >= 64, 'the registry counts at least 64 of 69 actions built (59 + 5; slice 7 adds the last five)');
$params = fn (string $a): array => array_column($reg['actions'][$a]['params'], 'name');
ok($params('announcement_post') === ['site', 'title', 'body', 'audience', 'position', 'members', 'pinned_until'] && $params('prefs_save') === ['by_email', 'by_sms', 'kinds', 'reminder_minutes'] && $params('calendar_feed_rotate') === [], 'the manifest\'s parameters reach the registry');
foreach ([['/announcements/', $priya, 'announcements-list'], ['/settings/', $priya, 'settings'], ['/announcements/new?site=102', $mara, 'announcement-add']] as [$p, $jar, $id]) {
    $html = req('GET', $p, ['jar' => $jar])['body'];
    ok(!str_contains($html, 'is not built yet') && !str_contains($html, 'slice 6') && str_contains($html, 'id="' . $id . '-header"'), "$p is no longer a placeholder");
}
$stub = (string) file_get_contents($root . '/app/features/shell/nav.php');
ok(!str_contains($stub, 'Announcements and notifications (slice 6)'), 'the shell\'s placeholder text for this slice is gone');
ok(preg_match_all('/notify\(/', shell_exec('cd ' . escapeshellarg($root) . ' && grep -rn "function notify(" app')) === 1, 'notify() is defined once, in app/features/notify/queue.php');
ok(!str_contains((string) file_get_contents($root . '/app/features/exchanges/respond.php'), 'INSERT INTO notification_outbox') && trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && grep -rln "INSERT INTO notification_outbox" app html bin')) === 'app/features/notify/queue.php', 'and it is the only code that inserts into the outbox');
$mf = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
ok(in_array('deploy/txtschedules-notifications.service', $mf['services'], true) && in_array('deploy/txtschedules-notifications.timer', $mf['services'], true) && is_file($root . '/deploy/txtschedules-notifications.service') && is_file($root . '/deploy/txtschedules-notifications.timer'), 'maludb-os.json lists the worker\'s service and timer, and both files exist');
$svc = (string) file_get_contents($root . '/deploy/txtschedules-notifications.service') . (string) file_get_contents($root . '/deploy/txtschedules-notifications.timer');
ok(str_contains($svc, 'ExecStart=/usr/bin/php {{APP_DIR}}/bin/notifications.php') && str_contains($svc, 'OnUnitActiveSec=1min') && str_contains($svc, 'User=www-data') && str_contains($svc, 'Type=oneshot'), 'the unit runs bin/notifications.php once every minute as www-data');
$rs = (string) file_get_contents($root . '/deploy/ROOT_STEPS.sh');
ok(str_contains($rs, 'txtschedules-notifications.timer') && str_contains($rs, 'MALUMAIL_API_KEY'), 'deploy/ROOT_STEPS.sh names the timer and the mail key');
$files = glob($root . '/db/0*.sql'); sort($files);
ok(basename(end($files)) === '015_exchange_overlap.sql', 'no migration');
$manifestEp = array_column($mf['endpoints'], 'path');
ok(in_array('/api/v1/calendar/{token}.ics', $manifestEp, true), 'the calendar feed is listed among the endpoints');
$mine = array_filter(explode("\n", trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && ls app/features/notify/*.php app/features/announcements/*.php app/features/calendar/*.php app/views/announcements/*.php app/views/settings/*.php app/views/settings/partials/*.php app/views/emails/*.php html/announcements/*.php html/settings/*.php html/api/v1/calendar.php bin/notifications.php'))));
$wage = array_filter($mine, fn ($f) => preg_match('/wage_rate|wage_override|default_wage|ts_effective_rate|scheduled_cost|\.cost\b/', (string) file_get_contents($root . '/' . $f)));
ok(count($mine) >= 25 && $wage === [], count($mine) . ' files make up the slice and none names a wage or a cost column');
finish();
