<?php
/**
 * Proof — the manifest and the shell: the five actions and four screens of the slice are in the registry, built, their files exist; the registry reaches 69 of 69 actions and 43 of 43 screens — nothing is left for a placeholder
 * to show; the placeholder mechanism is gone from the shell; every slice's actions are still built; the kernel's registry file can be regenerated from this one; nothing was migrated; the root steps name what the owner runs.
 */
require __DIR__ . '/lib.php';
$W = reset7();
$root = dirname(__DIR__, 3);
$priya = as_member(26); $mara = as_member(33); $owner = admin_jar(102);
$out = []; exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $code);
ok($code === 0, 'bin/build_action_registry.php --check: the registry is current with the manifest (' . trim(implode(' ', $out)) . ')');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$acts = ['site_settings_save' => '/site/', 'day_part_save' => '/site/', 'day_part_archive' => '/site/', 'rule_save' => '/rules/', 'rule_preset_apply' => '/rules/'];
$bad = [];
foreach ($acts as $a => $base) { $r = $reg['actions'][$a] ?? null; if ($r === null || $r['built'] !== true || !is_file($root . '/html' . $r['endpoint']) || !str_starts_with($r['endpoint'], $base)) { $bad[] = $a; } }
ok($bad === [], 'all five actions are built and their endpoint files exist' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$bad = [];
foreach (['site-settings', 'day-parts', 'rules', 'reports'] as $s) { $r = $reg['screens'][$s] ?? null; if ($r === null || $r['built'] !== true || ($r['stub'] ?? false)) { $bad[] = $s; } }
ok($bad === [], 'the four screens are built in the registry (none a stub)' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$built = count(array_filter($reg['actions'], fn ($a) => $a['built'])); $sb = count(array_filter($reg['screens'], fn ($a) => $a['built'])); $stubs = array_keys(array_filter($reg['screens'], fn ($a) => $a['stub'] ?? false));
ok(count($reg['actions']) === 69 && $built === 69 && count($reg['screens']) === 43 && $sb === 43 && $stubs === [], "the registry counts $built of " . count($reg['actions']) . " actions built and $sb of " . count($reg['screens']) . ' screens built — none a stub');
$unbuilt = array_merge(array_keys(array_filter($reg['actions'], fn ($a) => !$a['built'])), array_keys(array_filter($reg['screens'], fn ($a) => !$a['built'])));
ok($unbuilt === [], 'nothing in the manifest is unbuilt');
$missing = [];
foreach ($reg['actions'] as $n => $a) { if (!is_file($root . '/html' . $a['endpoint'])) { $missing[] = $n; } }

ok($missing === [], 'every action endpoint the registry names has a file (the screens\' controllers are what the registry\'s own built flags test)' . ($missing ? ' — ' . implode(', ', $missing) : ''));
$params = fn (string $a): array => array_column($reg['actions'][$a]['params'], 'name');
ok($params('rule_save') === ['site', 'rule', 'severity', 'params'] && in_array('time_off_day_hours', $params('site_settings_save'), true) && $params('day_part_save') === ['site', 'name', 'starts_at', 'ends_at', 'service_name', 'sort_order', 'day_part'] && $params('day_part_archive') === ['day_part'] && $params('rule_preset_apply') === ['site', 'preset'], 'the manifest\'s parameters reach the registry');
foreach ([['/site/?site=102', $owner, 'site-settings'], ['/site/day-parts?site=102', $owner, 'day-parts'], ['/rules/?site=102', $mara, 'rules'], ['/reports/?site=102', $mara, 'reports']] as [$p, $jar, $id]) {
    $html = req('GET', $p, ['jar' => $jar])['body'];
    ok(!str_contains($html, 'is not built yet') && !str_contains($html, 'slice 7') && str_contains($html, 'id="' . $id . '-header"'), "$p is no longer a placeholder");
}
$nav = (string) file_get_contents($root . '/app/features/shell/nav.php');
ok(!str_contains($nav, 'render_nav_stub') && !str_contains($nav, 'Settings, rules and reports (slice 7)') && !str_contains($nav, 'what fills it') && !str_contains((string) file_get_contents($root . '/app/http.php'), 'render_module_stub') && !is_file($root . '/app/views/shared/stub.php'), 'the placeholder mechanism is gone: no helper, no view, no "what fills it" column');
$rows = 0; $short = 0;
foreach (preg_split('/\R/', substr($nav, 0, strpos($nav, 'function nav_tabs'))) as $line) { if (preg_match("/^\s+\['[a-z-]+', '\/[^']*', 'feather-[a-z0-9-]+', '[^']+', '[^']+'\],?$/", $line)) { $rows++; } elseif (preg_match("/^\s+\['[a-z-]+', '\//", $line)) { $short++; } }
ok($rows === 24 && $short === 0, "every menu row is [id, url, icon, label, right] ($rows rows)");
$mf = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$granted = [];
foreach ($mf['agents'] as $a) { foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { $granted[$t] = true; } }
$unknown = array_values(array_filter(array_keys($granted), fn ($t) => !isset($reg['actions'][$t])));
ok($unknown === [], 'every Actions MCP tool an agent is granted exists in the registry' . ($unknown ? ' — ' . implode(', ', $unknown) : ''));
$appr = array_column($mf['approvals'] ?? [], null, 'action');
ok(!isset($appr['site_settings_save']) && !isset($appr['rule_save']) && !isset($appr['day_part_save']) && !isset($appr['rule_preset_apply']) && !isset($appr['day_part_archive']), 'maludb-os.json registers no approval for the five: "No agent approvals"');
// The kernel's registry file is the application's own registry wrapped (bin/app_install.php, step "registry"): regenerate it in memory from ours and hold it against the kernel's header.
$kernel = json_decode((string) @file_get_contents('/var/www/mcp/registries/txtschedules.json'), true);
if ($kernel) {
    $wrapped = ['schema' => $kernel['schema'], 'app_key' => $kernel['app_key'], 'name' => $kernel['name'], 'base_url' => $kernel['base_url'], 'records_url' => $kernel['records_url'], 'resolve' => $kernel['resolve'], 'registry' => $reg];
    $enc = json_encode($wrapped);
    ok($wrapped['schema'] === 'maludb-os.registry/1' && $wrapped['app_key'] === 'txtschedules' && json_decode($enc, true)['registry']['actions']['rule_save']['built'] === true && count(array_filter($wrapped['registry']['actions'], fn ($a) => $a['built'])) === 69, 'the kernel\'s mcp/registries/txtschedules.json can be regenerated from this registry: same header (' . $kernel['base_url'] . '), 69 built actions (the owner\'s refresh — not done here)');
    ok(count($kernel['registry']['actions'] ?? []) === 69 && count(array_filter($kernel['registry']['actions'] ?? [], fn ($a) => $a['built'])) < 69, 'and the kernel\'s copy is still the older one (' . count(array_filter($kernel['registry']['actions'] ?? [], fn ($a) => $a['built'])) . ' built) — untouched by this slice');
}
$files = glob($root . '/db/0*.sql'); sort($files);
ok(in_array(basename(end($files)), ['015_exchange_overlap.sql', '016_mcp_servers.sql'], true), 'no migration');
$rs = (string) file_get_contents($root . '/deploy/ROOT_STEPS.sh');
ok(str_contains($rs, 'Phase 3 (slice 7)') && str_contains($rs, 'apply') && str_contains($rs, '015_exchange_overlap.sql') && str_contains($rs, 'txtschedules-notifications.timer') && str_contains($rs, 'MALUMAIL_API_KEY') && str_contains($rs, 'notify_endpoint_set.php') && str_contains($rs, 'app_connection.php') && str_contains($rs, 'certstudy-actions-mcp'), 'deploy/ROOT_STEPS.sh names the migration, the timer, the mail key, the text sender, the Reservations connection and the actions-MCP restart');
$mine = array_filter(explode("\n", trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && ls app/features/site/*.php app/features/rules/*.php app/features/reports/*.php app/views/site/*.php app/views/site/partials/*.php app/views/rules/*.php app/views/reports/*.php html/site/*.php html/rules/*.php html/reports/*.php'))));
$wage = array_filter($mine, fn ($f) => preg_match('/wage_rate|wage_override|default_wage|ts_effective_rate/', (string) file_get_contents($root . '/' . $f)));
ok(count($mine) >= 19 && $wage === [], count($mine) . ' files make up the slice and none names a wage column');
finish();
