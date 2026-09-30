<?php
/**
 * Proof — the wage rule, by grep. A rate (a position's default, a person's own, the effective one), a shift's cost, a budget: an answer carries one only for a
 * caller who holds labor.view at that restaurant — or, for a person, their OWN effective rate. Proved four ways:
 *   1. the views' definitions: every view that mentions pay mentions labor.view or the caller's own id (read from pg_get_viewdef, not from the migration text);
 *   2. the servers' source: every table or view they read is an mcp_* view, a gated function, or the roles catalogue — never a base table;
 *   3. a battery of EVERY records tool (with the arguments that ask for the most) run as eleven callers, people and agents, without labor.view, and as three with:
 *      no pay key carries a value (bar the caller's own effective rate on their own rows) and none of the fixture rates — each person's own rate is distinct — appears in the text;
 *   4. the positive control: the callers with labor.view do get numbers, for their own restaurant only (Marco: Downtown's, not Airport's).
 */
require __DIR__ . '/lib.php';
$W = reset_p4();
$AIR = 102; $DT = 101; $srv = $W['aSrv']; $bar = $W['aBar']; $dsrv = $W['dSrv'];
$FRI = next_dow('America/Chicago', 5); $FRI_DT = next_dow('America/New_York', 5);
foreach ([$AIR => $FRI, $DT => $FRI_DT] as $site => $d) { clear_day($site, $d); }
// every person's own rate distinct, so a leak is recognisable by its digits
admin_sql("UPDATE staff_positions SET wage_override = NULL; UPDATE staff_positions SET wage_override = 24.61 WHERE member_id = 26 AND position_id = $srv;
           UPDATE staff_positions SET wage_override = 26.99 WHERE member_id = 31 AND position_id = $srv; UPDATE staff_positions SET wage_override = 27.31 WHERE member_id = 32 AND position_id = $srv;
           UPDATE staff_positions SET wage_override = 19.77 WHERE member_id = 34 AND position_id = $dsrv;
           DELETE FROM labor_budgets; INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount) VALUES ($AIR, '" . monday_of(new DateTimeImmutable($FRI), 'America/Chicago') . "', 'all', 187.5, 4321.09), ($DT, '" . monday_of(new DateTimeImmutable($FRI_DT), 'America/New_York') . "', 'all', 55.5, 2222.22);");
$ana = local_shift($AIR, $srv, 30, $FRI, '17:00', '22:00'); $lee = local_shift($AIR, $srv, 31, $FRI, '11:00', '15:00'); $dana = local_shift($AIR, $srv, 32, $FRI, '17:00', '22:00'); $open = local_shift($AIR, $srv, null, $FRI, '17:00', '22:00');
$joe = local_shift($DT, $dsrv, 34, $FRI_DT, '12:00', '16:00'); $pri = local_shift($AIR, $srv, 26, $FRI, '06:00', '10:00');
$ALL = ['21.37', '24.61', '23.19', '22.83', '26.99', '27.31', '19.77', '4321.09', '2222.22', '187.5', '55.5'];
$OWN = [30 => ['21.37', '23.19'], 26 => ['24.61'], 31 => ['26.99'], 32 => ['27.31'], 34 => ['19.77'], 27 => ['22.83', '21.37'], 35 => [], 29 => [], 1 => $ALL, 33 => $ALL];

echo "1. The views' own definitions\n";
$views = q("SELECT viewname, definition FROM pg_views WHERE schemaname = 'public' AND viewname LIKE 'mcp\\_%' ORDER BY 1");
$paid = [];
foreach ($views as $v) {
    $d = strtolower($v['definition']);
    $mentions = (bool) preg_match('/wage|cost|budget_amount|default_wage/', $d);
    if ($mentions) { $paid[] = $v['viewname']; }
    if ($mentions) { ok(str_contains($d, "'labor.view'"), $v['viewname'] . ' mentions pay and asks labor.view'); }
}
ok(count($paid) === 5, 'exactly five views carry pay (mcp_positions, mcp_staff_positions, mcp_shifts, mcp_labor_budgets, mcp_labor_weekly): ' . implode(', ', $paid));
foreach (['mcp_staff_positions'] as $vn) { $d = strtolower(q("SELECT definition FROM pg_views WHERE viewname = '$vn'")[0]['definition']); ok(str_contains($d, 'app_current_member_id()'), "$vn also lets a person read their own rate"); }
ok(!array_filter($views, fn ($v) => $v['viewname'] === 'mcp_time_off_ledger' && preg_match('/wage|cost/', strtolower($v['definition']))), 'the time-off ledger is hours, not pay');

echo "2. What the servers' source reads\n";
$allowed = ['mcp_sites', 'mcp_members', 'mcp_member_site_roles', 'mcp_positions', 'mcp_staff', 'mcp_staff_positions', 'mcp_certification_kinds', 'mcp_certifications', 'mcp_certifications_due', 'mcp_availability',
    'mcp_time_off_types', 'mcp_time_off_balances', 'mcp_time_off_ledger', 'mcp_time_off_requests', 'mcp_blackout_dates', 'mcp_schedule_weeks', 'mcp_shifts', 'mcp_templates', 'mcp_template_shifts', 'mcp_exchanges',
    'mcp_exchange_claims', 'mcp_exchange_invitees', 'mcp_site_rules', 'mcp_rule_overrides', 'mcp_labor_budgets', 'mcp_labor_weekly', 'mcp_day_parts', 'mcp_forecast_covers', 'mcp_staffing_ratios', 'mcp_announcements',
    'mcp_hours_weekly', 'mcp_activity_log', 'mcp_app_roles', 'ts_rights', 'ts_check_assignment', 'ts_coverage_candidates', 'ts_week_warnings', 'ts_staffing_needs', 'ts_scopes_with_right', 'ts_time_off_taken', 'ts_has_right',
    'generate_series', 'jsonb_array_elements', 'unnest'];
$found = [];
foreach (glob('mcp/{ts_*,records_server,activity_server}.py', GLOB_BRACE) as $f) {
    preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/', file_get_contents($f), $m);       // SQL is upper-case here; python's from-import and prose are not
    foreach ($m[1] as $t) { $found[strtolower($t)][] = basename($f); }
}
$bad = array_values(array_diff(array_keys($found), $allowed));
ok($bad === [], 'every FROM / JOIN in the servers names an mcp_* view, a gated function or the roles catalogue' . ($bad ? ' — NOT: ' . implode(', ', $bad) : ' (' . count($found) . ' distinct)'));
foreach (['positions', 'staff_positions', 'shifts', 'labor_budgets', 'time_off_requests', 'members', 'activity_log', 'exchanges', 'site_settings'] as $base) {
    ok(!isset($found[$base]), "no server reads the base table $base");
}
$src = implode("\n", array_map('file_get_contents', glob('mcp/{ts_*,records_server,activity_server}.py', GLOB_BRACE)));
ok(!preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE)\s+(INTO|FROM|TABLE|\w+\s+SET)/', $src), 'no write statement anywhere in the servers\' SQL');
ok(!preg_match('/ts_effective_rate/', $src), 'ts_effective_rate is not used (the views inline the same COALESCE, gated)');

echo "3. The battery — every records tool, the arguments that ask for the most\n";
$battery = [
    ['find_sites', []], ['find_staff', ['limit' => 100]], ['find_staff', ['site_id' => $AIR, 'limit' => 100]], ['find_positions', ['include_archived' => true]], ['find_positions', ['site_id' => $DT]], ['find_positions', ['site_id' => $AIR]],
    ['staff_profile', []], ['staff_profile', ['member_id' => 26]], ['staff_profile', ['member_id' => 31]], ['staff_profile', ['member_id' => 32]], ['staff_profile', ['member_id' => 34]], ['staff_profile', ['member_id' => 30]], ['staff_profile', ['member_id' => 1]],
    ['certification_kinds', []], ['certifications', ['member_id' => 30]], ['certifications_due', []],
    ['who_is_on', ['site_id' => $AIR, 'date' => $FRI]], ['who_is_on', ['site_id' => $DT, 'date' => $FRI_DT]], ['my_shifts', ['from' => $FRI, 'to' => $FRI]], ['my_shifts', ['member_id' => 30, 'from' => $FRI, 'to' => $FRI]],
    ['week_schedule', ['site_id' => $AIR, 'week_start' => $FRI]], ['week_schedule', ['site_id' => $DT, 'week_start' => $FRI_DT]], ['week_schedule', ['q' => 'Ana']], ['week_schedule', ['q' => 'Joe']],
    ['get_shift', ['shift_id' => $ana]], ['get_shift', ['shift_id' => $lee]], ['get_shift', ['shift_id' => $joe]], ['get_shift', ['shift_id' => $open]], ['find_templates', []], ['marketplace', ['status' => 'all']],
    ['coverage_candidates', ['shift_id' => $open]], ['check_assignment', ['member_id' => 31, 'shift_id' => $open]], ['week_warnings', ['site_id' => $AIR, 'week_start' => $FRI]], ['overrides', []], ['hours_this_week', ['near_overtime' => true]], ['hours_this_week', ['member_id' => 30]],
    ['availability', []], ['time_off', []], ['time_off_balances', ['member_id' => 30, 'ledger' => true]], ['my_requests', []], ['pending_requests', []],
    ['labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI]], ['labor_vs_budget', ['site_id' => $DT, 'week_start' => $FRI_DT]], ['labor_vs_budget', ['site_id' => $AIR, 'week_start' => $FRI, 'by' => 'day']], ['labor_vs_budget', ['site_id' => $DT, 'by' => 'day']],
    ['staffing_needs', ['site_id' => $AIR, 'from' => $FRI, 'to' => $FRI]], ['site_settings', ['site_id' => $AIR]], ['site_settings', ['site_id' => $DT]], ['site_rules', ['site_id' => $AIR]], ['announcements', []], ['exchange_report', ['site_id' => $AIR]],
    ['records_search', ['sql' => 'select * from mcp_staff_positions']], ['records_search', ['sql' => 'select * from mcp_positions']], ['records_search', ['sql' => 'select * from mcp_shifts']], ['records_search', ['sql' => 'select * from mcp_labor_weekly']],
    ['records_search', ['sql' => 'select * from mcp_labor_budgets']], ['records_search', ['sql' => 'select * from mcp_staff']], ['records_search', ['sql' => 'select * from mcp_sites']], ['records_search', ['sql' => 'select * from mcp_members']],
    ['records_search', ['sql' => 'select s.*, p.* from mcp_shifts s join mcp_positions p on p.position_id = s.position_id']], ['records_search', ['sql' => 'select * from mcp_hours_weekly']], ['records_search', ['sql' => "select to_jsonb(t) as j from mcp_staff_positions t"]],
];
$recTools = mcp_tools(REC, person_token(33));
ok(count($battery) >= 60 && !array_diff($recTools, array_merge(array_unique(array_column($battery, 0)), ['app_roles'])), 'the battery calls all ' . (count($recTools) - 1) . ' records tools (with app_roles the catalogue) — ' . count($battery) . ' calls per caller');
make_agent(952, 'SMOKE Helper P4', [$AIR => 'staff']); make_agent(951, 'SMOKE Scheduler P4', [$AIR => 'manager']);
$runs = [];
foreach ([952, 951] as $ag) { $r = random_int(900000, 949999) + $ag; $runs[$ag] = run_token($ag, $r); facts($r, $ag, ['Records MCP' => array_fill_keys($recTools, [])]); }
$callers = [
    'Ana (staff, Airport)' => [fn ($t, $a) => mcp_tool(REC, person_token(30), $t, $a), 30, false], 'Lee (staff)' => [fn ($t, $a) => mcp_tool(REC, person_token(31), $t, $a), 31, false],
    'Priya (staff, own override)' => [fn ($t, $a) => mcp_tool(REC, person_token(26), $t, $a), 26, false], 'Dana (shift lead)' => [fn ($t, $a) => mcp_tool(REC, person_token(32), $t, $a), 32, false],
    'Pat (planner: builds, no pay)' => [fn ($t, $a) => mcp_tool(REC, person_token(35), $t, $a), 35, false], 'Joe (staff, Downtown)' => [fn ($t, $a) => mcp_tool(REC, person_token(34), $t, $a), 34, false],
    'Nobody (no restaurant)' => [fn ($t, $a) => mcp_tool(REC, person_token(29), $t, $a), 29, false],
    'Ana through the command bar (action token)' => [fn ($t, $a) => mcp_tool(REC, action_token(30), $t, $a), 30, false],
    'the staff AGENT (run token, every tool granted)' => [fn ($t, $a) => mcp_tool(REC, $runs[952], $t, $a), 952, false],
    'Marco (manager Downtown, staff Airport)' => [fn ($t, $a) => mcp_tool(REC, person_token(27), $t, $a), 27, 'DT'],
    'Mara (manager Airport)' => [fn ($t, $a) => mcp_tool(REC, person_token(33), $t, $a), 33, 'AIR'], 'the manager AGENT (run token)' => [fn ($t, $a) => mcp_tool(REC, $runs[951], $t, $a), 951, 'AIR'],
    'the owner' => [fn ($t, $a) => mcp_tool(REC, person_token(1), $t, $a), 1, 'BOTH'],
];
$positive = [];
foreach ($callers as $label => [$call, $who, $labor]) {
    $leaks = []; $payKeys = []; $seen = 0; $errors = 0;
    foreach ($battery as [$t, $a]) {
        $r = $call($t, $a);
        if ($r['http'] !== 200) { ok(false, "$label: $t answered HTTP {$r['http']}"); continue; }
        $seen++;
        if ($r['error']) { $errors++; }
        $pay = pay_in($r['data']);
        // one's own effective rate is allowed on one's own rows: staff_profile's positions of the caller, and search rows about them
        foreach ($pay as $path => $v) {
            $own = ($t === 'staff_profile' && (($a['member_id'] ?? $who) === $who) && str_contains($path, 'effective_rate'))
                || ($t === 'staff_profile' && (($a['member_id'] ?? $who) === $who) && str_contains($path, 'wage_override'))
                || (in_array($t, ['records_search'], true) && $labor === false);
            if ($labor === false && !$own) { $payKeys[] = "$t $path=$v"; }
        }
        if ($t === 'records_search' && $labor === false) {          // rows of a search: allowed only where the row is the caller's own
            foreach (($r['data'] ?? []) as $row) {
                if (!is_array($row)) { continue; }
                foreach (PAY_KEYS as $k) { if (isset($row[$k]) && $row[$k] !== null && (int) ($row['member_id'] ?? -1) !== $who) { $payKeys[] = "$t row of " . ($row['member_id'] ?? '?') . " $k"; } }
                if (isset($row['j']) && is_array($row['j'])) { foreach (PAY_KEYS as $k) { if (isset($row['j'][$k]) && (int) $row['j']['member_id'] !== $who) { $payKeys[] = "$t json row of " . $row['j']['member_id']; } } }
            }
        }
        if ($labor === false) {
            foreach (array_diff($ALL, $OWN[$who] ?? []) as $w) { if (str_contains($r['text'], $w)) { $leaks[] = "$t has $w"; } }
        } else {
            if ($pay) { $positive[$label] = ($positive[$label] ?? 0) + 1; }
            if ($labor === 'AIR' || $labor === 'DT') {                   // only their own restaurant's numbers: no other restaurant's rates or budget
                $foreign = $labor === 'AIR' ? ['22.83', '19.77', '2222.22', '55.5'] : ['23.19', '24.61', '26.99', '27.31', '4321.09', '187.5'];
                foreach ($foreign as $w) { if (str_contains($r['text'], $w)) { $leaks[] = "$t has another restaurant's $w"; } }
            }
        }
    }
    ok($seen === count($battery) && $payKeys === [] && $leaks === [], ($labor === false ? "$label: $seen answers, no pay key with a value, none of the eleven fixture numbers (bar their own)" : "$label: $seen answers, only their own restaurant's numbers")
        . ($payKeys ? ' — PAY: ' . implode('; ', array_slice($payKeys, 0, 4)) : '') . ($leaks ? ' — LEAK: ' . implode('; ', array_slice($leaks, 0, 4)) : '') . " ($errors refusals in words)");
}

echo "4. The positive control\n";
foreach (['Mara (manager Airport)', 'the manager AGENT (run token)', 'the owner', 'Marco (manager Downtown, staff Airport)'] as $l) { ok(($positive[$l] ?? 0) >= 8, "$l is given numbers by " . ($positive[$l] ?? 0) . ' of the answers (labor.view at their restaurant)'); }
$r = tool(27, 'labor_vs_budget', ['site_id' => $DT, 'week_start' => $FRI_DT]);
ok(!$r['error'] && str_contains($r['text'], '2222.22') && !str_contains($r['text'], '4321.09'), 'Marco: Downtown\'s budget yes, Airport\'s no');
$r = tool(27, 'staff_profile', ['member_id' => 34]);
ok(array_column($r['data']['positions'], 'effective_rate', 'position') == ['Server' => 19.77], 'Marco reads Joe\'s rate (labor.view at Downtown) — the override, effective');
$r = tool(27, 'staff_profile', ['member_id' => 26]);
ok($r['data']['positions'][0]['effective_rate'] === null || $r['error'], 'but not Priya\'s (Airport: he is staff there)');
finish();
