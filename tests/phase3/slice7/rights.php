<?php
/**
 * Proof — rights (spec "Proof: Rights"): who opens the settings, day-parts, rules and reports of each restaurant; who may change what (every write needs settings.manage AT the record's restaurant); a restaurant not held does
 * not exist; the handlers refuse a GET, a missing token and a missing CSRF; the records role says what the pages say and cannot write; the menu; and a crawl of every page of the slice as each kind of person.
 */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = admin_jar(102); $mara = as_member(33); $pat = as_planner(); $sol = as_setter();
$dana = as_member(32); $priya = as_member(26); $ana = as_member(30); $dee = as_dee(); $joe = as_member(34); $dex = as_dex();
$srv = $W['aSrv']; $lunch = dpid(102, 'lunch');

echo "1. Who opens the screens\n";
$people = ['Owner' => $owner, 'Mara (manager, Airport)' => $mara, 'Pat (builds, no settings)' => $pat, 'Sol (settings, builds, no pay)' => $sol, 'Dana (shift lead)' => $dana, 'Priya (staff)' => $priya, 'Ana (staff)' => $ana,
           'Dee (manager, Downtown)' => $dee, 'Joe (staff, Downtown)' => $joe, 'Dex (admin, Downtown)' => $dex];
$expect = [ // [settings 102, day-parts 102, rules 102, reports 102, settings 101, day-parts 101, rules 101, reports 101]
    'Owner' => [200, 200, 200, 200, 200, 200, 200, 200], 'Mara (manager, Airport)' => [403, 403, 200, 200, 404, 404, 404, 404], 'Pat (builds, no settings)' => [403, 403, 200, 200, 404, 404, 404, 404],
    'Sol (settings, builds, no pay)' => [200, 200, 200, 200, 404, 404, 404, 404], 'Dana (shift lead)' => [403, 403, 403, 403, 404, 404, 404, 404], 'Priya (staff)' => [403, 403, 403, 403, 404, 404, 404, 404],
    'Ana (staff)' => [403, 403, 403, 403, 404, 404, 404, 404], 'Dee (manager, Downtown)' => [404, 404, 404, 404, 403, 403, 200, 200], 'Joe (staff, Downtown)' => [404, 404, 404, 404, 403, 403, 403, 403],
    'Dex (admin, Downtown)' => [404, 404, 404, 404, 200, 200, 200, 200]];
$paths = ['/site/?site=102', '/site/day-parts?site=102', '/rules/?site=102', '/reports/?site=102', '/site/?site=101', '/site/day-parts?site=101', '/rules/?site=101', '/reports/?site=101'];
foreach ($people as $who => $jar) {
    $got = []; $gotJ = [];
    foreach ($paths as $p) { $got[] = req('GET', $p, ['jar' => $jar])['code']; $gotJ[] = req('GET', $p, ['jar' => $jar, 'headers' => JSONH])['code']; }
    ok($got === $expect[$who] && $gotJ === $expect[$who], "$who: settings / day-parts / rules / reports at Airport then Downtown → " . implode('/', $got) . ' (page and JSON alike)');
}
foreach ($paths as $p) { $codes[] = req('GET', $p)['code'] . '/' . req('GET', $p, ['headers' => JSONH])['code']; }
ok(count(array_unique(array_map(fn ($c) => explode('/', $c)[1], $codes))) === 1 && array_unique(array_map(fn ($c) => explode('/', $c)[1], $codes))[0] === '401' && !array_filter($codes, fn ($c) => explode('/', $c)[0] === '200'), 'anonymous: never a 200 (JSON: 401)');
ok(req('GET', '/site/?site=999', ['jar' => $owner])['code'] === 404 && req('GET', '/rules/?site=999', ['jar' => $owner])['code'] === 404 && req('GET', '/reports/?site=999', ['jar' => $owner])['code'] === 404, 'a restaurant that does not exist: 404 on all');
foreach (['/site/', '/site/day-parts', '/rules/', '/reports/'] as $p) {
    $r = req('GET', "$p", ['jar' => $mara]);
    ok(in_array($r['code'], [200, 403], true), "$p with no ?site= uses the current restaurant (" . $r['code'] . ')');
}

echo "2. The menu\n";
$navs = ['Owner' => ['site-settings', 'day-parts', 'rules', 'reports'], 'Mara (manager, Airport)' => ['rules', 'reports'], 'Pat (builds, no settings)' => ['rules', 'reports'], 'Sol (settings, builds, no pay)' => ['site-settings', 'day-parts', 'rules', 'reports'], 'Dana (shift lead)' => [], 'Priya (staff)' => []];
foreach ($navs as $who => $want) {
    $h = page($people[$who], '/')['body']; $have = array_values(array_filter(['site-settings', 'day-parts', 'rules', 'reports'], fn ($id) => str_contains($h, 'id="nav-' . $id . '"')));
    ok($have === $want, "$who's menu offers " . ($want ? implode(', ', $want) : 'none of the four'));
}

echo "3. Who may change what (every handler, the same benign call each time)\n";
$h = [['/site/save.php', ['cutoff_minutes' => '120']], ['/site/day-part.php', ['day_part' => $lunch, 'name' => 'Lunch']], ['/rules/save.php', ['rule' => 'min_rest', 'severity' => 'soft']], ['/rules/preset.php', ['preset' => 'generic']]];
$snap = fn () => json_encode([q('SELECT * FROM site_settings ORDER BY scope_id'), q('SELECT * FROM site_rules ORDER BY scope_id, rule_key'), q('SELECT id, scope_id, name, starts_at, ends_at, service_name, sort_order, archived_at FROM day_parts ORDER BY id')]);
$hasSetting = ['Owner' => true, 'Sol (settings, builds, no pay)' => true];
foreach ($people as $who => $jar) {
    $codes = []; $bad = [];
    foreach ($h as [$path, $form]) {
        $before = $snap();
        [$c] = act($jar, $path, ['site' => 102] + $form);
        $codes[] = $c;
        if ($c !== 200 && $snap() !== $before) { $bad[] = $path; }
        $want = isset($hasSetting[$who]) ? 200 : (str_contains($who, 'Downtown') ? 404 : 403);
        if ($who === 'Dee (manager, Downtown)' || $who === 'Joe (staff, Downtown)' || $who === 'Dex (admin, Downtown)') { $want = 404; }
        if ($c !== $want) { $bad[] = "$path → $c wanted $want"; }
    }
    [$c1] = act($jar, '/site/day-part-archive.php', ['day_part' => 99999999]);
    ok($bad === [] && $c1 === 404, "$who: the four writes → " . implode('/', $codes) . '; an archive of a day-part that does not exist 404' . ($bad ? ' — ' . implode('; ', $bad) : ''));
}
[$c] = act($dex, '/site/save.php', ['site' => 101, 'cutoff_minutes' => '120']); [$c2] = act($dex, '/rules/save.php', ['site' => 101, 'rule' => 'min_rest', 'severity' => 'soft']); [$c3] = act($dee, '/rules/save.php', ['site' => 101, 'rule' => 'min_rest', 'severity' => 'soft']);
ok($c === 200 && $c2 === 200 && $c3 === 403, 'at his own restaurant: Downtown\'s admin 200, Downtown\'s manager (no settings.manage) 403');
$mine = $snap();
foreach ($h as [$path, $form]) {
    $r = req('POST', $path, ['jar' => $owner, 'headers' => JSONH, 'form' => ['site' => 102] + $form]);
    $r2 = req('POST', $path, ['headers' => JSONH, 'form' => ['site' => 102] + $form]);
    $r3 = req('GET', $path . '?site=102', ['jar' => $owner, 'headers' => JSONH]);
    ok($r['code'] === 403 && $r2['code'] === 401 && $r3['code'] === 405, "$path without a CSRF token: 403; with no session: 401; by GET: 405");
}
ok($snap() === $mine, 'and nothing changed');

echo "4. The records role says what the pages say, and cannot write\n";
$n = fn (int $m, string $sql): int => (int) recq($m, $sql)[0]['n'];
ok($n(26, 'SELECT count(*) AS n FROM mcp_site_rules WHERE site_id = 102') === 12 && $n(33, 'SELECT count(*) AS n FROM mcp_site_rules WHERE site_id = 102') === 12 && $n(36, 'SELECT count(*) AS n FROM mcp_site_rules WHERE site_id = 102') === 0 && $n(36, 'SELECT count(*) AS n FROM mcp_site_rules WHERE site_id = 101') === 12, 'mcp_site_rules: every member of a restaurant reads its 12 rules (Priya, Mara); Dee reads Downtown\'s and none of Airport\'s');
ok($n(26, 'SELECT count(*) AS n FROM mcp_day_parts WHERE site_id = 102') === 2 && $n(36, 'SELECT count(*) AS n FROM mcp_day_parts WHERE site_id = 102') === 0, 'mcp_day_parts: Priya reads Airport\'s two; Dee none');
ok($n(26, 'SELECT count(*) AS n FROM mcp_sites WHERE site_id = 102') === 1 && $n(36, 'SELECT count(*) AS n FROM mcp_sites WHERE site_id = 102') === 0 && (int) recq(26, 'SELECT cutoff_minutes AS n FROM mcp_sites WHERE site_id = 102')[0]['n'] === 120, 'mcp_sites: the trade settings are for the restaurant\'s own people (Priya reads cutoff 120; Dee none at Airport)');
admin_sql("INSERT INTO rule_overrides (scope_id, shift_id, member_id, rule_key, message, reason, overridden_by, context) VALUES (102, NULL, 31, 'min_rest', 'm', 'SMOKE s7 rights', 33, 'build')");
ok($n(26, 'SELECT count(*) AS n FROM mcp_rule_overrides WHERE site_id = 102') === 0 && $n(35, 'SELECT count(*) AS n FROM mcp_rule_overrides WHERE site_id = 102') >= 1 && $n(33, 'SELECT count(*) AS n FROM mcp_rule_overrides WHERE site_id = 102') >= 1 && $n(36, 'SELECT count(*) AS n FROM mcp_rule_overrides WHERE site_id = 102') === 0, 'mcp_rule_overrides: those who build (Mara, Pat) — not Priya, not Dee');
$rw = fn (string $t, string $p): bool => trim(admin_sql("SELECT has_table_privilege('txtschedules_records_ro', 'public.$t', '$p')")) === 't';
ok(!$rw('site_settings', 'UPDATE') && !$rw('site_rules', 'UPDATE') && !$rw('day_parts', 'UPDATE') && !$rw('rule_overrides', 'INSERT') && !$rw('site_settings', 'SELECT') && !$rw('site_rules', 'SELECT') && !$rw('day_parts', 'SELECT'), 'the records role has no privilege on the base tables at all: it reads the views and cannot write');
ok(trim(admin_sql("SELECT has_table_privilege('txtschedules_rw', 'public.site_rules', 'DELETE')")) === 'f' && trim(admin_sql("SELECT has_table_privilege('txtschedules_rw', 'public.rule_overrides', 'UPDATE')")) === 'f' && trim(admin_sql("SELECT has_table_privilege('txtschedules_rw', 'public.day_parts', 'DELETE')")) === 'f', 'the application role cannot delete a rule or a day-part, nor change an override (day-parts are archived, overrides are history)');

echo "5. A crawl: every page of the slice, HTML and JSON, as each kind of person\n";
$pages = ['/site/?site=102', '/site/day-parts?site=102', '/site/day-parts?site=102&add=1', "/site/day-parts?site=102&edit=$lunch", '/rules/?site=102', '/reports/?site=102', "/reports/?site=102&report=hours&from=2026-01-05&to=2026-03-01", '/reports/?site=102&report=overrides', '/site/?site=101', '/rules/?site=101', '/reports/?site=101&report=labor'];
foreach ($people as $who => $jar) {
    $leaks = []; $n = 0;
    foreach ($pages as $p) {
        foreach ([[], JSONH] as $hd) {
            $r = req('GET', $p, ['jar' => $jar, 'headers' => $hd]); $n++;
            foreach (wage_marks() as $w) { if (str_contains(strip_ts($r['body']), $w)) { $leaks[] = "$w @ $p"; } }
            if (in_array($r['code'], [500, 502, 503], true)) { $leaks[] = "{$r['code']} @ $p"; }
        }
    }
    ok($leaks === [], "$who: $n responses — no wage and no server error" . ($leaks ? ' — ' . implode('; ', array_slice($leaks, 0, 4)) : ''));
}
$logs = json_encode(q("SELECT action, before, after, route FROM activity_log WHERE action IN ('settings.update', 'day_part.save', 'day_part.archive', 'rule.update', 'rule.preset', 'report.export')")) . json_encode(q('SELECT subject, body FROM notification_outbox'));
ok(wage_leaks($logs) === [], 'the trail and the outbox hold no wage');
reset7();
finish();
