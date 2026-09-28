<?php
// php bin/dev_handoff.php <member_id> [scope_id] — prints a /sso URL signed exactly as the kernel signs one, from the
// claims in bin/dev_directory.json (testing-without-a-kernel.md). Development only: needs a development
// ACTION_TOKEN_KEY (openssl rand -hex 32); the installer replaces it with the tenant's, so it never opens a real install.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/app/bootstrap.php';
$member = (int) ($argv[1] ?? 1);
$scope = isset($argv[2]) ? (int) $argv[2] : null;
$key = action_token_key();
$app = app_key();
$payload = $member . '.' . (time() + 60) . '.' . $app . '.' . bin2hex(random_bytes(16));
$token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
$fixture = json_decode((string) file_get_contents(__DIR__ . '/dev_directory.json'), true);
$claims = ($fixture['claims'][(string) $member] ?? []) + ['member_id' => $member];
$claims['scope'] = $scope;
$text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
echo rtrim((string) env('APP_URL'), '/') . '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]) . "\n";
