<?php
/** Proof — the manifest and the shell: the thirteen actions and six screens of the slice are in the registry, built, and their files exist; the placeholders that belonged to this slice are gone. */
require __DIR__ . '/lib.php';
$W = reset3();
$root = dirname(__DIR__, 3);
$priya = as_member(26); $mara = as_member(33); $owner = as_member(1, 102);
$out = []; exec('php ' . escapeshellarg($root . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $code);
ok($code === 0, 'bin/build_action_registry.php --check: the registry is current with the manifest (' . trim(implode(' ', $out)) . ')');
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$acts = ['availability_submit', 'availability_remove', 'availability_approve', 'availability_decline', 'time_off_request', 'time_off_approve', 'time_off_decline', 'time_off_cancel', 'balance_adjust', 'time_off_type_save', 'time_off_type_archive', 'blackout_save', 'blackout_remove'];
$bad = [];
foreach ($acts as $a) { $r = $reg['actions'][$a] ?? null; if ($r === null || $r['built'] !== true || !is_file($root . '/html' . $r['endpoint'])) { $bad[] = $a; } }
ok($bad === [], 'all thirteen actions are built and their endpoint files exist' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$screens = ['availability', 'time-off', 'time-off-add', 'time-off-view', 'balances', 'time-off-types'];
$bad = [];
foreach ($screens as $s) { $r = $reg['screens'][$s] ?? null; if ($r === null || $r['built'] !== true) { $bad[] = $s; } }
ok($bad === [], 'all six screens are built in the registry' . ($bad ? ' — ' . implode(', ', $bad) : ''));
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) >= 43, 'the registry counts at least 43 of 69 actions built (30 + 13; later slices add more)');
$params = fn (string $a): array => array_column($reg['actions'][$a]['params'], 'name');
ok($params('time_off_approve') === ['request', 'note', 'open_shifts'] && in_array('member', $params('time_off_request'), true) && in_array('hours', $params('time_off_request'), true), 'the manifest\'s parameters reach the registry (open_shifts, member, hours)');
foreach ([['/availability', $priya], ['/time-off', $priya], ['/availability', $mara], ['/time-off', $owner]] as [$p, $jar]) {
    $html = req('GET', $p, ['jar' => $jar])['body'];
    ok(!str_contains($html, 'is not built yet') && !str_contains($html, 'slice 3'), "$p is no longer a placeholder");
}
$html = page($priya, '/')['body'];
ok(str_contains($html, 'id="nav-availability"') && str_contains($html, 'id="nav-time-off"'), 'the menu lists Availability and Time off for staff');
$mf = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$granted = [];
foreach ($mf['agents'] as $a) { foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { $granted[$t] = true; } }
$unknown = array_values(array_filter(array_keys($granted), fn ($t) => !isset($reg['actions'][$t])));
ok($unknown === [], 'every Actions MCP tool an agent is granted exists in the registry' . ($unknown ? ' — ' . implode(', ', $unknown) : ''));
$src = shell_exec('grep -rnE "UPDATE +time_off_balances|INSERT +INTO +time_off_ledger|INSERT +INTO +time_off_balances" ' . escapeshellarg($root . '/app') . ' ' . escapeshellarg($root . '/html') . ' 2>/dev/null');
ok(trim((string) $src) === '', 'no PHP writes a balance or the ledger directly: they move only through ts_time_off_post()');
$src = shell_exec('grep -rnE "time_off_ledger|time_off_balances" ' . escapeshellarg($root . '/html') . ' 2>/dev/null');
ok(trim((string) $src) === '', 'no handler names the ledger tables at all');
finish();
