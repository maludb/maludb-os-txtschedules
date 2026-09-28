<?php
/**
 * Proof: txtSchedules speaks the kernel's REAL wire formats. The kernel's own functions (read from /var/www/app/auth.php,
 * never modified, run under a shim that supplies the scratch ACTION_TOKEN_KEY) mint a hand-off token, its signed claims, a
 * sign-out notice, a person's action token and a kernel token; this application must accept exactly those. Catches drift between
 * the copied verifiers here and the kernel's minting side. Run through tests/phase2/run.sh (after the servers are up).
 */
require __DIR__ . '/lib.php';
$src = file_get_contents('/var/www/app/auth.php');
$grab = function (string $name) use ($src): string {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $src, $m)) { fwrite(STDERR, "cannot find $name in the kernel's app/auth.php\n"); exit(2); }
    return $m[0];
};
if (!function_exists('env')) { function env(string $k, ?string $d = null): ?string { $v = getenv($k); return $v === false ? $d : $v; } }
// ts_-prefix the kernel's names so they can live beside this application's own verifiers in one process
$code = '';
foreach (['base64url_encode', 'mint_sso_token', 'sign_sso_claims', 'mint_sso_logout_notice', 'mint_action_token', 'mint_kernel_token', 'action_token_key'] as $fn) {
    $code .= preg_replace('/^function ' . $fn . '\(/m', 'function kernel_' . $fn . '(', $grab($fn)) . "\n";
}
$code = preg_replace('/\b(base64url_encode|mint_sso_token|sign_sso_claims|mint_sso_logout_notice|mint_action_token|mint_kernel_token|action_token_key)\(/', 'kernel_$1(', $code);
$code = preg_replace('/function kernel_kernel_/', 'function kernel_', $code);
eval($code);

echo "The kernel mints; txtSchedules accepts\n";
$claims = ['member_id' => 27, 'display_name' => 'Kernel Minted', 'email' => 'km@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [],
    'capability' => 'write', 'role' => null, 'roles' => ['manager', 'staff'], 'rights' => ['schedule.build'],
    'scopes' => [['scope_id' => 101, 'kind' => 'location', 'id' => 10, 'name' => 'SMOKE Downtown', 'role' => 'manager', 'roles' => ['manager', 'staff'], 'rights' => ['schedule.build'], 'capability' => 'write']], 'scope' => 101];
$url = '/sso?' . http_build_query(['token' => kernel_mint_sso_token(27, 'txtschedules', 60), 'claims' => kernel_sign_sso_claims($claims)]);
$j = jar();
$r = req('GET', $url, ['jar' => $j]);
ok($r['code'] === 302 && $r['location'] === '/', "a token and claims minted by the kernel's mint_sso_token() and sign_sso_claims(): 302 to / ({$r['code']})");
ok(site_of($j) === 'SMOKE Downtown' && q("SELECT display_name FROM members WHERE id = 27")[0]['display_name'] === 'Kernel Minted', 'and the session is open at the claimed site, the mirror follows the claims');
$r = req('GET', '/sso?' . http_build_query(['token' => kernel_mint_sso_token(27, 'hr', 60), 'claims' => kernel_sign_sso_claims($claims)]), ['jar' => jar()]);
ok($r['code'] === 403, 'the kernel\'s token for another application: 403');
$notice = kernel_mint_sso_logout_notice(27, 'txtschedules');
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice]]);
ok($r['code'] === 204 && page($j, '/')['code'] === 302, "the kernel's mint_sso_logout_notice(): 204 and the session ended");
$t = kernel_mint_action_token(26, 300);
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $t]]);
ok($r['code'] === 200, "the kernel's mint_action_token() is honoured as a person's action token (200)");
$kt = kernel_mint_kernel_token('txtschedules', 60);
ok(count(explode('.', $kt)) === 5 && explode('.', $kt)[0] === 'kernel' && hash_equals(hash_hmac('sha256', 'kernel:' . implode('.', array_slice(explode('.', $kt), 1, 3)), need('ACTION_TOKEN_KEY')), explode('.', $kt)[4]),
   'the kernel token has the shape the records MCP will verify in Phase 4 (kernel.exp.app.nonce.hmac over "kernel:…")');
finish();
