<?php
/**
 * Proof: the directory sync (bin/directory_sync.php) against the fake kernel — the full pass creates a seeded restaurant per site,
 * the incremental pass applies nothing and moves the cursor, a new site becomes a restaurant, a removed one closes with its data
 * kept, a suspended member's sessions end in the same pass, --from-file, and a failing kernel is reported (sso-shell.md, item 5).
 * Run after sso.php and gates.php through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$seed = fn (int $s): array => [(int) one('SELECT count(*) FROM site_settings WHERE scope_id = :s', ['s' => $s]), (int) one('SELECT count(*) FROM day_parts WHERE scope_id = :s', ['s' => $s]),
    (int) one('SELECT count(*) FROM time_off_types WHERE scope_id = :s', ['s' => $s]), (int) one('SELECT count(*) FROM site_rules WHERE scope_id = :s', ['s' => $s])];
$incr = fn (array $lists, string $next): array => array_merge(['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => $next, 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [],
    'deleted_departments' => [], 'scopes' => [], 'access' => []], $lists);
$state = fn () => q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$scope = fn (int $id, int $loc, string $name, string $tz, ?string $removed = null): array => ['scope_id' => $id, 'kind' => 'location', 'location_id' => $loc, 'department_id' => null, 'name' => $name,
    'address' => '1 Test St', 'timezone' => $tz, 'removed_at' => $removed, 'updated_at' => '2026-02-01T00:00:00Z'];

echo "1. The full pass: a restaurant for each kernel site, seeded\n";
ok(q('SELECT scope_id, name FROM sites WHERE scope_id IN (101, 102) ORDER BY scope_id') === [['scope_id' => 101, 'name' => 'SMOKE Downtown'], ['scope_id' => 102, 'name' => 'SMOKE Airport']], 'the run.sh full pass made SMOKE Downtown (101) and SMOKE Airport (102) from scopes.php');
ok($seed(101) === [1, 2, 3, 12] && $seed(102) === [1, 2, 3, 12], 'each seeded: 1 settings row, 2 day-parts, 3 time-off types, 12 rules');
$st = $state();
ok($st['full_at'] !== null && $st['scopes_at'] !== null && $st['next_cursor'] !== null && $st['last_error'] === null, 'the sync state records the full pass (full_at, scopes_at, a cursor, no error)');
$out = sync('--full');
ok(str_contains($out, '(full)') && $seed(101) === [1, 2, 3, 12] && (int) one('SELECT count(*) FROM sites WHERE scope_id IN (101, 102)') === 2, 'running --full again changes nothing: still two sites, no duplicate seeds');
ok(count(activity('directory.sync', 0)) >= 1, 'directory.sync is logged (source cron)');

echo "2. The incremental pass applies nothing and advances the cursor\n";
kernel_state(function ($s) { $s['feed']['next'] = '2026-03-01T00:00:00.000000Z'; unset($s['incremental']); return $s; });
$before = $state()['next_cursor'];
$out = sync();
ok(str_contains($out, 'applied 0 members, 0 departments, 0 memberships, 0 sites, 0 holdings') && !str_contains($out, '(full)'), "no change: $out");
ok($state()['next_cursor'] !== $before && str_starts_with($state()['next_cursor'], '2026-03-01'), 'the cursor moved to the kernel\'s next (' . $state()['next_cursor'] . ')');

echo "3. A new site becomes a restaurant within a pass\n";
[$jOwner, ] = sign_on(1, 101);
kernel_state(function ($s) use ($incr, $scope) { $s['incremental'] = $incr(['scopes' => [$scope(103, 12, 'SMOKE Harbor', 'America/Los_Angeles')],
    'access' => [['member_id' => 1, 'role' => 'admin', 'roles' => ['admin'], 'capability' => 'admin', 'scopes' => array_map(fn ($i) => ['scope_id' => $i, 'role' => 'admin', 'roles' => ['admin'], 'capability' => 'admin'], [101, 102, 103])]]], '2026-03-02T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 sites, 1 holdings'), "one site and one holding applied: $out");
$site = q('SELECT name, timezone, address, removed_at FROM sites WHERE scope_id = 103')[0] ?? null;
ok($site && $site['name'] === 'SMOKE Harbor' && $site['timezone'] === 'America/Los_Angeles' && $site['address'] === '1 Test St' && $seed(103) === [1, 2, 3, 12], 'SMOKE Harbor exists with the kernel\'s name, time zone and address, seeded like the others');
ok(count(q('SELECT 1 FROM member_site_roles WHERE member_id = 1')) === 3 && str_contains(page($jOwner, '/')['body'], 'id="site-switch-103"'), 'the owner now holds three sites and the switcher lists Harbor');
$sync2 = sync();
ok($seed(103) === [1, 2, 3, 12] && (int) one('SELECT count(*) FROM sites WHERE scope_id = 103') === 1, 'the same feed again creates nothing twice');

echo "4. A removed site closes and keeps its data\n";
[$jPriya, ] = sign_on(26, 102);
[$jMarco, ] = sign_on(27, 102);
ok(site_of($jPriya) === 'SMOKE Airport' && site_of($jMarco) === 'SMOKE Airport', 'Priya (Airport only) and Marco (both) are signed in at Airport');
kernel_state(function ($s) use ($incr, $scope) { $s['incremental'] = $incr(['scopes' => [$scope(102, 11, 'SMOKE Airport', 'America/Chicago', '2026-03-03T00:00:00Z')]], '2026-03-03T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT removed_at FROM sites WHERE scope_id = 102')[0]['removed_at'] !== null, 'the site is closed (removed_at set)');
ok($seed(102) === [1, 2, 3, 12], 'and its data is kept: settings, day-parts, time-off types and rules all still there');
$r = page($jPriya, '/');
ok($r['code'] === 302 && $r['location'] === 'https://app.example.invalid/launcher?app=txtschedules', 'Priya, whose only site closed: her next request goes to the launcher');
$r = page($jMarco, '/');
ok($r['code'] === 200 && site_of($jMarco) === 'SMOKE Downtown', 'Marco, who holds another: moved to SMOKE Downtown, still signed in');
ok(!str_contains(page($jMarco, '/')['body'], 'id="site-switch-102"'), 'and the closed site is not in his switcher');
kernel_state(function ($s) use ($incr, $scope) { $s['incremental'] = $incr(['scopes' => [$scope(102, 11, 'SMOKE Airport', 'America/Chicago', null)]], '2026-03-04T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT removed_at FROM sites WHERE scope_id = 102')[0]['removed_at'] === null, 'and re-opening it (removed_at null) reopens the restaurant with its data');

echo "5. A suspended member's sessions end in the same pass\n";
[$jSam, ] = sign_on(28, 101);
ok(page($jSam, '/')['code'] === 200, 'Sam is signed in');
kernel_state(function ($s) use ($incr) { $s['incremental'] = $incr(['members' => [['id' => 28, 'member_kind' => 'human', 'display_name' => 'SMOKE Sam', 'email' => 'sam@example.invalid', 'business_role' => 'user',
    'is_external' => false, 'status' => 'suspended', 'updated_at' => '2026-03-05T00:00:00Z', 'departments' => []]]], '2026-03-05T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 members'), "the member row applied: $out");
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 28 AND ended_at IS NULL') === 0 && q("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', 'in that pass: his sessions ended (ended_by directory)');
ok(page($jSam, '/')['code'] === 302 && q('SELECT status FROM members WHERE id = 28')[0]['status'] === 'inactive', 'his next request goes to the launcher and the mirror says inactive');
$r = req('GET', handoff(28, 101, ['claims' => ['status' => 'suspended'] + fixture()['claims']['28']]), ['jar' => jar()]);
ok($r['code'] === 403, 'a hand-off for him is refused too');

echo "6. A fixture (--from-file) and a kernel that fails\n";
$out = sync('--from-file ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/dev_directory.json'));
ok(str_contains($out, '(full)') && str_contains($out, '2 sites') && str_contains($out, '4 holdings'), "--from-file applies the fixture's feed: $out");
$out = (string) shell_exec('OS_INTERNAL_URL=http://127.0.0.1:1 php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' 2>&1; echo "exit=$?"');
ok(str_contains($out, 'the kernel did not answer') && str_contains($out, 'exit=1'), 'an unreachable kernel: the run says so and exits 1');
ok(($state()['last_error'] ?? '') !== '' && $state()['last_error'] !== null, 'and directory_sync_state.last_error holds the reason (' . $state()['last_error'] . ')');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['directory']['error'] ?? '') !== '', 'health reports it: directory.error');
kernel_state(function ($s) use ($incr) { $s['incremental'] = $incr([], '2026-03-06T00:00:00.000000Z'); return $s; });
sync();
ok($state()['last_error'] === null && str_starts_with($state()['next_cursor'], '2026-03-06'), 'the next good pass clears the error and moves the cursor');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
