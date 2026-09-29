<?php
/**
 * Proof — agents: the run token acts as its agent's member under the same rights (settings.manage for all five actions); the rows are source agent with the run's request id and the site; a manager agent without
 * settings.manage is refused and changes nothing; the four screens are for people; and the five actions are registered with no agent approval (the spec: "No agent approvals") and their log events.
 */
require __DIR__ . '/lib.php';
$W = reset7();
as_member(33);
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$run = fn (int $m, int $runId): string => ($p = $m . '.' . (time() + 300) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (905, 'agent', 'SMOKE Scheduler', 'user', 'active', 'write', '{manager}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (905, 102, 'manager', '{manager}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (907, 'agent', 'SMOKE Steward', 'user', 'active', 'admin', '{admin}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (907, 102, 'admin', '{admin}', 'admin') ON CONFLICT DO NOTHING;");
kernel_state(function ($s) {
    foreach ([[741, 905], [742, 907]] as [$r, $m]) { $s['facts'][(string) $r] = ['valid' => true, 'is_agent' => true, 'member_id' => $m, 'run_id' => $r, 'request_id' => "req-run-$r", 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; }
    return $s;
});
$hdr = function (int $m, int $r) use ($run, $relay): array { $t = $run($m, $r); return ['X-Action-Token: ' . $t, 'X-Action-Relay: ' . $relay($t)]; };
$snap = fn () => json_encode([q('SELECT * FROM site_settings ORDER BY scope_id'), q('SELECT * FROM site_rules ORDER BY scope_id, rule_key'), q('SELECT id, scope_id, name, starts_at, ends_at, service_name, sort_order, archived_at FROM day_parts ORDER BY id')]);
$lunch = dpid(102, 'lunch');

echo "1. An agent that manages the schedule but not the settings\n";
$before = $snap(); $codes = [];
foreach ([['/site/save.php', ['site' => 102, 'cutoff_minutes' => '30']], ['/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Agent Brunch', 'starts_at' => '09:00', 'ends_at' => '11:00']], ['/site/day-part-archive.php', ['day_part' => $lunch]],
          ['/rules/save.php', ['site' => 102, 'rule' => 'min_rest', 'severity' => 'off']], ['/rules/preset.php', ['site' => 102, 'preset' => 'generic']]] as [$p, $f]) {
    [$c, $b] = $post($p, $f, $hdr(905, 741)); $codes[] = $c;
}
ok($codes === [403, 403, 403, 403, 403] && $snap() === $before, 'the manager agent (905): all five actions 403 and nothing changed');
echo "2. The admin agent\n";
$since = last_activity_id();
[$c, $b] = $post('/site/save.php', ['site' => 102, 'cutoff_minutes' => '90', 'time_off_day_hours' => '7.5'], $hdr(907, 742));
$row = q("SELECT source, agent_run_id, request_id, scope_id, actor_member_id, before, after FROM activity_log WHERE action = 'settings.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && $b['changed'] === ['cutoff_minutes', 'time_off_day_hours'] && (int) $b['record_id'] === 102 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 742 && $row['request_id'] === 'req-run-742' && (int) $row['scope_id'] === 102 && (int) $row['actor_member_id'] === 907, 'site_settings_save under the run token: 200, source agent, run 742, the run\'s request id, the site');
ok(json_decode($row['before'], true) == ['cutoff_minutes' => 120, 'time_off_day_hours' => 8] && json_decode($row['after'], true) == ['cutoff_minutes' => 90, 'time_off_day_hours' => 7.5], 'before and after carry the two changed fields alone');
$since = last_activity_id();
[$c, $b] = $post('/site/day-part.php', ['site' => 102, 'name' => 'SMOKE Agent Brunch', 'starts_at' => '09:00', 'ends_at' => '11:00', 'service_name' => 'Brunch'], $hdr(907, 742));
$id = (int) ($b['record_id'] ?? 0);
$row = q("SELECT source, agent_run_id, scope_id FROM activity_log WHERE action = 'day_part.save' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && $id > 0 && str_ends_with($b['location'] ?? '', '#day-part-' . $id) && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 742 && (int) $row['scope_id'] === 102, 'day_part_save: 200, the reply carries record_id and the location ends in it; source agent, run 742');
[$c, $b] = $post('/site/day-part-archive.php', ['day_part' => $id], $hdr(907, 742));
ok($c === 200 && one('SELECT archived_at FROM day_parts WHERE id = :i', ['i' => $id]) !== null && (int) one("SELECT count(*) FROM activity_log WHERE action = 'day_part.archive' AND source = 'agent' AND entity_id = :i", ['i' => $id]) === 1, 'day_part_archive: 200 (no confirm page for an API caller), archived, logged as the agent');
$since = last_activity_id();
[$c, $b] = $post('/rules/save.php', ['site' => 102, 'rule' => 'min_rest', 'severity' => 'hard', 'params' => '{"hours": 12}'], $hdr(907, 742));
$row = q("SELECT source, agent_run_id, scope_id FROM activity_log WHERE action = 'rule.update' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && rule_of(102, 'min_rest') === ['severity' => 'hard', 'params' => ['hours' => 12]] && ($row['source'] ?? '') === 'agent' && (int) $row['scope_id'] === 102, 'rule_save with params as JSON text (an agent\'s way): hard, 12 hours, logged as the agent');
[$c, $b] = $post('/rules/save.php', ['site' => 102, 'rule' => 'min_rest', 'severity' => 'hard', 'params' => '{"hours": "soon"}'], $hdr(907, 742));
ok($c === 422 && rule_of(102, 'min_rest')['params'] === ['hours' => 12], 'a bad value from an agent is 422 in words: "' . msg($b) . '"');
[$c, $b] = $post('/rules/preset.php', ['site' => 102, 'preset' => 'generic'], $hdr(907, 742));
ok($c === 200 && $b['rules_changed'] >= 1 && rule_of(102, 'min_rest') === ['severity' => 'soft', 'params' => ['hours' => 10]] && (int) one("SELECT count(*) FROM activity_log WHERE action = 'rule.preset' AND source = 'agent'") === 1, 'rule_preset_apply: 200, the rules are back, logged as the agent');
[$c, $b] = $post('/site/save.php', ['site' => 101, 'cutoff_minutes' => '30'], $hdr(907, 742));
ok($c === 404 && (int) site_row(101)['cutoff_minutes'] === 120, 'the admin agent holds Airport only: Downtown\'s settings are 404');
$r = req('POST', '/site/save.php', ['headers' => array_merge(JSONH, $hdr(907, 742)), 'form' => ['site' => 102, 'cutoff_minutes' => '60'], 'jar' => null]);
ok($r['code'] === 200, 'an action token stands in for the CSRF token');
$r = req('POST', '/site/save.php', ['headers' => JSONH, 'form' => ['site' => 102, 'cutoff_minutes' => '60']]);
ok($r['code'] === 401, 'with neither a session nor a token: 401');
echo "3. Agents use the tools, not the screens\n";
$codes = [];
foreach (['/site/?site=102', '/site/day-parts?site=102', '/rules/?site=102', '/reports/?site=102'] as $p) { $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(905, 741))])['code']; $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(907, 742))])['code']; }
ok(array_unique($codes) === [403], 'the four screens answer an agent 403 (people only): ' . implode(',', $codes));
echo "4. What the registry says\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$bad = [];
foreach (['site_settings_save' => ['/site/save.php', 'settings.update', false], 'day_part_save' => ['/site/day-part.php', 'day_part.save', false], 'day_part_archive' => ['/site/day-part-archive.php', 'day_part.archive', true],
          'rule_save' => ['/rules/save.php', 'rule.update', false], 'rule_preset_apply' => ['/rules/preset.php', 'rule.preset', true]] as $a => [$ep, $ev, $confirm]) {
    $r = $reg[$a] ?? null;
    if ($r === null || $r['built'] !== true || $r['endpoint'] !== $ep || $r['log_event'] !== $ev || $r['approval'] !== null || $r['confirm'] !== $confirm || !is_file(dirname(__DIR__, 3) . '/html' . $ep)) { $bad[] = $a; }
}
ok($bad === [], 'the five actions are built, at their endpoints, with the log event and confirm the manifest says and NO agent approval' . ($bad ? ' — ' . implode(', ', $bad) : ''));
kernel_state(function ($s) { unset($s['facts']); return $s; });
reset7();
finish();
