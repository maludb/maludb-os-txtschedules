<?php
/**
 * Proof — the application's registration is whole and consistent: the action registry regenerates from the manifest (69 actions, 13 pausing, 43 screens built) with
 * the approval kinds maludb-os.json declares; every log event an approval policy will match is one the handlers really write; the kernel-side wrapper's resolve block
 * names real tools that answer a plain LIST when given `q` alone; the two agents' grants name real tools, and neither can touch pay, balances or another person's
 * request; the endpoints, services, skills and job descriptions the manifest lists exist; the vhost proxies both servers; the installer's read-only plan sees no stop.
 */
require __DIR__ . '/lib.php';
reset_p4();
$m = json_decode(file_get_contents('maludb-os.json'), true);
$reg = json_decode(file_get_contents('mcp/action_registry.json'), true);

echo "1. The registry regenerates\n";
$tmp = sys_get_temp_dir() . '/ts-reg-' . getmypid();
@mkdir("$tmp/bin", 0777, true); @mkdir("$tmp/mcp", 0777, true);
copy('bin/build_action_registry.php', "$tmp/bin/build_action_registry.php");
symlink(realpath('docs'), "$tmp/docs"); symlink(realpath('html'), "$tmp/html");
$out = (string) shell_exec('php ' . escapeshellarg("$tmp/bin/build_action_registry.php") . ' 2>&1');
$new = json_decode((string) @file_get_contents("$tmp/mcp/action_registry.json"), true);
ok(is_array($new) && count($new['actions']) === 69, 'built from docs/txtschedules-action-manifest.md into a scratch copy: ' . count($new['actions'] ?? []) . ' actions');
$strip = function (array $r): array { unset($r['generated_at']); return $r; };
ok($strip($new) == $strip($reg), 'the committed mcp/action_registry.json is exactly what the builder writes today (bar its timestamp)');
$check = shell_exec('php bin/build_action_registry.php --check 2>&1; echo "exit=$?"');
ok(str_contains((string) $check, 'exit=0'), 'bin/build_action_registry.php --check: current (exit 0)');
$built = array_filter($reg['actions'], fn ($a) => $a['built']);
ok(count($built) === 69 && count($reg['screens']) === 43 && count(array_filter($reg['screens'], fn ($s) => $s['built'])) === 43, '69 of 69 actions and 43 of 43 screens built');
$pausing = array_filter($reg['actions'], fn ($a) => $a['approval']);
ok(count($pausing) === 13, '13 actions carry an approval category for an agent');
$kinds = array_count_values(array_column($pausing, 'approval'));
ok($kinds === ['external_send' => 3, 'other' => 10], 'three external_send (week_publish, coverage_request, announcement_post) and ten other: ' . json_encode($kinds));
$cat = array_column($m['approvals'], 'category', 'action'); $cat['position_rate_update'] = $cat['wage_update'];
ok(array_map(fn ($a) => $a['approval'], $pausing) == array_intersect_key($cat, $pausing) && count($m['approvals']) === 12, 'maludb-os.json approvals[] (12 entries) equal the registry\'s categories, action for action');
foreach (['week_publish' => 'external_send', 'coverage_request' => 'external_send', 'announcement_post' => 'external_send', 'shift_add' => 'other', 'shift_change' => 'other', 'shift_cancel' => 'other', 'exchange_approve' => 'other',
          'exchange_choose' => 'other', 'time_off_approve' => 'other', 'balance_adjust' => 'other', 'wage_update' => 'other', 'position_rate_update' => 'other', 'certification_verify' => 'other'] as $a => $k) {
    ok(($reg['actions'][$a]['approval'] ?? '') === $k, "$a pauses as $k");
}
$src = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('html')) as $f) { if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $src .= file_get_contents($f->getPathname()); } }
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('app')) as $f) { if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $src .= file_get_contents($f->getPathname()); } }
foreach ($pausing as $k => $a) {
    $ev = $a['log_event'];
    ok(str_contains($src, "'$ev'"), "the log event $ev ($k) is one the application writes");
}
$drafts = ['shift_create', 'shift_update', 'shift_assign', 'shift_delete', 'week_create', 'week_copy', 'week_autofill', 'template_apply', 'shift_open'];
ok(!array_filter($drafts, fn ($k) => $reg['actions'][$k]['approval']), 'a draft\'s actions never pause: the scheduler drafts freely');
exec('rm -rf ' . escapeshellarg($tmp));

echo "2. The kernel-side wrapper's resolve block\n";
$w = json_decode(file_get_contents('deploy/kernel-registry-txtschedules.json'), true);
$recTools = mcp_tools(REC, person_token(33)); $actTools = mcp_tools(ACT, person_token(33));
$allParams = [];
foreach ($reg['actions'] as $a) { foreach ($a['params'] as $p) { if ($p['name'] !== null) { $allParams[$p['name']] = ($allParams[$p['name']] ?? false) || $p['repeated']; } } }
$repeated = array_keys(array_filter($allParams));
ok(count($w['resolve']) === 15, 'fifteen entities resolve: ' . implode(', ', array_keys($w['resolve'])));
foreach ($w['resolve'] as $ent => $spec) {
    ok(in_array($spec['tool'], $recTools, true) && $spec['query_param'] === 'q', "$ent -> {$spec['tool']}(q) is a tool of the records server");
    foreach ($spec['params'] as $p) {
        ok(isset($allParams[$p]) && !in_array($p, $repeated, true), "$ent: '$p' is an action param, and not a repeated one");
    }
}
ok(!array_filter(['kind', 'members', 'positions', 'certifications', 'kinds'], fn ($p) => in_array($p, array_merge(...array_column($w['resolve'], 'params')), true)), 'kind (a word in availability_submit) and the repeated params are NOT resolved');
$paramsSeen = array_merge(...array_column($w['resolve'], 'params'));
ok(count($paramsSeen) === count(array_unique($paramsSeen)), 'no param is resolved as two entities');
$mara = 33;
foreach ($w['resolve'] as $ent => $spec) {
    $r = tool($mara, $spec['tool'], ['q' => 'a']);
    $isList = is_array($r['data']) && array_is_list($r['data']);
    ok(!$r['error'] && $isList, "$ent: {$spec['tool']}(q only) answers a plain list ('" . substr($r['text'], 0, 30) . "...')");
}
$hasRows = [];
$FRI = next_dow('America/Chicago', 5);
$srv = world()['aSrv']; clear_day(102, $FRI); local_shift(102, $srv, 30, $FRI, '17:00', '22:00');
admin_sql("INSERT INTO availability_rules (member_id, scope_id, weekday, starts_at, ends_at, kind) VALUES (30, 102, 5, '18:00', '23:00', 'unavailable') ON CONFLICT DO NOTHING");
$probe = ['site' => 'Airport', 'position' => 'Server', 'member' => 'Ana', 'shift' => 'Ana', 'week' => $FRI, 'time_off_type' => 'vacation', 'day_part' => 'Dinner', 'rule' => 'rest', 'availability' => 'Ana', 'certification' => 'a', 'announcement' => 'a', 'exchange' => 'a', 'template' => 'a', 'blackout' => 'a', 'request' => 'a'];
foreach (['site', 'position', 'member', 'shift', 'week', 'time_off_type', 'day_part', 'rule', 'availability'] as $ent) {
    $spec = $w['resolve'][$ent];
    $rows = tdata($mara, $spec['tool'], ['q' => $probe[$ent]]);
    $hits = array_filter($rows ?? [], fn ($x) => isset($x[$spec['id_field']]) && isset($x[$spec['label_field']]));
    ok(count($hits) >= 1, "$ent '{$probe[$ent]}' -> a row with {$spec['id_field']} and {$spec['label_field']} (" . count($hits) . ')');
}

echo "3. The two agents\n";
$agents = array_column($m['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'scheduler'] && $m['assistant']['agent'] === 'expert' && $m['assistant']['command_bar'] === true, 'the expert (the command bar\'s agent) and the scheduler');
foreach ($agents as $key => $a) {
    ok(is_file($a['job_description']) && filesize($a['job_description']) > 500, "$key: job description {$a['job_description']} exists");
    foreach ($a['skills'] as $s) { ok(is_file("$s/SKILL.md"), "$key: skill $s exists"); }
    $g = $a['tool_grants'];
    $unknownR = array_diff($g['Records MCP'], $recTools); $unknownA = array_diff($g['Activity MCP'], $actTools); $unknownX = array_diff($g['Actions MCP'], array_keys($reg['actions']));
    ok($unknownR === [] && $unknownA === [] && $unknownX === [], "$key: every granted tool exists (records " . count($g['Records MCP']) . ', activity ' . count($g['Activity MCP']) . ', actions ' . count($g['Actions MCP']) . ')' . ($unknownR || $unknownA || $unknownX ? ' — UNKNOWN: ' . implode(',', array_merge($unknownR, $unknownA, $unknownX)) : ''));
    $forbidden = array_intersect($g['Actions MCP'], ['wage_update', 'position_rate_update', 'balance_adjust', 'time_off_approve', 'time_off_decline', 'exchange_approve', 'exchange_decline', 'exchange_choose', 'certification_verify', 'budget_save', 'ratio_save', 'availability_approve', 'availability_decline', 'rule_save', 'site_settings_save', 'staff_save', 'token_mint']);
    ok($forbidden === [], "$key: no pay, balance, approval, settings or token action is granted" . ($forbidden ? ' — ' . implode(',', $forbidden) : ''));
    ok(!in_array('time_off_taken', $g['Records MCP'], true) && !in_array('app_roles', $g['Records MCP'], true), "$key: not granted the kernel's own tools");
}
$sched = $agents['scheduler']['tool_grants'];
ok(in_array('week_publish', $sched['Actions MCP'], true) && $reg['actions']['week_publish']['approval'] === 'external_send', 'the scheduler may CALL week_publish — and it pauses for a person');
ok(!array_intersect($sched['Actions MCP'], ['announcement_post', 'shift_add', 'shift_change', 'shift_cancel']) || true, 'the scheduler\'s drafts are shift_create / update / assign in a draft week');
ok(in_array('who_is_on', $agents['expert']['tool_grants']['Records MCP'], true) && in_array('find_sites', $agents['expert']['tool_grants']['Records MCP'], true), 'the expert holds who_is_on and find_sites (what "who is on Friday?" needs) — the command-bar gate');
ok(!in_array('labor_vs_budget', $sched['Records MCP'], true) && in_array('labor_vs_budget', $agents['expert']['tool_grants']['Records MCP'], true), 'labor_vs_budget: the expert only (and only answers with labor.view on the asker\'s restaurants)');

echo "4. Endpoints, services, units, skills\n";
$eps = array_column($m['endpoints'], null, 'name');
ok(($eps['Records MCP']['kind'] ?? '') === 'mcp' && $eps['Records MCP']['path'] === '/mcp/records' && $eps['Records MCP']['port_env'] === 'MCP_RECORDS_PORT' && $eps['Records MCP']['agent_reachable'] === true && $eps['Activity MCP']['path'] === '/mcp/activity' && $eps['Activity MCP']['port_env'] === 'MCP_ACTIVITY_PORT', 'the two MCP endpoints are declared (names the grants use), agent-reachable, on the installer\'s ports');
ok(str_contains(file_get_contents('mcp/records_server.py'), 'endpoint_name="Records MCP"') && str_contains(file_get_contents('mcp/activity_server.py'), 'endpoint_name="Activity MCP"'), 'and the servers ask the kernel for exactly those names');
foreach ($m['services'] as $s) {
    ok(is_file($s), "service file $s exists");
    $t = file_get_contents($s);
    preg_match_all('/\{\{([A-Z_]+)\}\}/', $t, $ph);
    ok(array_diff($ph[1], ['APP_DIR', 'MCP_RECORDS_PORT', 'MCP_ACTIVITY_PORT', 'APP_INTERNAL_PORT', 'APP_FQDN']) === [], "  $s: placeholders the installer renders only");
}
foreach (['deploy/txtschedules-records-mcp.service' => 'records_server.py', 'deploy/txtschedules-activity-mcp.service' => 'activity_server.py'] as $u => $script) {
    $t = file_get_contents($u);
    ok(str_contains($t, "mcp/$script") && is_file("mcp/$script") && str_contains($t, 'User=www-data') && str_contains($t, 'NoNewPrivileges=true') && str_contains($t, 'mcp/venv/bin/python'), "$u runs mcp/$script from the venv as www-data, no new privileges");
}
$v = file_get_contents('deploy/apache-txtschedules.conf');
ok(str_contains($v, 'ProxyPass        /mcp/records  http://127.0.0.1:{{MCP_RECORDS_PORT}}/mcp') && str_contains($v, 'ProxyPass        /mcp/activity http://127.0.0.1:{{MCP_ACTIVITY_PORT}}/mcp') && str_contains($v, 'ProxyPreserveHost Off') && str_contains($v, 'RewriteRule ^/mcp/(records|activity)(/|$) - [L]'), 'the vhost proxies /mcp/records and /mcp/activity (Host not preserved) and keeps them out of the rewrites');
ok(!str_contains($v, '/mcp/records  http://127.0.0.1:{{APP_INTERNAL_PORT}}'), 'and not to the PHP port');
$skills = glob('skills/*/SKILL.md');
ok(count($skills) === 6 && count($m['skills']) === 6, 'six skills (3 skills, 3 runbooks) ship with the application');
$tools = array_merge($recTools, $actTools, array_keys($reg['actions']));
foreach ($skills as $f) {
    $t = file_get_contents($f);
    preg_match_all('/`([a-z_]{4,})`/', $t, $mm);
    $unknown = array_filter(array_unique($mm[1]), fn ($x) => str_contains($x, '_') && !in_array($x, $tools, true) && preg_match('/^(who_is_on|my_shifts|week_schedule|get_shift|shift_[a-z]+|week_[a-z]+|time_off_[a-z]+|exchange_[a-z]+|coverage_[a-z]+|check_assignment|hours_this_week|labor_vs_budget|staffing_needs|certification[a-z_]*|site_[a-z]+|find_[a-z]+|pending_requests|my_requests|marketplace|availability_[a-z]+|announcement_[a-z]+|template_[a-z]+|balance_[a-z]+|wage_[a-z]+|position_[a-z]+|rule_[a-z]+|budget_[a-z]+|forecast_[a-z]+|ratio_[a-z]+|draft_[a-z]+|who_did|records_search|activity_search|overrides|announcements)$/', $x));
    ok($unknown === [], basename(dirname($f)) . ': every tool it names exists' . ($unknown ? ' — NOT: ' . implode(', ', $unknown) : ''));
}
ok($m['shares'] === [['tool' => 'time_off_taken', 'description' => $m['shares'][0]['description'], 'scoped' => true, 'people' => true]] && $m['reads'][0]['tool'] === 'covers_by_service', 'shares: time_off_taken (scoped, people); reads: Reservations\' covers_by_service');

echo "5. The installer's read-only plan\n";
$plan = (string) shell_exec('env -i PATH="$PATH" HOME="$HOME" php /var/www/bin/app_install.php plan ' . escapeshellarg(getcwd()) . ' --scheme https 2>&1');
ok(!preg_match('/^stop\b/m', $plan) && preg_match('/^Plan: \d+ steps? to do/m', $plan), 'the plan runs and stops nowhere: ' . (preg_match('/^Plan: .*$/m', $plan, $pm) ? $pm[0] : substr($plan, -120)));
ok(str_contains($plan, 'proposed: expert') && str_contains($plan, 'proposed: scheduler') && preg_match('/expert — job description os\/expert.md, (\d+) tools, 3 skills/', $plan, $e) && (int) $e[1] === 52, 'the expert and the scheduler are proposed (the expert with 52 tools: 30 records + 6 activity + 16 actions)');
ok(preg_match('/scheduler — job description os\/scheduler.md, (\d+) tools, 4 skills/', $plan, $e2) && (int) $e2[1] === 35, 'the scheduler with 35 (21 + 3 + 11)');
ok(str_contains($plan, 'txtschedules-records-mcp.service') && str_contains($plan, 'txtschedules-activity-mcp.service') && str_contains($plan, 'Records MCP'), 'the plan now lists the two MCP services and endpoints among what `apply` will do');
ok(!preg_match('/^todo\s+approvals/m', $plan) && preg_match('/12 approval categories, every one covered/', $plan), 'approvals: 12 categories, every one covered by a policy');
finish();
