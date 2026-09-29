<?php
/**
 * Helpers for the Phase 3 slice 5 proofs (docs/build-specs/labor-forecast.md, "Proof"). Run through tests/phase3/slice5/run.sh: a fresh SCRATCH database (never the installed one), the
 * application on :8191, a FAKE kernel (which now answers K7's /api/v1/apps/read.php as the proof tells it) and a fake MaluDB. Everything a proof makes is named "SMOKE …"; nothing is sent.
 * Builds on slice 4's helpers. Every proof starts with reset5(): no forecast, no ratios, no budgets, the day-parts and rates as they ship, the kernel saying "no connection".
 * Each proof takes its OWN weeks far ahead (wk(n)), so one never disturbs another.
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** Airport's location in the kernel (scope 102 → location 11 in the fixture) and Downtown's. */
function loc_of(int $site): int { return (int) one('SELECT location_id FROM sites WHERE scope_id = :s', ['s' => $site]); }

/** Start clean: the slice's own tables emptied, the two shipped day-parts back, proof day-parts gone, the fixture's rates and areas back, the kernel unconnected. */
function reset5(): array
{
    $W = reset4();
    admin_sql("DELETE FROM forecast_covers; DELETE FROM staffing_ratios; DELETE FROM labor_budgets;
               DELETE FROM day_parts WHERE name LIKE 'SMOKE%';
               UPDATE day_parts SET archived_at = NULL, service_name = CASE key WHEN 'lunch' THEN 'Lunch' WHEN 'dinner' THEN 'Dinner' END WHERE key IN ('lunch', 'dinner');
               UPDATE sites SET location_id = scope_id - 91 WHERE scope_id IN (101, 102);
               UPDATE positions SET area = 'front' WHERE id IN ({$W['aSrv']}, {$W['dSrv']}); UPDATE positions SET area = 'bar' WHERE id = {$W['aBar']};
               DELETE FROM activity_log WHERE action IN ('forecast.update', 'forecast.copy', 'forecast.fill', 'ratio.update', 'budget.update') AND actor_member_id IS NULL;");
    kread(['mode' => 'no_connection']);
    @unlink(need('FAKE_KERNEL_STATE') . '.reads');
    return $W;
}
/** Tell the fake kernel how K7 answers: mode ok (rows, optional locations) | no_connection | provider_failed | garbage | not_shared. */
function kread(array $cfg): void { kernel_state(function ($s) use ($cfg) { $s['read'] = $cfg; return $s; }); }
/** What the fake kernel was asked, one decoded body per request, since the last clear. */
function kreads(): array { $f = need('FAKE_KERNEL_STATE') . '.reads'; return is_file($f) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : []; }

function dpid(int $site, string $key): int { return (int) one('SELECT id FROM day_parts WHERE scope_id = :s AND key = :k', ['s' => $site, 'k' => $key]); }
function cell(int $site, string $date, int $dp): ?array { $r = q('SELECT id, expected_covers, source, updated_by FROM forecast_covers WHERE scope_id = :s AND on_date = :d AND day_part_id = :p', ['s' => $site, 'd' => $date, 'p' => $dp]); return $r[0] ?? null; }
function put_cell(int $site, string $date, int $dp, int $covers, string $source): void { admin_sql("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source) VALUES ($site, '$date', $dp, $covers, '$source') ON CONFLICT (scope_id, on_date, day_part_id) DO UPDATE SET expected_covers = $covers, source = '$source'"); }
function ratio(int $site, int $pos): ?array { $r = q('SELECT covers_per_staff, min_staff FROM staffing_ratios WHERE scope_id = :s AND position_id = :p', ['s' => $site, 'p' => $pos]); return $r[0] ?? null; }
function budget_of(int $site, string $ws, string $area): ?array { $r = q('SELECT id, budget_hours, budget_amount FROM labor_budgets WHERE scope_id = :s AND week_start = :w AND area = :a', ['s' => $site, 'w' => $ws, 'a' => $area]); return $r[0] ?? null; }
function n_cells(int $site): int { return (int) one('SELECT count(*) FROM forecast_covers WHERE scope_id = :s', ['s' => $site]); }
/** The needs rows of one day, from the forecast screen's JSON (as a jar): [day_part => [position => row]]. */
function needs_of(string $jar, int $site, string $ws, string $date): array
{
    [, $d] = screen($jar, "/forecast?site=$site&week=$ws");
    $out = [];
    foreach ($d['needs'] ?? [] as $n) { if ($n['date'] === $date) { $out[$n['day_part']][$n['position']] = $n; } }
    return $out;
}
/** A settings-only person (settings.manage + schedule.build, NO labor.view) — a scratch role, member 37 at Airport. */
function setter_claims(): array
{
    $c = cast()['33'];
    $c['display_name'] = 'SMOKE Sol'; $c['email'] = 'sol@example.invalid'; $c['role'] = 'setter'; $c['roles'] = ['setter'];
    foreach ($c['scopes'] as &$s) { $s['role'] = 'setter'; $s['roles'] = ['setter']; }
    return $c;
}
function as_setter(): string
{
    admin_sql("INSERT INTO ts_roles (role_key, name, description, capability) VALUES ('setter', 'Setter', 'SMOKE settings without pay', 'write') ON CONFLICT DO NOTHING;
               INSERT INTO ts_role_rights (role_key, right_key) SELECT 'setter', r FROM unnest(ARRAY['schedule.view_own','availability.edit','market.trade','schedule.build','settings.manage']) r ON CONFLICT DO NOTHING;");
    [$j, $r] = sign_on(37, null, ['claims' => setter_claims()]);
    if ($r['code'] !== 302) { fwrite(STDERR, "setter sign-on failed {$r['code']}\n"); }
    return $j;
}
/** The cost figures a page must not show without labor.view (the fixture's scheduled cost in the budget proof). */
function cost_marks(): array { return ['147.66', '128.22', '92.76', '368.64', '275.88']; }
function money(float $n): string { return '$' . number_format($n, 2); }
