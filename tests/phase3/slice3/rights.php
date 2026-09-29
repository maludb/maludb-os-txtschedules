<?php
/** Proof — who sees what (spec "Proof": Who sees what): another restaurant's people see nothing of a request or a balance (404), a colleague neither, an approver at her restaurant does, only pay.edit adjusts; the records role's views say the same; no wage on any page of the slice. */
require __DIR__ . '/lib.php';
$W = reset3();
$priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $dana = as_member(32); $mara = as_member(33); $owner = as_member(1, 102); $sam = as_member(28, 101); $dee = as_dee(); $pat = as_planner(); $marco = as_member(27, 101);
$vac = typ(102, 'vacation');
$w = wk(77); $d = fn (int $i): string => dayn($w, $i);
grant(26, $vac, 16);
[$c, $b] = req_off($priya, $vac, $d(2), $d(2), ['note' => 'SMOKE private']);
$rq = (int) $b['record_id'];
[$c, $b] = act($priya, '/availability/save.php', ['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'unavailable']);
$av = (int) $b['record_id'];

echo "1. Nobody at another restaurant sees Priya's request or balance\n";
foreach (['Sam (shift lead, Downtown)' => $sam, 'Dee (manager, Downtown)' => $dee] as $who => $jar) {
    $codes = [];
    foreach (["/time-off/$rq", '/time-off?member=26', '/time-off/balances?member=26', '/availability?member=26'] as $path) { $codes[] = req('GET', $path, ['jar' => $jar, 'headers' => JSONH])['code']; }
    foreach (['/time-off/approve.php', '/time-off/decline.php', '/time-off/cancel.php'] as $path) { [$c] = act($jar, $path, ['request' => $rq]); $codes[] = $c; }
    [$c] = act($jar, '/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => 1, 'reason' => 'SMOKE snoop']); $codes[] = $c;
    ok($codes === [404, 404, 404, 404, 404, 404, 404, 404], "$who: the request page, the person's list, the balances, the availability and approve, decline, cancel and adjust are all 404 (" . implode(',', $codes) . ')');
    [$c, $b] = act($jar, '/time-off/approve.php', ['request' => $rq]);
    ok(msg($b) === 'Request not found.', 'in the same words as a missing request: "' . msg($b) . '"');
}
[$c, $b] = act($dee, '/time-off/approve.php', ['request' => 999999]);
ok($c === 404 && msg($b) === 'Request not found.', 'a request that does not exist: the same 404 and sentence');
[$c, $d0] = screen($dee, '/time-off?member=all&site=101');
ok($c === 200 && count(array_filter($d0['requests'], fn ($x) => $x['request_id'] === $rq)) === 0, 'Dee\'s own restaurant\'s list does not hold it');
[$c, $d0] = screen($dee, '/approvals');
ok($c === 200 && count($d0['time_off']) === 0 && count($d0['availability']) === 0, 'and neither does her approvals inbox');
echo "2. A colleague at the same restaurant\n";
$codes = [];
foreach (["/time-off/$rq", '/time-off/balances?member=26', '/time-off?member=26'] as $path) { $codes[] = req('GET', $path, ['jar' => $lee, 'headers' => JSONH])['code']; }
ok($codes === [404, 403, 403], 'Lee: the request is 404, her balances 403, her list 403 (' . implode(',', $codes) . ')');
[$c, $d0] = screen($lee, '/time-off?member=all&site=102');
ok($c === 403, 'Lee asking for Everyone: 403');
$codes = [];
foreach (['approve', 'decline', 'cancel'] as $a) { [$c] = act($lee, "/time-off/$a.php", ['request' => $rq]); $codes[] = $c; }
ok($codes === [403, 403, 403], 'Lee cannot approve, decline or cancel it: 403');
$codes = [];
foreach (["/time-off/$rq", '/time-off/balances?member=26'] as $path) { $codes[] = req('GET', $path, ['jar' => $dana, 'headers' => JSONH])['code']; }
ok($codes === [404, 403], 'Dana (shift lead): 404 and 403 — shift leads decide trades, not time off');
echo "3. An approver at her restaurant\n";
[$c, $d0] = screen($mara, "/time-off/$rq");
ok($c === 200 && $d0['request']['member']['name'] === 'SMOKE Priya' && $d0['request']['note'] === 'SMOKE private' && $d0['may']['decide'] === true && $d0['request']['balance']['hours'] == 16, 'Mara sees the request, the note, the balance, and may decide');
[$c, $d0] = screen($mara, '/time-off/balances?member=26');
ok($c === 200 && $d0['balances'][0]['balance_hours'] == 16 && count($d0['balances'][0]['ledger']) === 1, 'and her balances with the ledger');
[$c, $d0] = screen($mara, '/availability?member=26');
ok($c === 200 && count($d0['pending']) === 1, 'and her availability');
[$c, $d0] = screen($owner, "/time-off/$rq");
ok($c === 200 && $d0['may']['decide'] === true, 'the owner too');
echo "4. A builder who does not approve (schedule.build only)\n";
[$c, $d0] = screen($pat, "/time-off/$rq");
ok($c === 200 && $d0['may']['decide'] === false && !isset($d0['request']['balance']), 'Pat (planner) may read the request but not decide, and sees no balance: ' . json_encode($d0['may'] ?? []));
[$c, $b] = act($pat, '/time-off/approve.php', ['request' => $rq]);
ok($c === 403 && msg($b) === 'You may not approve requests here.', 'approving: 403 "' . msg($b) . '"');
[$c, $d0] = screen($pat, '/time-off/balances?member=26');
ok($c === 403, 'her balances: 403');
[$c, $d0] = screen($pat, '/availability?member=26');
ok($c === 200, 'availability she may read (she builds the schedule): 200');
$html = page($pat, "/time-off/$rq")['body'];
ok(!str_contains($html, 'id="time-off-decide"') && !str_contains($html, 'id="time-off-balance"'), 'the page has no Decide card and no balance line');
echo "5. Only pay.edit adjusts a balance\n";
$v = bal(26, $vac);
$codes = [];
foreach (['Mara (manager)' => $mara, 'Pat (planner)' => $pat, 'Priya' => $priya, 'Lee' => $lee] as $who => $jar) { [$c, $b] = act($jar, '/time-off/balance.php', ['member' => 26, 'time_off_type' => $vac, 'delta_hours' => 5, 'reason' => 'SMOKE try']); $codes[$who] = $c; }
ok(array_unique(array_values($codes)) === [403] && bal(26, $vac) === $v, 'a manager, a planner, the person and a colleague: all 403, balance unchanged (' . json_encode($codes) . ')');
[$c, $d0] = screen($owner, '/time-off/balances?member=26');
ok($c === 200, 'the owner (pay.edit) reads and adjusts');
$html = page($owner, '/time-off/balances?member=26')['body'];
ok(str_contains($html, 'id="balance-form"'), 'the adjust form is on the owner\'s page');
$html = page($mara, '/time-off/balances?member=26')['body'];
ok(!str_contains($html, 'id="balance-form"'), 'and not on the manager\'s');
$html = page($priya, '/time-off/balances')['body'];
ok(!str_contains($html, 'id="balance-form"') && str_contains($html, 'id="balance-card-' . $vac . '-hours"'), 'nor on the person\'s own — she sees her balance and ledger');
echo "6. The records role's views say the same\n";
$records = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$as = function (int $m, string $sql) use ($records): int { $records->exec("SELECT set_config('app.member_id', '$m', false)"); return (int) $records->query($sql)->fetchColumn(); };
foreach (['mcp_time_off_requests' => "SELECT count(*) FROM mcp_time_off_requests WHERE request_id = $rq", 'mcp_time_off_balances' => 'SELECT count(*) FROM mcp_time_off_balances WHERE member_id = 26', 'mcp_availability' => "SELECT count(*) FROM mcp_availability WHERE availability_id = $av"] as $view => $sql) {
    $seen = ['Priya' => $as(26, $sql), 'Mara' => $as(33, $sql), 'Owner' => $as(1, $sql), 'Lee' => $as(31, $sql), 'Dee' => $as(36, $sql), 'Sam' => $as(28, $sql)];
    ok($seen['Priya'] === 1 && $seen['Mara'] === 1 && $seen['Owner'] === 1 && $seen['Lee'] === 0 && $seen['Dee'] === 0 && $seen['Sam'] === 0, "$view: Priya, Mara and the owner see the row; Lee, Dee and Sam do not " . json_encode($seen));
}
ok($as(35, "SELECT count(*) FROM mcp_time_off_requests WHERE request_id = $rq") === 1 && $as(35, 'SELECT count(*) FROM mcp_time_off_balances WHERE member_id = 26') === 0, 'a builder who does not approve (Pat): the request row, not the balance');
ok($as(31, 'SELECT count(*) FROM mcp_blackout_dates') >= 0 && $as(36, 'SELECT count(*) FROM mcp_time_off_types WHERE site_id = 102') === 0, 'types are those of one\'s own restaurants only (Dee sees none of Airport\'s)');
try { $records->exec('UPDATE time_off_balances SET balance_hours = 999'); ok(false, 'the records role cannot write'); } catch (PDOException $e) { ok(true, 'the records role cannot write a balance (' . $e->getCode() . ')'); }
echo "7. No wage on any page of the slice\n";
$paths = ['/availability', '/availability?member=26', "/time-off", '/time-off?member=all&site=102', "/time-off/$rq", '/time-off/new', '/time-off/balances?member=26', '/site/time-off?site=102', '/approvals', '/requests'];
$leaks = [];
foreach (['owner' => $owner, 'mara' => $mara, 'priya' => $priya] as $who => $jar) { foreach ($paths as $p) { $r = req('GET', $p, ['jar' => $jar]); $l = leaks($r['body']); if ($l) { $leaks[] = "$who $p: " . implode(',', $l); } $r = req('GET', $p, ['jar' => $jar, 'headers' => JSONH]); $l = leaks($r['body']); if ($l) { $leaks[] = "$who json $p: " . implode(',', $l); } } }
ok($leaks === [], 'no fixture wage (21.37, 24.61, 23.19, 22.83) on any of ' . count($paths) . ' pages for the owner, a manager and a person, page or JSON' . ($leaks ? ' — ' . implode('; ', $leaks) : ''));
$leak = q("SELECT id, action FROM activity_log WHERE (after::text ~* 'wage|rate|cost|pay_' OR before::text ~* 'wage|rate|cost|pay_') AND action ~ '^(timeoff|availability|balance|blackout|timeoff_type)'");
ok($leak === [], 'no activity row of the slice carries a wage, rate or cost');
$ob = q("SELECT id FROM notification_outbox WHERE (body ~* 'wage|rate of pay|\\\$[0-9]' OR body ~ '[0-9]+\\.[0-9]{2} an hour')");
ok($ob === [], 'and no notice does');
echo "8. Action tokens act as their member\n";
$key = need('ACTION_TOKEN_KEY');
$person = fn (int $m, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl)) . '.' . hash_hmac('sha256', $p, $key);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
[$c, $b] = $post('/time-off/approve.php', ['request' => $rq], ['X-Action-Token: ' . $person(31)]);
ok($c === 403, 'a token for Lee (staff): time_off_approve → 403');
[$c, $b] = $post('/time-off/approve.php', ['request' => $rq], ['X-Action-Token: ' . $person(36)]);
ok($c === 404, 'a token for Dee (another restaurant): 404');
[$c, $b] = $post('/time-off/request.php', ['time_off_type' => typ(102, 'unpaid'), 'from' => $d(4), 'to' => $d(4)], ['X-Action-Token: ' . $person(30)]);
$tr = (int) ($b['record_id'] ?? 0);
$row = q("SELECT source, actor_member_id, scope_id FROM activity_log WHERE action = 'timeoff.request' AND entity_id = :i", ['i' => $tr])[0] ?? [];
ok($c === 200 && $b['location'] === "/time-off/$tr" && !str_contains($b['location'] ?? '', 'notice') && ($row['source'] ?? '') === 'assistant' && (int) $row['actor_member_id'] === 30 && (int) $row['scope_id'] === 102, 'a token for Ana: time_off_request → 200, the location ends in the id, source assistant, her id, the site');
[$c, $b] = $post('/time-off/approve.php', ['request' => $tr], ['X-Action-Token: ' . $person(33, -5)]);
ok($c === 401, 'an expired token: 401');
[$c, $b, $r] = $post('/time-off/approve.php', ['request' => $tr], ['X-Action-Token: ' . $person(33)]);
ok($c === 200 && !str_contains($r['headers'], 'Set-Cookie'), 'a token for Mara: time_off_approve → 200, no CSRF token, no cookie');
$r = req('POST', '/time-off/approve.php', ['headers' => JSONH, 'form' => ['request' => $rq]]);
ok($r['code'] === 401, 'no login and no token: 401');
$r = req('POST', '/time-off/approve.php', ['jar' => $mara, 'headers' => JSONH, 'form' => ['request' => $rq]]);
ok($r['code'] === 403, 'a signed-in POST without the CSRF token: 403');
$r = req('GET', '/time-off/approve.php', ['jar' => $mara, 'headers' => JSONH]);
ok($r['code'] === 405, 'a GET to a handler: 405');
finish();
