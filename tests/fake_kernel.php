<?php
/**
 * A FAKE Business OS kernel for the proofs — `php -S 127.0.0.1:8192 tests/fake_kernel.php` — answering the few internal
 * endpoints txtSchedules calls, from the JSON state file $FAKE_KERNEL_STATE (the proofs rewrite it between steps):
 *   GET  /api/v1/directory/scopes.php   → {schema: os.directory-scopes/1, scopes: state.scopes}
 *   GET  /api/v1/directory/changes.php  → state.feed (os.directory-changes/1); ?since= answers state.incremental when set, else an empty change
 *   POST /api/v1/runs/facts.php {token} → state.facts[<run id>] (the run token's third part), else {valid: false}
 *   POST /api/v1/agents/chat.php        → state.chat (a canned reply), echoing the acting member and the bearer it saw
 *   POST /api/v1/apps/read.php          → K7, as the state says (state.read: mode ok | list | no_connection | provider_failed | garbage | negative | string | not_shared; rows; locations = the sites Reservations serves);
 *                                         every request body is appended to "<state file>.reads" (one JSON line each) so a proof can see exactly what was asked
 *  POST /api/v1/notify/sms.php         → K6, as the state says (state.sms: mode = ok | no_sender | not_held | no_verified_phone | opted_out | rate_limited | server_error; members = per-member mode; limit = texts a member may get
 *                                         before rate_limited, like the kernel's 30 a day); every request body is appended to "<state file>.sms" (one JSON line each)
 * Every call needs Authorization: Bearer $OS_APPLICATION_TOKEN (else 401), like the real one. Never a real kernel.
 */
$state = json_decode((string) @file_get_contents((string) getenv('FAKE_KERNEL_STATE')), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$out = static function (array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body); exit; };
if ($auth !== 'Bearer ' . getenv('OS_APPLICATION_TOKEN')) { $out(['error' => ['code' => 'unauthorized', 'message' => 'A valid application token is required.']], 401); }
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
switch ($path) {
    case '/api/v1/notify/sms.php':
        $sms = $state['sms'] ?? ['mode' => 'ok'];
        $log = (string) getenv('FAKE_KERNEL_STATE') . '.sms';
        $member = (int) ($input['member_id'] ?? 0);
        $sent = 0;
        foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [] as $l) { $d = json_decode($l, true); if ((int) ($d['member_id'] ?? 0) === $member && ($d['accepted'] ?? false)) { $sent++; } }
        $mode = $sms['members'][(string) $member] ?? ($sms['mode'] ?? 'ok');
        if ($mode === 'ok' && isset($sms['limit']) && $sent >= (int) $sms['limit']) { $mode = 'rate_limited'; }
        if (($input['text'] ?? '') === '' || mb_strlen((string) ($input['text'] ?? '')) > 480) { $mode = 'invalid'; }
        file_put_contents($log, json_encode($input + ['accepted' => $mode === 'ok', 'mode' => $mode]) . "\n", FILE_APPEND);
        $refuse = static fn (string $code, int $status) => $out(['error' => ['code' => $code, 'message' => 'Refused: ' . $code]], $status);
        switch ($mode) {
            case 'ok':           $out(['notification' => ['id' => 9000 + substr_count((string) @file_get_contents($log), "\n"), 'status' => 'queued']], 202);
            case 'no_sender':    $refuse('no_sender', 503);
            case 'server_error': $refuse('server_error', 500);
            default:             $refuse($mode, 422);
        }
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
    case '/api/v1/apps/read.php':
        file_put_contents((string) getenv('FAKE_KERNEL_STATE') . '.reads', json_encode(['body' => $input, 'method' => $_SERVER['REQUEST_METHOD']]) . "\n", FILE_APPEND);
        $r = $state['read'] ?? ['mode' => 'no_connection'];
        $mode = $r['mode'] ?? 'no_connection';
        if (($input['provider'] ?? '') !== 'reservations' || ($input['tool'] ?? '') !== 'covers_by_service') { $mode = 'not_shared'; }
        elseif (isset($r['locations']) && !in_array((int) ($input['location_id'] ?? 0), array_map('intval', $r['locations']), true)) { $mode = 'not_at_location'; }
        switch ($mode) {
            case 'ok':              $out(['result' => ['schema' => 'reservations.covers-by-service/1', 'restaurant' => 'SMOKE Reservations', 'from' => $input['arguments']['from'] ?? null, 'to' => $input['arguments']['to'] ?? null, 'rows' => $r['rows'] ?? []], 'provider' => 'reservations', 'tool' => 'covers_by_service']);
            case 'negative':        $out(['result' => ['rows' => [['date' => $input['arguments']['from'] ?? '', 'service' => 'Dinner', 'reservations' => 1, 'covers' => -3]]]]);
            case 'string':          $out(['result' => 'nonsense']);
            case 'list':            $out(['result' => [['date' => $input['arguments']['from'] ?? '', 'service' => 'Dinner', 'reservations' => 2, 'covers' => 8]]]);
            case 'garbage':         $out(['result' => ['rows' => [['date' => 'tomorrow', 'service' => 5]]]]);
            case 'provider_failed': $out(['error' => ['code' => 'provider_failed', 'message' => 'The provider did not answer.']], 502);
            case 'not_shared':      $out(['error' => ['code' => 'not_shared', 'message' => 'That tool is not shared.']], 403);
            case 'not_at_location': $out(['error' => ['code' => 'not_at_location', 'message' => 'Both applications must serve that location.']], 403);
            default:                $out(['error' => ['code' => 'no_connection', 'message' => 'No approved connection.']], 403);
        }
}
$out(['error' => ['code' => 'not_found', 'message' => 'No such internal endpoint.']], 404);
