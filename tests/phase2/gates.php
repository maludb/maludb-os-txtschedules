<?php
/**
 * Proof: rights per role AT A SITE on the shell's placeholder screens (403 vs 200), the menu, CSRF, the tokens screen, the
 * command bar through the kernel's chat endpoint, the activity trail's visibility, and the action-token / run-token gate
 * (sso-shell.md "Proof before Phase 3", items 3–4). Run after sso.php through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=txtschedules';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role at the site — the placeholder screens answer 200 or 403\n";
// route => [the roles that get 200]; the roles are: staff, lead, manager, admin.
$matrix = [
    '/my-schedule' => 'staff lead manager admin', '/team-schedule' => 'staff lead manager admin', '/marketplace' => 'staff lead manager admin',
    '/requests' => 'staff lead manager admin', '/availability' => 'staff lead manager admin', '/time-off' => 'staff lead manager admin',
    '/announcements/' => 'staff lead manager admin', '/certifications/mine' => 'staff lead manager admin', '/settings/' => 'staff lead manager admin',
    '/coverage' => 'lead manager admin',
    '/approvals' => 'manager admin', '/builder' => 'manager admin', '/templates/' => 'manager admin', '/staff/' => 'manager admin', '/positions/' => 'manager admin',
    '/certifications/' => 'manager admin', '/forecast' => 'manager admin', '/budget' => 'manager admin', '/reports/' => 'manager admin',
    '/site/' => 'admin', '/rules/' => 'admin',
];
[$jStaff, ] = sign_on(26, 102);
[$jLead, ] = sign_on(28, 101);
[$jMgr, ] = sign_on(27, 101);
[$jAdmin, ] = sign_on(1, 101);
$who = ['staff' => $jStaff, 'lead' => $jLead, 'manager' => $jMgr, 'admin' => $jAdmin];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 4 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 5)) : ''));
$r = page($jStaff, '/builder');
ok(str_contains($r['body'], 'You may not build the schedule here.'), 'a refusal says what the person may not do, in words ("You may not build the schedule here.")');
$r = page($jMgr, '/builder');
ok($r['code'] === 200 && str_contains($r['body'], 'id="builder-empty"') && str_contains($r['body'], 'The week builder (slice 2) fills this.'), 'an empty state names the slice that fills it');
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$sm = $menu($jStaff); $mm = $menu($jMgr); $am = $menu($jAdmin);
ok(!in_array('builder', $sm, true) && !in_array('site-settings', $sm, true) && in_array('my-schedule', $sm, true), 'the menu follows the rights: staff see no Builder or Settings');
ok(in_array('builder', $mm, true) && in_array('budget', $mm, true) && !in_array('site-settings', $mm, true) && !in_array('rules', $mm, true), 'a manager sees Builder and Budget but not the restaurant\'s Settings and Rules');
ok(in_array('site-settings', $am, true) && in_array('rules', $am, true), 'an admin sees Settings and Rules');
echo "   — and it is per site: Marco is manager at Downtown, staff at Airport\n";
ok(page($jMgr, '/builder')['code'] === 200 && in_array('builder', $menu($jMgr), true), 'at Downtown: /builder 200 and Builder in the menu');
$t = csrf_of(page($jMgr, '/')['body']);
req('POST', '/switch-site.php', ['jar' => $jMgr, 'form' => ['site' => 102, 'csrf_token' => $t]]);
ok(page($jMgr, '/builder')['code'] === 403 && !in_array('builder', $menu($jMgr), true) && page($jMgr, '/marketplace')['code'] === 200, 'switched to Airport: /builder 403, no Builder in the menu, /marketplace still 200');
req('POST', '/switch-site.php', ['jar' => $jMgr, 'form' => ['site' => 101, 'csrf_token' => $t]]);
ok(page($jMgr, '/builder')['code'] === 200, 'and back at Downtown: 200 again');
$r = page(jar(), '/builder');
ok($r['code'] === 302 && $r['location'] === $L, 'no session: a screen goes to the launcher with ?app=txtschedules');
$r = req('GET', '/builder', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/', ['jar' => $jStaff] + $json);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && ($d['site']['name'] ?? '') === 'SMOKE Airport' && ($d['site']['role'] ?? '') === 'staff' && array_key_exists('next_shift', $d), 'the dashboard answers JSON: the site, the role and the empty shape');

echo "2. CSRF and the tokens screen\n";
$tj = csrf_of(page($jStaff, '/')['body']);
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jStaff, 'form' => ['label' => 'proof']]);
ok($r['code'] === 403, 'minting a token without the CSRF token: 403');
$before = (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jStaff, 'form' => ['label' => 'proof', 'csrf_token' => $tj]]);
ok($r['code'] === 302 && $r['location'] === '/settings/tokens/', 'with it: 302 to the tokens screen');
$r = page($jStaff, '/settings/tokens/');
preg_match('/id="tokens-minted-value">(mcp_[0-9a-f]{48})</', $r['body'], $m);
$raw = $m[1] ?? '';
ok($raw !== '' && !str_contains(page($jStaff, '/settings/tokens/')['body'], $raw), 'the new token is shown once (mcp_ + 48 hex) and never again');
$row = q('SELECT token_hash, label, scope FROM mcp_access_tokens WHERE member_id = 26 ORDER BY id DESC LIMIT 1')[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['token_hash'] !== $raw && $row['scope'] === 'mcp' && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26') === $before + 1, 'only its SHA-256 is stored; scope mcp');
$logged = q("SELECT after::text AS a FROM activity_log WHERE action = 'token.mint' ORDER BY id DESC LIMIT 1")[0]['a'] ?? '';
ok($logged !== '' && !str_contains($logged, $raw) && str_contains($logged, 'proof'), 'token.mint logs the label, never the token');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jStaff, 'form' => ['label' => 'x', 'scope' => 'api', 'csrf_token' => $tj]]);
ok($r['code'] === 422, 'an api scope is not offered: 422');
$id = (int) one('SELECT id FROM mcp_access_tokens WHERE token_hash = :h', ['h' => $row['token_hash']]);
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jMgr, 'form' => ['token' => $id, 'csrf_token' => csrf_of(page($jMgr, '/')['body'])]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null, 'another person cannot revoke it: 404, still live');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jStaff, 'form' => ['token' => $id, 'csrf_token' => $tj]]);
ok($r['code'] === 302 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null, 'the owner revokes it: 302, revoked_at set');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'token.revoke' AND entity_id = :i", ['i' => $id])) === 1, 'token.revoke is logged');

echo "3. The activity trail — the caller's own rows, and where they build\n";
$rows = json_decode(page($jStaff, '/activity', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 26, 'staff see only their own rows (' . count($rows) . ', all by member 26)');
$rows = json_decode(page($jMgr, '/activity?action=member.', $json)['body'], true)['data']['rows'] ?? [];
$actors = array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows));
ok(count($actors) > 1 && in_array(28, $actors, true), 'a manager sees the rows at the restaurant they build for, other people\'s included (actors ' . implode(',', $actors) . ')');
ok(str_contains(json_encode($rows), 'signed on'), 'each row has its sentence ("… signed on")');
$r = page($jStaff, '/activity');
ok($r['code'] === 200 && str_contains($r['body'], 'id="activity-table"'), 'the screen renders (Pattern B table)');

echo "4. The command bar goes through the kernel's chat endpoint\n";
$ta = csrf_of(page($jStaff, '/')['body']);
$r = req('POST', '/assistant/message.php', ['jar' => $jStaff, 'headers' => ['HX-Request: true'], 'form' => ['message' => 'Who is on Friday night?', 'screen' => 'dashboard', 'csrf_token' => $ta]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), 'the utterance is answered with the kernel\'s reply');
ok(activity('assistant.message', 0) !== [] && activity('assistant.reply', 0) !== [], 'assistant.message and assistant.reply are logged');
$r = req('POST', '/assistant/message.php', ['jar' => $jStaff, 'form' => ['message' => 'hi']]);
ok($r['code'] === 403, 'without the CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/message.php', ['jar' => $jStaff, 'headers' => ['HX-Request: true'], 'form' => ['message' => 'hi', 'csrf_token' => $ta]]);
ok($r['code'] === 404 && str_contains($r['body'], 'txtSchedules has no expert yet'), 'the kernel answers 404 (no expert): the bar says so in words');
kernel_state(function ($s) { unset($s['chat_status']); return $s; });
ok(str_contains(json_encode(kernel_state()), 'chat_status') === false, 'fake kernel restored');
$r = req('POST', '/assistant/message.php', ['jar' => $jStaff, 'headers' => ['HX-Request: true'], 'form' => ['message' => str_repeat('x', 2100), 'csrf_token' => $ta]]);
ok($r['code'] === 422, 'an utterance over 2,000 characters: 422');

echo "5. Action tokens and run tokens — the gate for the actions server and the MCP servers\n";
$key = need('ACTION_TOKEN_KEY');
$relayKey = need('ACTIONS_RELAY_KEY');
$person = fn (int $m, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl)) . '.' . hash_hmac('sha256', $p, $key);
$run = fn (int $m, int $runId, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26)]]);
$rows = json_decode($r['body'], true)['data']['rows'] ?? [];
ok($r['code'] === 200 && $rows !== [] && $rows[0]['actor']['member_id'] === 26 && !str_contains($r['headers'], 'Set-Cookie'), 'a person\'s action token acts as that member for one request (200, her rows, no Set-Cookie)');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26, -5)]]);
ok($r['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . substr($person(26), 0, -1) . '0']]);
ok($r['code'] === 401, 'a tampered action token: 401');
$since = last_activity_id();
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(4242)]]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 4242') === 0, 'an action token for an id with no mirror row: 401 and NO row created');
ok(count(activity('member.refused', $since)) === 1, 'and member.refused is logged');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26)], 'form' => ['label' => 'by action token']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && str_starts_with($d['token'] ?? '', 'mcp_'), 'a write under the action token needs no CSRF token (the token stands in): JSON answer carries the token once');
// an agent in the mirror with no grant yet
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => '2026-01-01T00:02:00.000000Z', 'full' => false,
    'members' => [['id' => 900, 'member_kind' => 'agent', 'display_name' => 'SMOKE Scheduler agent', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]],
    'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [], 'access' => []]; return $s; });
sync();
ok(q('SELECT member_kind, capability FROM members WHERE id = 900')[0] === ['member_kind' => 'agent', 'capability' => null], 'the feed introduced an agent (member 900) with no grant yet');
$tok = $run(900, 77);
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok($r['code'] === 401, 'a run token WITHOUT the relay: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)]]);
ok($r['code'] === 401, 'a run token with a wrong relay: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 401 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === null, 'a run token with the relay but a run the kernel does not vouch for: 401, no admission recorded');
kernel_state(function ($s) { $s['facts']['77'] = ['valid' => true, 'is_agent' => true, 'member_id' => 900, 'run_id' => 77, 'request_id' => 'req-run-77', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Screen-View: 1', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 200 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === 'write', 'once the kernel vouches (valid, an agent, this application\'s endpoints): 200 and the admission is recorded');
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND id > :s ORDER BY id LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 77 && ($row['request_id'] ?? '') === 'req-run-77', 'its rows are source agent, run 77, with the run\'s own request id from the kernel');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)], 'form' => ['label' => 'agent']]);
ok($r['code'] === 403, 'an agent may not mint a person\'s token (people only): 403');
$r = req('GET', '/', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 403, 'nor open the person\'s dashboard: 403');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });

echo "6. Health\n";
$r = req('GET', '/api/v1/health');
$h = json_decode($r['body'], true);
ok($r['code'] === 200 && $h['ok'] === true && $h['application'] === 'txtschedules' && $h['database'] === 'ok', 'GET /api/v1/health: ok, application txtschedules, database ok');
ok(array_key_exists('ingest_lag', $h) && is_array($h['directory']) && $h['directory']['synced'] === true && $h['directory']['error'] === null, 'it carries ingest_lag and the directory sync state (synced, no error)');
ok(req('POST', '/api/v1/health')['code'] === 405, 'POST to health: 405');
finish();
