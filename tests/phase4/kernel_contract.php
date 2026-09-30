<?php
/**
 * Proof — the kernel's side of the contract, with the KERNEL'S OWN CODE. /var/www/app/features/applications/roles.php (read, never modified) supplies mcp_http_post(),
 * mcp_call_tool_as_kernel() and validate_application_roles(); the kernel's mint_kernel_token() is lifted from app/auth.php under a shim that supplies the scratch
 * ACTION_TOKEN_KEY (as tests/phase2/kernel_compat.php does). What the kernel calls, and how, is exactly what this application must answer:
 *   1. app_roles — the document the kernel parses (os.app-roles/1) validates with no error, and equals the roles and rights the schema seeds (db/004).
 *   2. time_off_taken — the share HR will read, called flat (from, to, scope_id) as the kernel calls a share: per person, only that restaurant's approved
 *      time off, keyed by the kernel's member id; never a reason, a note, a balance or a rate; the SECURITY DEFINER function answers nobody who is acting.
 */
require __DIR__ . '/lib.php';
$src = file_get_contents('/var/www/app/auth.php');
foreach (['mint_kernel_token', 'action_token_key'] as $fn) {
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?^}\n/ms', $src, $m)) { fwrite(STDERR, "cannot find $fn in the kernel's app/auth.php\n"); exit(2); }
    if (!function_exists($fn)) { eval($m[0]); }
}
if (!function_exists('env')) { function env(string $k, ?string $d = null): ?string { $v = getenv($k); return $v === false ? $d : $v; } }
const ACCESS_CAPABILITIES = ['read', 'write', 'admin'];
require_once '/var/www/app/features/applications/roles.php';
reset_p4();
$url = 'http://127.0.0.1:' . REC . '/mcp';

echo "1. app_roles, read and judged by the kernel's own code\n";
$doc = mcp_call_tool_as_kernel($url, 'txtschedules', 'app_roles');
[$roles, $errors] = validate_application_roles($doc);
ok($errors === [], 'validate_application_roles(): no error' . ($errors ? ' — ' . implode(' | ', $errors) : ''));
ok(($doc['schema'] ?? '') === 'os.app-roles/1', 'schema os.app-roles/1');
$shipped = array_values(array_diff(array_column($roles, 'key'), ['planner', 'setter']));    // earlier proofs add scratch roles (planner, setter) to the scratch catalogue
ok($shipped === ['staff', 'shift_lead', 'manager', 'admin'], 'the four roles in the published order (scratch-only extras aside): ' . implode(', ', $shipped));
ok(count($doc['rights']) === 11, 'eleven rights');
$adminRoles = array_values(array_filter($roles, fn ($r) => $r['is_admin']));
ok(count($adminRoles) === 1 && $adminRoles[0]['key'] === 'admin' && $adminRoles[0]['capability'] === 'admin', 'exactly one admin role, key admin, capability admin');
$expect = [];
foreach (q('SELECT role_key, name, description, capability, is_admin FROM ts_roles ORDER BY sort_order') as $r) {
    $rights = array_column(q('SELECT rr.right_key FROM ts_role_rights rr JOIN ts_rights h ON h.right_key = rr.right_key WHERE rr.role_key = :r ORDER BY h.sort_order', ['r' => $r['role_key']]), 'right_key');
    $expect[] = ['key' => $r['role_key'], 'name' => $r['name'], 'description' => $r['description'], 'capability' => $r['capability'], 'is_admin' => (bool) $r['is_admin'], 'rights' => $rights];
}
ok($doc['roles'] == $expect, 'the roles, their words, capabilities and rights equal the schema\'s (ts_roles, ts_role_rights)');
$byKey = array_column($doc['roles'], 'rights', 'key');
ok($byKey['staff'] === ['schedule.view_own', 'availability.edit', 'market.trade'], 'Staff: view own, availability, trade');
ok(in_array('labor.view', $byKey['manager'], true) && !in_array('labor.view', $byKey['shift_lead'], true) && !in_array('labor.view', $byKey['staff'], true), 'labor.view: Manager (and Admin) only');
ok(!in_array('pay.edit', $byKey['manager'], true) && in_array('pay.edit', $byKey['admin'], true) && count($byKey['admin']) === 11, 'pay.edit and settings.manage: Admin only; Admin holds all eleven');
ok(array_column($doc['rights'], 'key') === array_column(q('SELECT right_key FROM ts_rights ORDER BY sort_order'), 'right_key'), 'the rights are the catalogue, in its order');
foreach ($roles as $r) { foreach ($r['rights'] as $right) { if (!preg_match('/^[a-z][a-z0-9_.]{0,59}$/', $right['key'])) { ok(false, 'right key shape ' . $right['key']); } } }
$refused = false;
try { mcp_call_tool_as_kernel($url, 'hr', 'app_roles'); } catch (RuntimeException $e) { $refused = true; }
ok($refused, 'a kernel token minted for another application (hr) gets no roles');
$refused = false;
try { mcp_call_tool_as_kernel($url, 'txtschedules', 'find_staff'); } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'find_staff'); }
ok($refused, 'the kernel asking for any other tool (find_staff) is refused');

echo "2. time_off_taken — the share\n";
$m = json_decode((string) file_get_contents('maludb-os.json'), true);
$share = $m['shares'][0] ?? [];
ok(count($m['shares']) === 1 && $share['tool'] === 'time_off_taken' && $share['scoped'] === true && $share['people'] === true, 'maludb-os.json declares exactly one share: time_off_taken, scoped and people');
admin_sql("DELETE FROM time_off_requests WHERE note LIKE 'SMOKE p4%'");
$typ = fn (int $site, string $key) => (int) one('SELECT id FROM time_off_types WHERE scope_id = :s AND key = :k', ['s' => $site, 'k' => $key]);
$req = function (int $member, int $site, string $type, string $from, string $to, string $status, float $hours, string $note = '') use ($typ) {
    $tz = (string) one('SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $site]);
    $a = (new DateTimeImmutable($from, new DateTimeZone($tz)))->format('c'); $b = (new DateTimeImmutable($to, new DateTimeZone($tz)))->format('c');
    return (int) one("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours, note, status, decided_by, decided_at, decision_note)
                      VALUES (:m, :s, :t, :a, :b, :h, :n, :st, 33, now(), :dn) RETURNING id", ['m' => $member, 's' => $site, 't' => $typ($site, $type), 'a' => $a, 'b' => $b, 'h' => $hours, 'n' => 'SMOKE p4 ' . $note, 'st' => $status,
                      'dn' => $status === 'pending' ? null : 'SMOKE p4 decision reason ' . $note]);
};
$req(26, 102, 'vacation', '2027-02-10 00:00', '2027-02-12 00:00', 'approved', 16, 'secret reason dentist');
$req(26, 102, 'unpaid', '2027-02-20 00:00', '2027-02-21 00:00', 'approved', 8, 'secret reason moving');
$req(30, 102, 'sick', '2027-02-15 09:00', '2027-02-15 13:00', 'approved', 4, 'private illness');
$req(31, 102, 'vacation', '2027-02-16 00:00', '2027-02-17 00:00', 'pending', 8, 'pending only');
$req(32, 102, 'vacation', '2027-02-17 00:00', '2027-02-18 00:00', 'declined', 8, 'declined');
$req(33, 102, 'vacation', '2027-02-18 00:00', '2027-02-19 00:00', 'cancelled', 8, 'cancelled');
$req(30, 102, 'vacation', '2027-01-30 00:00', '2027-01-31 00:00', 'approved', 8, 'before the period');
$req(26, 102, 'vacation', '2027-03-02 00:00', '2027-03-03 00:00', 'approved', 8, 'after the period');
$req(31, 102, 'vacation', '2027-01-31 22:00', '2027-02-01 02:00', 'approved', 4, 'straddles the start');
$req(34, 101, 'vacation', '2027-02-10 00:00', '2027-02-11 00:00', 'approved', 8, 'downtown only');
admin_sql("INSERT INTO time_off_balances (member_id, type_id, balance_hours) VALUES (26, {$typ(102, 'vacation')}, 123.45) ON CONFLICT (member_id, type_id) DO UPDATE SET balance_hours = 123.45");

$call = fn (array $args) => mcp_call_tool_as_kernel($url, 'txtschedules', 'time_off_taken', $args);
$a = $call(['from' => '2027-02-01', 'to' => '2027-02-28', 'scope_id' => 102]);
ok(($a['schema'] ?? '') === 'txtschedules.time-off-taken/1' && $a['scope_id'] === 102 && $a['from'] === '2027-02-01' && $a['to'] === '2027-02-28', 'the answer names its schema, restaurant and period');
$ppl = array_column($a['people'], null, 'member_id');
ok(array_keys($ppl) === [26, 30, 31], 'people are keyed by the KERNEL\'s member id, only those with approved time off in the period: ' . implode(',', array_keys($ppl)));
ok(count($ppl[26]['requests']) === 2 && $ppl[26]['total_hours'] == 24 && $ppl[26]['total_days'] === 3, 'Priya: two requests, 24 hours, 3 days counted');
$v = $ppl[26]['requests'][0];
ok($v['type'] === 'vacation' && $v['type_name'] === 'Vacation / PTO' && $v['paid'] === true && $v['hours'] == 16 && $v['days'] === 2 && $v['timezone'] === 'America/Chicago'
   && $v['starts_local'] === '2027-02-10T00:00' && $v['ends_local'] === '2027-02-12T00:00' && str_starts_with($v['starts_utc'], '2027-02-10T06:00'), 'a request: type key and name, paid, hours, days, the restaurant\'s local times and UTC');
ok($ppl[26]['requests'][1]['type'] === 'unpaid' && $ppl[26]['requests'][1]['paid'] === false, 'an unpaid type says so');
ok($ppl[30]['requests'][0]['type'] === 'sick' && $ppl[30]['requests'][0]['days'] === 1 && $ppl[30]['requests'][0]['hours'] == 4, 'Ana: sick, 4 hours, one day');
ok($ppl[31]['requests'][0]['days'] === 2 && $ppl[31]['requests'][0]['starts_local'] === '2027-01-31T22:00', 'a request straddling the period start is included, its two days counted in the restaurant\'s zone');
$text = json_encode($a);
ok(!str_contains($text, 'secret reason') && !str_contains($text, 'private illness') && !str_contains($text, 'decision reason') && !str_contains($text, 'downtown only'), 'no note, no reason, nothing of another restaurant in the text');
ok(!str_contains($text, '123.45') && !str_contains($text, 'balance'), 'no balance');
$keys = [];
walk($a, function ($p, $x) use (&$keys) { $keys[preg_replace('/\.\d+/', '', $p)] = 1; });
ok(array_diff(array_keys($keys), ['.schema', '.scope_id', '.from', '.to', '.people.member_id', '.people.total_hours', '.people.total_days', '.people.requests.type', '.people.requests.type_name', '.people.requests.paid',
    '.people.requests.starts_local', '.people.requests.ends_local', '.people.requests.starts_utc', '.people.requests.ends_utc', '.people.requests.timezone', '.people.requests.hours', '.people.requests.days']) === [], 'the answer\'s whole vocabulary is the seventeen fields the surface allows');
ok(pay_in($a) === [] && array_filter(wages(), fn ($w) => str_contains($text, $w)) === [] && !preg_match('/wage|rate|cost|phone|email/i', $text), 'no rate, no cost, no phone or email');
$d = $call(['from' => '2027-02-01', 'to' => '2027-02-28', 'scope_id' => 101]);
ok(array_column($d['people'], 'member_id') === [34] && $d['people'][0]['requests'][0]['timezone'] === 'America/New_York', 'scope 101: only Joe, in Downtown\'s zone');
$e = $call(['from' => '2027-02-01', 'to' => '2027-02-28', 'scope_id' => 999]);
ok($e['people'] === [], 'an unknown scope: nobody');
$e = $call(['from' => '2027-02-11', 'to' => '2027-02-11', 'scope_id' => 102]);
ok(array_column($e['people'], 'member_id') === [26], 'one day (Feb 11): only Priya\'s vacation covers it');
$e = $call(['from' => '2027-02-12', 'to' => '2027-02-12', 'scope_id' => 102]);
ok($e['people'] === [], 'Feb 12: nobody (Priya\'s ends at midnight, the 12th is not a day of it)');
foreach ([['from' => '2027-03-01', 'to' => '2027-02-01', 'scope_id' => 102], ['from' => 'soon', 'to' => '2027-02-01', 'scope_id' => 102], ['from' => '2026-01-01', 'to' => '2027-06-01', 'scope_id' => 102]] as $bad) {
    $threw = false;
    try { $call($bad); } catch (RuntimeException $x) { $threw = true; }
    ok($threw, 'a bad period is refused in words: ' . json_encode([$bad['from'], $bad['to']]));
}

echo "3. The door in the database\n";
$p = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$rows = fn (string $who) => (function () use ($p, $who) { $p->exec("SELECT set_config('app.member_id', '$who', false)"); return $p->query("SELECT * FROM ts_time_off_taken(102, '2027-02-01', '2027-02-28')")->fetchAll(); })();
ok(count($rows('')) === 4, 'with no member acting (the kernel path) the function answers: 4 requests');
foreach ([33, 1, 26, 27] as $who) { ok(count($rows((string) $who)) === 0, "with member $who acting (a manager, the owner, a person): nothing"); }
try { $p->query('SELECT * FROM time_off_requests LIMIT 1'); ok(false, 'the records role reads the base table'); } catch (PDOException $x) { ok(true, 'the records role cannot read time_off_requests (no privilege)'); }
foreach ([33, 1] as $who) {
    $r = tool($who, 'records_search', ['sql' => "select * from ts_time_off_taken(102, '2027-02-01', '2027-02-28')"]);
    ok($r['error'] && str_contains($r['text'], 'not available'), "records_search cannot reach the share function (member $who)");
    $r = tool($who, 'records_search', ['sql' => "select set_config('app.member_id', '', true), * from mcp_members"]);
    ok($r['error'], "nor set_config to become nobody (member $who)");
}
$r = tool(33, 'records_search', ['sql' => "select mcp_admit_agent(953)"]);
ok($r['error'], 'nor admit an agent from a search');
$r = tool(33, 'activity_search', ['sql' => "select set_config('app.member_id', '1', true)"], ACT);
ok($r['error'], 'the activity search refuses set_config too');
finish();
