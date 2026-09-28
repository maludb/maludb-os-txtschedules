<?php
/**
 * A FAKE Business OS kernel for the proofs — `php -S 127.0.0.1:8192 tests/fake_kernel.php` — answering the few internal
 * endpoints txtSchedules calls, from the JSON state file $FAKE_KERNEL_STATE (the proofs rewrite it between steps):
 *   GET  /api/v1/directory/scopes.php   → {schema: os.directory-scopes/1, scopes: state.scopes}
 *   GET  /api/v1/directory/changes.php  → state.feed (os.directory-changes/1); ?since= answers state.incremental when set, else an empty change
 *   POST /api/v1/runs/facts.php {token} → state.facts[<run id>] (the run token's third part), else {valid: false}
 *   POST /api/v1/agents/chat.php        → state.chat (a canned reply), echoing the acting member and the bearer it saw
 * Every call needs Authorization: Bearer $OS_APPLICATION_TOKEN (else 401), like the real one. Never a real kernel.
 */
$state = json_decode((string) @file_get_contents((string) getenv('FAKE_KERNEL_STATE')), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$out = static function (array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body); exit; };
if ($auth !== 'Bearer ' . getenv('OS_APPLICATION_TOKEN')) { $out(['error' => ['code' => 'unauthorized', 'message' => 'A valid application token is required.']], 401); }
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
switch ($path) {
    case '/api/v1/directory/scopes.php':
        $out(['schema' => 'os.directory-scopes/1', 'scope_kind' => 'location', 'scopes' => $state['scopes'] ?? []]);
    case '/api/v1/directory/changes.php':
        if (isset($_GET['since']) && isset($state['incremental'])) { $out($state['incremental']); }
        if (isset($_GET['since'])) { $out(['schema' => 'os.directory-changes/1', 'since' => $_GET['since'], 'next' => $state['feed']['next'] ?? $_GET['since'], 'full' => false,
                                          'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [], 'access' => []]); }
        $out($state['feed'] ?? []);
    case '/api/v1/runs/facts.php':
        $parts = explode('.', (string) ($input['token'] ?? ''));
        $run = $parts[2] ?? '';
        $out($state['facts'][$run] ?? ['valid' => false]);
    case '/api/v1/agents/chat.php':
        if (isset($state['chat_status'])) { $out(['error' => ['code' => 'not_found', 'message' => 'No expert for this application.']], (int) $state['chat_status']); }
        $out(($state['chat'] ?? ['run_id' => 1, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Hello from the fake expert', 'actions' => []])
            + ['seen_acting_member' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null, 'seen_agent' => $_GET['agent'] ?? null, 'seen_utterance' => $input['utterance'] ?? null]);
}
$out(['error' => ['code' => 'not_found', 'message' => 'No such internal endpoint.']], 404);
