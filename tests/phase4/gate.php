<?php
/**
 * Proof — the door. Who gets in and what they are offered: no token, a forged, an expired one -> 401; a person's own token, and the command bar's action
 * token -> every tool; an agent's RUN token -> exactly the tools the kernel's run-facts call grants on THIS endpoint (fail closed: facts that say invalid,
 * facts for another application, a kernel that cannot be reached, an endpoint the agent was not granted); an agent the directory has not admitted is
 * admitted at first contact only when the kernel vouches for it; an evaluation run may read; the kernel's own token reaches app_roles and time_off_taken only,
 * and a person or an agent never sees time_off_taken. Servers: records :8194, activity :8195, records-with-a-dead-kernel :8196.
 */
require __DIR__ . '/lib.php';
reset_p4();
$run = fn () => random_int(700000, 799999);      // a fresh run id every time: the servers cache a run's facts

echo "1. Tokens that do not get in\n";
foreach ([REC, ACT] as $port) {
    $n = $port === REC ? 'records' : 'activity';
    ok(mcp_tools($port, '') === null, "$n: no token -> 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a made-up mcp_ token -> 401");
    $r = $run(); $t = run_token(951, $r);
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature -> 401");
    ok(mcp_tools($port, run_token(951, $run(), -5)) === null, "$n: an expired run token -> 401");
    ok(mcp_tools($port, action_token(30, -5)) === null, "$n: an expired action token -> 401");
    ok(mcp_tools($port, action_token(9999)) === null, "$n: an action token for a member the mirror does not know -> 401 (never created)");
}
ok(one('SELECT count(*) FROM members WHERE id = 9999') == 0, 'and the unknown member was not created');

echo "2. People\n";
$rec = mcp_tools(REC, person_token(33));
ok(is_array($rec) && count($rec) === 31, 'Mara (a manager) is offered the 31 records tools: ' . count($rec ?? []));
ok(!in_array('time_off_taken', $rec ?? []), 'time_off_taken is not among them (the share is the kernel\'s alone)');
$act = mcp_tools(ACT, person_token(33));
ok($act === ['activity_search', 'draft_history', 'exchange_history', 'shift_history', 'site_activity', 'who_did'], 'and the 6 activity tools on the activity server');
$want = ['activity_search', 'draft_history', 'exchange_history', 'shift_history', 'site_activity', 'who_did'];
ok(mcp_tools(REC, person_token(30)) === $rec && mcp_tools(REC, action_token(30)) === $rec, 'a staff member (own token) and the command bar\'s action token get the same 31 (row scoping does the rest)');
$r = mcp_tool(REC, action_token(30), 'my_shifts', []);
ok(!$r['error'] && ($r['data']['member_id'] ?? 0) === 30, 'the action token acts as its member: my_shifts is Ana\'s');
$r = mcp_tool(REC, person_token(33), 'time_off_taken', ['from' => '2026-01-01', 'to' => '2026-01-31', 'scope_id' => 102], true);
ok($r['error'] && str_contains($r['text'], 'kernel'), 'a person calling time_off_taken is refused: ' . substr($r['text'], 0, 90));

echo "3. An agent sees exactly what the kernel granted on this endpoint\n";
make_agent(951, 'SMOKE Scheduler P4', [102 => 'manager']);
$r1 = $run();
facts($r1, 951, ['Records MCP' => ['who_is_on' => [], 'week_schedule' => [], 'find_sites' => []], 'Activity MCP' => ['shift_history' => []]]);
$tok = run_token(951, $r1);
ok(mcp_tools(REC, $tok) === ['find_sites', 'week_schedule', 'who_is_on'], 'records: exactly the three granted (' . implode(',', mcp_tools(REC, $tok) ?? []) . ')');
ok(mcp_tools(ACT, $tok) === ['shift_history'], 'activity: exactly shift_history — a grant on one endpoint is not a grant on the other');
$r = mcp_tool(REC, $tok, 'who_is_on', ['site_id' => 102]);
ok(!$r['error'] && ($r['data']['site'] ?? '') === 'SMOKE Airport', 'a granted tool answers (as the agent, at the restaurant it holds)');
$r = mcp_tool(REC, $tok, 'staffing_needs', ['site_id' => 102]);
ok($r['error'] && str_contains($r['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($r['text'], 0, 90));
$r = mcp_tool(REC, $tok, 'records_search', ['sql' => 'select 1']);
ok($r['error'], 'records_search is a tool like any other: refused unless granted');
$r = mcp_tool(REC, $tok, 'time_off_taken', ['from' => '2026-01-01', 'to' => '2026-01-31', 'scope_id' => 102], true);
ok($r['error'], 'time_off_taken: refused for an agent');
facts($r1 = $run(), 951, ['Records MCP' => ['time_off_taken' => [], 'who_is_on' => []]]);
ok(mcp_tools(REC, run_token(951, $r1)) === ['who_is_on'], 'even a grant NAMING time_off_taken does not offer it to an agent');

echo "4. Fail closed\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['who_is_on' => []]], ['valid' => false]);
$t = run_token(951, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
$c = mcp_tool(REC, $t, 'who_is_on', ['site_id' => 102]);
ok($c['error'], 'and cannot call anything');
$r = $run(); facts($r, 951, []);                                   // another application's agent: the kernel answers only this application's endpoints, and there are none
ok(mcp_tools(REC, run_token(951, $r)) === [], 'facts with no endpoint for this application: nothing offered');
$r = $run();                                                        // the kernel has never heard of this run at all
ok(mcp_tools(REC, run_token(951, $r)) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = $run(); facts($r, 951, ['Records MCP' => ['who_is_on' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, run_token(951, $r)) ?? []) === 31, 'facts that say is_agent false are a person: never filtered (a person is never filtered)');
$r = $run(); facts($r, 951, ['Records MCP' => ['who_is_on' => []]]);
$t = run_token(951, $r);
ok(mcp_tools(REC_DEAD, $t) === [], 'the kernel cannot be reached (:8196, dead URL): an admitted agent is offered nothing');
$c = mcp_tool(REC_DEAD, $t, 'who_is_on', ['site_id' => 102]);
ok($c['error'], 'and cannot call');
ok(count(mcp_tools(REC_DEAD, person_token(33)) ?? []) === 31, 'while a person\'s own token still works with no kernel');
ok(count(mcp_tools(REC_DEAD, action_token(30)) ?? []) === 31, 'and so does the command bar\'s action token');

echo "5. An agent the directory has not admitted yet\n";
make_agent(953, 'SMOKE Unadmitted P4', [102 => 'manager'], null);
ok(one('SELECT capability FROM members WHERE id = 953') === null, 'agent 953 is in the mirror with no capability');
$r = $run(); $t = run_token(953, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 953') === null, '...capability still null');
facts($r, 953, ['Records MCP' => ['who_is_on' => [], 'find_sites' => []]]);
ok(mcp_tools(REC, $t) === ['find_sites', 'who_is_on'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 953') === 'write', '...and its capability is now write');
$r = $run(); make_agent(956, 'SMOKE Unadmitted P4 B', [102 => 'manager'], null); facts($r, 956, []);
ok(mcp_tools(REC, run_token(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts with NO endpoint here do not admit: 401, capability still null');
$r = $run(); facts($r, 956, ['Records MCP' => ['who_is_on' => []]], ['valid' => false]);
ok(mcp_tools(REC, run_token(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts valid:false do not admit');
$r = $run(); $t = run_token(956, $r); facts($r, 956, ['Records MCP' => ['who_is_on' => []]]);
$r2 = mcp_tools(REC_DEAD, $t);
ok($r2 === null && one('SELECT capability FROM members WHERE id = 956') === null, 'a dead kernel does not admit either (401)');
$r = $run(); facts($r, 957, ['Records MCP' => ['who_is_on' => []]]);
ok(mcp_tools(REC, run_token(957, $r)) === null, 'a run token for a member the mirror does not know -> 401 even when the kernel vouches: never created');
ok(one('SELECT count(*) FROM members WHERE id = 957') == 0, '...and 957 does not exist');
$r = $run(); make_agent(958, 'SMOKE Human-like', [102 => 'staff'], null); admin_sql("UPDATE members SET member_kind = 'human' WHERE id = 958");
facts($r, 958, ['Records MCP' => ['who_is_on' => []]]);
ok(mcp_tools(REC, run_token(958, $r)) === null && one('SELECT capability FROM members WHERE id = 958') === null, 'a HUMAN is never admitted this way (their capability comes with the hand-off claims)');

echo "6. An evaluation may read\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['find_sites' => [], 'who_is_on' => []]], ['trigger' => 'eval']);
$t = run_token(951, $r);
$c = mcp_tool(REC, $t, 'who_is_on', ['site_id' => 102]);
ok(mcp_tools(REC, $t) === ['find_sites', 'who_is_on'] && !$c['error'], 'an eval run reads (the tools never write: nothing an eval could change)');

echo "7. The kernel's own token\n";
$k = kernel_token();
ok(mcp_tools(REC, $k) === ['app_roles', 'time_off_taken'], 'records: app_roles and the share, nothing else');
foreach (['who_is_on' => ['site_id' => 102], 'records_search' => ['sql' => 'select 1'], 'find_staff' => [], 'staff_profile' => [], 'labor_vs_budget' => ['site_id' => 102]] as $tn => $args) {
    $c = mcp_tool(REC, $k, $tn, $args);
    ok($c['error'] && str_contains($c['text'], 'reaches'), "the kernel's token calling $tn: refused");
}
ok(!mcp_tool(REC, $k, 'app_roles', [])['error'], 'the kernel\'s token calling app_roles: answers');
ok(mcp_tools(REC, kernel_token('hr')) === null, 'a kernel token for another application (hr) -> 401');
ok(mcp_tools(REC, kernel_token('txtschedules', -5)) === null, 'an expired kernel token -> 401');
$bad = kernel_token(); ok(mcp_tools(REC, substr($bad, 0, -1) . (substr($bad, -1) === '0' ? '1' : '0')) === null, 'a kernel token with a bad signature -> 401');
ok(mcp_tools(REC_DEAD, kernel_token()) === ['app_roles', 'time_off_taken'], 'the kernel\'s token needs no kernel round-trip (:8196)');
$c = mcp_tool(REC, person_token(33), 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'any person may call app_roles (the catalogue is about no one)');
$r = $run(); facts($r, 951, ['Records MCP' => ['app_roles' => []]]);
ok(!mcp_tool(REC, run_token(951, $r), 'app_roles', [])['error'], 'and an agent granted it');

echo "8. The proxy name (only when the proofs run behind a real Apache: TS_APP=apache)\n";
if (getenv('TS_APP') === 'apache') {
    $names = mcp_tools(8191, person_token(33), '/mcp/records');
    ok(is_array($names) && count($names) === 31, 'http://…:8191/mcp/records reaches the records server through the vhost\'s proxy: ' . count($names ?? []) . ' tools');
    $names = mcp_tools(8191, person_token(33), '/mcp/activity');
    ok(is_array($names) && count($names) === 6, '/mcp/activity reaches the activity server: ' . count($names ?? []) . ' tools');
    ok(mcp_tools(8191, '', '/mcp/records') === null, 'and with no token the proxy answers the servers\' 401');
} else {
    echo "  --   skipped (php -S front end; run with TS_APP=apache to prove the vhost's ProxyPass lines)\n";
}
finish();
