<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6): GET-only /api/v1/*.php, a session or a Bearer token with
 * scope 'api'. One 401 body for every failure. CORS from an allow-list, never with credentials.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('api error: ' . $e->getMessage());
    if (!headers_sent()) {
        api_error('server_error', 'Something went wrong.', 500);
    }
    exit;
});

function api_json(array $payload, int $status = 200): never
{
    json_response($payload, $status);
}

function api_error(string $code, string $message, int $status): never
{
    json_error($code, $message, $status);
}

function api_cors(): void
{
    header('Vary: Origin');
    $configured = array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('method_not_allowed', 'Only GET is supported.', 405);
    }
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
}

/** The caller as a member row, or 401 — a session first, then an `api` token. */
function api_authenticate(): array
{
    $pdo = db();
    if (is_logged_in() && empty($GLOBALS['__action_authed'])) {
        $member = current_member();
        if ($member !== null && $member['status'] === 'active' && $member['capability'] !== null) {
            return $member;
        }
    }
    $token = api_bearer_token();
    if ($token === '') {
        api_error('unauthorized', 'A valid API token is required.', 401);
    }
    $st = $pdo->prepare("SELECT member_id FROM mcp_resolve_token(:h, 'api')");
    $st->execute(['h' => hash('sha256', $token)]);
    $mid = $st->fetchColumn();
    $member = $mid === false ? null : find_member_by_id($pdo, (int) $mid);
    if ($member === null) {
        api_error('unauthorized', 'A valid API token is required.', 401);
    }
    $_SESSION['member_id'] = (int) $member['id'];
    $GLOBALS['__api_token_authed'] = true;
    header_remove('Set-Cookie');
    db_apply_context($pdo);
    return $member;
}
