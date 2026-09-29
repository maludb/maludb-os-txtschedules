<?php
/** Proof — the manifest and the shell: the five actions and two screens of the slice are in the registry, built, their files exist; the placeholders are gone; nothing here names a wage column and cost is read in one place. */
require __DIR__ . '/lib.php';
$W = reset5();
$root = dirname(__DIR__, 3);
$priya = as_member(26); $mara = as_member(33); $pat = as_planner(); $owner = as_member(1, 102);
$out = []; exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $code);
ok($code === 0, 'bin/build_action_registry.php --check: the registry is current with the manifest (' . trim(implode(' ', $out)) . ')');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$acts = ['forecast_save', 'forecast_copy', 'forecast_fill', 'ratio_save', 'budget_save'];
$bad = [];
foreach ($acts as $a) { $r = $reg['actions'][$a] ?? null; if ($r === null || $r['built'] !== true || !is_file($root . '/html' . $r['endpoint']) || !str_starts_with($r['endpoint'], '/labor/')) { $bad[] = $a; } }
ok($bad === [], 'all five actions are built and their endpoint files exist under /labor/' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$bad = [];
foreach (['forecast', 'budget'] as $s) { $r = $reg['screens'][$s] ?? null; if ($r === null || $r['built'] !== true) { $bad[] = $s; } }
ok($bad === [], 'both screens are built in the registry');
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) >= 59, 'the registry counts at least 59 of 69 actions built (54 + 5; later slices add more)');
$params = fn (string $a): array => array_column($reg['actions'][$a]['params'], 'name');
ok($params('forecast_save') === ['site', 'on_date', 'day_part', 'expected_covers'] && in_array('replace_manual', $params('forecast_fill'), true) && $params('ratio_save') === ['site', 'position', 'covers_per_staff', 'min_staff'] && in_array('budget_amount', $params('budget_save'), true), 'the manifest\'s parameters reach the registry');
foreach ([['/forecast', $mara], ['/forecast', $pat], ['/budget', $mara], ['/budget', $owner]] as [$p, $jar]) {
    $html = req('GET', $p, ['jar' => $jar])['body'];
    ok(!str_contains($html, 'is not built yet') && !str_contains($html, 'slice 5') && str_contains($html, 'id="' . ($p === '/forecast' ? 'forecast' : 'budget') . '-header"'), "$p is no longer a placeholder");
}
$html = page($mara, '/')['body'];
ok(str_contains($html, 'id="nav-forecast"') && str_contains($html, 'id="nav-budget"'), 'the menu lists Forecast and Budget for a manager');
$html = page($pat, '/')['body'];
ok(str_contains($html, 'id="nav-forecast"') && !str_contains($html, 'id="nav-budget"'), 'for a planner (no pay) Forecast only — Budget is not offered');
$html = page($priya, '/')['body'];
ok(!str_contains($html, 'id="nav-forecast"') && !str_contains($html, 'id="nav-budget"'), 'and for staff neither');
$mf = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$granted = [];
foreach ($mf['agents'] as $a) { foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { $granted[$t] = true; } }
$unknown = array_values(array_filter(array_keys($granted), fn ($t) => !isset($reg['actions'][$t])));
ok($unknown === [], 'every Actions MCP tool an agent is granted exists in the registry' . ($unknown ? ' — ' . implode(', ', $unknown) : ''));
ok(($mf['reads'][0]['app'] ?? '') === 'reservations' && ($mf['reads'][0]['tool'] ?? '') === 'covers_by_service', 'maludb-os.json still declares the read of reservations.covers_by_service');
$mine = array_filter(explode("\n", trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && ls app/features/labor/*.php app/views/labor/*.php app/views/labor/partials/*.php html/forecast.php html/budget.php html/labor/*.php'))));
$wage = array_filter($mine, fn ($f) => preg_match('/wage_rate|wage_override|default_wage|ts_effective_rate/', (string) file_get_contents($root . '/' . $f)));
ok(count($mine) === 14 && $wage === [], count($mine) . ' files make up the slice and none names a wage column');
$found = array_filter(explode("\n", trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && grep -rlE "scheduled_cost|s\\.cost|\\.cost\\b|SUM\\(.*cost" app html --include=*.php'))));
sort($found);
ok(array_values(array_diff($found, ['app/features/labor/queries.php', 'app/features/labor/present.php', 'app/features/weeks/queries.php', 'app/views/builder/grid.php', 'app/views/builder/publish.php', 'app/views/labor/budget.php', 'app/features/weeks/publish.php', 'html/builder.php', 'html/budget.php', 'html/weeks/publish-confirm.php', 'app/features/weeks/present.php', 'app/features/shifts/queries.php', 'app/features/shifts/present.php'])) === [], 'cost is named only in the labor files, the builder and its publish page: ' . implode(', ', $found));
$stub = (string) file_get_contents($root . '/app/features/shell/nav.php');
ok(!str_contains($stub, 'Labor and forecast (slice 5)'), 'the shell\'s placeholder text for this slice is gone');
finish();
