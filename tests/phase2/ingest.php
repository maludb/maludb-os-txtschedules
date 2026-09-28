<?php
/**
 * Proof: the activity ingest bridge (mcp/activity_ingest.py) ships the log to the MaluDB API tagged "txtschedules", with the
 * site, advances the checkpoint only past rows the API accepted, and health reads ingest_lag (sso-shell.md, item 7).
 * The MaluDB API is a fake (tests/fake_maludb.php) — a scratch row must never reach the tenant's real memory. Needs the asyncpg/
 * httpx venv of /srv/apps/projects (read only). Run last-but-one through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$py = getenv('TS_PYTHON') ?: '/srv/apps/projects/mcp/venv/bin/python';
$log = need('FAKE_MALUDB_LOG');
$run = fn (string $env = '') => trim((string) shell_exec($env . ' ' . escapeshellarg($py) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/mcp/activity_ingest.py') . ' 2>&1; echo "exit=$?"'));
$state = fn () => (int) one('SELECT last_id FROM activity_ingest_state WHERE id = 1');
$max = fn () => (int) one('SELECT max(id) FROM activity_log');
$lines = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));

file_put_contents($log, '');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($state() === 0 && $h['ingest_lag'] === $max() && $max() > 50, "before: checkpoint 0, health ingest_lag {$h['ingest_lag']} = {$max()} rows waiting");
$out = $run('MALUDB_API_TOKEN=wrong-token');
ok(str_contains($out, 'episode POST failed') && str_contains($out, 'exit=1') && $state() === 0 && $lines() === [], 'the API refuses (401): the run reports it, exits 1 and the checkpoint does NOT move');
$out = $run();
$total = $max();
ok(preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[1] === (int) $m[2] && (int) $m[3] === $total && str_contains($out, 'exit=0'), "a good run ships every row: $out");
ok($state() === $total, "the checkpoint is at the last row ($total)");
$eps = $lines();
ok(count($eps) === $total, count($eps) . ' episodes reached the API, one per activity row');
ok(count(array_filter($eps, fn ($e) => ($e['kind'] ?? '') === 'activity' && ($e['payload']['application'] ?? '') === 'txtschedules')) === $total && array_keys($eps[0]['payload'])[0] === 'application', 'every one is an activity episode whose payload STARTS with "application": "txtschedules"');
$signOns = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'member.sign_on'));
ok($signOns !== [] && isset($signOns[0]['payload']['scope_id']) && in_array($signOns[0]['payload']['scope_id'], [101, 102, 103], true) && str_starts_with($signOns[0]['title'], 'member.sign_on by member #'), 'a sign-on episode carries the site (scope_id) so memory can answer "what happened at Airport"');
$wages = array_filter($eps, fn ($e) => preg_match('/wage|rate|salary/i', json_encode($e['payload']['after'] ?? []) . json_encode($e['payload']['before'] ?? [])));
ok($wages === [], 'no episode carries a wage, a rate or pay');
ok(!str_contains(file_get_contents($log), 'mcp_') || preg_match('/mcp_[0-9a-f]{48}/', file_get_contents($log)) === 0, 'and none carries a token value');
$out = $run();
ok($out === 'exit=0' && count($lines()) === $total, 'a second run has nothing to do (no duplicates)');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($h['ingest_lag'] <= 1 && $h['maludb'] === 'ok', "health: ingest_lag {$h['ingest_lag']} (the health request's own row at most), maludb ok");
[$j, ] = sign_on(27, 101);
$out = $run();
ok(preg_match('/shipped (\d+)\/(\d+)/', $out, $m) && (int) $m[1] >= 1 && $state() === $max(), 'new activity ships on the next run and the checkpoint follows: ' . $out);
finish();
