<?php
/** Proof driver — the kernel's Actions MCP against a stubbed hook (tests/phase4/hook.py, run under the KERNEL's venv). Sets the fixtures the resolver needs and passes their ids in the environment. */
require __DIR__ . '/lib.php';
$W = reset_p4();
$AIR = 102; $srv = $W['aSrv'];
$FRI = next_dow('America/Chicago', 5);
clear_day($AIR, $FRI);
foreach ([30 => 'Ana'] as $m => $n) {}
$ana = local_shift($AIR, $srv, 30, $FRI, '17:00', '22:00');
local_shift($AIR, $srv, 32, $FRI, '17:00', '22:00'); local_shift($AIR, $srv, 31, $FRI, '11:00', '15:00');
$week = (int) one('SELECT week_id FROM shifts WHERE id = :s', ['s' => $ana]);
// Mara's token: she is a manager at Airport only — 'Server' is one position there
$env = ['P4_MARA_TOKEN' => person_token(33), 'P4_ANA_SHIFT' => (string) $ana, 'P4_ANA_ID' => '30', 'P4_SRV' => (string) $srv, 'P4_FRI' => $FRI, 'P4_WEEK' => (string) $week, 'P4_REC_PORT' => (string) REC];
$cmd = '';
foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg($v) . ' '; }
passthru($cmd . '/var/www/mcp/venv/bin/python ' . escapeshellarg(__DIR__ . '/hook.py') . ' 2>&1', $code);
exit($code);
