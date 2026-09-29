<?php
/** Proof — agents (spec "Proof", 9): the scheduling assistant's run token drafts freely (rows source agent, the run's request id); publishing and every live change is registered to pause for approval in the kernel (Phase 4 proves the pause itself). */
require __DIR__ . '/lib.php';
$W = w2(); reset_people();
$mara = as_member(33); as_member(30); as_member(31);
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$run = fn (int $m, int $runId): string => ($p = $m . '.' . (time() + 300) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
$srv = srv();
admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (903, 'agent', 'SMOKE Scheduling assistant', 'user', 'active', 'write', '{manager}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (903, 102, 'manager', '{manager}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (904, 'agent', 'SMOKE Helper agent', 'user', 'active', 'write', '{staff}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (904, 102, 'staff', '{staff}', 'write') ON CONFLICT DO NOTHING;");
kernel_state(function ($s) {
    foreach ([[601, 903], [602, 904]] as [$r, $m]) { $s['facts'][(string) $r] = ['valid' => true, 'is_agent' => true, 'member_id' => $m, 'run_id' => $r, 'request_id' => "req-run-$r", 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; }
    return $s;
});
$tok = $run(903, 601); $hdr = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)];
$ws = wk(52);

echo "1. The scheduling assistant drafts freely\n";
$since = last_activity_id();
[$c, $b] = $post('/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws, 1, '17:00'), 'ends_at' => at($ws, 1, '23:00'), 'assignee' => 30], $hdr);
$s1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $s1 > 0 && $b['location'] === "/shifts/$s1", 'shift_create under a run token: 200, the location ends in the id');
[$c, $b] = $post('/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws, 2, '17:00'), 'ends_at' => at($ws, 2, '23:00')], $hdr);
$s2 = (int) ($b['record_id'] ?? 0);
[$c, $b] = $post('/shifts/assign.php', ['shift' => $s2, 'assignee' => 31], $hdr);
ok($c === 200 && (int) one('SELECT assignee_member_id FROM shifts WHERE id = :i', ['i' => $s2]) === 31, 'shift_assign: the shift is Lee\'s');
[$c, $b] = $post('/shifts/save.php', ['shift' => $s1, 'note' => 'SMOKE from the assistant', '_partial' => '1'], $hdr);
$row = q('SELECT starts_at, ends_at, assignee_member_id, position_id, note FROM shifts WHERE id = :i', ['i' => $s1])[0];
ok($c === 200 && $row['note'] === 'SMOKE from the assistant' && loc($row['starts_at']) === dayn($ws, 1) . ' 17:00' && loc($row['ends_at']) === dayn($ws, 1) . ' 23:00' && (int) $row['assignee_member_id'] === 30 && (int) $row['position_id'] === $srv, 'shift_update sending only the note (partial): everything it did not send is kept — position, times and person');
$rows = q("SELECT action, source, agent_run_id, request_id, scope_id, actor_member_id FROM activity_log WHERE id > :s AND action IN ('shift.create', 'shift.assign', 'shift.update', 'week.create')", ['s' => $since]);
ok(count($rows) === 5 && count(array_filter($rows, fn ($r) => $r['source'] === 'agent' && (int) $r['agent_run_id'] === 601 && $r['request_id'] === 'req-run-601' && (int) $r['scope_id'] === 102 && (int) $r['actor_member_id'] === 903)) === 5, 'every row (week.create, shift.create ×2, shift.assign, shift.update) is source agent, run 601, the run\'s request id, the site: ' . json_encode(array_count_values(array_column($rows, 'action'))));
[$c, $b] = $post('/weeks/autofill.php', ['week' => week_id_of(102, $ws)], $hdr);
ok($c === 200, 'week_autofill under the token: 200');
[$c, $b] = $post('/shifts/delete.php', ['shift' => $s2], $hdr);
ok($c === 200 && (int) one('SELECT count(*) FROM shifts WHERE id = :i', ['i' => $s2]) === 0, 'shift_delete of its own draft: 200');
[$c, $b] = $post('/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws, 1, '20:00'), 'ends_at' => at($ws, 1, '23:00'), 'assignee' => 30], $hdr);
ok($c === 422 && str_contains(msg($b), 'already has a shift then'), 'the rules apply to an agent exactly as to a person: an overlap is 422 "' . msg($b) . '"');
[$c, $b] = $post('/shifts/save.php', ['site' => 102, 'position' => (int) $W['aBar'], 'starts_at' => at($ws, 3, '17:00'), 'ends_at' => at($ws, 3, '21:00'), 'assignee' => 31], $hdr);
ok($c === 422 && str_contains(msg($b), 'Does not work this position here.'), 'and a hard rule the same: 422 "' . msg($b) . '"');
$r = req('GET', "/builder?site=102&week=$ws", ['headers' => array_merge(JSONH, $hdr)]);
ok($r['code'] === 403, 'an agent does not open the builder SCREEN (people only): 403');

echo "2. An agent without the right\n";
$tok2 = $run(904, 602); $h2 = ['X-Action-Token: ' . $tok2, 'X-Action-Relay: ' . $relay($tok2)];
[$c, $b] = $post('/shifts/save.php', ['site' => 102, 'position' => $srv, 'starts_at' => at($ws, 4, '17:00'), 'ends_at' => at($ws, 4, '23:00')], $h2);
ok($c === 403 && msg($b) === 'You may not build the schedule here.', 'an agent granted only as staff: shift_create → 403');
[$c, $b] = $post('/weeks/publish.php', ['week' => week_id_of(102, $ws)], $h2);
ok($c === 403, 'and week_publish → 403');

echo "3. What pauses in the kernel (registered here; the pause itself is Phase 4's proof)\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$appr = fn (string $a) => isset($reg[$a]) ? ($reg[$a]['approval'] ?? null) : 'MISSING';
ok($appr('week_publish') === 'external_send' && $appr('shift_add') === 'other' && $appr('shift_change') === 'other' && $appr('shift_cancel') === 'other', 'week_publish is registered external_send; shift_add, shift_change, shift_cancel other');
ok(array_reduce(['week_create', 'week_copy', 'week_autofill', 'week_clear', 'shift_create', 'shift_update', 'shift_assign', 'shift_delete', 'template_save', 'template_apply', 'template_archive'], fn ($ok, $a) => $ok && $appr($a) === null, true), 'a draft\'s actions (create, update, assign, delete, copy, autofill, clear, templates) never pause: the assistant drafts freely and cannot publish');
ok(array_reduce(array_keys(array_filter($reg, fn ($a) => ($a['endpoint'] ?? '') !== '' && preg_match('~^/(weeks|shifts|templates)/~', $a['endpoint']) && in_array($a['action'], ['week_create', 'week_copy', 'week_autofill', 'week_clear', 'week_publish', 'shift_create', 'shift_update', 'shift_assign', 'shift_delete', 'shift_add', 'shift_change', 'shift_cancel', 'template_save', 'template_apply', 'template_archive'], true))), fn ($ok, $k) => $ok && $reg[$k]['built'] === true && is_file(dirname(__DIR__, 3) . '/html' . $reg[$k]['endpoint']), true), 'all fifteen actions of the slice are built and their files exist');
$mf = file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json');
ok(str_contains($mf, 'week_publish') && str_contains($mf, 'external_send') && str_contains($mf, 'shift_cancel'), 'maludb-os.json declares the approvals: week_publish external_send, the live changes other');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
