<?php
/**
 * Proof — the activity server's six tools, over rows the APPLICATION itself wrote (handlers, a person's session and an agent's run token): a shift's history
 * oldest first with who and what changed, a trade's story, what one person or agent did, a restaurant's week, what the scheduling assistant drafted and what
 * the manager changed after it, and the guarded long tail. The view decides who sees which rows (managers: the restaurant's; everyone: their own); nothing in
 * the trail is a wage — proved by grepping every row the owner can read for the rates that were set and used.
 */
require __DIR__ . '/lib.php';
$W = reset_p4();
$AIR = 102; $srv = $W['aSrv']; $bar = $W['aBar'];
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$since = last_activity_id();
$mara = as_member(33, $AIR); $owner = as_member(1, $AIR); $ana = as_member(30); $lee = as_member(31);
make_agent(951, 'SMOKE Scheduler P4', [$AIR => 'manager']);
$RUN = random_int(800000, 899999);
facts($RUN, 951, ['Records MCP' => ['week_schedule' => []], 'Activity MCP' => ['draft_history' => [], 'shift_history' => [], 'who_did' => []]]);
$tok = run_token(951, $RUN);
$agent = function (string $path, array $form) use ($tok, $relayKey): array {
    $h = array_merge(JSONH, ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . hash_hmac('sha256', $tok, $relayKey)]);
    $r = req('POST', $path, ['headers' => $h, 'form' => $form]);
    return [$r['code'], json_decode($r['body'], true) ?? []];
};
$ws = (new DateTimeImmutable('monday next week', new DateTimeZone('America/Chicago')))->modify('+84 days')->format('Y-m-d');
$day = fn (int $n) => (new DateTimeImmutable($ws))->modify("+$n days")->format('Y-m-d');
admin_sql("DELETE FROM schedule_weeks WHERE scope_id = $AIR AND week_start = '$ws' AND status = 'draft' AND NOT EXISTS (SELECT 1 FROM shifts WHERE week_id = schedule_weeks.id)");

echo "1. The rows the tools will read, made by the application\n";
[$c, $b] = $agent('/weeks/save.php', ['site' => $AIR, 'week_start' => $ws]);
$week = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $week > 0, "the scheduling agent (run $RUN) creates the draft week of $ws (week $week)");
[$c, $b] = $agent('/shifts/save.php', ['site' => $AIR, 'position' => $srv, 'date' => $day(4), 'starts' => '17:00', 'ends' => '22:00', 'assignee' => 30]);
$s1 = (int) ($b['record_id'] ?? 0);
[$c2, $b2] = $agent('/shifts/save.php', ['site' => $AIR, 'position' => $srv, 'date' => $day(4), 'starts' => '17:00', 'ends' => '22:00', 'assignee' => 32]);
$s2 = (int) ($b2['record_id'] ?? 0);
ok($c === 200 && $c2 === 200 && $s1 > 0 && $s2 > 0, "and drafts two Friday shifts ($s1 for Ana, $s2 for Dana)");
[$c, $b] = act($mara, '/shifts/save.php', ['shift' => $s1, 'note' => 'SMOKE p4 bring the new menu', 'starts' => '16:30']);
ok($c === 200, 'Mara changes Ana\'s draft shift (start 16:30 and a note)');
[$c, $b] = act($mara, '/weeks/publish.php', ['week' => $week]);
ok($c === 200 && one('SELECT status FROM schedule_weeks WHERE id = ' . $week) === 'published', 'Mara publishes the week');
[$c, $b] = act($ana, '/exchanges/offer.php', ['shift' => $s1, 'note' => 'SMOKE p4 family thing']); $x = (int) ($b['record_id'] ?? 0);
[$c2] = act($lee, '/exchanges/claim.php', ['exchange' => $x]);
$stat = one('SELECT status FROM exchanges WHERE id = ' . $x);
if ($stat === 'pending_approval') { [$c3, $b3] = act($mara, '/exchanges/approve.php', ['exchange' => $x, 'note' => 'SMOKE p4 fine']); }
ok($c === 200 && $c2 === 200 && one('SELECT status FROM exchanges WHERE id = ' . $x) === 'approved', "Ana offers, Lee claims, Mara approves if it waited (exchange $x approved)");
[$c, $b] = act($owner, '/staff/wage.php', ['member' => 30, 'position' => $srv, 'rate' => '25.55']);
ok($c === 200, 'the owner sets Ana\'s hourly rate to 25.55 (the trail must say THAT it changed, never to what)');

echo "2. shift_history\n";
$h = tdata(33, 'shift_history', ['shift_id' => $s1], ACT);
$acts = array_column($h['events'], 'action');
ok($acts[0] === 'shift.create' && $h['events'][0]['source'] === 'agent' && (int) $h['events'][0]['agent_run_id'] === $RUN, 'Mara: the first event is the agent\'s shift.create, source agent, run ' . $RUN);
ok(in_array('shift.update', $acts, true) || in_array('shift.change', $acts, true), 'then Mara\'s change — ' . implode(', ', $acts));
ok(in_array('exchange.offer', $acts, true) && in_array('exchange.claim', $acts, true), 'and the trade made on it (offer, claim) — the exchange rows for this shift are included');
$times = array_column($h['events'], 'occurred_at'); $sorted = $times; sort($sorted);
ok($times === $sorted, 'oldest first');
ok($h['events'][0]['actor_name'] === 'SMOKE Scheduler P4' && str_contains($h['events'][0]['sentence'], '(agent)'), 'who: the agent\'s name, marked (agent) in the sentence');
$ch = array_values(array_filter($h['events'], fn ($e) => in_array($e['action'], ['shift.update', 'shift.change'], true)))[0] ?? [];
ok(($ch['actor_name'] ?? '') === 'SMOKE Mara' && $ch['source'] === 'web' && ($ch['before'] ?? null) !== null && ($ch['after'] ?? null) !== null, 'the change carries before and after and says web');
ok(count(tdata(33, 'shift_history', ['shift_id' => $s1, 'action_prefix' => 'exchange.'], ACT)['events']) >= 2 && tdata(33, 'shift_history', ['shift_id' => $s1, 'action_prefix' => 'exchange.'], ACT)['events'][0]['action'] === 'exchange.offer', 'action_prefix filter');
$mine = tdata(30, 'shift_history', ['shift_id' => $s1], ACT);
ok($mine['events'] !== [] && array_unique(array_column($mine['events'], 'actor_member_id')) === [0 => 30], 'Ana (staff): only what she did to that shift');
ok(tdata(34, 'shift_history', ['shift_id' => $s1], ACT)['events'] === [] && tdata(29, 'shift_history', ['shift_id' => $s1], ACT)['events'] === [], 'Joe and Nobody: nothing');
ok(tdata(35, 'shift_history', ['shift_id' => $s1], ACT)['events'] !== [], 'Pat (builds) sees the restaurant\'s history');

echo "3. exchange_history, who_did, site_activity\n";
$e = tdata(33, 'exchange_history', ['exchange_id' => $x], ACT);
$ea = array_column($e['events'], 'action');
ok($ea[0] === 'exchange.offer' && in_array('exchange.claim', $ea, true) && count($ea) >= 2, 'the trade\'s story: ' . implode(' → ', $ea));
ok(($e['events'][0]['after']['kind'] ?? '') === 'offer' && ($e['events'][0]['after']['shift_id'] ?? 0) === $s1, 'the offer\'s payload: kind and shift');
ok(tdata(34, 'exchange_history', ['exchange_id' => $x], ACT)['events'] === [], 'Joe: nothing');
ok(array_unique(array_column(tdata(31, 'exchange_history', ['exchange_id' => $x], ACT)['events'], 'actor_member_id')) === [0 => 31], 'Lee (staff): only his own claim');
$w = tdata(33, 'who_did', ['member_id' => 951, 'period' => 'this_month'], ACT);
ok($w['count'] >= 3 && array_unique(array_column($w['events'], 'source')) === [0 => 'agent'] && array_unique(array_column($w['events'], 'actor_member_id')) === [0 => 951], 'who_did the scheduling agent (Mara): its rows, all source agent (' . $w['count'] . ')');
$w = tdata(30, 'who_did', ['period' => 'this_month'], ACT);
ok($w['member_id'] === 30 && $w['count'] >= 1 && array_unique(array_column($w['events'], 'actor_member_id')) === [0 => 30], 'Ana: herself by default');
$w = tdata(30, 'who_did', ['member_id' => 33, 'period' => 'this_month'], ACT);
ok($w['events'] === [], 'Ana asking what Mara did: nothing (not hers to see)');
$w = tdata(33, 'who_did', ['member_id' => 30, 'period' => 'this_month', 'action_prefix' => 'exchange'], ACT);
ok($w['count'] >= 1 && str_starts_with($w['events'][0]['action'], 'exchange'), 'action_prefix on who_did');
ok(tdata(33, 'who_did', ['member_id' => 30, 'period' => 'last_year'], ACT)['events'] === [], 'a period with nothing in it: nothing');
ok(!tool(33, 'who_did', ['period' => '2026-09'], ACT)['error'], 'a YYYY-MM period');
$sa = tdata(33, 'site_activity', ['site_id' => $AIR, 'period' => 'this_month'], ACT);
$kinds = array_column($sa['by_kind'], 'events', 'kind');
ok(($kinds['shift'] ?? 0) >= 3 && ($kinds['week'] ?? 0) >= 2 && ($kinds['exchange'] ?? 0) >= 2, 'site_activity (Mara): events by kind — ' . json_encode($kinds));
ok(count(tdata(33, 'site_activity', ['site_id' => $AIR, 'period' => 'this_month', 'action_prefix' => 'week.publish'], ACT)['events']) === 1, 'the week\'s publish, by prefix');
$sa = tdata(30, 'site_activity', ['site_id' => $AIR, 'period' => 'this_month'], ACT);
ok(array_unique(array_column($sa['events'], 'actor_member_id')) === [0 => 30] || $sa['events'] === [], 'Ana: only what she did');
ok(tdata(34, 'site_activity', ['site_id' => $AIR, 'period' => 'this_month'], ACT)['events'] === [], 'Joe: nothing of Airport');
ok(tdata(33, 'site_activity', ['site_id' => $AIR, 'period' => 'yesterday'], ACT)['events'] !== null, "'yesterday' is understood");

echo "4. draft_history — what the assistant drafted and what the manager changed\n";
$d = tdata(33, 'draft_history', ['site_id' => $AIR, 'week_start' => $ws], ACT);
ok($d['week_id'] === $week, 'found by site and week_start (through the week.create row): week ' . $week);
ok(count($d['drafted_by_agent']) >= 3 && $d['runs'] === [$RUN], 'the agent\'s rows (the week, two shifts) and the run: ' . json_encode($d['runs']));
ok(array_column($d['drafted_by_agent'], 'action') == ['week.create', 'shift.create', 'shift.create'] || in_array('shift.create', array_column($d['drafted_by_agent'], 'action'), true), 'created by the agent');
$after = array_column($d['changed_by_people_after'], 'action');
ok(in_array('week.publish', $after, true) && (in_array('shift.update', $after, true) || in_array('shift.change', $after, true)), 'and what the people did after: the change and the publish — ' . implode(', ', $after));
$d2 = tdata(33, 'draft_history', ['week_id' => $week], ACT);
ok($d2['week_id'] === $week && count($d2['all']) === count($d['all']), 'by week_id too');
ok(tool(30, 'draft_history', ['site_id' => $AIR, 'week_start' => $ws], ACT)['error'], 'Ana: told there is no such record among the restaurants she builds for');
ok(tool(33, 'draft_history', ['site_id' => $AIR], ACT)['error'], 'no week_start: told what to give');
ok(tdata(34, 'draft_history', ['week_id' => $week], ACT)['all'] === [], 'Joe by week_id: nothing');

echo "5. As an agent (its run token, the kernel's grants)\n";
$c = mcp_tool(ACT, $tok, 'draft_history', ['week_id' => $week]);
ok(!$c['error'] && $c['data']['week_id'] === $week && count($c['data']['drafted_by_agent']) >= 3, 'the scheduling agent may read its own drafting (it is a manager at Airport)');
$c = mcp_tool(ACT, $tok, 'who_did', ['period' => 'this_month']);
ok(!$c['error'] && $c['data']['member_id'] === 951 && $c['data']['count'] >= 3, 'who_did defaults to the agent itself');
$c = mcp_tool(ACT, $tok, 'site_activity', ['site_id' => $AIR]);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted: refused');
make_agent(952, 'SMOKE Helper P4', [$AIR => 'staff']);
$R2 = $RUN + 1; facts($R2, 952, ['Activity MCP' => ['shift_history' => [], 'site_activity' => []]]);
$c = mcp_tool(ACT, run_token(952, $R2), 'site_activity', ['site_id' => $AIR, 'period' => 'this_month']);
ok(!$c['error'] && $c['data']['events'] === [], 'a STAFF-level agent granted site_activity still sees only its own rows (none): the grant opens the tool, not the rows');
$c = mcp_tool(ACT, run_token(952, $R2), 'shift_history', ['shift_id' => $s1]);
ok(!$c['error'] && $c['data']['events'] === [], '...nor another person\'s shift history');

echo "6. The trail holds no wage\n";
$all = tool(1, 'activity_search', ['sql' => "select action, before, after from mcp_activity_log where occurred_at > now() - interval '1 day'"], ACT);
$wageRow = array_values(array_filter($all['data'] ?? [], fn ($r) => $r['action'] === 'wage.update'));
ok(count($wageRow) >= 1 && ($wageRow[0]['after']['scope'] ?? '') === 'employee' && !array_key_exists('rate', $wageRow[0]['after']), 'the owner reads the wage.update row: it says THAT the employee\'s rate changed (scope employee), with no rate');
$blob = json_encode($all['data']);
$leaks = array_filter(array_merge(wages(), ['25.55']), fn ($w) => str_contains($blob, $w));
ok($leaks === [], 'grep over every row the owner can read: none of 21.37 / 24.61 / 23.19 / 22.83 / 25.55 (' . count($all['data']) . ' rows)');
ok(!preg_match('/"(rate|wage|wage_rate|cost|amount)"\s*:\s*[0-9]/', $blob), 'and no key called rate, wage, cost or amount carries a number');
$r = tool(33, 'activity_search', ['sql' => 'select count(*) as n from mcp_activity_log'], ACT);
ok($r['data'][0]['n'] > 10, 'activity_search answers over the view');
foreach (['select * from activity_log', 'delete from activity_log', 'select 1; select 2', "select set_config('app.member_id','1',true)", 'select * from mcp_shifts'] as $sql) {
    $r = tool(33, 'activity_search', ['sql' => $sql], ACT);
    ok($r['error'] || isset($r['data']['error']), 'the activity search refuses: ' . substr($sql, 0, 50));
}
ok(tdata(30, 'activity_search', ['sql' => 'select count(*) as n from mcp_activity_log where actor_member_id <> 30'], ACT)[0]['n'] === 0, 'Ana\'s search sees no one else\'s rows');
ok(tdata(29, 'activity_search', ['sql' => 'select count(*) as n from mcp_activity_log'], ACT)[0]['n'] >= 0 && tdata(34, 'activity_search', ['sql' => "select count(*) as n from mcp_activity_log where site_id = $AIR"], ACT)[0]['n'] === 0, 'Joe\'s: nothing of Airport');
finish();
