<?php
/**
 * Proof: the deploy vhost's URL rules (deploy/apache-txtschedules.conf). Meaningful under TS_APP=apache tests/phase2/run.sh,
 * where a real Apache serves the template rendered as the installer renders it; under php -S the same checks run against
 * tests/dev_router.php, which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for health.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27, 101);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/certifications/mine')['code'] === 200 && page($j, '/certifications/')['code'] === 200 && page($j, '/certifications')['code'] === 200, '/certifications/mine (a file), /certifications/ and /certifications (a directory) all resolve');
ok(page($j, '/builder')['code'] === 200 && page($j, '/builder/')['code'] === 200, '/builder and /builder/ both resolve to builder.php');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'txtschedules', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404 (the calendar feed comes with slice 6)');
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1, '/manifest.webmanifest is served as JSON (' . (preg_match('/^Content-Type: (.+)$/mi', $m['headers'], $t) ? trim($t[1]) : '?') . ')');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/config/.env.example', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
