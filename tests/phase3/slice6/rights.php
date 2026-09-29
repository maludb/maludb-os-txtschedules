<?php
/**
 * Proof — rights (spec "Proof"): who opens the announcements screens and who posts, that a restaurant not held does not exist, that every handler refuses a GET, a missing token and a missing CSRF,
 * that the records role's view (`mcp_announcements`) says what the page says, and a crawl of every page of the slice as each kind of person finds no wage.
 */
require __DIR__ . '/lib.php';
$W = reset6();
$owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $sol = as_setter(); $dana = as_member(32); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dee = as_dee(); $joe = as_member(34);
$people = ['Owner' => $owner, 'Mara (manager)' => $mara, 'Pat (planner)' => $pat, 'Sol (settings)' => $sol, 'Dana (shift lead)' => $dana, 'Priya' => $priya, 'Ana' => $ana, 'Dee (Downtown manager)' => $dee, 'Joe (Downtown staff)' => $joe];
[$c, $b] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE All', 'body' => 'Everyone.', 'audience' => 'site']);
$a1 = (int) $b['record_id'];
[$c, $b] = act($dee, '/announcements/save.php', ['site' => 101, 'title' => 'SMOKE Downtown', 'body' => 'Downtown only.', 'audience' => 'site']);
$a2 = (int) $b['record_id'];
echo "1. Who opens the screens\n";
$expect = [ // list at 102, list at 101, form at 102, form at 101
    'Owner' => [200, 200, 200, 200], 'Mara (manager)' => [200, 404, 200, 404], 'Pat (planner)' => [200, 404, 403, 404], 'Sol (settings)' => [200, 404, 403, 404], 'Dana (shift lead)' => [200, 404, 403, 404], 'Priya' => [200, 404, 403, 404], 'Ana' => [200, 404, 403, 404],
    'Dee (Downtown manager)' => [404, 200, 404, 200], 'Joe (Downtown staff)' => [404, 200, 404, 403]];
$paths = ['/announcements/?site=102', '/announcements/?site=101', '/announcements/new?site=102', '/announcements/new?site=101'];
foreach ($people as $who => $jar) {
    $got = []; $gotJ = [];
    foreach ($paths as $p) { $got[] = req('GET', $p, ['jar' => $jar])['code']; $gotJ[] = req('GET', $p, ['jar' => $jar, 'headers' => JSONH])['code']; }
    ok($got === $expect[$who] && $gotJ === $expect[$who], "$who: list and post form at Airport then Downtown → " . implode('/', $got) . ' (page and JSON alike)');
}
$r = req('GET', '/announcements/?site=102');
ok(in_array($r['code'], [302, 401], true) && in_array(req('GET', '/settings/')['code'], [302, 401], true), 'anonymous: neither the announcements nor the settings are shown');
ok(req('GET', '/announcements/?site=999', ['jar' => $mara])['code'] === 404, 'a restaurant that does not exist: 404');
foreach ($people as $who => $jar) { ok(req('GET', '/settings/', ['jar' => $jar])['code'] === 200 && req('GET', '/settings/?tab=calendar', ['jar' => $jar])['code'] === 200, "$who opens their own settings"); }
echo "2. Every handler refuses a GET, an anonymous POST and a missing CSRF token\n";
foreach (['/announcements/save.php' => ['site' => 102, 'title' => 't', 'body' => 'b'], '/announcements/remove.php' => ['announcement' => $a1], '/announcements/read.php' => ['announcement' => $a1],
          '/settings/prefs.php' => ['by_sms' => 'no'], '/settings/calendar-feed.php' => []] as $p => $f) {
    $g = req('GET', $p, ['jar' => $mara])['code'];
    $anon = req('POST', $p, ['headers' => JSONH, 'form' => $f])['code'];
    $nocsrf = req('POST', $p, ['jar' => $mara, 'headers' => JSONH, 'form' => $f])['code'];
    ok($g === 405 && $anon === 401 && $nocsrf === 403, "$p: GET $g, anonymous $anon, no CSRF $nocsrf");
}
ok(one('SELECT removed_at FROM announcements WHERE id = :a', ['a' => $a1]) === null && (int) one('SELECT count(*) FROM notification_prefs WHERE member_id = 33') === 0 && (int) one('SELECT count(*) FROM calendar_feeds') === 0, 'and none of them changed anything');
echo "3. Another restaurant's announcement does not exist\n";
foreach ([['Priya', $priya], ['Mara', $mara], ['Owner-at-Airport-scope', $ana]] as [$n, $jar]) {
    [$c1] = act($jar, '/announcements/read.php', ['announcement' => $a2]);
    [$c2] = act($jar, '/announcements/remove.php', ['announcement' => $a2]);
    ok($c1 === 404 && $c2 === 404, "$n: reading or removing Downtown's announcement: $c1 / $c2");
}
[$c] = act($joe, '/announcements/read.php', ['announcement' => $a1]);
ok($c === 404 && (int) one('SELECT count(*) FROM announcement_reads') === 0, 'Joe reading Airport\'s: 404, no receipt');
[$c, $b] = act($owner, '/announcements/read.php', ['announcement' => $a1]);
ok($c === 200 && (int) one('SELECT count(*) FROM announcement_reads') === 1, 'the owner (both restaurants) may read either');
echo "4. The records role sees what the page shows\n";
$rec = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$as = function (int $m) use ($rec): array { $rec->prepare("SELECT set_config('app.member_id', ?, false)")->execute([(string) $m]); return array_map('intval', array_column($rec->query('SELECT announcement_id FROM mcp_announcements ORDER BY 1')->fetchAll(), 'announcement_id')); };
$page = function (string $jar, int $site) { [$c, $d] = screen($jar, "/announcements/?site=$site"); return $c === 200 ? array_column($d['announcements'], 'announcement_id') : []; };
foreach ([[26, $priya, 102], [30, $ana, 102], [33, $mara, 102], [34, $joe, 101], [36, $dee, 101]] as [$m, $jar, $site]) {
    $v = $as($m); $pg = $page($jar, $site); sort($pg);
    ok($v === $pg, "member $m: the view lists " . json_encode($v) . ' and so does the page at ' . $site);
}
$rc = $rec->query("SELECT count(*) FROM mcp_announcements WHERE read_count IS NOT NULL")->fetchColumn();
$as(30);
ok((int) $rec->query("SELECT count(*) FROM mcp_announcements WHERE read_count IS NOT NULL")->fetchColumn() === 0, 'Ana, through the view, gets no read count for any announcement');
$rec->prepare("SELECT set_config('app.member_id', '', false)")->execute();
ok((int) $rec->query('SELECT count(*) FROM mcp_announcements')->fetchColumn() === 0, 'with no acting member the view is empty');
echo "5. A crawl: no wage on any page, for anyone\n";
$pages = ['/announcements/?site=102', '/announcements/?site=101', '/announcements/new?site=102', '/announcements/new?site=101', '/settings/', '/settings/?tab=calendar', '/settings/?tab=notify&notice=pf_saved'];
$n = 0; $leaks = [];
foreach ($people as $who => $jar) {
    foreach ($pages as $p) { foreach ([[], JSONH] as $h) { $body = req('GET', $p, ['jar' => $jar, 'headers' => $h])['body']; $n++; foreach (wage_leaks($body) as $l) { $leaks[] = "$who $p $l"; } } }
}
$tok = csrf_of(req('GET', '/', ['jar' => $mara])['body']);
$conf = req('POST', '/announcements/save.php', ['jar' => $mara, 'form' => ['site' => 102, 'title' => 'SMOKE c', 'body' => 'b', 'audience' => 'people', 'members' => [26, 30], 'csrf_token' => $tok]]);
$n++; foreach (wage_leaks($conf['body']) as $l) { $leaks[] = "confirm $l"; }
ok($n > 100 && $leaks === [], "$n page views (7 pages × 9 people × HTML and JSON, plus the confirm page): no wage" . ($leaks ? ' — ' . implode(', ', array_slice($leaks, 0, 5)) : ''));
ok(str_contains($conf['body'], 'This will be sent to 2 people') && str_contains($conf['body'], 'SMOKE Ana, SMOKE Priya'), 'the confirm page for two named people says 2 and names them');
admin_sql("DELETE FROM announcement_reads; DELETE FROM notification_outbox");
finish();
