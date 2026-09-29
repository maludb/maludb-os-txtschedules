<?php
/**
 * Proof — the wage model, through the screens (spec "Proof": The wage model; Pay is invisible to everyone else; The log never carries pay). Airport Server default $17.31, Priya's own $18.43, Lee has none;
 * the owner sees each with where it comes from; raising the default to $26.19 moves Lee and not Priya, the page says how many it reaches; clearing Priya's own moves her; shift cost follows the effective rate.
 */
require __DIR__ . '/lib.php';
$W = reset4();
$priya = as_member(26); $lee = as_member(31); $ana = as_member(30); $owner = as_member(1, 102); $mara = as_member(33); $pat = as_planner(); $dee = as_dee(); $marco = as_member(27, 101);
$srv = $W['aSrv'];
$since = last_activity_id();
$html = fn (string $jar, string $path): string => page($jar, $path)['body'];
$json = fn (string $jar, string $path): string => req('GET', $path, ['jar' => $jar, 'headers' => JSONH])['body'];
$everything = [];
$track = function (string $jar, string $path) use (&$everything, $html, $json): void { $everything[] = $html($jar, $path); $everything[] = $json($jar, $path); };

echo "1. The owner sets the default and Priya's own rate, and sees where each comes from\n";
[$c, $b] = act($owner, '/positions/rate.php', ['position' => $srv, 'rate' => '17.31']);
ok($c === 200 && ($b['ok'] ?? false) === true && !isset($b['rate']) && !str_contains(json_encode($b), '17.31'), 'position_rate_update: 200, and the reply does not echo the rate (' . json_encode($b) . ')');
[$c, $b] = act($owner, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '18.43']);
ok($c === 200 && !str_contains(json_encode($b), '18.43'), 'wage_update for Priya: 200, the rate not echoed');
ok(one('SELECT default_wage_rate FROM positions WHERE id = :p', ['p' => $srv]) == 17.31 && one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $srv]) == 18.43, 'the database holds 17.31 and 18.43');
$pg = $html($owner, '/staff/26');
ok(str_contains($pg, '$18.43 an hour') && str_contains($pg, 'Own rate'), 'the owner sees Priya\'s page: "$18.43 an hour — Own rate"');
$pg = $html($owner, '/staff/31');
ok(str_contains($pg, '$17.31 an hour') && str_contains($pg, 'Default for the position'), 'and Lee\'s: "$17.31 an hour — Default for the position"');
$pg = $html($owner, '/positions/?site=102');
ok(str_contains($pg, '$17.31 an hour') && str_contains($pg, 'Applies to') , 'the positions page shows the default');
preg_match('/id="position-' . $srv . '-reach">([^<]+)</', $pg, $m);
$reach = $m[1] ?? '';
$onDefault = (int) one("SELECT count(*) FROM staff_positions sp JOIN staff_profiles pr ON pr.member_id = sp.member_id WHERE sp.position_id = :p AND pr.active AND sp.wage_override IS NULL", ['p' => $srv]);
ok($reach === "Applies to $onDefault people; 1 has their own rate.", 'the page says how many it reaches: "' . $reach . '"');

echo "2. Raising the default moves Lee, not Priya\n";
[$c, $b] = act($owner, '/positions/rate.php', ['position' => $srv, 'rate' => '26.19']);
ok($c === 200, 'the default is now 26.19');
ok(str_contains($html($owner, '/staff/31'), '$26.19 an hour') && str_contains($html($owner, '/staff/26'), '$18.43 an hour'), 'Lee\'s page shows $26.19, Priya\'s still $18.43');
$ws = wk(90);
$sp = pub_shift(102, $srv, 26, $ws, 2, '17:00', '23:00'); $sl = pub_shift(102, $srv, 31, $ws, 2, '17:00', '23:00');
$hrs = (float) one('SELECT EXTRACT(EPOCH FROM (ends_at - starts_at)) / 3600 - break_minutes / 60.0 FROM shifts WHERE id = :s', ['s' => $sp]);
$pcost = one('SELECT ts_effective_rate(26, :p) * :h', ['p' => $srv, 'h' => $hrs]);
$lcost = one('SELECT ts_effective_rate(31, :p) * :h', ['p' => $srv, 'h' => $hrs]);
ok((float) $pcost === round(18.43 * $hrs, 2) || abs((float) $pcost - 18.43 * $hrs) < 0.01, 'the effective rate function gives Priya 18.43 × paid hours');
ok(abs((float) $lcost - 26.19 * $hrs) < 0.01, 'and Lee 26.19 × paid hours (cost follows the effective rate)');
$wc = labor_cost($owner, $ws);
ok($wc !== null && abs($wc - (18.43 + 26.19) * $hrs) < 0.02, "the builder's week labor (labor.view) is (18.43 + 26.19) × $hrs h = " . round((18.43 + 26.19) * $hrs, 2) . ' — Priya at her own rate, Lee at the default (got ' . var_export($wc, true) . ')');
$pg = $html($owner, '/positions/?site=102');
preg_match('/id="position-' . $srv . '-reach">([^<]+)</', $pg, $m);
ok(($m[1] ?? '') === "Applies to $onDefault people; 1 has their own rate.", 'the positions page still says who it reaches: "' . ($m[1] ?? '') . '"');

echo "3. Clearing Priya's own rate makes her the default\n";
[$c, $b] = act($owner, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '']);
ok($c === 200 && one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $srv]) === null, 'wage_update with an empty rate removes the override');
$pg = $html($owner, '/staff/26');
ok(str_contains($pg, '$26.19 an hour') && str_contains($pg, 'Default for the position') && !str_contains($pg, '18.43'), 'Priya is now "$26.19 an hour — Default for the position" and 18.43 is nowhere');
$wc = labor_cost($owner, $ws);
ok($wc !== null && abs($wc - 2 * 26.19 * $hrs) < 0.02, 'the week\'s labor is now 2 × 26.19 × paid hours — her shift moved to the default (got ' . var_export($wc, true) . ')');
[$c, $b] = act($owner, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '$18.43']);
ok($c === 200 && one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $srv]) == 18.43, 'a "$18.43" typed with the sign is accepted');
foreach (['abc', '-3', '99999'] as $bad) { [$c, $b] = act($owner, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => $bad]); ok($c === 422, "a rate of \"$bad\": 422 (" . msg($b) . ')'); }
[$c, $b] = act($owner, '/staff/wage.php', ['member' => 26, 'position' => $W['aBar'], 'rate' => '5']);
ok($c === 422 && msg($b) === 'They do not work that position.', 'a rate on a position the person does not work: 422 "' . msg($b) . '"');

echo "4. Pay is invisible to everyone else\n";
$pages = ['/staff/26', '/staff/31', '/staff/30', '/staff/?site=102', '/positions/?site=102', '/certifications/?site=102', '/certifications/mine', '/builder?site=102&week=' . $ws, "/shifts/$sp", '/team-schedule', '/my-schedule', '/marketplace', '/approvals', '/coverage', '/'];
foreach (['Priya' => $priya, 'Lee' => $lee, 'Pat (planner: builds, no labor.view)' => $pat, 'Dee (manager of Downtown)' => $dee, 'Marco (staff at Airport)' => $marco] as $who => $jar) {
    $leak = [];
    foreach ($pages as $p) {
        foreach ([$html($jar, $p), $json($jar, $p)] as $body) { foreach (wage_leaks($body) as $w) { $leak[$w . ' @ ' . $p] = true; } }
    }
    $isPriya = $jar === $priya;
    if ($isPriya) { unset($leak['18.43 @ /staff/26']); }          // her own effective rate is hers to see, on her own page only
    if ($jar === $lee) { unset($leak['26.19 @ /staff/31']); }     // and Lee's
    ok($leak === [], "$who: no fixture wage on any of " . count($pages) . " pages or their JSON" . ($leak ? ' — LEAK: ' . implode(', ', array_keys($leak)) : ''));
}
$mine = $html($priya, '/staff/26');
ok(str_contains($mine, '$18.43 an hour') && !str_contains($mine, 'Own rate') && !str_contains($mine, 'Default for the position') && !str_contains($mine, 'staff-position-' . $srv . '-pay-form'), 'Priya sees her OWN effective rate on her own page — no source, no form');
$theirs = $html($priya, '/staff/31');
ok(in_array(req('GET', '/staff/31', ['jar' => $priya])['code'], [403, 404], true) && !str_contains($theirs, '26.19'), 'and nothing of Lee\'s: the page is refused (' . req('GET', '/staff/31', ['jar' => $priya])['code'] . ')');
[$c, $d] = screen($priya, '/staff/26');
ok($c === 200 && ($d['positions'][0]['pay']['effective_rate'] ?? null) == 18.43 && !isset($d['positions'][0]['pay']['own_rate']) && !isset($d['positions'][0]['pay']['source']), 'her JSON carries her effective rate alone');
[$c, $d] = screen($pat, '/staff/26');
ok($c === 200 && !isset($d['positions'][0]['pay']) && !str_contains(json_encode($d), 'pay'), 'Pat (no labor.view) reads Priya\'s page with no pay key at all');
$pg = $html($pat, '/staff/26');
ok(!str_contains($pg, 'an hour') && !str_contains($pg, 'staff-position-' . $srv . '-pay') && !str_contains($pg, 'No rate set'), 'the planner\'s page has no rate line, no "no rate set" and no pay form — not even a dash');
$pg = $html($pat, '/positions/?site=102');
ok(!str_contains($pg, 'Applies to') && !str_contains($pg, ' default</span>') && !str_contains($pg, 'rate-form') && !str_contains($pg, 'No default rate'), 'the planner\'s positions page: no default, no reach, no form, no "no default rate"');
[$c, $d] = screen($pat, '/positions/?site=102');
ok($c === 200 && !isset($d['positions'][0]['default_rate']) && !isset($d['positions'][0]['people_on_default']), 'and its JSON has no default_rate key');
$pg = $html($mara, '/staff/26');
ok(str_contains($pg, '$18.43 an hour') && str_contains($pg, 'Own rate') && !str_contains($pg, 'pay-form'), 'Mara (labor.view, no pay.edit) sees rates with their source and has no pay form');
[$c, $b] = act($mara, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '31.07']);
ok($c === 403 && msg($b) === 'You may not change pay or balances here.', 'Mara: wage.php → 403 "' . msg($b) . '"');
[$c, $b] = act($mara, '/positions/rate.php', ['position' => $srv, 'rate' => '31.07']);
ok($c === 403, 'Mara: rate.php → 403');
foreach (['Priya' => $priya, 'Lee' => $lee, 'Pat' => $pat] as $who => $jar) {
    [$c1] = act($jar, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '31.07']); [$c2] = act($jar, '/positions/rate.php', ['position' => $srv, 'rate' => '31.07']);
    ok($c1 === 403 && $c2 === 403, "$who: wage.php and rate.php → 403");
}
[$c1] = act($dee, '/staff/wage.php', ['member' => 26, 'position' => $srv, 'rate' => '31.07']); [$c2] = act($dee, '/positions/rate.php', ['position' => $srv, 'rate' => '31.07']);
ok($c1 === 404 && $c2 === 404, 'Dee (Downtown): another restaurant\'s position does not exist — both 404');
ok(one('SELECT default_wage_rate FROM positions WHERE id = :p', ['p' => $srv]) == 26.19 && one('SELECT wage_override FROM staff_positions WHERE member_id = 26 AND position_id = :p', ['p' => $srv]) == 18.43, 'and every refused call changed nothing');
$pg = $html($owner, '/positions/?site=102');
ok(str_contains($pg, 'id="position-' . $srv . '-rate-form"') && str_contains($pg, 'id="staff-position-') === false, 'the default-rate form is on the owner\'s positions page');
$pg = $html($mara, '/positions/?site=102');
ok(!str_contains($pg, 'rate-form') && str_contains($pg, '$26.19 an hour'), 'and not on Mara\'s, who still sees the default');

echo "5. The log never carries pay\n";
$rows = q("SELECT id, action, entity_type, entity_id, scope_id, before, after FROM activity_log WHERE action = 'wage.update' AND id > :s ORDER BY id", ['s' => $since]);
ok(count($rows) >= 4, count($rows) . ' wage.update rows were written');
$bad = array_filter($rows, fn ($r) => $r['scope_id'] === null || $r['before'] !== null || array_diff(array_keys(json_decode($r['after'], true)), ['scope', 'member_id', 'position_id', 'cleared']) !== []);
ok($bad === [], 'each carries the site, no `before`, and an `after` of only scope, member_id, position_id and cleared');
$scopes = array_map(fn ($r) => json_decode($r['after'], true)['scope'], $rows);
ok(in_array('employee', $scopes, true) && in_array('position_default', $scopes, true), 'scope is `employee` for a person\'s rate and `position_default` for the position\'s');
ok(count(array_filter($rows, fn ($r) => json_decode($r['after'], true)['cleared'] === true)) === 1, 'exactly one row says `cleared`');
$text = logged_text($since);
ok(wage_leaks($text) === [], 'no activity_log row (route included), no outbox subject or body holds a fixture wage: ' . (wage_leaks($text) ? implode(',', wage_leaks($text)) : 'none'));
ok(wage_leaks((string) shell_exec('tail -n 400 ' . escapeshellarg(need('TS_DEV_STATE') . '/kernel.log') . ' 2>/dev/null')) === [], 'the kernel\'s request log holds none either');
finish();
function labor_cost(string $jar, string $ws): ?float
{
    $d = json_decode(req('GET', "/builder?site=102&week=$ws", ['jar' => $jar, 'headers' => JSONH])['body'], true)['data'] ?? [];
    return isset($d['labor']['scheduled_cost']) ? (float) $d['labor']['scheduled_cost'] : null;
}
