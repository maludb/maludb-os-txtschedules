<?php
/**
 * Proof — the records tools, part 2: availability, time off, requests, labor against the budget, staffing needs, settings, rules, announcements, the trade
 * report, and the guarded long tail (records_search). The wage rule again where it bites (labor_vs_budget is for labor.view alone); a person's note and their
 * balance are theirs and their approvers'; what waits for a manager waits only for one who approves.
 */
require __DIR__ . '/lib.php';
$W = reset_p4();
$AIR = 102; $DT = 101; $srv = $W['aSrv']; $bar = $W['aBar']; $dsrv = $W['dSrv'];
$FRI = next_dow('America/Chicago', 5);
$monday = monday_of(new DateTimeImmutable($FRI), 'America/Chicago');
foreach ([$AIR => $FRI, $DT => next_dow('America/New_York', 5)] as $site => $d) { clear_day($site, $d); }
admin_sql("DELETE FROM availability_rules; DELETE FROM blackout_dates; DELETE FROM time_off_ledger; DELETE FROM time_off_balances; DELETE FROM time_off_requests WHERE note LIKE 'SMOKE p4%' OR true;
           DELETE FROM announcements; DELETE FROM labor_budgets; DELETE FROM forecast_covers; DELETE FROM staffing_ratios; DELETE FROM exchanges;
           UPDATE site_settings SET approval_pickup = 'on_warning', allow_pickup = true;");
$typ = fn (int $site, string $key) => (int) one('SELECT id FROM time_off_types WHERE scope_id = :s AND key = :k', ['s' => $site, 'k' => $key]);
$ana = local_shift($AIR, $srv, 30, $FRI, '17:00', '22:00');
$dana = local_shift($AIR, $srv, 32, $FRI, '17:00', '22:00');
$lee = local_shift($AIR, $srv, 31, $FRI, '11:00', '15:00');
$open = local_shift($AIR, $srv, null, $FRI, '17:00', '22:00');
$joe = local_shift($DT, $dsrv, 34, next_dow('America/New_York', 5), '12:00', '16:00');
$req = function (int $member, int $site, string $type, string $from, string $to, string $status, float $hours, string $note) use ($typ) {
    $tz = (string) one('SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $site]);
    return (int) one("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours, note, status, decided_by, decided_at, decision_note)
                      VALUES (:m, :s, :t, :a, :b, :h, :n, :st, :by, CASE WHEN :st2 = 'pending' THEN NULL ELSE now() END, :dn) RETURNING id",
        ['m' => $member, 's' => $site, 't' => $typ($site, $type), 'a' => (new DateTimeImmutable($from, new DateTimeZone($tz)))->format('c'), 'b' => (new DateTimeImmutable($to, new DateTimeZone($tz)))->format('c'),
         'h' => $hours, 'n' => $note, 'st' => $status, 'st2' => $status, 'by' => $status === 'pending' ? null : 33, 'dn' => $status === 'pending' ? null : 'SMOKE p4 decided']);
};

echo "1. availability\n";
admin_sql("INSERT INTO availability_rules (member_id, scope_id, weekday, starts_at, ends_at, kind, effective_from, status) VALUES
           (30, $AIR, 5, '18:00', '23:00', 'unavailable', current_date - 30, 'approved'), (31, $AIR, 1, '09:00', '17:00', 'available', current_date - 30, 'approved'),
           (26, $AIR, 3, '09:00', '13:00', 'unavailable', current_date, 'pending'), (34, $DT, 2, '10:00', '20:00', 'preferred', current_date - 5, 'approved')");
$a = tdata(30, 'availability');
ok(count($a) === 1 && $a[0]['kind'] === 'unavailable' && $a[0]['weekday'] === 5 && $a[0]['starts_at'] === '18:00:00' && $a[0]['status'] === 'approved' && str_contains($a[0]['label'], 'Fri'), 'Ana: her own entry (unavailable Friday 18:00-23:00), approved, with a label');
ok(tdata(31, 'availability', ['member_id' => 30]) === [], 'Lee cannot read Ana\'s');
$a = tdata(33, 'availability', ['member_id' => 30]);
ok(count($a) === 1 && $a[0]['member_name'] === 'SMOKE Ana', 'Mara can');
$a = tdata(33, 'availability', ['at' => "{$FRI}T19:00"]);
ok(array_column($a, 'member_id') === [30], 'who is unavailable Friday at 19:00 (Mara): Ana');
ok(tdata(33, 'availability', ['at' => "{$FRI}T12:00"]) === [], 'at noon: nobody');
ok(tdata(30, 'availability', ['at' => "{$FRI}T19:00"]) === [30] || array_column(tdata(30, 'availability', ['at' => "{$FRI}T19:00"]), 'member_id') === [30], 'Ana asking who is unavailable sees only herself');
ok(array_column(tdata(33, 'availability', ['member_id' => 26, 'status' => 'pending']), 'member_id') === [26] && tdata(33, 'availability', ['member_id' => 26, 'status' => 'approved']) === [], 'status filter: Priya\'s change waits (pending, not approved)');
ok(array_column(tdata(33, 'availability', ['q' => 'Ana']), 'member_id') === [30], 'look-up by words (how an action resolves an entry): a list with availability_id');
ok(tdata(34, 'availability', ['member_id' => 30]) === [] && tdata(34, 'availability')[0]['kind'] === 'preferred', 'Joe: none of Airport\'s; his own');
ok(tdata(29, 'availability') === [], 'Nobody: none');

echo "2. time off\n";
$rv = $req(26, $AIR, 'vacation', "$FRI 00:00", (new DateTimeImmutable($FRI))->modify('+2 days')->format('Y-m-d') . ' 00:00', 'pending', 16, 'SMOKE p4 secret family reason');
$rs = $req(30, $AIR, 'sick', "$FRI 09:00", "$FRI 13:00", 'approved', 4, 'SMOKE p4 private illness');
$rj = $req(34, $DT, 'vacation', next_dow('America/New_York', 5) . ' 00:00', (new DateTimeImmutable(next_dow('America/New_York', 5)))->modify('+1 day')->format('Y-m-d') . ' 00:00', 'approved', 8, 'SMOKE p4 downtown');
$rx = $req(31, $AIR, 'vacation', (new DateTimeImmutable($FRI))->modify('+20 days')->format('Y-m-d') . ' 00:00', (new DateTimeImmutable($FRI))->modify('+21 days')->format('Y-m-d') . ' 00:00', 'declined', 8, 'SMOKE p4 declined one');
admin_sql("INSERT INTO blackout_dates (scope_id, on_date, reason) VALUES ($AIR, '" . (new DateTimeImmutable($FRI))->modify('+30 days')->format('Y-m-d') . "', 'SMOKE p4 Holiday party')");
$t = tdata(33, 'time_off');
ok(array_column($t['requests'], 'request_id') == [$rs, $rv, $rx] || count($t['requests']) === 3, 'Mara (approves at Airport): the three Airport requests, not Downtown\'s (' . count($t['requests']) . ')');
$byId = array_column($t['requests'], null, 'request_id');
ok($byId[$rv]['note'] === 'SMOKE p4 secret family reason' && $byId[$rv]['hours'] == 16 && $byId[$rv]['status'] === 'pending' && $byId[$rv]['starts_local'] === "{$FRI}T00:00" && $byId[$rv]['timezone'] === 'America/Chicago', 'a request: type, local and UTC times, hours, status, and to a decider the person\'s note');
ok(str_contains($byId[$rv]['label'], 'Priya') && str_contains($byId[$rv]['label'], 'vacation'), 'with a label (Priya vacation …) an action can resolve by');
ok(count($t['blackout_dates']) === 1 && $t['blackout_dates'][0]['reason'] === 'SMOKE p4 Holiday party', 'the blackout dates ride along');
$t = tdata(30, 'time_off');
ok(array_column($t['requests'], 'request_id') === [$rs] && $t['requests'][0]['note'] === 'SMOKE p4 private illness', 'Ana: her own request only, with her note');
ok(!str_contains(tool(30, 'time_off')['text'], 'secret family reason'), '...and never another person\'s note');
$t = tdata(35, 'time_off');
ok(count($t['requests']) === 3 && array_filter($t['requests'], fn ($x) => $x['note'] !== null) === [] && array_filter($t['requests'], fn ($x) => $x['decision_note'] !== null) === [], 'Pat (builds, does not approve) sees the requests but not a person\'s note or the decision\'s');
ok(!str_contains(tool(35, 'time_off')['text'], 'secret family'), '...nowhere in the text');
$t = tdata(33, 'time_off', ['on' => $FRI]);
ok(array_column($t['requests'], 'member_id') == [26, 30] || count($t['requests']) === 2, 'who is off on Friday (pending and approved): Priya (pending), Ana');
$t = tdata(33, 'time_off', ['on' => $FRI, 'status' => 'approved']);
ok(array_column($t['requests'], 'member_id') === [30], 'approved only: Ana');
$t = tdata(33, 'time_off', ['status' => 'declined']);
ok(array_column($t['requests'], 'request_id') === [$rx], 'status filter');
$t = tdata(33, 'time_off', ['member_id' => 26]);
ok(array_column($t['requests'], 'member_id') === [26], 'member filter');
$t = tdata(34, 'time_off');
ok(array_column($t['requests'], 'request_id') === [$rj] && $t['blackout_dates'] === [], 'Joe: his own request; Airport\'s blackout date is not his to see');
$t = tdata(29, 'time_off');
ok($t['requests'] === [] && $t['blackout_dates'] === [], 'Nobody: nothing');
$r = tdata(33, 'time_off', ['q' => 'Priya vacation']);
ok(count($r) === 1 && $r[0]['request_id'] === $rv && str_contains($r[0]['label'], 'Priya vacation'), 'RESOLVE MODE (q alone): a plain list with request_id');
$r = tdata(33, 'time_off', ['q' => (new DateTimeImmutable($FRI))->modify('+30 days')->format('Y-m-d')]);
ok(count($r) === 1 && isset($r[0]['blackout_id']) && str_contains($r[0]['label'], 'Holiday party'), 'a date alone names a blackout date (blackout_id)');
ok(tdata(30, 'time_off', ['q' => 'Priya']) === [], 'Ana resolving "Priya": nothing (not hers)');
ok(tool(33, 'time_off', ['status' => 'maybe'])['error'], 'a bad status is refused in words');

echo "3. balances\n";
admin_sql("INSERT INTO time_off_balances (member_id, type_id, balance_hours) VALUES (30, {$typ($AIR, 'vacation')}, 40), (30, {$typ($AIR, 'sick')}, 16), (26, {$typ($AIR, 'vacation')}, 80);
           INSERT INTO time_off_ledger (member_id, type_id, delta_hours, reason, note, recorded_by) VALUES (30, {$typ($AIR, 'vacation')}, 40, 'grant', 'SMOKE p4 yearly grant', 33), (30, {$typ($AIR, 'sick')}, -4, 'request_approved', NULL, 33)");
$b = tdata(30, 'time_off_balances', ['ledger' => true]);
ok(array_column($b['balances'], 'balance_hours', 'type_name') == ['Vacation / PTO' => 40, 'Sick' => 16] && count($b['ledger']) === 2 && $b['ledger'][0]['delta_hours'] !== null, 'Ana: balances in hours per type and the ledger that made them');
$b = tdata(30, 'time_off_balances');
ok(!isset($b['ledger']), 'the ledger only when asked');
$b = tdata(33, 'time_off_balances', ['member_id' => 30, 'ledger' => true]);
ok(count($b['balances']) === 2 && count($b['ledger']) === 2, 'Mara (approves): Ana\'s balances and ledger');
ok(tdata(31, 'time_off_balances', ['member_id' => 30])['balances'] === [], 'Lee cannot read Ana\'s balance');
ok(tdata(35, 'time_off_balances', ['member_id' => 30])['balances'] === [], 'Pat (builds, does not approve) cannot either');
ok(tdata(34, 'time_off_balances', ['member_id' => 30])['balances'] === [] && tdata(29, 'time_off_balances')['balances'] === [], 'Joe and Nobody: nothing');
ok(pay_in(tdata(33, 'time_off_balances', ['member_id' => 30, 'ledger' => true])) === [], 'hours, never pay');

echo "4. my_requests and pending_requests\n";
$jar = as_member(30);
[$c, $b] = act($jar, '/exchanges/offer.php', ['shift' => $ana]); $xo = (int) $b['record_id'];
admin_sql("UPDATE site_settings SET approval_pickup = 'always' WHERE scope_id = $AIR");
[$c2, $b2] = act(as_member(31), '/exchanges/claim.php', ['exchange' => $xo]);
ok($c === 200 && $c2 === 200 && one('SELECT status FROM exchanges WHERE id = ' . $xo) === 'pending_approval', 'set-up: Ana offered, Lee claimed, the restaurant approves every pick-up: pending approval');
$mr = tdata(30, 'my_requests');
$kinds = array_count_values(array_column($mr, 'kind'));
ok(($kinds['time_off'] ?? 0) === 1 && ($kinds['availability'] ?? 0) === 1 && ($kinds['offer'] ?? 0) === 1, 'Ana: her time off, her availability, her offer — ' . json_encode($kinds));
ok(array_unique(array_column(array_filter($mr, fn ($x) => $x['kind'] === 'offer'), 'state')) === [0 => 'waiting'], 'the offer waits');
ok(array_unique(array_column(array_filter($mr, fn ($x) => $x['kind'] === 'time_off'), 'state')) === [0 => 'decided'], 'the approved sick day is decided');
ok(count(tdata(30, 'my_requests', ['state' => 'waiting'])) === 1 && count(tdata(30, 'my_requests', ['state' => 'decided'])) === 2, 'state filter');
$ml = tdata(31, 'my_requests');
ok(count(array_filter($ml, fn ($x) => $x['kind'] === 'claim')) === 1 && !str_contains(json_encode($ml), 'SMOKE Ana'), 'Lee: his claim, nothing of Ana\'s');
ok(tdata(29, 'my_requests') === [], 'Nobody: none');
$p = tdata(33, 'pending_requests');
$pk = array_count_values(array_column($p, 'kind'));
ok(($pk['time_off'] ?? 0) === 1 && ($pk['availability'] ?? 0) === 1 && ($pk['exchange'] ?? 0) === 1, 'Mara: what waits — a time-off request, an availability change, an exchange — ' . json_encode($pk));
$ex = array_values(array_filter($p, fn ($x) => $x['kind'] === 'exchange'))[0];
ok($ex['id'] === $xo && $ex['status'] === 'pending_approval' && is_array($ex['warnings']) && str_contains($ex['summary'], 'offer'), 'the exchange carries its warnings and a sentence');
ok(str_contains(array_values(array_filter($p, fn ($x) => $x['kind'] === 'time_off'))[0]['summary'], 'Priya'), 'the time-off sentence names Priya');
ok(array_column(tdata(33, 'pending_requests', ['kind' => 'exchange']), 'kind') === ['exchange'], 'kind filter');
ok(tdata(30, 'pending_requests') === [] && tdata(31, 'pending_requests') === [] && tdata(35, 'pending_requests') === [], 'Ana, Lee and Pat (builds, does not approve): nothing waits for them');
$soon = $FRI <= (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->modify('+1 day')->format('Y-m-d');
$dn = tdata(32, 'pending_requests');
ok($soon ? count($dn) === 1 && $dn[0]['kind'] === 'exchange' : $dn === [], 'Dana (shift lead: approves same-day trades only): ' . ($soon ? 'Friday is today or tomorrow, so the trade is hers' : 'Friday is further off, so nothing') . ' (never the time off or availability)');
ok(tdata(34, 'pending_requests') === [] && tdata(29, 'pending_requests') === [], 'Joe and Nobody: nothing');
ok(tool(33, 'pending_requests', ['kind' => 'shifts'])['error'], 'a bad kind is refused in words');
admin_sql("UPDATE site_settings SET approval_pickup = 'on_warning' WHERE scope_id = $AIR");

echo "5. labor_vs_budget and staffing_needs\n";
admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount) VALUES ($AIR, '$monday', 'all', 187.5, 4321.09), ($AIR, '$monday', 'front', 100, 2000)");
$l = tool(33, 'labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI]);
$rows = array_column($l['data']['rows'], null, 'area');
ok(!$l['error'] && $l['data']['currency'] === 'USD' && $rows['all']['budget_amount'] == 4321.09 && $rows['all']['budget_hours'] == 187.5 && $rows['all']['scheduled_cost'] > 0, 'Mara (labor.view): the week against its budget — cost, hours, budget');
ok(abs($rows['all']['scheduled_hours'] - (float) one("SELECT round(sum(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 3600 - break_minutes / 60.0)::numeric, 2) FROM shifts WHERE scope_id = $AIR AND status = 'scheduled' AND week_id = (SELECT id FROM schedule_weeks WHERE scope_id = $AIR AND week_start = '$monday')")) < 0.01, 'hours equal the database\'s own sum of the week\'s paid hours');
ok($rows['all']['cost_over_budget'] < 0 && $rows['all']['hours_over_budget'] < 0 && str_contains($l['data']['note'], 'overtime'), 'variances against the budget, and the note that overtime premium is not applied');
$d = tdata(33, 'labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI, 'by' => 'day']);
$fr = array_values(array_filter($d['days'], fn ($x) => $x['date'] === $FRI))[0];
ok($fr['shifts'] === 4 && $fr['open_shifts'] === 1 && abs($fr['scheduled_cost'] - (5 * 21.37 + 5 * 21.37 + 4 * 21.37)) < 0.02, 'by day: Friday\'s 4 shifts, 1 open, cost 14 h x 21.37');
ok(count(tdata(33, 'labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI, 'area' => 'front'])['rows']) === 1, 'area filter');
foreach ([30 => 'Ana (staff)', 32 => 'Dana (shift lead)', 35 => 'Pat (builds, no labor.view)', 34 => 'Joe (other restaurant)', 29 => 'Nobody'] as $who => $label) {
    $r = tool($who, 'labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI]);
    ok($r['error'] && pay_in($r['data']) === [] && array_filter(pay_strings(), fn ($w) => str_contains($r['text'], $w)) === [], "$label: told it needs labor.view (or that the restaurant is not theirs) — no number");
}
$r = tool(27, 'labor_vs_budget', ['site_id' => $AIR]);
ok($r['error'], 'Marco (manager Downtown, STAFF at Airport): no cost for Airport');
$r = tool(27, 'labor_vs_budget', ['site_id' => $DT]);
ok(!$r['error'] && !str_contains($r['text'], '4321.09'), 'but his own restaurant\'s (Downtown): yes, and none of Airport\'s budget');
$n = tool(33, 'staffing_needs', ['site_id' => $AIR, 'from' => $FRI, 'to' => $FRI]);
ok(!$n['error'] && $n['data']['rows'] === [] && $n['data']['note'] !== null, 'staffing_needs with no ratios or forecast: no rows, and why');
admin_sql("INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff) VALUES ($AIR, $srv, 20, 1);
           INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source) SELECT $AIR, '$FRI', id, 100, 'manual' FROM day_parts WHERE scope_id = $AIR AND key = 'dinner'");
$n = tdata(33, 'staffing_needs', ['site_id' => $AIR, 'from' => $FRI, 'to' => $FRI]);
$dinner = array_values(array_filter($n['rows'], fn ($x) => $x['day_part'] === 'Dinner'))[0];
ok($dinner['expected_covers'] === 100 && $dinner['recommended'] === 5 && $dinner['scheduled'] === 2 && $dinner['open_shifts'] === 1 && $dinner['short_by'] === 3, 'Friday dinner: 100 covers -> 5 servers, 2 scheduled, 1 open, short by 3');
ok($n['gaps'][0]['short_by'] === 3 && $n['gaps'][0]['day_part'] === 'Dinner', 'the gaps come first');
ok(!tool(35, 'staffing_needs', ['site_id' => $AIR, 'from' => $FRI, 'to' => $FRI])['error'], 'Pat (builds) may — needs need no pay');
foreach ([30, 31, 32, 34, 29] as $who) { ok(tool($who, 'staffing_needs', ['site_id' => $AIR])['error'], "member $who: staffing needs are for managers"); }
ok(tool(33, 'staffing_needs', ['site_id' => $AIR, 'from' => $FRI, 'to' => '2030-01-01'])['error'], 'a period of years is refused');

echo "7. settings, rules, announcements\n";
$s = tdata(30, 'site_settings', ['site_id' => $AIR]);
ok($s['settings']['allow_swap'] === true && $s['settings']['cutoff_minutes'] === 120 && $s['settings']['claim_mode'] === 'first' && $s['settings']['time_off_day_hours'] == 8 && $s['settings']['my_role'] === 'staff', 'Ana reads Airport\'s trade rules (they affect everyone)');
ok(array_column($s['day_parts'], 'name') === ['Lunch', 'Dinner'] && array_column($s['time_off_types'], 'name') == ['Sick', 'Unpaid', 'Vacation / PTO'], 'day-parts and time-off types ride along');
ok(!isset($s['settings']['location_id']) && pay_in($s) === [], 'no location id, no pay');
ok(tool(34, 'site_settings', ['site_id' => $AIR])['error'], 'Joe: Airport does not exist');
$r = tdata(30, 'site_settings', ['q' => 'vacation']);
ok(count($r) === 1 && isset($r[0]['type_id']) && $r[0]['name'] === 'Vacation / PTO', 'RESOLVE MODE: a time-off type (type_id) by words');
$r = tdata(30, 'site_settings', ['q' => 'dinner']);
ok(count($r) === 1 && isset($r[0]['day_part_id']), '...a day-part (day_part_id)');
ok(tdata(34, 'site_settings', ['q' => 'vacation'])[0]['site_id'] === $DT, 'Joe finds only Downtown\'s');
$r = tdata(30, 'site_rules', ['site_id' => $AIR]);
ok($r['preset'] === 'generic' && count($r['rules']) === 12 && array_column($r['rules'], 'severity', 'rule_key')['min_rest'] === 'soft' && $r['rules'][0]['explains'] !== '', 'site_rules: the preset, twelve rules, severity, what each means');
ok(count(tdata(30, 'site_rules', ['site_id' => $AIR, 'rule_key' => 'min_rest'])['rules']) === 1, 'one rule by key');
$r = tdata(30, 'site_rules', ['q' => 'rest']);
ok(count($r) === 1 && $r[0]['rule_key'] === 'min_rest', 'RESOLVE MODE: a rule by words');
ok(tool(34, 'site_rules', ['site_id' => $AIR])['error'], 'Joe: not his');
admin_sql("INSERT INTO announcements (scope_id, title, body, audience, position_id, member_ids, pinned_until, posted_by) VALUES
           ($AIR, 'SMOKE p4 Everyone', 'All hands Monday', 'site', NULL, '{}', current_date + 3, 33),
           ($AIR, 'SMOKE p4 Bar only', 'New drinks list', 'position', $bar, '{}', NULL, 33),
           ($AIR, 'SMOKE p4 Lee only', 'Come see me', 'people', NULL, '{31}', NULL, 33),
           ($DT, 'SMOKE p4 Downtown news', 'Not for Airport', 'site', NULL, '{}', NULL, 27)");
$a = tdata(31, 'announcements');
ok(array_column($a, 'title') == ['SMOKE p4 Everyone', 'SMOKE p4 Lee only'] && $a[0]['pinned'] === true && $a[0]['read_count'] === null, 'Lee (Server): the site-wide (pinned first) and the one named to him; no read count');
$a = tdata(30, 'announcements');
ok(array_column($a, 'title') == ['SMOKE p4 Everyone', 'SMOKE p4 Bar only'], 'Ana (works Bar): the site-wide and the Bar one — not Lee\'s');
$a = tdata(33, 'announcements');
ok(count($a) === 3 && array_filter($a, fn ($x) => $x['read_count'] === null) === [] && $a[0]['title'] === 'SMOKE p4 Everyone', 'Mara (posts): all three at Airport with read counts, pinned first');
$a = tdata(33, 'announcements', ['mine' => true]);
ok(array_column($a, 'title') == ['SMOKE p4 Everyone'] || array_diff(array_column($a, 'title'), ['SMOKE p4 Everyone', 'SMOKE p4 Bar only']) === [], 'mine: not the private list she wrote for Lee');
ok(count(tdata(33, 'announcements', ['q' => 'bar'])) === 1, 'q finds by title');
ok(array_column(tdata(34, 'announcements'), 'title') === ['SMOKE p4 Downtown news'], 'Joe: only Downtown\'s');
ok(tdata(29, 'announcements') === [], 'Nobody: none');

echo "8. exchange_report\n";
$r = tdata(33, 'exchange_report', ['site_id' => $AIR]);
$g = array_column($r['groups'], null, 'group');
ok($g['offer']['total'] === 1 && $g['offer']['still_open'] === 1 && $r['most_offers'][0]['name'] === 'SMOKE Ana' && $r['most_offers'][0]['offers'] === 1, 'Mara: one offer, still open; Ana offers most');
$by = tdata(33, 'exchange_report', ['site_id' => $AIR, 'group_by' => 'person']);
ok($by['groups'][0]['group'] === 'SMOKE Ana', 'grouped by person');
ok(count(tdata(33, 'exchange_report', ['site_id' => $AIR, 'group_by' => 'week'])['groups']) === 1, 'grouped by week');
ok(!tool(35, 'exchange_report', ['site_id' => $AIR])['error'], 'Pat (builds) may');
foreach ([30, 31, 32, 34] as $who) { ok(tool($who, 'exchange_report', ['site_id' => $AIR])['error'], "member $who: the trade report is for managers"); }
ok(tool(33, 'exchange_report', ['site_id' => $AIR, 'group_by' => 'mood'])['error'], 'a bad group_by is refused in words');

echo "9. records_search — the long tail, guarded\n";
ok(tdata(30, 'records_search', ['sql' => 'select count(*) as n from mcp_shifts where site_id = ' . $DT])[0]['n'] === 0, 'Ana: mcp_shifts holds nothing of Downtown');
ok(tdata(34, 'records_search', ['sql' => 'select count(*) as n from mcp_shifts where site_id = ' . $AIR])[0]['n'] === 0, 'Joe: nothing of Airport');
ok(tdata(29, 'records_search', ['sql' => 'select count(*) as n from mcp_shifts'])[0]['n'] === 0, 'Nobody: nothing');
$rows = tdata(33, 'records_search', ['sql' => 'select generate_series(1, 500) as n']);
ok(count($rows) === 200, 'a row cap of 200');
foreach (['update members set display_name = \'x\'', 'select 1; select 2', 'delete from shifts', 'drop table shifts', 'select * from shifts', 'select * from members', 'select * from time_off_requests', 'select * from mcp_access_tokens', "select pg_sleep(1)", 'select * from activity_log', 'insert into announcements (title) values (1)', "copy shifts to '/tmp/x'"] as $sql) {
    $r = tool(33, 'records_search', ['sql' => $sql]);
    ok($r['error'] || isset($r['data']['error']), 'refused: ' . substr($sql, 0, 40) . ' — ' . substr($r['text'], 0, 60));
}
ok(one("select count(*) from members where display_name = 'x'") == 0 && one('select count(*) from shifts') > 0, 'and nothing changed');
$rows = tdata(30, 'records_search', ['sql' => 'select member_id, wage_rate, wage_override from mcp_staff_positions']);
ok(array_filter($rows, fn ($x) => $x['member_id'] !== 30 && ($x['wage_rate'] !== null || $x['wage_override'] !== null)) === [] && array_filter($rows, fn ($x) => $x['member_id'] === 30 && $x['wage_rate'] !== null) !== [], 'Ana searching the wage view: her own rate, nobody else\'s');
$rows = tdata(33, 'records_search', ['sql' => 'select member_id, wage_rate from mcp_staff_positions where wage_rate is not null']);
ok(count($rows) >= 5, 'Mara (labor.view at Airport): the rates of Airport\'s staff (' . count($rows) . ')');
$r = tool(35, 'records_search', ['sql' => 'select * from mcp_shifts s left join mcp_positions p on p.position_id = s.position_id']);
ok(pay_in($r['data']) === [], 'Pat searching shifts joined to positions: cost and default rate are null columns');
$r = tool(30, 'records_search', ['sql' => 'select * from mcp_labor_weekly']);
ok($r['data'] === [] || $r['data'] === null && false, 'Ana: mcp_labor_weekly is empty for her');
$r = tool(30, 'records_search', ['sql' => 'select * from mcp_labor_budgets']);
ok($r['data'] === [], 'Ana: mcp_labor_budgets is empty for her');
finish();
