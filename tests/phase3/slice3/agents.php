<?php
/** Proof — agents (spec "Proof": an agent's balance_adjust is paused; an agent approving time off pauses for a person, D14): the run token acts as its agent's member under the same rights, the rows are source agent with the run's request id, and the approvals are registered `other` for the kernel to pause. */
require __DIR__ . '/lib.php';
$W = reset3();
$priya = as_member(26); $mara = as_member(33);
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$run = fn (int $m, int $runId): string => ($p = $m . '.' . (time() + 300) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
$vac = typ(102, 'vacation'); $unpaid = typ(102, 'unpaid');
admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (905, 'agent', 'SMOKE Leave desk', 'user', 'active', 'write', '{manager}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (905, 102, 'manager', '{manager}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (906, 'agent', 'SMOKE Helper', 'user', 'active', 'write', '{staff}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (906, 102, 'staff', '{staff}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (907, 'agent', 'SMOKE Payroll agent', 'user', 'active', 'admin', '{admin}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (907, 102, 'admin', '{admin}', 'admin') ON CONFLICT DO NOTHING;");
kernel_state(function ($s) {
    foreach ([[701, 905], [702, 906], [703, 907]] as [$r, $m]) { $s['facts'][(string) $r] = ['valid' => true, 'is_agent' => true, 'member_id' => $m, 'run_id' => $r, 'request_id' => "req-run-$r", 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; }
    return $s;
});
$hdr = function (int $m, int $r) use ($run, $relay): array { $t = $run($m, $r); return ['X-Action-Token: ' . $t, 'X-Action-Relay: ' . $relay($t)]; };
$w = wk(78); $d = fn (int $i): string => dayn($w, $i);
grant(26, $vac, 16);

echo "1. The leave desk agent (a manager's rights) works requests as its member\n";
[$c, $b] = req_off($priya, $unpaid, $d(1), $d(1));
$r1 = (int) $b['record_id'];
$since = last_activity_id();
[$c, $b] = $post('/time-off/approve.php', ['request' => $r1, 'note' => 'SMOKE by the agent'], $hdr(905, 701));
ok($c === 200 && status_off($r1) === 'approved', 'time_off_approve under the run token: 200 (the kernel pauses it FIRST — registered `other`, below)');
$row = q("SELECT source, agent_run_id, request_id, scope_id, actor_member_id FROM activity_log WHERE action = 'timeoff.approve' AND entity_id = :i AND id > :s", ['i' => $r1, 's' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 701 && $row['request_id'] === 'req-run-701' && (int) $row['scope_id'] === 102 && (int) $row['actor_member_id'] === 905, 'the row is source agent, run 701, the run\'s request id, the site, the agent\'s member');
[$c, $b] = $post('/time-off/request.php', ['time_off_type' => $vac, 'from' => $d(3), 'to' => $d(3), 'member' => 26, 'note' => 'SMOKE asked by the agent'], $hdr(905, 701));
$r2 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $r2 > 0 && (int) one('SELECT member_id FROM time_off_requests WHERE id = :i', ['i' => $r2]) === 26 && $b['location'] === "/time-off/$r2", 'time_off_request for a person (member=26): 200, record_id, the location ends in the id');
[$c, $b] = $post('/time-off/decline.php', ['request' => $r2, 'note' => 'SMOKE no'], $hdr(905, 701));
ok($c === 200 && status_off($r2) === 'declined', 'time_off_decline: 200');
[$c, $b] = $post('/availability/save.php', ['member' => 26, 'weekday' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'unavailable'], $hdr(905, 701));
ok($c === 200 && ($b['status'] ?? '') === 'approved', 'availability_submit for a person by an agent that may approve: approved at once');
[$c, $b] = $post('/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => 2, 'reason' => 'SMOKE agent tries'], $hdr(905, 701));
ok($c === 403 && msg($b) === 'You may not change pay or balances here.', 'a manager agent without pay.edit: balance_adjust → 403');
echo "2. An agent without the rights\n";
[$c, $b] = req_off($priya, $unpaid, $d(4), $d(4)); $r3 = (int) $b['record_id'];
[$c, $b] = $post('/time-off/approve.php', ['request' => $r3], $hdr(906, 702));
ok($c === 403 && msg($b) === 'You may not approve requests here.', 'the helper agent (staff): time_off_approve → 403 "' . msg($b) . '"');
[$c, $b] = $post('/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => 2, 'reason' => 'SMOKE agent tries'], $hdr(906, 702));
ok($c === 403, 'and balance_adjust → 403');
[$c, $b] = $post('/time-off/request.php', ['time_off_type' => $unpaid, 'from' => $d(5), 'to' => $d(5), 'member' => 26], $hdr(906, 702));
ok($c === 403, 'and asking time off for a colleague → 403');
[$c, $b] = $post('/time-off/request.php', ['time_off_type' => $unpaid, 'from' => $d(5), 'to' => $d(5)], $hdr(906, 702));
ok($c === 200 && (int) one('SELECT member_id FROM time_off_requests WHERE id = :i', ['i' => (int) $b['record_id']]) === 906, 'asking for its OWN time off (its member): 200');
echo "3. The payroll agent (pay.edit) adjusts — the kernel pauses it\n";
$v = bal(26, $vac);
[$c, $b] = $post('/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => 3, 'reason' => 'SMOKE approved by a person first'], $hdr(907, 703));
$row = q("SELECT source, agent_run_id, request_id, scope_id FROM activity_log WHERE action = 'balance.adjust' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok($c === 200 && bal(26, $vac) === $v + 3 && $row['source'] === 'agent' && (int) $row['agent_run_id'] === 703 && (int) $row['scope_id'] === 102, 'balance_adjust under the token, once the approval was given: 200, one ledger row, source agent, run 703, the site');
echo "4. Agents use the tools, not the screens\n";
$codes = [];
foreach (['/time-off', '/availability', '/time-off/balances', '/time-off/new', '/site/time-off?site=102'] as $p) { $r = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(905, 701))]); $codes[] = $r['code']; }
ok($codes === [403, 403, 403, 403, 403], 'the five screens answer an agent 403 (people only)');
echo "5. What pauses in the kernel (registered here; the pause itself is the kernel's and Phase 4's proof)\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$appr = fn (string $a) => isset($reg[$a]) ? ($reg[$a]['approval'] ?? null) : 'MISSING';
ok($appr('time_off_approve') === 'other' && $appr('balance_adjust') === 'other', 'time_off_approve and balance_adjust are registered `other` — an agent\'s call pauses for a person (D14)');
ok(array_reduce(['availability_submit', 'availability_remove', 'availability_approve', 'availability_decline', 'time_off_request', 'time_off_decline', 'time_off_cancel', 'time_off_type_save', 'time_off_type_archive', 'blackout_save', 'blackout_remove'], fn ($ok, $a) => $ok && $appr($a) === null, true), 'the others carry no approval');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
