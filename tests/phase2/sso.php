<?php
/**
 * Proof: the kernel's hand-off, the mirror, the site, the switcher, revocation and sign-out (sso-shell.md "Proof before Phase 3",
 * items 1–3 and 6). Run through tests/phase2/run.sh (needs the scratch database, the app, the fake kernel).
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=txtschedules';
$refusedWith = fn (int $since): ?string => (($r = activity('member.sign_on.refused', $since)) ? (json_decode((string) end($r)['after'], true)['reason'] ?? null) : null);

echo "1. A hand-off opens a session once\n";
$sinceLog = last_activity_id();
$before = (int) one('SELECT count(*) FROM members WHERE id = 27');
$url = handoff(27, 101);
$jm = jar();
$r = req('GET', $url, ['jar' => $jm]);
ok($before === 0, 'member 27 is unknown to the mirror before the first sign-on');
ok($r['code'] === 302 && $r['location'] === '/', "the hand-off answers 302 to / ({$r['code']} {$r['location']})");
$m = q('SELECT display_name, capability, status FROM members WHERE id = 27')[0] ?? null;
ok($m && $m['display_name'] === 'SMOKE Marco' && $m['capability'] === 'write' && $m['status'] === 'active', 'the first sign-on created the member from the claims (name, capability write, active)');
$roles = q('SELECT scope_id, role_key, roles, capability FROM member_site_roles WHERE member_id = 27 ORDER BY scope_id');
ok(count($roles) === 2 && $roles[0]['scope_id'] == 101 && $roles[0]['role_key'] === 'manager' && $roles[1]['scope_id'] == 102 && $roles[1]['role_key'] === 'staff', 'and his holding per site: manager at 101, staff at 102');
ok((int) one('SELECT main_scope_id FROM staff_profiles WHERE member_id = 27') === 101, 'and a staff profile with his first restaurant as MAIN restaurant (D4)');
$home = page($jm, '/');
ok($home['code'] === 200 && str_contains($home['body'], 'id="dashboard-header"') && site_of($jm) === 'SMOKE Downtown', 'the dashboard answers 200 at the chosen site (SMOKE Downtown)');
ok(count(activity('member.sign_on', $sinceLog)) === 1 && (int) activity('member.sign_on', $sinceLog)[0]['scope_id'] === 101, 'member.sign_on is logged with the site');
$r = req('GET', $url, ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'replay', "the same URL again: 403, reason logged as replay ({$r['code']})");
ok(!preg_match('/replay|nonce|signature|token|audience/i', preg_replace('/txtschedules|sign-on link|launcher/i', '', $r['body'])), 'and the page never says which check failed');
$r = req('GET', handoff(27, 101, ['app' => 'hr']), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'token', 'a token made for app_key hr: 403 (audience)');
$r = req('GET', handoff(27, 101, ['ttl' => -5]), ['jar' => jar()]);
ok($r['code'] === 403, 'an expired token: 403');
$r = req('GET', handoff(27, 101, ['key' => str_repeat('ab', 32)]), ['jar' => jar()]);
ok($r['code'] === 403, 'a token signed with another key: 403');
$u = handoff(27, 101);
$r = req('GET', preg_replace('/claims=([^&.]+)/', 'claims=x$1', $u), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'claims', 'tampered claims: 403 (claims)');
$r = req('GET', handoff(26, null, ['claims' => fixture()['claims']['27'] + ['member_id' => 27]]), ['jar' => jar()]);
ok($r['code'] === 403, 'claims of another member beside the token: 403');
$r = req('GET', handoff(27, 101, ['claims' => ['status' => 'suspended'] + fixture()['claims']['27']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'status', 'a suspended member: 403 (status)');
$r = req('GET', handoff(27, 101, ['claims' => ['capability' => null] + fixture()['claims']['27']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'capability', 'no capability on this application: 403 (capability)');
ok(req('GET', '/sso', ['jar' => jar()])['code'] === 403, '/sso with no token: the one refusal page');

echo "2. The site: chosen, the only one, none\n";
[$jp, $r] = sign_on(26, 101);           // Priya holds only Airport (102), the launcher named Downtown (101)
ok($r['code'] === 302 && site_of($jp) === 'SMOKE Airport', 'claims.scope names a restaurant she does not hold: the session opens at the one she holds (Airport)');
[$jm2, $r] = sign_on(27, 102);
ok(site_of($jm2) === 'SMOKE Airport', 'Marco asks for Airport, which he holds: the session opens there');
[$jm3, $r] = sign_on(27);
ok(site_of($jm3) === 'SMOKE Airport' || site_of($jm3) === 'SMOKE Downtown', 'no scope asked and two held: the first by name (' . site_of($jm3) . ')');
$sess = (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 29');
$r = req('GET', handoff(29), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'no_site', 'a person who holds no restaurant: 403 (no_site)');
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 29') === $sess, 'and no session was opened for them');

echo "3. The switcher\n";
$body = page($jm, '/')['body'];
preg_match_all('/id="site-switch-(\d+)"/', $body, $ms);
sort($ms[1]);
ok($ms[1] === ['101', '102'], 'Marco\'s switcher lists exactly the two sites he holds, by name (' . implode(', ', $ms[1]) . ')');
ok(!str_contains(page($jp, '/')['body'], 'id="site-switcher"'), 'Priya, with one site, has no switcher');
$t = csrf_of($body);
$r = req('POST', '/switch-site.php', ['jar' => $jm, 'form' => ['site' => 102, 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/' && site_of($jm) === 'SMOKE Airport', 'switching to a held site (102): 302, the header follows');
ok((int) one("SELECT scope_id FROM member_sessions WHERE member_id = 27 AND ended_at IS NULL ORDER BY last_seen_at DESC LIMIT 1") > 0 && count(activity('site.switch', $sinceLog)) >= 1, 'the session list and the log record the switch');
$tp = csrf_of(page($jp, '/')['body']);
$r = req('POST', '/switch-site.php', ['jar' => $jp, 'form' => ['site' => 101, 'csrf_token' => $tp]]);
ok($r['code'] === 403 && site_of($jp) === 'SMOKE Airport', 'Priya switching to Downtown, which she does not hold: 403, nothing changes');
$r = req('POST', '/switch-site.php', ['jar' => $jp, 'form' => ['site' => 999, 'csrf_token' => $tp]]);
ok($r['code'] === 403, 'a site that does not exist: the same 403');
$r = req('POST', '/switch-site.php', ['jar' => $jm, 'form' => ['site' => 101]]);
ok($r['code'] === 403 && site_of($jm) === 'SMOKE Airport', 'without the CSRF token: 403, nothing changes');
$r = req('GET', '/switch-site.php', ['jar' => $jm]);
ok($r['code'] === 405, 'a GET to the switch: 405');

echo "4. Revocation — the next request lands on the launcher\n";
[$jr, ] = sign_on(26, 102);
ok(page($jr, '/')['code'] === 200, 'Priya is signed in');
kernel_state(fn ($s) => $s + ['incremental' => ['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => '2026-01-01T00:01:00.000000Z', 'full' => false,
    'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [],
    'access' => [['member_id' => 26, 'role' => null, 'roles' => [], 'capability' => 'write', 'scopes' => []]]]]);
$out = sync();
ok(str_contains($out, '1 holdings'), "the sync applies Priya's access row with no site left: $out");
$r = page($jr, '/');
ok($r['code'] === 302 && $r['location'] === $L, 'her next request: 302 to the launcher with ?app=txtschedules');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 26 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "member_sessions shows ended_by = 'directory'");
$r = req('GET', handoff(26, null, ['claims' => ['scopes' => [], 'roles' => [], 'role' => null] + fixture()['claims']['26']]), ['jar' => jar()]);
ok($r['code'] === 403 && $refusedWith($sinceLog) === 'no_site', 'and a new hand-off for her, with the claims the kernel would now send (no site), is refused');
[$js, ] = sign_on(28, 101);
ok(page($js, '/')['code'] === 200, 'Sam (shift lead) is signed in');
kernel_state(function ($s) { $s['incremental']['access'] = [['member_id' => 28, 'role' => null, 'roles' => [], 'capability' => null, 'scopes' => []]]; return $s; });
sync();
ok(page($js, '/')['code'] === 302 && (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 28 AND ended_at IS NULL') === 0, 'access revoked (capability null): the session ends and the next request goes to the launcher');
ok(q('SELECT capability FROM members WHERE id = 28')[0]['capability'] === null, 'and the mirror holds no capability for him');
// restore for later scripts
kernel_state(function ($s) { $s['incremental']['access'] = [
    ['member_id' => 26, 'role' => 'staff', 'roles' => ['staff'], 'capability' => 'write', 'scopes' => [['scope_id' => 102, 'role' => 'staff', 'roles' => ['staff'], 'capability' => 'write']]],
    ['member_id' => 28, 'role' => 'shift_lead', 'roles' => ['shift_lead'], 'capability' => 'write', 'scopes' => [['scope_id' => 101, 'role' => 'shift_lead', 'roles' => ['shift_lead'], 'capability' => 'write']]]]; return $s; });
sync();

echo "5. Sign-out\n";
$notice = fn (int $m, string $app = 'txtschedules', ?int $issued = null): string => ($p = $m . '.' . ($issued ?? time()) . '.' . $app) . '.' . hash_hmac('sha256', 'sso-logout:' . $p, need('ACTION_TOKEN_KEY'));
[$jk, ] = sign_on(26, 102);
[$jk2, ] = sign_on(26, 102);
ok(page($jk, '/')['code'] === 200 && page($jk2, '/')['code'] === 200, 'Priya holds two sessions');
$since = last_activity_id();
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26)]]);
ok($r['code'] === 204 && $r['body'] === '', 'the kernel\'s sign-out notice: 204, no body');
ok(page($jk, '/')['code'] === 302 && page($jk2, '/')['code'] === 302, 'every session of hers ends: the next requests go to the launcher');
ok(q("SELECT DISTINCT ended_by FROM member_sessions WHERE member_id = 26 AND ended_at IS NOT NULL AND ended_by = 'kernel'") !== [] && count(activity('member.sign_out', $since)) === 1, "ended_by = 'kernel' and member.sign_out logged once");
[$jk3, ] = sign_on(26, 102);
$r = req('POST', '/sso/logout', ['form' => ['notice' => 'garbage']]);
ok($r['code'] === 204 && count(activity('member.sign_out.refused', $since)) === 1, 'an invalid notice: 204 and member.sign_out.refused logged');
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26, 'hr')]]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 200, 'a notice made for another application: 204 and changes nothing');
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice(26, 'txtschedules', time() - 600)]]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 200, 'a stale notice (10 minutes old): 204 and changes nothing');
$r = req('POST', '/sso/logout', ['raw' => json_encode(['notice' => $notice(26)]), 'headers' => ['Content-Type: application/json']]);
ok($r['code'] === 204 && page($jk3, '/')['code'] === 302, 'the notice as JSON works too');
ok(req('GET', '/sso/logout')['code'] === 405, 'GET /sso/logout: 405');
[$jo, ] = sign_on(27, 101);
$r = req('POST', '/logout.php', ['jar' => $jo, 'form' => []]);
ok($r['code'] === 403 && page($jo, '/')['code'] === 200, 'own sign-out without the CSRF token: 403, still signed in');
$r = req('POST', '/logout.php', ['jar' => $jo, 'form' => ['csrf_token' => csrf_of(page($jo, '/')['body'])]]);
ok($r['code'] === 302 && $r['location'] === 'https://app.example.invalid/launcher' && page($jo, '/')['code'] === 302, 'own sign-out (POST + CSRF): 302 to the launcher, this session ended');
ok(page($jo, '/')['location'] === $L, 'and a signed-out visitor is sent to the launcher with ?app=txtschedules');
ok(req('GET', '/login.php')['location'] === $L, '/login.php is only a redirect to the launcher (no form, no password)');
finish();
