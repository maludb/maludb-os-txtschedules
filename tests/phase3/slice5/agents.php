<?php
/** Proof — agents: the run token acts as its agent's member under the same rights (the five labor and forecast tools), rows are source agent with the run's request id and the site; none pauses for an approval; the screens are for people. */
require __DIR__ . '/lib.php';
$W = reset5();
$owner = as_member(1, 102);
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$run = fn (int $m, int $runId): string => ($p = $m . '.' . (time() + 300) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (905, 'agent', 'SMOKE Scheduler', 'user', 'active', 'write', '{manager}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (905, 102, 'manager', '{manager}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (906, 'agent', 'SMOKE Helper', 'user', 'active', 'write', '{staff}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (906, 102, 'staff', '{staff}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (907, 'agent', 'SMOKE Planner agent', 'user', 'active', 'admin', '{admin}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (907, 102, 'admin', '{admin}', 'admin') ON CONFLICT DO NOTHING;");
kernel_state(function ($s) {
    foreach ([[721, 905], [722, 906], [723, 907]] as [$r, $m]) { $s['facts'][(string) $r] = ['valid' => true, 'is_agent' => true, 'member_id' => $m, 'run_id' => $r, 'request_id' => "req-run-$r", 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; }
    return $s;
});
$hdr = function (int $m, int $r) use ($run, $relay): array { $t = $run($m, $r); return ['X-Action-Token: ' . $t, 'X-Action-Relay: ' . $relay($t)]; };
$ws = wk(140); $fri = dayn($ws, 4); $dinner = dpid(102, 'dinner'); $srv = $W['aSrv'];
echo "1. The scheduler agent (schedule.build, labor.view — no settings.manage)\n";
$since = last_activity_id();
[$c, $b] = $post('/labor/forecast.php', ['site' => 102, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '80'], $hdr(905, 721));
$row = q("SELECT source, agent_run_id, request_id, scope_id, actor_member_id FROM activity_log WHERE action = 'forecast.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && cell(102, $fri, $dinner) && (int) cell(102, $fri, $dinner)['expected_covers'] === 80 && (int) (cell(102, $fri, $dinner)['updated_by'] ?? 0) === 905 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 721 && $row['request_id'] === 'req-run-721' && (int) $row['scope_id'] === 102 && (int) $row['actor_member_id'] === 905,
    'forecast_save under the run token: 200; the cell is the agent\'s; the row is source agent, run 721, the run\'s request id, the site');
ok(($b['record_id'] ?? 0) === (int) cell(102, $fri, $dinner)['id'] && str_ends_with($b['location'] ?? '', '#forecast-cover-' . $b['record_id']), 'the reply carries record_id and the location ends in it');
[$c, $b] = $post('/labor/forecast-copy.php', ['site' => 102, 'from_week' => $ws, 'to_week' => wk(141)], $hdr(905, 721));
ok($c === 200 && ($b['cells'] ?? 0) === 1 && cell(102, dayn(wk(141), 4), $dinner)['source'] === 'copied', 'forecast_copy: 200, one cell, marked copied');
kread(['mode' => 'ok', 'rows' => [['date' => $fri, 'service' => 'Lunch', 'reservations' => 4, 'covers' => 14]]]);
$since = last_activity_id();
[$c, $b] = $post('/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws], $hdr(905, 721));
$row = q("SELECT source, agent_run_id, scope_id FROM activity_log WHERE action = 'forecast.fill' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && ($b['written'] ?? 0) === 1 && (int) cell(102, $fri, dpid(102, 'lunch'))['expected_covers'] === 14 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 721, 'forecast_fill under the token (kernel stubbed): 200, one cell, logged as the agent');
kread(['mode' => 'no_connection']);
[$c, $b] = $post('/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws], $hdr(905, 721));
ok($c === 409 && str_starts_with(msg($b), 'Reservations is not connected'), 'and with no connection the agent is told in the same words (409): "' . msg($b) . '"');
[$c, $b] = $post('/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1'], $hdr(905, 721));
ok($c === 403 && ratio(102, $srv) === null, 'ratio_save without settings.manage: 403, nothing set');
[$c, $b] = $post('/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '10'], $hdr(905, 721));
ok($c === 403 && budget_of(102, $ws, 'all') === null, 'budget_save without settings.manage: 403, nothing set');
echo "2. The staff agent\n";
foreach ([['/labor/forecast.php', ['site' => 102, 'on_date' => $fri, 'day_part' => 'Dinner', 'expected_covers' => '9']], ['/labor/forecast-copy.php', ['site' => 102, 'from_week' => $ws, 'to_week' => wk(142)]], ['/labor/forecast-fill.php', ['site' => 102, 'week_start' => $ws]],
          ['/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '9']], ['/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'budget_hours' => '9']]] as [$p, $f]) {
    [$c, $b] = $post($p, $f, $hdr(906, 722));
    ok($c === 403, "$p → 403");
}
ok((int) cell(102, $fri, $dinner)['expected_covers'] === 80, 'and Friday\'s 80 stands');
echo "3. The planner agent (admin)\n";
$since = last_activity_id();
[$c, $b] = $post('/labor/ratio.php', ['position' => $srv, 'covers_per_staff' => '25', 'min_staff' => '1'], $hdr(907, 723));
$row = q("SELECT source, agent_run_id, request_id, scope_id FROM activity_log WHERE action = 'ratio.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && (float) ratio(102, $srv)['covers_per_staff'] === 25.0 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 723 && (int) $row['scope_id'] === 102, 'ratio_save: 200, source agent, run 723, the site');
$since = last_activity_id();
[$c, $b] = $post('/labor/budget.php', ['site' => 102, 'week_start' => $ws, 'area' => 'all', 'budget_hours' => '120', 'budget_amount' => '1500'], $hdr(907, 723));
$row = q("SELECT source, agent_run_id, scope_id, before, after FROM activity_log WHERE action = 'budget.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && ($b['record_id'] ?? 0) === (int) budget_of(102, $ws, 'all')['id'] && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 723, 'budget_save: 200, record_id, source agent, run 723');
ok(wage_leaks(json_encode($row) . $b['did']) === [], 'no wage in the reply or the row');
echo "4. Agents use the tools, not the screens\n";
$codes = [];
foreach (["/forecast?site=102&week=$ws", "/budget?site=102&week=$ws"] as $p) { $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(905, 721))])['code']; $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(907, 723))])['code']; }
ok(array_unique($codes) === [403], 'the two screens answer an agent 403 (people only): ' . implode(',', $codes));
echo "5. Nothing here pauses for an approval\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$bad = [];
foreach (['forecast_save' => 'forecast.update', 'forecast_copy' => 'forecast.copy', 'forecast_fill' => 'forecast.fill', 'ratio_save' => 'ratio.update', 'budget_save' => 'budget.update'] as $a => $ev) {
    if (!array_key_exists('approval', $reg[$a]) || $reg[$a]['approval'] !== null || ($reg[$a]['log_event'] ?? '') !== $ev) { $bad[] = $a; }
}
ok($bad === [], 'the five actions carry no approval and each names the log event it writes' . ($bad ? ' — ' . implode(', ', $bad) : ''));
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
