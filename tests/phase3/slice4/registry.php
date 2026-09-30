<?php
/** Proof — the manifest and the shell: the eleven actions and ten screens of the slice are in the registry, built, their files exist; the placeholders are gone; no wage escapes the files that own it. */
require __DIR__ . '/lib.php';
$W = reset4();
$root = dirname(__DIR__, 3);
$priya = as_member(26); $mara = as_member(33); $owner = as_member(1, 102);
$out = []; exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $code);
ok($code === 0, 'bin/build_action_registry.php --check: the registry is current with the manifest (' . trim(implode(' ', $out)) . ')');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$acts = ['staff_save', 'wage_update', 'certification_add', 'certification_update', 'certification_remove', 'certification_verify', 'certification_kind_save', 'certification_kind_archive', 'position_save', 'position_rate_update', 'position_archive'];
$bad = [];
foreach ($acts as $a) { $r = $reg['actions'][$a] ?? null; if ($r === null || $r['built'] !== true || !is_file($root . '/html' . $r['endpoint'])) { $bad[] = $a; } }
ok($bad === [], 'all eleven actions are built and their endpoint files exist' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$screens = ['staff-list', 'staff-view', 'staff-edit', 'positions-list', 'position-add', 'position-edit', 'certifications', 'certification-kind-add', 'certification-kind-edit', 'my-certifications'];
$bad = [];
foreach ($screens as $s) { $r = $reg['screens'][$s] ?? null; if ($r === null || $r['built'] !== true) { $bad[] = $s; } }
ok($bad === [], 'all ten screens are built in the registry' . ($bad ? ' — ' . implode(', ', $bad) : ''));
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) >= 54, 'the registry counts at least 54 of 69 actions built (43 + 11 at slice 4; later slices add more)');
$params = fn (string $a): array => array_column($reg['actions'][$a]['params'], 'name');
ok($params('wage_update') === ['member', 'position', 'rate'] && in_array('positions', $params('staff_save'), true) && in_array('certifications', $params('position_save'), true), 'the manifest\'s parameters reach the registry (member, position, rate; positions; certifications)');
foreach ([['/staff/', $mara], ['/positions/', $mara], ['/certifications/', $mara], ['/certifications/mine', $priya], ['/staff/', $owner]] as [$p, $jar]) {
    $html = req('GET', $p, ['jar' => $jar])['body'];
    ok(!str_contains($html, 'is not built yet') && !str_contains($html, 'slice 4'), "$p is no longer a placeholder");
}
$html = page($mara, '/')['body'];
ok(str_contains($html, 'id="nav-staff-list"') && str_contains($html, 'id="nav-positions-list"') && str_contains($html, 'id="nav-certifications"') && str_contains($html, 'id="nav-my-certifications"'), 'the menu lists Staff, Positions, Certifications and My certifications for a manager');
$html = page($priya, '/')['body'];
ok(!str_contains($html, 'id="nav-staff-list"') && str_contains($html, 'id="nav-my-certifications"'), 'and for staff only My certifications');
$mf = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$granted = [];
foreach ($mf['agents'] as $a) { foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { $granted[$t] = true; } }
$unknown = array_values(array_filter(array_keys($granted), fn ($t) => !isset($reg['actions'][$t])));
ok($unknown === [], 'every Actions MCP tool an agent is granted exists in the registry' . ($unknown ? ' — ' . implode(', ', $unknown) : ''));
$own = ['app/features/positions/queries.php', 'app/features/staff/queries.php', 'app/features/staff/present.php', 'app/views/positions/partials/card.php', 'html/positions/index.php', 'html/staff/wage.php', 'mcp/ts_people.py'];
$found = array_filter(explode("\n", trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && grep -rlE "wage_rate|wage_override|default_wage" app html bin mcp --include=*.php --include=*.py'))));
sort($own); $found = array_values($found); sort($found);
ok($found === $own, 'only these seven files name a wage column — the two query files, the presenter, the position card, the positions screen and wage.php: ' . implode(', ', $found));
$src = shell_exec('grep -rnE "UPDATE +staff_positions +SET +wage_override|UPDATE +positions +SET +default_wage" ' . escapeshellarg($root . '/app') . ' ' . escapeshellarg($root . '/html') . ' 2>/dev/null');
ok(substr_count((string) $src, "\n") === 2, 'exactly two statements write a rate: set_wage_override() and set_position_rate()');
$code = (string) file_get_contents($root . '/app/features/staff/queries.php');
$list = substr($code, (int) strpos($code, 'function find_staff('), (int) strpos($code, 'function find_staff_member') - (int) strpos($code, 'function find_staff('));
ok(strlen($list) > 500 && !str_contains($list, 'wage'), 'find_staff() — the list query — names no wage column at all');
$src = shell_exec('grep -rnE "log_activity\(.*wage_|wage_rate.*log_activity" ' . escapeshellarg($root . '/html') . ' ' . escapeshellarg($root . '/app') . ' 2>/dev/null');
ok(trim((string) $src) === '', 'no log_activity call mentions a wage column');
finish();
