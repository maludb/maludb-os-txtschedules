<?php
/**
 * Proof — who sees what (spec "Proof": Pay is invisible to everyone else; Rights): a crawl of every page of the slice — and its JSON — as each kind of person, grepped for the fixture wages; the records
 * role's views say the same as the pages; a manager of one restaurant sees nothing of another's people, cards or pay.
 */
require __DIR__ . '/lib.php';
$W = reset4();
$owner = as_member(1, 102); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32); $mara = as_member(33); $pat = as_planner(); $dee = as_dee(); $joe = as_member(34); $sam = as_member(28, 101); $marco = as_member(27, 101);
act($owner, '/staff/certifications/add.php', ['member' => 26, 'kind' => 'Food handler', 'expires_on' => today_plus(20), 'reference' => 'SMOKE-P']);
act($owner, '/staff/wage.php', ['member' => 30, 'position' => $W['aBar'], 'rate' => '12.55']);         // Ana's own rate on Bar
$ws = wk(94);
pub_shift(102, $W['aSrv'], 26, $ws, 1); pub_shift(101, $W['dSrv'], 34, $ws, 1);
$members = [1, 26, 27, 28, 30, 31, 32, 33, 34, 35, 36];
$pages = ['/', '/activity', '/team-schedule', '/my-schedule', '/marketplace', '/requests', '/approvals', '/coverage', '/certifications/mine', '/staff/?site=102', '/staff/?site=101', '/positions/?site=102', '/positions/?site=101',
          '/certifications/?site=102', '/certifications/?site=101', "/builder?site=102&week=$ws", "/builder?site=101&week=$ws", '/positions/new?site=102', '/certifications/kinds/new?site=102', '/time-off/balances', '/settings/tokens/'];
foreach ($members as $m) { $pages[] = "/staff/$m"; $pages[] = "/staff/$m/edit"; }
$marks = array_merge(wage_marks(), ['12.55']);
$own = ['Priya' => [26 => ['24.61']], 'Ana' => [30 => ['21.37', '23.19', '12.55']], 'Lee' => [31 => ['21.37']], 'Dana' => [32 => ['21.37']], 'Pat' => [], 'Joe' => [34 => ['22.83']], 'Sam (shift lead, Downtown)' => [28 => ['22.83']], ];
$jars = ['Priya' => $priya, 'Ana' => $ana, 'Lee' => $lee, 'Dana' => $dana, 'Pat' => $pat, 'Joe' => $joe, 'Sam (shift lead, Downtown)' => $sam];
echo "1. A crawl: " . count($pages) . " pages, HTML and JSON, as each person without labor.view somewhere\n";
foreach ($jars as $who => $jar) {
    $leaks = []; $n = 0;
    foreach ($pages as $p) {
        foreach ([[], JSONH] as $h) {
            $r = req('GET', $p, ['jar' => $jar, 'headers' => $h]); $n++;
            foreach ($marks as $w) {
                if (!str_contains(strip_ts($r['body']), $w)) { continue; }
                $allowed = false;
                foreach ($own[$who] as $mid => $ok) { if (in_array($w, $ok, true) && $p === "/staff/$mid") { $allowed = true; } }
                if (!$allowed) { $leaks[] = "$w @ $p"; }
            }
        }
    }
    ok($leaks === [], "$who: $n responses, no wage outside their own page" . ($leaks ? ' — LEAK: ' . implode('; ', array_slice($leaks, 0, 5)) : ''));
}
foreach (['Dee (manager of Downtown, labor.view there)' => [$dee, null], 'Marco (manager at Downtown, staff at Airport)' => [$marco, 27]] as $who => [$jar, $selfId]) {
    $leaks = [];
    foreach ($pages as $p) { foreach ([[], JSONH] as $h) { $r = req('GET', $p, ['jar' => $jar, 'headers' => $h]); foreach (['21.37', '24.61', '23.19', '12.55'] as $w) { if (str_contains(strip_ts($r['body']), $w) && !($selfId !== null && $w === '21.37' && $p === "/staff/$selfId")) { $leaks[] = "$w @ $p"; } } } }
    ok($leaks === [], "$who: may see Downtown's 22.83 but not one Airport rate" . ($leaks ? ' — LEAK: ' . implode('; ', array_slice($leaks, 0, 5)) : ''));
}
ok(str_contains(page($dee, '/positions/?site=101')['body'], '$22.83 an hour'), 'and does see Downtown\'s default');
$pg = page($mara, '/staff/30')['body'];
ok(str_contains($pg, '$12.55 an hour') && str_contains($pg, '$21.37 an hour'), 'Mara (labor.view at Airport) sees Ana\'s two rates: 12.55 own on Bar, 21.37 default on Server');

echo "2. The records role's views say the same\n";
$records = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$as = function (int $m, string $sql) use ($records): array { $records->exec("SELECT set_config('app.member_id', '$m', false)"); return $records->query($sql)->fetchAll(); };
$srv = $W['aSrv'];
$rate = fn (int $m, string $sql) => ($r = $as($m, $sql)[0] ?? null) === null ? 'nothing' : json_encode($r);
$q1 = "SELECT wage_rate, wage_override, wage_source FROM mcp_staff_positions WHERE member_id = 26 AND position_id = $srv";
ok(str_contains($rate(26, $q1), '24.61') && str_contains($rate(33, $q1), '24.61') && str_contains($rate(1, $q1), '24.61'), 'mcp_staff_positions: Priya, Mara and the owner read Priya\'s rate');
$blank = fn (string $j) => $j === 'nothing' || (json_decode($j, true)['wage_rate'] ?? null) === null && (json_decode($j, true)['wage_override'] ?? null) === null;
ok($blank($rate(31, $q1)) && $blank($rate(35, $q1)) && $blank($rate(36, $q1)) && $blank($rate(30, $q1)), 'Lee, Pat, Dee and Ana get no rate of Priya\'s (NULL or no row): ' . $rate(31, $q1) . ' / ' . $rate(35, $q1) . ' / ' . $rate(36, $q1));
$q2 = "SELECT default_wage_rate FROM mcp_positions WHERE position_id = $srv";
ok(str_contains($rate(33, $q2), '21.37') && json_decode($rate(35, $q2), true)['default_wage_rate'] === null && json_decode($rate(26, $q2), true)['default_wage_rate'] === null, 'mcp_positions: the default is Mara\'s and the owner\'s; NULL to Pat and to Priya');
$q3 = "SELECT count(*) AS n FROM mcp_certifications WHERE member_id = 26";
$n = fn (int $m, string $sql) => (int) $as($m, $sql)[0]['n'];
ok($n(26, $q3) === 1 && $n(33, $q3) === 1 && $n(1, $q3) === 1 && $n(31, $q3) === 0 && $n(36, $q3) === 0 && $n(35, $q3) === 1, 'mcp_certifications: Priya\'s card is seen by Priya, Mara, the owner and Pat (who builds); not Lee, not Dee');
$q4 = "SELECT count(*) AS n FROM mcp_certifications_due WHERE member_id = 26";
ok($n(33, $q4) >= 1 && $n(31, $q4) === 0 && $n(36, $q4) === 0, 'mcp_certifications_due: Mara sees Priya\'s rows; Lee and Dee do not');
$q5 = "SELECT count(*) AS n FROM mcp_staff WHERE member_id = 26";
ok($n(26, $q5) === 1 && $n(33, $q5) === 1 && $n(31, $q5) === 0 && $n(36, $q5) === 0 && $n(28, $q5) === 0, 'mcp_staff: a profile is its owner\'s and their managers\' — not a colleague\'s, not another restaurant\'s');
admin_sql("UPDATE staff_profiles SET notes = 'SMOKE note' WHERE member_id = 26");
$q6 = "SELECT notes FROM mcp_staff WHERE member_id = 26";
ok(($as(33, $q6)[0]['notes'] ?? null) === 'SMOKE note' && array_key_exists('notes', $as(26, $q6)[0]) && $as(26, $q6)[0]['notes'] === null, 'the manager\'s notes: Mara reads them; Priya\'s own row shows none');
$q7 = "SELECT email FROM mcp_members WHERE member_id = 26";
ok(array_key_exists('email', $as(33, $q7)[0]) && $as(33, $q7)[0]['email'] === null && ($as(26, $q7)[0]['email'] ?? null) !== null, 'mcp_members: nobody\'s email but one\'s own');
$write = function (string $sql) use ($records): string { try { $records->exec($sql); return 'allowed'; } catch (PDOException $e) { return 'refused'; } };
ok($write('UPDATE staff_positions SET wage_override = 1') === 'refused' && $write('UPDATE positions SET default_wage_rate = 1') === 'refused' && $write("UPDATE certifications SET verified_at = now()") === 'refused', 'the records role cannot write a rate, a default or a card');
ok(count($records->query('SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = \'public\' AND c.relname IN (\'staff_positions\', \'positions\')')->fetchAll()) === 2 && $write('SELECT wage_override FROM staff_positions LIMIT 1') === 'refused', 'and cannot read staff_positions itself — only the views');

echo "3. Another restaurant's people, cards and pay\n";
$codes = [];
foreach (["/staff/26", "/staff/26/edit"] as $p) { $codes[] = req('GET', $p, ['jar' => $dee])['code']; }
foreach (['/staff/save.php' => ['member' => 26, 'max_hours_week' => 9], '/staff/wage.php' => ['member' => 26, 'position' => $srv, 'rate' => '31.07'], '/staff/certifications/add.php' => ['member' => 26, 'kind' => 'Food handler', 'expires_on' => today_plus(50)]] as $p => $f) { $codes[] = act($dee, $p, $f)[0]; }
ok($codes === [404, 404, 404, 404, 404], 'Dee on an Airport person: the pages and all three writes are 404 (' . implode(',', $codes) . ')');
$codes = [];
foreach (["/staff/26", "/staff/26/edit", '/positions/?site=102'] as $p) { $codes[] = req('GET', $p, ['jar' => $joe])['code']; }
ok($codes === [404, 404, 403] || $codes === [404, 404, 404], 'Joe (staff at Downtown): Airport\'s people 404; a restaurant he does not hold 404 (' . implode(',', $codes) . ')');
ok(one('SELECT max_hours_week FROM staff_profiles WHERE member_id = 26') === null && (float) one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $srv]) === 24.61, 'and nothing was changed');
finish();
